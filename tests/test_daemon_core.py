"""Core daemon tests using fake clients, clocks, and filesystem state."""

import json
import logging
import os
import signal
from datetime import datetime, timedelta, timezone
from threading import Event
from unittest.mock import Mock

import pytest

from scripts import gnmi_daemon as daemon


@pytest.fixture
def config():
    return {
        "device_id": 1,
        "hostname": "router",
        "port": 9339,
        "username": "user",
        "password": "pass",
        "poller_interval": 60,
        "sample_interval": 5,
        "subscriptions": [{
            "path": "/interfaces/interface[name=eth0]/state/counters",
            "instance": "eth0",
            "metrics": ["in-octets", "out-octets"],
            "field_mapping": {"in-octets": "in_octets", "out-octets": "out_octets"},
        }],
    }


@pytest.fixture
def instance(tmp_path, config):
    return daemon.GNMIDaemon(1, config, str(tmp_path))


def test_load_config_rejects_invalid_json_and_shapes(config):
    with pytest.raises(ValueError, match="Invalid JSON"):
        daemon.load_config("{")

    for missing in ("device_id", "hostname", "port", "username", "password", "subscriptions"):
        candidate = dict(config)
        candidate.pop(missing)
        with pytest.raises(ValueError, match=missing):
            daemon.load_config(json.dumps(candidate))

    for subscriptions in (None, []):
        candidate = dict(config, subscriptions=subscriptions)
        with pytest.raises(ValueError, match="at least one subscription"):
            daemon.load_config(json.dumps(candidate))

    for field in ("path", "instance", "metrics", "field_mapping"):
        sub = dict(config["subscriptions"][0])
        sub.pop(field)
        with pytest.raises(ValueError, match=field):
            daemon.load_config(json.dumps(dict(config, subscriptions=[sub])))

    sub = dict(config["subscriptions"][0], metrics=[])
    with pytest.raises(ValueError, match="at least one metric"):
        daemon.load_config(json.dumps(dict(config, subscriptions=[sub])))
    sub = dict(config["subscriptions"][0], field_mapping=[])
    with pytest.raises(ValueError, match="must be a dictionary"):
        daemon.load_config(json.dumps(dict(config, subscriptions=[sub])))
    with pytest.raises(ValueError, match="Invalid compatibility_mode"):
        daemon.load_config(json.dumps(dict(config, compatibility_mode="unknown")))
    with pytest.raises(ValueError, match="Invalid tls_cipher_policy"):
        daemon.load_config(json.dumps(dict(config, tls_cipher_policy="unknown")))
    assert daemon.load_config(json.dumps(config)) == config


def test_build_subscriptions_normalizes_encoding(config):
    request = daemon.build_gnmi_subscriptions(dict(config, encoding="JSON_IETF"))
    assert request["encoding"] == "json_ietf"
    assert request["subscription"][0]["sample_interval"] == 5_000_000_000
    # An explicit sample_interval is expected to be numeric; exercise the documented fallback separately.
    fallback = dict(config)
    fallback.pop("sample_interval")
    fallback["collection_interval"] = 7
    assert daemon.build_gnmi_subscriptions(fallback)["subscription"][0]["sample_interval"] == 7_000_000_000


def test_flatten_telemetry_update_supports_leaf_and_json_ietf_container_shapes():
    assert daemon._flatten_telemetry_update("state/in-octets", 42) == {"in-octets": 42}
    assert daemon._flatten_telemetry_update(
        "srl_nokia-interfaces:interface[name=ethernet-1/1]/statistics",
        {
            "in-octets": "5994",
            "out-octets": "1017089",
            "srl_nokia-interfaces:in-unicast-packets": "29",
        },
    ) == {
        "in-octets": "5994",
        "out-octets": "1017089",
        "in-unicast-packets": "29",
    }


def test_exponential_backoff_sequence_and_reset():
    backoff = daemon.ExponentialBackoff(initial_delay=1, max_delay=3, multiplier=2)
    assert [backoff.get_delay() for _ in range(4)] == [1, 2, 3, 3]
    assert backoff.retry_count == 4
    backoff.reset()
    assert backoff.current_delay == 1
    assert backoff.retry_count == 0


def test_sample_buffer_empty_invalid_timestamp_clear_and_write_clock(monkeypatch):
    buffer = daemon.SampleBuffer()
    assert buffer.get_aggregated() == {}
    buffer.buffer.append({"timestamp": "invalid", "value": 1})
    buffer.buffer.append({"value": 2})
    assert buffer.get_all_samples_with_epoch() == [{"value": 2}]
    monkeypatch.setattr(daemon.time, "time", lambda: 20.0)
    buffer.last_write_time = 5.0
    assert buffer.should_write(10) is True
    buffer.mark_written()
    assert buffer.last_write_time == 20.0
    buffer.clear()
    assert list(buffer.buffer) == []


def test_constructor_default_storage_contract(monkeypatch, tmp_path, config):
    missing = tmp_path / "missing"
    monkeypatch.setattr(daemon, "default_storage_dir", lambda: missing)
    with pytest.raises(RuntimeError, match="Runtime storage directory missing"):
        daemon.GNMIDaemon(1, config)

    missing.mkdir()
    chmod = Mock()
    monkeypatch.setattr(daemon, "chmod_private_file", chmod)
    created = daemon.GNMIDaemon(1, config)
    assert created.storage_dir == missing
    chmod.assert_called_once_with(missing, 0o750)


def test_pid_file_lifecycle(instance, monkeypatch):
    chmod = Mock()
    monkeypatch.setattr(daemon, "chmod_private_file", chmod)
    instance.write_pid_file()
    assert int(instance.pid_file.read_text()) == os.getpid()
    assert chmod.call_count == 2
    chmod.assert_any_call(instance.pid_lock_file)
    chmod.assert_any_call(instance.pid_file)
    instance.remove_pid_file()
    assert not instance.pid_file.exists()
    instance.remove_pid_file()


def test_pid_file_cleanup_does_not_unlink_successor(instance):
    successor_pid = os.getpid() + 1
    instance.pid_file.write_text(str(successor_pid))

    instance.remove_pid_file()

    assert int(instance.pid_file.read_text()) == successor_pid


def test_pid_lock_is_held_for_daemon_lifetime(instance, config):
    contender = daemon.GNMIDaemon(1, config, str(instance.storage_dir))

    assert instance.write_pid_file() is True
    assert contender.write_pid_file() is False

    instance.remove_pid_file()
    assert contender.write_pid_file() is True
    contender.remove_pid_file()


def test_pid_file_errors_are_nonfatal(instance, monkeypatch):
    monkeypatch.setattr(daemon.os, "open", Mock(side_effect=OSError("write denied")))
    instance.write_pid_file()
    instance.pid_file.mkdir()
    instance.remove_pid_file()


def test_write_storage_is_atomic_and_records_state(instance, monkeypatch):
    instance.connection_status = "connected"
    instance.connection_start_time = 100.0
    instance.error_count = 2
    instance.last_error = "previous"
    instance.unmatched_instance_count = 3
    instance.sample_buffer.add_sample("eth0", {"in_octets": 9})
    monkeypatch.setattr(daemon.time, "time", lambda: 130.0)
    chmod = Mock()
    monkeypatch.setattr(daemon, "chmod_private_file", chmod)

    instance.write_storage({"eth0": {"in_octets": 9}})

    stored = json.loads(instance.storage_file.read_text())
    assert stored["connection_uptime"] == 30.0
    assert stored["metric_groups"] == {"eth0": {"in_octets": 9}}
    assert stored["samples_history"]["eth0"][0]["in_octets"] == 9
    assert stored["unmatched_instance_count"] == 3
    assert instance.write_count == 1
    chmod.assert_called_once_with(instance.storage_file)


def test_write_storage_cleans_up_failed_temporary_file(instance, monkeypatch):
    real_rename = daemon.os.rename

    def fail_rename(source, destination):
        assert os.path.exists(source)
        raise OSError("rename failed")

    monkeypatch.setattr(daemon.os, "rename", fail_rename)
    instance.write_storage({"eth0": {"in_octets": 1}})
    assert list(instance.storage_dir.glob(".tmp_device_*")) == []
    monkeypatch.setattr(daemon.os, "rename", real_rename)


def test_process_sample_filters_maps_and_flushes(instance, monkeypatch):
    instance._subscription_by_instance = {"eth0": instance.config["subscriptions"][0]}
    monkeypatch.setattr(instance.sample_buffer, "should_write", lambda interval: True)
    write = Mock()
    monkeypatch.setattr(instance, "write_storage", write)
    monkeypatch.setattr(daemon.time, "time", lambda: 20.0)
    instance.last_sample_time = 1.0

    instance.process_sample_with_config("eth0", {"in-octets": 5, "ignored": 99})

    assert instance.sample_buffer.get_aggregated_by_instance()["eth0"]["in_octets"] == 5
    write.assert_called_once()


def test_process_sample_skips_empty_and_contains_processing_errors(instance, monkeypatch):
    instance._subscription_by_instance = {"eth0": instance.config["subscriptions"][0]}
    instance.process_sample_with_config("eth0", {"ignored": 1})
    assert not instance.sample_buffer.has_data()
    monkeypatch.setattr(instance.sample_buffer, "add_sample", Mock(side_effect=RuntimeError("buffer")))
    instance.process_sample_with_config("unknown", {"anything": 1})


class FakeGNMIClient:
    instances = []
    entries = []
    init_error = None
    connect_error = None
    close_error = None
    subscribe_error = None
    subscribe_errors = {}

    def __init__(self, **kwargs):
        if self.init_error:
            raise self.init_error
        self.kwargs = kwargs
        self.request = None
        self.closed = False
        self.__class__.instances.append(self)

    def connect(self):
        if self.connect_error:
            raise self.connect_error

    def subscribe(self, request):
        self.request = request
        subscriptions = request.get("subscription", [])
        if self.subscribe_error and len(subscriptions) > 1:
            raise self.subscribe_error
        if subscriptions:
            error = self.subscribe_errors.get(subscriptions[0]["path"])
            if error:
                raise error
        return iter(self.entries)

    def close(self):
        self.closed = True
        if self.close_error:
            raise self.close_error


def test_connect_and_subscribe_routes_updates_and_optional_tls(instance, monkeypatch):
    FakeGNMIClient.instances.clear()
    FakeGNMIClient.init_error = None
    FakeGNMIClient.connect_error = None
    FakeGNMIClient.close_error = None
    FakeGNMIClient.subscribe_error = None
    FakeGNMIClient.subscribe_errors = {}
    FakeGNMIClient.entries = [
        {"update": {"prefix": "/interfaces/interface[name=eth0]", "timestamp": 1,
                    "update": [{"path": "state/in-octets", "val": 8}, {"not": "metric"}]}},
        {"update": {"prefix": "/interfaces/interface[name=eth0]", "update": []}},
        {"sync_response": True},
        {"update": {"prefix": "/interfaces/interface[name=missing]",
                    "update": [{"path": "in-octets", "val": 1}]}},
        "bad",
    ]
    instance.config.update({
        "ca_cert": "ca", "client_key": "key", "client_cert": "cert",
        "tls_override": "router.example", "no_qos_marking": True,
    })
    monkeypatch.setattr(daemon, "gNMIclient", FakeGNMIClient)
    patch_ciena = Mock()
    monkeypatch.setattr(daemon, "patch_pygnmi_for_ciena", patch_ciena)
    monkeypatch.setattr(
        daemon,
        "telemetryParser",
        lambda entry: (_ for _ in ()).throw(ValueError("parse")) if entry == "bad" else entry,
    )
    process = Mock()
    poll = Mock()
    monkeypatch.setattr(instance, "process_sample_with_config", process)
    monkeypatch.setattr(instance, "poll_config_changes", poll)
    instance.last_config_poll = 0

    assert instance.connect_and_subscribe() is True

    client = FakeGNMIClient.instances[0]
    assert client.kwargs["path_root"] == "ca"
    assert client.kwargs["path_key"] == "key"
    assert client.kwargs["path_cert"] == "cert"
    assert client.kwargs["override"] == "router.example"
    assert client.kwargs["no_qos_marking"] is True
    assert client.kwargs["insecure"] is False
    patch_ciena.assert_not_called()
    process.assert_called_once_with("eth0", {"in-octets": 8})
    assert instance.unmatched_instance_count == 1
    assert instance.connection_status == "connected"
    assert poll.called
    assert client.closed is True
    assert instance.gnmi_client is None


def test_direct_daemon_initialization_applies_process_scoped_tls_policy(monkeypatch):
    monkeypatch.setattr(daemon, "gNMIclient", object())
    monkeypatch.setenv("GRPC_SSL_CIPHER_SUITES", "inherited-global-override")

    policy = daemon.initialize_gnmi_runtime({
        "use_tls": True,
        "tls_cipher_policy": "legacy_compatibility",
    })

    assert policy == "legacy_compatibility"
    assert os.environ["GRPC_SSL_CIPHER_SUITES"] == (
        "ECDHE-RSA-AES256-GCM-SHA384:ECDHE-RSA-AES128-SHA"
    )

    daemon.initialize_gnmi_runtime({
        "use_tls": True,
        "tls_cipher_policy": "default",
    })
    assert "GRPC_SSL_CIPHER_SUITES" not in os.environ


def test_connect_derives_insecure_and_scopes_ciena_patch(instance, monkeypatch):
    FakeGNMIClient.instances.clear()
    FakeGNMIClient.init_error = None
    FakeGNMIClient.entries = []
    instance.config.update({
        "use_tls": False,
        "compatibility_mode": "ciena_saos10",
    })
    instance.config.pop("insecure", None)
    monkeypatch.setattr(daemon, "gNMIclient", FakeGNMIClient)
    patch_ciena = Mock()
    monkeypatch.setattr(daemon, "patch_pygnmi_for_ciena", patch_ciena)

    assert instance.connect_and_subscribe() is True

    patch_ciena.assert_called_once_with()
    assert FakeGNMIClient.instances[0].kwargs["insecure"] is True


def test_connect_and_subscribe_records_errors_and_reraises_interrupt(instance, monkeypatch):
    FakeGNMIClient.init_error = RuntimeError("connect failed")
    monkeypatch.setattr(daemon, "gNMIclient", FakeGNMIClient)
    write = Mock()
    monkeypatch.setattr(instance, "write_storage", write)
    assert instance.connect_and_subscribe() is False
    assert instance.connection_status == "error"
    assert "connect failed" in instance.last_error
    write.assert_called_once_with()

    FakeGNMIClient.init_error = KeyboardInterrupt()
    with pytest.raises(KeyboardInterrupt):
        instance.connect_and_subscribe()
    FakeGNMIClient.init_error = None


def test_invalid_aggregate_subscription_isolates_rejected_path(instance, monkeypatch):
    class InvalidArgumentError(Exception):
        def code(self):
            return type("Status", (), {"name": "INVALID_ARGUMENT"})()

    bad_path = "/interfaces/interface[name=invalid]/state/counters"
    instance.config["subscriptions"].append({
        "path": bad_path,
        "instance": "invalid",
        "metrics": ["in-octets"],
        "field_mapping": {"in-octets": "in_octets"},
    })
    FakeGNMIClient.instances.clear()
    FakeGNMIClient.init_error = None
    FakeGNMIClient.connect_error = None
    FakeGNMIClient.close_error = None
    FakeGNMIClient.entries = []
    FakeGNMIClient.subscribe_error = InvalidArgumentError("aggregate rejected")
    FakeGNMIClient.subscribe_errors = {bad_path: InvalidArgumentError("invalid path")}
    monkeypatch.setattr(daemon, "gNMIclient", FakeGNMIClient)

    assert instance.connect_and_subscribe() is False
    assert instance._rejected_subscription_paths == {bad_path}

    FakeGNMIClient.subscribe_error = None
    assert instance.connect_and_subscribe() is True
    request_paths = [
        subscription["path"]
        for subscription in FakeGNMIClient.instances[-1].request["subscription"]
    ]
    assert request_paths == [instance.config["subscriptions"][0]["path"]]


def test_pygnmi_wrapped_path_rejection_is_definitive():
    WrappedError = type("gNMIException", (Exception,), {})
    assert daemon.GNMIDaemon._is_definitive_path_rejection(
        WrappedError("GRPC ERROR: Failed to parse path - Invalid value")
    ) is True
    assert daemon.GNMIDaemon._is_definitive_path_rejection(
        WrappedError("GRPC ERROR: connection unavailable")
    ) is False


def test_wrapped_aggregate_error_activates_subscription_isolation(instance, monkeypatch):
    WrappedError = type("gNMIException", (Exception,), {})
    bad_path = "/interfaces/interface[name=invalid]/state/counters"
    instance.config["subscriptions"].append({
        "path": bad_path,
        "instance": "invalid",
        "metrics": ["in-octets"],
        "field_mapping": {"in-octets": "in_octets"},
    })
    FakeGNMIClient.instances.clear()
    FakeGNMIClient.init_error = None
    FakeGNMIClient.connect_error = None
    FakeGNMIClient.close_error = None
    FakeGNMIClient.entries = [{"sync_response": True}]
    FakeGNMIClient.subscribe_error = WrappedError("GRPC ERROR: invalid path")
    FakeGNMIClient.subscribe_errors = {bad_path: WrappedError("GRPC ERROR: invalid value")}
    monkeypatch.setattr(daemon, "gNMIclient", FakeGNMIClient)

    assert instance.connect_and_subscribe() is False
    assert instance._rejected_subscription_paths == {bad_path}


def test_subscription_isolation_probe_has_bounded_first_response_wait(instance):
    cancelled = Event()

    class BlockingSubscription:
        def __iter__(self):
            return self

        def __next__(self):
            cancelled.wait(timeout=1)
            raise StopIteration

        def cancel(self):
            cancelled.set()

    instance.gnmi_client = Mock()
    instance.gnmi_client.subscribe.return_value = BlockingSubscription()
    instance.subscription_probe_timeout = 0.01

    rejected = instance._identify_rejected_subscriptions(
        instance.config["subscriptions"], "json"
    )

    assert rejected == set()
    assert cancelled.wait(timeout=0.2)


def test_shutdown_exception_branch_does_not_write_error_state(instance, monkeypatch):
    FakeGNMIClient.init_error = RuntimeError("stream closed")
    instance.shutdown_event.set()
    write = Mock()
    monkeypatch.setattr(instance, "write_storage", write)
    monkeypatch.setattr(daemon, "gNMIclient", FakeGNMIClient)

    assert instance.connect_and_subscribe() is True
    write.assert_not_called()

    instance.shutdown()

    assert instance.connection_status == "disconnected"
    write.assert_called_once_with()
    FakeGNMIClient.init_error = None


def test_request_shutdown_defers_final_cleanup(instance):
    instance.running = True
    instance.gnmi_client = Mock()

    instance.request_shutdown()

    assert instance.running is False
    assert instance.shutdown_event.is_set()
    assert instance._shutdown_complete is False
    instance.gnmi_client.close.assert_called_once()


def test_run_reconnects_then_shutdown(instance, monkeypatch):
    monkeypatch.setattr(instance, "write_pid_file", Mock())
    monkeypatch.setattr(instance, "connect_and_subscribe", Mock(return_value=False))
    monkeypatch.setattr(instance.shutdown_event, "wait", Mock(return_value=True))
    shutdown = Mock()
    monkeypatch.setattr(instance, "shutdown", shutdown)

    instance.run()

    assert instance.reconnection_count == 1
    instance.shutdown_event.wait.assert_called_once_with(timeout=1.0)
    shutdown.assert_called_once()


def test_run_contains_keyboard_interrupt(instance, monkeypatch):
    monkeypatch.setattr(instance, "write_pid_file", Mock())
    monkeypatch.setattr(instance, "connect_and_subscribe", Mock(side_effect=KeyboardInterrupt()))
    shutdown = Mock()
    monkeypatch.setattr(instance, "shutdown", shutdown)
    instance.run()
    shutdown.assert_called_once()


def test_shutdown_closes_flushes_updates_status_and_removes_pid(instance, monkeypatch):
    client = Mock()
    instance.gnmi_client = client
    monkeypatch.setattr(
        instance.sample_buffer,
        "get_aggregated_by_instance",
        Mock(return_value={"eth0": {"in_octets": 1}}),
    )
    writes = []
    monkeypatch.setattr(instance, "write_storage", lambda data=None: writes.append(data))
    remove = Mock()
    monkeypatch.setattr(instance, "remove_pid_file", remove)

    instance.shutdown()

    client.close.assert_called_once()
    assert writes == [{"eth0": {"in_octets": 1}}, None]
    assert instance.connection_status == "disconnected"
    remove.assert_called_once()

    # Signal handling and run() both call shutdown; the second call must not
    # overwrite a successor's storage or PID file.
    instance.shutdown()
    client.close.assert_called_once()
    assert writes == [{"eth0": {"in_octets": 1}}, None]
    remove.assert_called_once()


def test_shutdown_contains_cleanup_failures(instance, monkeypatch):
    instance.gnmi_client = Mock()
    instance.gnmi_client.close.side_effect = RuntimeError("close")
    monkeypatch.setattr(
        instance.sample_buffer, "get_aggregated_by_instance", Mock(side_effect=RuntimeError("buffer"))
    )
    monkeypatch.setattr(instance, "write_storage", Mock(side_effect=RuntimeError("storage")))
    monkeypatch.setattr(instance, "remove_pid_file", Mock())
    instance.shutdown()


def test_check_health_process_and_file_error_paths(monkeypatch, tmp_path):
    (tmp_path / "device_1.pid").write_text("123")
    monkeypatch.setattr(daemon.os, "kill", Mock(side_effect=OSError("dead")))
    health = daemon.GNMIDaemon.check_health(1, str(tmp_path))
    assert health["status"] == "pid_exists_but_process_dead"

    (tmp_path / "device_1.pid").write_text("invalid")
    (tmp_path / "device_1.json").write_text("not json")
    health = daemon.GNMIDaemon.check_health(1, str(tmp_path))
    assert health["running"] is False


class FakeDaemon:
    health = {"running": True}
    run_error = None
    instances = []

    def __init__(self, **kwargs):
        self.kwargs = kwargs
        self.shutdown = Mock()
        self.__class__.instances.append(self)

    @classmethod
    def check_health(cls, *args):
        return cls.health

    def run(self):
        if self.run_error:
            raise self.run_error


def invoke_main(monkeypatch, args):
    monkeypatch.setattr(daemon, "GNMIDaemon", FakeDaemon)
    monkeypatch.setattr(daemon.signal, "signal", lambda *args: None)
    monkeypatch.setattr(daemon.sys, "argv", ["daemon"] + args)
    return daemon.main()


def test_main_health_and_configuration_contracts(monkeypatch, tmp_path, capsys):
    FakeDaemon.health = {"running": True}
    with pytest.raises(SystemExit) as exc:
        invoke_main(monkeypatch, ["--device-id", "1", "--check-health", "--debug"])
    assert exc.value.code == 0
    assert json.loads(capsys.readouterr().out)["running"] is True
    assert daemon.logger.level == logging.DEBUG

    with pytest.raises(SystemExit) as exc:
        invoke_main(monkeypatch, ["--device-id", "1"])
    assert exc.value.code == 1

    config_file = tmp_path / "config.json"
    config_file.write_text('{"hostname":"router"}')
    FakeDaemon.instances.clear()
    FakeDaemon.run_error = None
    assert invoke_main(monkeypatch, [
        "--device-id", "2", "--config-file", str(config_file), "--storage-dir", str(tmp_path),
    ]) is None
    assert FakeDaemon.instances[-1].kwargs["device_id"] == 2


def test_main_shuts_down_after_daemon_crash(monkeypatch, tmp_path):
    config_file = tmp_path / "config.json"
    config_file.write_text('{}')
    FakeDaemon.instances.clear()
    FakeDaemon.run_error = RuntimeError("crash")
    with pytest.raises(SystemExit) as exc:
        invoke_main(monkeypatch, ["--device-id", "1", "--config-file", str(config_file)])
    assert exc.value.code == 1
    FakeDaemon.instances[-1].shutdown.assert_called_once()
    FakeDaemon.run_error = None


def test_signal_handler_is_nonfatal():
    daemon.signal_handler(signal.SIGTERM, None)

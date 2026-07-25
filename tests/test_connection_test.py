"""Tests for the user-facing three-stage gNMI connection probe."""

import json
import signal
import socket
import sys
from types import ModuleType
from unittest.mock import Mock

import pytest

from scripts import gnmi_connection_test as probe


def emitted(capsys):
    return json.loads(capsys.readouterr().out.strip().splitlines()[-1])


def test_emit_writes_ndjson(capsys):
    probe.emit("tcp", True, "ok", 12)
    assert emitted(capsys) == {
        "stage": "tcp", "success": True, "message": "ok", "duration_ms": 12,
    }


def test_stage_tcp_success_and_failure(monkeypatch, capsys):
    connection = Mock()
    monkeypatch.setattr(probe.socket, "create_connection", lambda *args, **kwargs: connection)
    monkeypatch.setattr(probe.time, "monotonic", Mock(side_effect=[1.0, 1.025]))
    assert probe.stage_tcp("router", 9339) is True
    connection.close.assert_called_once()
    assert emitted(capsys)["success"] is True

    monkeypatch.setattr(
        probe.socket, "create_connection", Mock(side_effect=OSError("refused"))
    )
    monkeypatch.setattr(probe.time, "monotonic", Mock(side_effect=[2.0, 2.1]))
    assert probe.stage_tcp("router", 9339) is False
    assert "refused" in emitted(capsys)["message"]


class TLSClient:
    instances = []
    connect_error = None

    def __init__(self, **kwargs):
        self.kwargs = kwargs
        self.__class__.instances.append(self)

    def connect(self):
        if self.connect_error:
            raise self.connect_error


def install_tls_client(monkeypatch):
    module = ModuleType("pygnmi.client")
    module.gNMIclient = TLSClient
    monkeypatch.setitem(sys.modules, "pygnmi.client", module)
    patch_ciena = Mock()
    collector = ModuleType("gnmi_collector")
    collector.__path__ = []
    patch_module = ModuleType("gnmi_collector.pygnmi_patch")
    patch_module.patch_pygnmi_for_ciena = patch_ciena
    monkeypatch.setitem(sys.modules, "gnmi_collector", collector)
    monkeypatch.setitem(sys.modules, "gnmi_collector.pygnmi_patch", patch_module)
    return patch_ciena


def test_stage_tls_builds_all_connection_options(monkeypatch, capsys):
    patch_ciena = install_tls_client(monkeypatch)
    TLSClient.instances.clear()
    TLSClient.connect_error = None
    config = {
        "hostname": "router", "port": 9339, "username": "u", "password": "p",
        "use_tls": True, "skip_verify": True, "ca_cert_path": "ca",
        "client_key_path": "key", "client_cert_path": "cert", "tls_override": "name",
    }

    client = probe.stage_tls(config)

    assert client is TLSClient.instances[0]
    assert client.kwargs == {
        "target": ("router", 9339), "username": "u", "password": "p",
        "insecure": False, "skip_verify": True, "path_root": "ca",
        "path_key": "key", "path_cert": "cert", "override": "name",
    }
    assert emitted(capsys)["success"] is True
    patch_ciena.assert_not_called()


def test_stage_tls_applies_ciena_patch_only_when_selected(monkeypatch, capsys):
    patch_ciena = install_tls_client(monkeypatch)
    TLSClient.instances.clear()
    TLSClient.connect_error = None

    client = probe.stage_tls({
        "hostname": "router", "port": 9339,
        "use_tls": False, "compatibility_mode": "ciena_saos10",
    })

    assert client is TLSClient.instances[0]
    assert client.kwargs["insecure"] is True
    patch_ciena.assert_called_once_with()
    assert "Insecure gNMI transport OK" in emitted(capsys)["message"]


@pytest.mark.parametrize(
    "error, expected",
    [
        (RuntimeError("UNAUTHENTICATED"), "Authentication failed"),
        (RuntimeError("SSL handshake failed"), "TLS certificate validation failed"),
        (RuntimeError("unexpected"), "TLS connection failed"),
    ],
)
def test_stage_tls_classifies_failures(monkeypatch, capsys, error, expected):
    install_tls_client(monkeypatch)
    TLSClient.connect_error = error

    assert probe.stage_tls({"hostname": "r", "port": 1}) is None
    assert expected in emitted(capsys)["message"]


class ProbeClient:
    def __init__(self, responses=(), error=None, close_error=None):
        self.responses = responses
        self.error = error
        self.close_error = close_error
        self.closed = False
        self.request = None

    def subscribe(self, request):
        self.request = request
        if self.error:
            raise self.error
        return iter(self.responses)

    def close(self):
        self.closed = True
        if self.close_error:
            raise self.close_error


@pytest.fixture
def no_alarms(monkeypatch):
    monkeypatch.setattr(signal, "signal", lambda *args: None)
    monkeypatch.setattr(signal, "alarm", lambda *args: None)


@pytest.mark.parametrize(
    "response, success, phrase",
    [
        ({"sync_response": True}, True, "data/sync"),
        ("code: 3 INVALID_ARGUMENT", True, "path not supported"),
        ("code: 12 UNIMPLEMENTED", True, "unimplemented"),
        ("code: 16 UNAUTHENTICATED", False, "Authentication failed"),
        ("code: 14 UNAVAILABLE", False, "connection dropped"),
    ],
)
def test_stage_gnmi_classifies_stream_responses(no_alarms, capsys, response, success, phrase):
    client = ProbeClient([response])
    assert probe.stage_gnmi(client) is success
    assert phrase in emitted(capsys)["message"]
    assert client.closed is True
    assert client.request["subscription"][0]["mode"] == "on_change"


def test_stage_gnmi_unknown_stream_end(no_alarms, capsys):
    client = ProbeClient([{"notification": True}], close_error=RuntimeError("close"))
    assert probe.stage_gnmi(client) is False
    assert "ended" in emitted(capsys)["message"]


@pytest.mark.parametrize(
    "error, success, phrase",
    [
        (TimeoutError(), False, "timed out"),
        (RuntimeError("code: 16 UNAUTHENTICATED"), False, "Authentication failed"),
        (RuntimeError("code: 3 INVALID_ARGUMENT"), True, "path not supported"),
        (RuntimeError("code: 12 UNIMPLEMENTED"), True, "unimplemented"),
        (RuntimeError("code: 14 UNAVAILABLE"), False, "connection dropped"),
        (RuntimeError("other"), False, "gNMI error"),
    ],
)
def test_stage_gnmi_classifies_exceptions(no_alarms, capsys, error, success, phrase):
    client = ProbeClient(error=error)
    assert probe.stage_gnmi(client) is success
    assert phrase in emitted(capsys)["message"]


def run_main(monkeypatch, argv, tcp=True, tls=object(), gnmi=True):
    monkeypatch.setattr(probe.sys, "argv", argv)
    monkeypatch.setattr(probe, "stage_tcp", Mock(return_value=tcp))
    monkeypatch.setattr(probe, "stage_tls", Mock(return_value=tls))
    monkeypatch.setattr(probe, "stage_gnmi", Mock(return_value=gnmi))
    with pytest.raises(SystemExit) as exc:
        probe.main()
    return exc.value.code


def test_main_validates_input_and_stops_at_failed_stage(monkeypatch, tmp_path, capsys):
    assert run_main(monkeypatch, ["probe"]) == 1
    assert emitted(capsys)["stage"] == "error"

    assert run_main(monkeypatch, ["probe", str(tmp_path / "missing")]) == 1
    assert "Cannot read" in emitted(capsys)["message"]

    config = tmp_path / "config.json"
    config.write_text('{"hostname":"router","port":"9339"}')
    assert run_main(monkeypatch, ["probe", str(config)], tcp=False) == 1
    assert run_main(monkeypatch, ["probe", str(config)], tls=None) == 1
    assert run_main(monkeypatch, ["probe", str(config)], gnmi=False) == 1


def test_main_success_returns_normally(monkeypatch, tmp_path):
    config = tmp_path / "config.json"
    config.write_text('{"hostname":"router"}')
    monkeypatch.setattr(probe.sys, "argv", ["probe", str(config)])
    monkeypatch.setattr(probe, "stage_tcp", lambda *args: True)
    monkeypatch.setattr(probe, "stage_tls", lambda *args: object())
    monkeypatch.setattr(probe, "stage_gnmi", lambda *args: True)

    assert probe.main() is None

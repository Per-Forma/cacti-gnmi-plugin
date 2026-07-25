"""Tests for the legacy collector subscription API while it remains shipped."""

from unittest.mock import Mock

import pytest

from scripts.gnmi_collector import subscriber


def test_process_and_store_maps_paths_transforms_and_timestamp(monkeypatch):
    apply_transforms = Mock()
    store_results = Mock()
    monkeypatch.setattr("scripts.gnmi_collector.transforms.apply_transforms", apply_transforms)
    monkeypatch.setattr("scripts.gnmi_collector.rrd.store_results", store_results)
    data = [{"path": "/in", "val": 4}, {"path": "/out", "val": 8}]
    transforms = [{"operation": "multiply", "factor": 8}]

    subscriber.process_and_store(data, 12_000_000_000, ["/out", "/missing"], "x.rrd", transforms)

    results = [{"path": "/out", "val": 8}, None]
    apply_transforms.assert_called_once_with(results, transforms)
    store_results.assert_called_once_with(results, 12, "x.rrd")


def test_process_and_store_without_transforms(monkeypatch):
    apply_transforms = Mock()
    store_results = Mock()
    monkeypatch.setattr("scripts.gnmi_collector.transforms.apply_transforms", apply_transforms)
    monkeypatch.setattr("scripts.gnmi_collector.rrd.store_results", store_results)

    subscriber.process_and_store([], 0, [], "x.rrd")

    apply_transforms.assert_not_called()
    store_results.assert_not_called()


class FakeClient:
    instances = []
    responses = []

    def __init__(self, **kwargs):
        self.kwargs = kwargs
        self.request = None
        self.__class__.instances.append(self)

    def __enter__(self):
        return self

    def __exit__(self, *args):
        return False

    def subscribe(self, request):
        self.request = request
        return iter(self.responses)


def test_subscribe_and_store_builds_client_and_yields_updates(monkeypatch):
    FakeClient.instances.clear()
    FakeClient.responses = ["update", "sync"]
    monkeypatch.setattr(subscriber, "gNMIclient", FakeClient)
    patch_ciena = Mock()
    monkeypatch.setattr(subscriber, "patch_pygnmi_for_ciena", patch_ciena)
    monkeypatch.setattr(
        subscriber,
        "telemetryParser",
        lambda value: (
            {"update": {"update": [{"path": "/in", "val": 1}], "timestamp": 2_000_000_000}}
            if value == "update"
            else {"sync_response": True}
        ),
    )
    monkeypatch.setattr("scripts.gnmi_collector.rrd.rrd_file_exists", lambda path: False)
    create_rrd = Mock()
    monkeypatch.setattr("scripts.gnmi_collector.rrd.create_rrd", create_rrd)
    process = Mock()
    monkeypatch.setattr(subscriber, "process_and_store", process)

    updates = list(subscriber.subscribe_and_store(
        "/interfaces", "router", 9339, ["in"], "x.rrd", "user", "pass",
        path_root="ca", path_key="key", path_cert="cert", override="router.example",
        skip_verify=False, debug=True, insecure=True, no_qos_marking=True,
    ))

    assert len(updates) == 1
    client = FakeClient.instances[0]
    assert client.kwargs == {
        "target": ("router", 9339), "username": "user", "password": "pass",
        "skip_verify": False, "debug": True, "insecure": True,
        "no_qos_marking": True, "path_root": "ca", "path_key": "key",
        "path_cert": "cert", "override": "router.example",
    }
    assert client.request["subscription"][0]["sample_interval"] == 5_000_000_000
    create_rrd.assert_called_once_with("x.rrd", ["in"], step=5, ds_type="COUNTER")
    process.assert_called_once()
    patch_ciena.assert_not_called()


def test_subscribe_and_store_scopes_ciena_patch(monkeypatch):
    FakeClient.instances.clear()
    FakeClient.responses = []
    monkeypatch.setattr(subscriber, "gNMIclient", FakeClient)
    monkeypatch.setattr("scripts.gnmi_collector.rrd.rrd_file_exists", lambda path: True)
    patch_ciena = Mock()
    monkeypatch.setattr(subscriber, "patch_pygnmi_for_ciena", patch_ciena)

    assert list(subscriber.subscribe_and_store(
        "/x", "host", 1, [], "x", "u", "p",
        compatibility_mode="ciena_saos10",
    )) == []
    patch_ciena.assert_called_once_with()


def test_subscribe_and_store_skips_creation_for_existing_rrd(monkeypatch):
    FakeClient.instances.clear()
    FakeClient.responses = []
    monkeypatch.setattr(subscriber, "gNMIclient", FakeClient)
    monkeypatch.setattr("scripts.gnmi_collector.rrd.rrd_file_exists", lambda path: True)
    create_rrd = Mock()
    monkeypatch.setattr("scripts.gnmi_collector.rrd.create_rrd", create_rrd)

    assert list(subscriber.subscribe_and_store("/x", "host", 1, [], "x", "u", "p")) == []
    create_rrd.assert_not_called()


def test_subscribe_and_store_reraises_client_errors(monkeypatch):
    class BrokenClient(FakeClient):
        def __enter__(self):
            raise RuntimeError("connect failed")

    monkeypatch.setattr(subscriber, "gNMIclient", BrokenClient)
    monkeypatch.setattr("scripts.gnmi_collector.rrd.rrd_file_exists", lambda path: True)

    with pytest.raises(RuntimeError, match="connect failed"):
        list(subscriber.subscribe_and_store("/x", "host", 1, [], "x", "u", "p"))

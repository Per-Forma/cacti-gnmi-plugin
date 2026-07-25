"""Behavioral tests for the Ciena pygnmi compatibility patch."""

import builtins
import sys
from types import ModuleType

import pytest

from scripts.gnmi_collector.pygnmi_patch import patch_pygnmi_for_ciena


def install_fake_pygnmi(monkeypatch, stream_class):
    class Client:
        def capabilities(self):
            return {"original": True}

    client_module = ModuleType("pygnmi.client")
    client_module.gNMIclient = Client
    client_module.StreamSubscriber = stream_class
    monkeypatch.setitem(sys.modules, "pygnmi.client", client_module)
    return Client


def test_patch_replaces_capabilities_and_handles_none_updates(monkeypatch):
    class Stream:
        def _merge_updates(self, resp, new_resp):
            resp.update(new_resp)
            return "merged"

        def _get_one_update(self, timeout=None):
            return {"sync_response": True}

    client = install_fake_pygnmi(monkeypatch, Stream)
    patch_pygnmi_for_ciena()

    assert client().capabilities() == {
        "supported_encodings": ["json", "json_ietf"],
        "gNMI_version": "0.7.0",
        "supported_models": [],
    }
    stream = Stream()
    assert stream._merge_updates({}, None) is None
    target = {}
    assert stream._merge_updates(target, {"value": 1}) == "merged"
    assert target == {"value": 1}

    # Reapplying during a daemon reconnect must not wrap methods repeatedly.
    merge_method = Stream._merge_updates
    patch_pygnmi_for_ciena()
    assert Stream._merge_updates is merge_method


def test_patch_get_updates_skips_none_until_sync(monkeypatch):
    class Stream:
        def __init__(self):
            self.responses = [None] * 11 + [{"sync_response": True}]

        def _merge_updates(self, resp, new_resp):
            resp.update(new_resp)

        def _get_one_update(self, timeout=None):
            return self.responses.pop(0)

        def _get_updates_till_sync(self, timeout=None):
            return {"original": True}

    install_fake_pygnmi(monkeypatch, Stream)
    patch_pygnmi_for_ciena()

    assert Stream()._get_updates_till_sync(timeout=3)["sync_response"] is True


def test_patch_merge_swallows_only_none_related_type_errors(monkeypatch):
    class Stream:
        def _merge_updates(self, resp, new_resp):
            raise TypeError(new_resp)

    install_fake_pygnmi(monkeypatch, Stream)
    patch_pygnmi_for_ciena()
    stream = Stream()

    assert stream._merge_updates({}, "argument of type 'NoneType' is not iterable") is None
    with pytest.raises(TypeError, match="different failure"):
        stream._merge_updates({}, "different failure")


def test_patch_tolerates_pygnmi_without_merge_api(monkeypatch):
    class Stream:
        pass

    client = install_fake_pygnmi(monkeypatch, Stream)
    patch_pygnmi_for_ciena()

    assert client().capabilities()["gNMI_version"] == "0.7.0"


def test_patch_reraises_import_error(monkeypatch):
    original_import = builtins.__import__

    def fail_pygnmi(name, *args, **kwargs):
        if name == "pygnmi.client":
            raise ImportError("pygnmi missing")
        return original_import(name, *args, **kwargs)

    monkeypatch.setattr(builtins, "__import__", fail_pygnmi)

    with pytest.raises(ImportError, match="pygnmi missing"):
        patch_pygnmi_for_ciena()

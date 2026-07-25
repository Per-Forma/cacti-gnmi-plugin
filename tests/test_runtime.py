"""Tests for protected runtime path helpers."""

from pathlib import Path

from scripts import gnmi_runtime


def test_default_runtime_paths(monkeypatch):
    for name in ("GNMI_RUNTIME_DIR", "GNMI_STORAGE_DIR", "GNMI_LOG_DIR", "GNMI_CERT_DIR"):
        monkeypatch.delenv(name, raising=False)

    assert gnmi_runtime.runtime_dir() == gnmi_runtime.PLUGIN_ROOT / "runtime"
    assert gnmi_runtime.storage_dir() == gnmi_runtime.PLUGIN_ROOT / "runtime/storage"
    assert gnmi_runtime.log_dir() == gnmi_runtime.PLUGIN_ROOT / "runtime/logs"
    assert gnmi_runtime.cert_dir() == gnmi_runtime.PLUGIN_ROOT / "runtime/certs"


def test_runtime_path_overrides_expand_user(monkeypatch):
    monkeypatch.setenv("GNMI_RUNTIME_DIR", "~/gnmi-runtime")
    monkeypatch.setenv("GNMI_STORAGE_DIR", "~/gnmi-storage")
    monkeypatch.setenv("GNMI_LOG_DIR", "~/gnmi-logs")
    monkeypatch.setenv("GNMI_CERT_DIR", "~/gnmi-certs")

    assert gnmi_runtime.runtime_dir() == Path("~/gnmi-runtime").expanduser()
    assert gnmi_runtime.storage_dir() == Path("~/gnmi-storage").expanduser()
    assert gnmi_runtime.log_dir() == Path("~/gnmi-logs").expanduser()
    assert gnmi_runtime.cert_dir() == Path("~/gnmi-certs").expanduser()


def test_derived_paths_use_runtime_override(monkeypatch, tmp_path):
    monkeypatch.setenv("GNMI_RUNTIME_DIR", str(tmp_path))
    for name in ("GNMI_STORAGE_DIR", "GNMI_LOG_DIR", "GNMI_CERT_DIR"):
        monkeypatch.delenv(name, raising=False)

    assert gnmi_runtime.storage_dir() == tmp_path / "storage"
    assert gnmi_runtime.log_dir() == tmp_path / "logs"
    assert gnmi_runtime.cert_dir() == tmp_path / "certs"


def test_ensure_private_dir_creates_and_chmods(monkeypatch, tmp_path):
    target = tmp_path / "nested/runtime"
    calls = []
    monkeypatch.setattr(gnmi_runtime.os, "chmod", lambda path, mode: calls.append((path, mode)))

    assert gnmi_runtime.ensure_private_dir(target) == target
    assert target.is_dir()
    assert calls == [(target, 0o750)]


def test_permission_errors_are_best_effort(monkeypatch, tmp_path):
    target = tmp_path / "runtime"
    monkeypatch.setattr(
        gnmi_runtime.os,
        "chmod",
        lambda *args: (_ for _ in ()).throw(PermissionError("not owner")),
    )

    assert gnmi_runtime.ensure_private_dir(target) == target
    gnmi_runtime.chmod_private_file(target / "file", 0o600)


def test_chmod_private_file_uses_requested_mode(monkeypatch, tmp_path):
    calls = []
    monkeypatch.setattr(gnmi_runtime.os, "chmod", lambda path, mode: calls.append((path, mode)))
    target = tmp_path / "secret"

    gnmi_runtime.chmod_private_file(target, 0o600)

    assert calls == [(target, 0o600)]

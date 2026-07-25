#!/usr/bin/env python3
"""
Runtime path helpers for the gNMI plugin.

The plugin stores daemon runtime data under a protected runtime directory:

    <plugin_root>/runtime/
      storage/  JSON telemetry, daemon config JSON, PID/lock files
      logs/     daemon stdout/stderr logs
      certs/    operator-installed TLS material

GNMI_RUNTIME_DIR can override the runtime root.  GNMI_STORAGE_DIR and
GNMI_LOG_DIR remain explicit per-directory overrides for direct script use and
tests.
"""

import os
from pathlib import Path


PLUGIN_ROOT = Path(__file__).resolve().parents[1]


def runtime_dir() -> Path:
    """Return the configured runtime root."""
    override = os.environ.get("GNMI_RUNTIME_DIR")
    if override:
        return Path(override).expanduser()
    return PLUGIN_ROOT / "runtime"


def storage_dir() -> Path:
    """Return the configured daemon storage directory."""
    override = os.environ.get("GNMI_STORAGE_DIR")
    if override:
        return Path(override).expanduser()
    return runtime_dir() / "storage"


def log_dir() -> Path:
    """Return the configured daemon log directory."""
    override = os.environ.get("GNMI_LOG_DIR")
    if override:
        return Path(override).expanduser()
    return runtime_dir() / "logs"


def cert_dir() -> Path:
    """Return the configured TLS certificate/key directory."""
    override = os.environ.get("GNMI_CERT_DIR")
    if override:
        return Path(override).expanduser()
    return runtime_dir() / "certs"


def ensure_private_dir(path: Path) -> Path:
    """Create a runtime directory with non-world-readable permissions."""
    path.mkdir(parents=True, exist_ok=True, mode=0o750)
    try:
        os.chmod(path, 0o750)
    except PermissionError:
        # The directory may be owned by a deployment user but still writable by
        # the runtime process via group permissions.  Let the caller's write
        # operation be the final authority.
        pass
    return path


def chmod_private_file(path: Path, mode: int = 0o640) -> None:
    """Best-effort chmod for runtime files that must not be world-readable."""
    try:
        os.chmod(path, mode)
    except PermissionError:
        pass

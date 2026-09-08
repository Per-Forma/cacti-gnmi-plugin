"""Unit tests for daemon lifecycle control without starting real processes."""

import json
import os
import signal
from unittest.mock import Mock

import pytest

from scripts import gnmi_daemon_ctl as ctl


@pytest.fixture
def controller(tmp_path):
    storage = tmp_path / "storage"
    logs = tmp_path / "logs"
    storage.mkdir()
    logs.mkdir()
    return ctl.DaemonController(str(storage), str(logs))


def test_constructor_uses_runtime_defaults(monkeypatch, tmp_path):
    storage = tmp_path / "storage"
    logs = tmp_path / "logs"
    monkeypatch.setattr(ctl, "default_storage_dir", lambda: storage)
    monkeypatch.setattr(ctl, "default_log_dir", lambda: logs)

    instance = ctl.DaemonController()

    assert instance.storage_dir == storage
    assert instance.log_dir == logs


def test_constructor_rejects_missing_daemon_script(monkeypatch, tmp_path):
    monkeypatch.setattr(ctl.Path, "exists", lambda self: False)
    with pytest.raises(FileNotFoundError, match="Daemon script not found"):
        ctl.DaemonController(str(tmp_path), str(tmp_path))


def test_paths_and_runtime_ready(controller, monkeypatch):
    chmod = Mock()
    monkeypatch.setattr(ctl, "chmod_private_file", chmod)

    assert controller.get_pid_file(7).name == "device_7.pid"
    assert controller.get_pid_lock_file(7).name == ".device_7.pid.lock"
    assert controller.get_log_file(7).name == "device_7.log"
    assert controller.runtime_ready() is True
    assert chmod.call_count == 2


def test_runtime_ready_rejects_missing_or_unwritable_directories(controller, monkeypatch, capsys):
    controller.storage_dir = controller.storage_dir / "missing"
    assert controller.runtime_ready() is False
    assert "runtime directory missing" in capsys.readouterr().err

    controller.storage_dir = controller.log_dir
    monkeypatch.setattr(ctl.os, "access", lambda path, mode: path != controller.storage_dir)
    assert controller.runtime_ready() is False
    assert "not writable" in capsys.readouterr().err


def test_read_pid_handles_missing_valid_and_invalid(controller):
    assert controller.read_pid(1) is None
    controller.get_pid_file(1).write_text("123\n")
    assert controller.read_pid(1) == 123
    controller.get_pid_file(1).write_text("bad")
    assert controller.read_pid(1) is None
    controller.get_pid_file(1).write_text("0")
    assert controller.read_pid(1) is None
    controller.get_pid_file(1).write_text("-123")
    assert controller.read_pid(1) is None
    controller.get_pid_file(1).unlink()
    controller.get_pid_file(1).mkdir()
    assert controller.read_pid(1) is None


def test_is_running_checks_process_and_removes_stale_pid(controller, monkeypatch):
    assert controller.is_running(1) is False
    pid_file = controller.get_pid_file(1)
    pid_file.write_text("123")
    monkeypatch.setattr(ctl.os, "kill", lambda pid, sig: None)
    monkeypatch.setattr(controller, "process_matches", lambda device_id, pid: True)
    assert controller.is_running(1) is True

    monkeypatch.setattr(ctl.os, "kill", Mock(side_effect=OSError("dead")))
    assert controller.is_running(1) is False
    assert not pid_file.exists()


def test_stale_cleanup_does_not_unlink_while_daemon_lock_is_held(controller):
    pid_file = controller.get_pid_file(1)
    pid_file.write_text("123")
    lock = open(controller.get_pid_lock_file(1), "a+")
    ctl.fcntl.flock(lock, ctl.fcntl.LOCK_EX | ctl.fcntl.LOCK_NB)

    controller.remove_stale_pid(1, 123)

    assert pid_file.exists()
    ctl.fcntl.flock(lock, ctl.fcntl.LOCK_UN)
    lock.close()


def test_start_returns_success_when_already_running(controller, monkeypatch, capsys):
    monkeypatch.setattr(controller, "is_running", lambda device_id: True)
    assert controller.start(1) is True
    assert "already running" in capsys.readouterr().out


def test_start_validates_runtime_and_configuration(controller, monkeypatch, capsys):
    monkeypatch.setattr(controller, "is_running", lambda device_id: False)
    monkeypatch.setattr(controller, "runtime_ready", lambda: False)
    assert controller.start(1, config={"x": 1}) is False

    monkeypatch.setattr(controller, "runtime_ready", lambda: True)
    assert controller.start(1) is False
    assert "No configuration" in capsys.readouterr().err


def test_start_writes_private_config_and_launches_daemon(controller, monkeypatch, capsys):
    running = iter([False, True])
    monkeypatch.setattr(controller, "is_running", lambda device_id: next(running))
    monkeypatch.setattr(controller, "read_pid", lambda device_id: 456)
    monkeypatch.setattr(controller, "runtime_ready", lambda: True)
    monkeypatch.setattr(ctl.time, "sleep", lambda seconds: None)
    chmod = Mock()
    popen = Mock(return_value=Mock())
    monkeypatch.setattr(ctl, "chmod_private_file", chmod)
    monkeypatch.setattr(ctl.subprocess, "Popen", popen)

    assert controller.start(8, config={"hostname": "router"}) is True

    config_file = controller.storage_dir / "device_8_config.json"
    assert json.loads(config_file.read_text()) == {"hostname": "router"}
    command = popen.call_args.args[0]
    assert command[command.index("--device-id") + 1] == "8"
    assert command[command.index("--config-file") + 1] == str(config_file)
    assert popen.call_args.kwargs["start_new_session"] is True
    assert "GRPC_SSL_CIPHER_SUITES" not in popen.call_args.kwargs["env"]
    assert "PID: 456" in capsys.readouterr().out


def test_start_scopes_legacy_cipher_policy_to_child_environment(controller, monkeypatch):
    running = iter([False, True])
    monkeypatch.setattr(controller, "is_running", lambda device_id: next(running))
    monkeypatch.setattr(controller, "read_pid", lambda device_id: 456)
    monkeypatch.setattr(controller, "runtime_ready", lambda: True)
    monkeypatch.setattr(ctl.time, "sleep", lambda seconds: None)
    popen = Mock(return_value=Mock())
    monkeypatch.setattr(ctl.subprocess, "Popen", popen)
    monkeypatch.setenv("GRPC_SSL_CIPHER_SUITES", "parent-value")

    assert controller.start(8, config={
        "hostname": "router",
        "use_tls": True,
        "tls_cipher_policy": "legacy_compatibility",
    }) is True

    assert popen.call_args.kwargs["env"]["GRPC_SSL_CIPHER_SUITES"] == (
        "ECDHE-RSA-AES256-GCM-SHA384:ECDHE-RSA-AES128-SHA"
    )
    assert os.environ["GRPC_SSL_CIPHER_SUITES"] == "parent-value"


def test_start_rejects_unknown_tls_cipher_policy(controller, monkeypatch, capsys):
    monkeypatch.setattr(controller, "is_running", lambda device_id: False)
    monkeypatch.setattr(controller, "runtime_ready", lambda: True)

    assert controller.start(8, config={
        "hostname": "router",
        "tls_cipher_policy": "arbitrary",
    }) is False
    assert "Invalid tls_cipher_policy" in capsys.readouterr().err


def test_start_reports_launch_and_verification_failures(controller, monkeypatch, capsys):
    monkeypatch.setattr(controller, "runtime_ready", lambda: True)
    monkeypatch.setattr(ctl.time, "sleep", lambda seconds: None)
    monkeypatch.setattr(controller, "is_running", Mock(side_effect=[False, False]))
    monkeypatch.setattr(ctl.subprocess, "Popen", lambda *args, **kwargs: Mock())
    config_file = controller.storage_dir / "config.json"
    config_file.write_text('{}')
    assert controller.start(1, config_file=str(config_file)) is False
    assert "failed to start" in capsys.readouterr().err

    monkeypatch.setattr(controller, "is_running", lambda device_id: False)
    monkeypatch.setattr(ctl.os, "open", Mock(side_effect=OSError("no log")))
    assert controller.start(1, config_file=str(config_file)) is False
    assert "no log" in capsys.readouterr().err


def test_start_rejects_unreadable_config_file(controller, monkeypatch, capsys):
    monkeypatch.setattr(controller, "is_running", lambda device_id: False)
    monkeypatch.setattr(controller, "runtime_ready", lambda: True)

    assert controller.start(1, config_file="missing.json") is False
    assert "Error reading daemon configuration" in capsys.readouterr().err


def test_stop_handles_not_running_and_missing_pid(controller, monkeypatch):
    monkeypatch.setattr(controller, "is_running", lambda device_id: False)
    assert controller.stop(1) is True

    monkeypatch.setattr(controller, "is_running", lambda device_id: True)
    monkeypatch.setattr(controller, "read_pid", lambda device_id: None)
    assert controller.stop(1) is True


def test_stop_graceful_and_forced_paths(controller, monkeypatch):
    monkeypatch.setattr(controller, "read_pid", lambda device_id: 123)
    monkeypatch.setattr(ctl.time, "sleep", lambda seconds: None)
    kills = []
    monkeypatch.setattr(ctl.os, "kill", lambda pid, sig: kills.append((pid, sig)))
    monkeypatch.setattr(controller, "process_matches", lambda device_id, pid: True)

    monkeypatch.setattr(controller, "is_running", Mock(side_effect=[True, False]))
    assert controller.stop(1) is True
    assert kills == [(123, signal.SIGTERM)]

    kills.clear()
    monkeypatch.setattr(controller, "is_running", Mock(side_effect=[True, False]))
    assert controller.stop(1, timeout=0) is True
    assert kills == [(123, signal.SIGTERM), (123, signal.SIGKILL)]

    kills.clear()
    monkeypatch.setattr(controller, "is_running", Mock(side_effect=[True, True]))
    assert controller.stop(1, timeout=0) is False


def test_stop_reports_signal_error(controller, monkeypatch, capsys):
    monkeypatch.setattr(controller, "is_running", lambda device_id: True)
    monkeypatch.setattr(controller, "read_pid", lambda device_id: 123)
    monkeypatch.setattr(controller, "process_matches", lambda device_id, pid: True)
    monkeypatch.setattr(ctl.os, "kill", Mock(side_effect=OSError("denied")))
    assert controller.stop(1) is False
    assert "denied" in capsys.readouterr().err


def test_process_identity_blocks_reused_or_dangerous_pids(controller, monkeypatch, capsys):
    assert controller.process_matches(1, 0) is False
    pid_file = controller.get_pid_file(1)
    pid_file.write_text("123")
    monkeypatch.setattr(ctl.os, "kill", Mock())
    monkeypatch.setattr(controller, "is_running", lambda device_id: True)
    monkeypatch.setattr(controller, "process_matches", lambda device_id, pid: False)

    assert controller.stop(1) is False
    ctl.os.kill.assert_not_called()
    assert "Refusing to signal" in capsys.readouterr().err


def test_restart_status_and_health(controller, monkeypatch):
    monkeypatch.setattr(controller, "is_running", lambda device_id: True)
    monkeypatch.setattr(controller, "stop", Mock(return_value=False))
    assert controller.restart(1) is False

    monkeypatch.setattr(controller, "is_running", lambda device_id: False)
    monkeypatch.setattr(controller, "start", Mock(return_value=True))
    assert controller.restart(1, {"x": 1}) is True

    monkeypatch.setattr(controller, "read_pid", lambda device_id: 42)
    check_health = Mock(return_value={"running": True})
    monkeypatch.setattr(ctl.GNMIDaemon, "check_health", check_health)
    status = controller.status(1, verbose=True)
    assert status["running"] is False
    assert "health" not in status

    monkeypatch.setattr(controller, "is_running", lambda device_id: True)
    assert controller.status(1, verbose=True)["health"] == {"running": True}
    assert controller.health(1, 20) == {"running": True}
    check_health.assert_called_with(1, str(controller.storage_dir), staleness_threshold=20)

    monkeypatch.setattr(controller, "is_running", lambda device_id: False)
    assert controller.health(1, 20) == {
        "running": False,
        "status": "pid_exists_but_process_mismatch",
    }


class FakeController:
    result = True
    status_result = {"running": True}
    health_result = {"running": True}
    calls = []

    def __init__(self, **kwargs):
        self.__class__.calls.append(("init", kwargs))

    def start(self, *args):
        self.__class__.calls.append(("start", args))
        return self.result

    def stop(self, *args, **kwargs):
        self.__class__.calls.append(("stop", args, kwargs))
        return self.result

    def restart(self, *args):
        self.__class__.calls.append(("restart", args))
        return self.result

    def status(self, *args, **kwargs):
        return self.status_result

    def health(self, *args):
        return self.health_result


def invoke_main(monkeypatch, args):
    FakeController.calls.clear()
    monkeypatch.setattr(ctl, "DaemonController", FakeController)
    monkeypatch.setattr(ctl.sys, "argv", ["ctl"] + args)
    with pytest.raises(SystemExit) as exc:
        ctl.main()
    return exc.value.code


@pytest.mark.parametrize("command", ["start", "restart"])
def test_main_start_and_restart_parse_json(monkeypatch, tmp_path, command):
    code = invoke_main(monkeypatch, [
        command, "--device-id", "7", "--storage-dir", str(tmp_path),
        "--log-dir", str(tmp_path), "--config", '{"hostname":"router"}',
    ])
    assert code == 0
    assert FakeController.calls[-1][0] == command
    assert FakeController.calls[-1][1][1] == {"hostname": "router"}


@pytest.mark.parametrize("command", ["start", "restart"])
def test_main_rejects_invalid_json(monkeypatch, tmp_path, command):
    assert invoke_main(monkeypatch, [
        command, "--device-id", "7", "--storage-dir", str(tmp_path),
        "--log-dir", str(tmp_path), "--config", "{bad",
    ]) == 1


def test_main_stop_status_and_health_exit_contract(monkeypatch, tmp_path, capsys):
    common = ["--device-id", "7", "--storage-dir", str(tmp_path), "--log-dir", str(tmp_path)]
    assert invoke_main(monkeypatch, ["stop"] + common + ["--timeout", "2"]) == 0

    FakeController.status_result = {"running": False}
    assert invoke_main(monkeypatch, ["status"] + common + ["--verbose"]) == 2
    assert json.loads(capsys.readouterr().out)["running"] is False

    FakeController.health_result = {"running": False}
    assert invoke_main(monkeypatch, ["health"] + common + ["--staleness-threshold", "20"]) == 2


def test_main_handles_controller_initialization_and_command_errors(monkeypatch, tmp_path):
    class BrokenInit:
        def __init__(self, **kwargs):
            raise RuntimeError("init failed")

    monkeypatch.setattr(ctl, "DaemonController", BrokenInit)
    monkeypatch.setattr(ctl.sys, "argv", [
        "ctl", "health", "--device-id", "1", "--storage-dir", str(tmp_path),
        "--log-dir", str(tmp_path),
    ])
    with pytest.raises(SystemExit) as exc:
        ctl.main()
    assert exc.value.code == 1

    class BrokenCommand(FakeController):
        def health(self, *args):
            raise RuntimeError("health failed")

    monkeypatch.setattr(ctl, "DaemonController", BrokenCommand)
    with pytest.raises(SystemExit) as exc:
        ctl.main()
    assert exc.value.code == 1

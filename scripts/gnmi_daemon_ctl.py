#!/usr/bin/env python3
"""
gNMI Daemon Control Script - Manages daemon lifecycle for Cacti poller hook.

This script provides start/stop/restart/status commands for managing
individual device daemons. Called by Cacti poller hook to ensure daemons
are running and healthy.

Usage:
    gnmi_daemon_ctl.py start --device-id <id> [--config <json>]
    gnmi_daemon_ctl.py stop --device-id <id>
    gnmi_daemon_ctl.py restart --device-id <id> [--config <json>]
    gnmi_daemon_ctl.py status --device-id <id>
    gnmi_daemon_ctl.py health --device-id <id>

Exit codes:
    0: Success
    1: Error
    2: Daemon not running (status/health commands)
"""

import argparse
import fcntl
import json
import os
import signal
import subprocess
import sys
import time
from pathlib import Path
from typing import Dict, Any, Optional

# Add current directory to path for daemon imports
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

from gnmi_runtime import (
    chmod_private_file,
    log_dir as default_log_dir,
    storage_dir as default_storage_dir,
)
from gnmi_tls import apply_tls_cipher_policy
from gnmi_daemon import GNMIDaemon


class DaemonController:
    """
    Controller for managing gNMI daemon processes.

    Provides start/stop/restart/status operations for daemons.
    """

    def __init__(self, storage_dir: str = None, log_dir: str = None):
        if storage_dir is None:
            storage_dir = str(default_storage_dir())
        if log_dir is None:
            log_dir = str(default_log_dir())
        self.storage_dir = Path(storage_dir)
        self.log_dir = Path(log_dir)

        # Path to daemon script
        self.daemon_script = Path(__file__).parent / "gnmi_daemon.py"
        if not self.daemon_script.exists():
            raise FileNotFoundError(f"Daemon script not found: {self.daemon_script}")

    def get_pid_file(self, device_id: int) -> Path:
        """Get PID file path for device."""
        return self.storage_dir / f"device_{device_id}.pid"

    def get_log_file(self, device_id: int) -> Path:
        """Get log file path for device."""
        return self.log_dir / f"device_{device_id}.log"

    def get_pid_lock_file(self, device_id: int) -> Path:
        """Get the per-device lifetime lock path."""
        return self.storage_dir / f".device_{device_id}.pid.lock"

    def remove_stale_pid(self, device_id: int, expected_pid: int) -> None:
        """Conditionally unlink a stale PID while no daemon owns the lock."""
        try:
            with open(self.get_pid_lock_file(device_id), 'a+') as lock:
                fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
                if self.read_pid(device_id) == expected_pid:
                    self.get_pid_file(device_id).unlink()
        except (BlockingIOError, OSError):
            pass

    def runtime_ready(self) -> bool:
        """Return True when install-created runtime directories are usable."""
        for directory in (self.storage_dir, self.log_dir):
            if not directory.is_dir():
                print(
                    f"Error: runtime directory missing: {directory}. "
                    "Install/enable the gNMI plugin to initialize runtime directories.",
                    file=sys.stderr,
                )
                return False
            if not os.access(directory, os.W_OK):
                print(f"Error: runtime directory not writable: {directory}", file=sys.stderr)
                return False
            chmod_private_file(directory, 0o750)
        return True

    def read_pid(self, device_id: int) -> Optional[int]:
        """
        Read PID from file if exists.

        Returns:
            int: PID if file exists and valid, None otherwise
        """
        pid_file = self.get_pid_file(device_id)
        if not pid_file.exists():
            return None

        try:
            with open(pid_file, 'r') as f:
                pid = int(f.read().strip())
            return pid if pid > 1 else None
        except (ValueError, IOError):
            return None

    def process_matches(self, device_id: int, pid: int) -> bool:
        """Verify a PID belongs to this controller's exact device daemon."""
        if pid <= 1:
            return False
        try:
            cmdline = (Path('/proc') / str(pid) / 'cmdline').read_bytes().split(b'\0')
            args = [part.decode(errors='replace') for part in cmdline if part]
        except (OSError, IOError):
            return False

        expected_script = os.path.realpath(str(self.daemon_script))
        script_matches = any(os.path.realpath(arg) == expected_script for arg in args)
        try:
            device_index = args.index('--device-id')
            device_matches = args[device_index + 1] == str(device_id)
        except (ValueError, IndexError):
            device_matches = False
        return script_matches and device_matches

    def is_running(self, device_id: int) -> bool:
        """
        Check if daemon is actually running.

        Checks both PID file existence and process existence.
        """
        pid = self.read_pid(device_id)
        if pid is None:
            return False

        # Check if process exists
        try:
            os.kill(pid, 0)  # Signal 0 just checks existence
            if self.process_matches(device_id, pid):
                return True
            self.remove_stale_pid(device_id, pid)
            return False
        except OSError:
            # Process doesn't exist, clean up stale PID file
            self.remove_stale_pid(device_id, pid)
            return False

    def start(self, device_id: int, config: Optional[Dict[str, Any]] = None,
              config_file: Optional[str] = None) -> bool:
        """
        Start daemon for device.

        Args:
            device_id: Device ID
            config: Configuration dict (will be written to temp file)
            config_file: Path to existing config file

        Returns:
            bool: True if started successfully
        """
        # Check if already running
        if self.is_running(device_id):
            print(f"Daemon already running for device {device_id}")
            return True

        if not self.runtime_ready():
            return False

        # Prepare config file
        if config and not config_file:
            # Write config to temporary file
            config_file = self.storage_dir / f"device_{device_id}_config.json"
            fd = os.open(config_file, os.O_WRONLY | os.O_CREAT | os.O_TRUNC, 0o640)
            with os.fdopen(fd, 'w') as f:
                json.dump(config, f, indent=2)
            chmod_private_file(config_file)

        if not config_file:
            print(f"Error: No configuration provided for device {device_id}", file=sys.stderr)
            return False

        launch_config = config
        if launch_config is None:
            try:
                with open(config_file, 'r') as f:
                    launch_config = json.load(f)
            except (OSError, json.JSONDecodeError) as e:
                print(f"Error reading daemon configuration: {e}", file=sys.stderr)
                return False

        child_env = os.environ.copy()
        try:
            tls_policy = apply_tls_cipher_policy(
                child_env,
                launch_config.get('tls_cipher_policy', 'default'),
                use_tls=bool(launch_config.get(
                    'use_tls',
                    not launch_config.get('insecure', False),
                )),
            )
        except ValueError as e:
            print(f"Error: {e}", file=sys.stderr)
            return False

        # Build daemon command
        cmd = [
            sys.executable,  # Use same Python interpreter
            str(self.daemon_script),
            '--device-id', str(device_id),
            '--config-file', str(config_file),
            '--storage-dir', str(self.storage_dir)
        ]

        # Log file for daemon output
        log_file = self.get_log_file(device_id)

        try:
            # Start daemon as background process
            fd = os.open(log_file, os.O_WRONLY | os.O_CREAT | os.O_APPEND, 0o640)
            with os.fdopen(fd, 'a') as log:
                chmod_private_file(log_file)
                process = subprocess.Popen(
                    cmd,
                    stdout=log,
                    stderr=subprocess.STDOUT,
                    env=child_env,
                    start_new_session=True  # Detach from parent
                )

            # Give it a moment to start
            time.sleep(0.5)

            # Verify it's running
            if self.is_running(device_id):
                pid = self.read_pid(device_id)
                print(
                    f"Daemon started for device {device_id} "
                    f"(PID: {pid}, TLS cipher policy: {tls_policy})"
                )
                return True
            else:
                print(f"Error: Daemon failed to start for device {device_id}", file=sys.stderr)
                print(f"Check log file: {log_file}", file=sys.stderr)
                return False

        except Exception as e:
            print(f"Error starting daemon: {e}", file=sys.stderr)
            return False

    def stop(self, device_id: int, timeout: int = 10) -> bool:
        """
        Stop daemon for device.

        Sends SIGTERM and waits for graceful shutdown.
        If timeout exceeded, sends SIGKILL.

        Args:
            device_id: Device ID
            timeout: Seconds to wait for graceful shutdown

        Returns:
            bool: True if stopped successfully
        """
        if not self.is_running(device_id):
            print(f"Daemon not running for device {device_id}")
            return True

        pid = self.read_pid(device_id)
        if pid is None:
            return True
        if not self.process_matches(device_id, pid):
            print(
                f"Refusing to signal PID {pid}: process does not match device {device_id} daemon",
                file=sys.stderr,
            )
            return False

        try:
            # Send SIGTERM for graceful shutdown
            print(f"Stopping daemon for device {device_id} (PID: {pid})")
            os.kill(pid, signal.SIGTERM)

            # Wait for graceful shutdown
            start_time = time.time()
            while time.time() - start_time < timeout:
                if not self.is_running(device_id):
                    print(f"Daemon stopped gracefully")
                    return True
                time.sleep(0.5)

            # Timeout exceeded, force kill
            print(f"Timeout exceeded, sending SIGKILL", file=sys.stderr)
            if not self.process_matches(device_id, pid):
                print("Original daemon PID no longer matches; treating it as stopped")
                return True
            os.kill(pid, signal.SIGKILL)
            time.sleep(0.5)

            if not self.is_running(device_id):
                print(f"Daemon force-stopped")
                return True
            else:
                print(f"Error: Failed to stop daemon", file=sys.stderr)
                return False

        except OSError as e:
            print(f"Error stopping daemon: {e}", file=sys.stderr)
            return False

    def restart(self, device_id: int, config: Optional[Dict[str, Any]] = None,
                config_file: Optional[str] = None) -> bool:
        """
        Restart daemon for device.

        Args:
            device_id: Device ID
            config: Configuration dict
            config_file: Path to config file

        Returns:
            bool: True if restarted successfully
        """
        print(f"Restarting daemon for device {device_id}")

        # Stop if running
        if self.is_running(device_id):
            if not self.stop(device_id):
                return False

        # Start with new config
        return self.start(device_id, config, config_file)

    def status(self, device_id: int, verbose: bool = False) -> Dict[str, Any]:
        """
        Get daemon status.

        Args:
            device_id: Device ID
            verbose: Include health check data

        Returns:
            dict: Status information
        """
        status = {
            "device_id": device_id,
            "running": self.is_running(device_id),
            "pid": self.read_pid(device_id),
            "pid_file": str(self.get_pid_file(device_id)),
            "log_file": str(self.get_log_file(device_id))
        }

        if verbose and status["running"]:
            # Get health check data
            health = GNMIDaemon.check_health(device_id, str(self.storage_dir))
            status["health"] = health

        return status

    def health(self, device_id: int, staleness_threshold: int = 600) -> Dict[str, Any]:
        """
        Get detailed health check.

        Args:
            device_id: Device ID

        Returns:
            dict: Health check information
        """
        health = GNMIDaemon.check_health(
            device_id,
            str(self.storage_dir),
            staleness_threshold=staleness_threshold,
        )
        owned_running = self.is_running(device_id)
        if health.get("running") and not owned_running:
            health["running"] = False
            health["status"] = "pid_exists_but_process_mismatch"
        return health


def main():
    """Main entry point."""
    parser = argparse.ArgumentParser(
        description='gNMI Daemon Control - Manage daemon lifecycle',
        formatter_class=argparse.RawDescriptionHelpFormatter
    )

    subparsers = parser.add_subparsers(dest='command', help='Command to execute')
    subparsers.required = True

    # Common arguments
    common = argparse.ArgumentParser(add_help=False)
    common.add_argument(
        '--device-id',
        type=int,
        required=True,
        help='Device ID'
    )
    common.add_argument(
        '--storage-dir',
        type=str,
        default=str(default_storage_dir()),
        help=f'Storage directory (default: {default_storage_dir()})'
    )
    common.add_argument(
        '--log-dir',
        type=str,
        default=str(default_log_dir()),
        help=f'Log directory (default: {default_log_dir()})'
    )

    # Start command
    start_parser = subparsers.add_parser(
        'start',
        parents=[common],
        help='Start daemon'
    )
    start_parser.add_argument(
        '--config',
        type=str,
        help='Configuration JSON string'
    )
    start_parser.add_argument(
        '--config-file',
        type=str,
        help='Configuration JSON file path'
    )

    # Stop command
    stop_parser = subparsers.add_parser(
        'stop',
        parents=[common],
        help='Stop daemon'
    )
    stop_parser.add_argument(
        '--timeout',
        type=int,
        default=10,
        help='Timeout for graceful shutdown (default: 10s)'
    )

    # Restart command
    restart_parser = subparsers.add_parser(
        'restart',
        parents=[common],
        help='Restart daemon'
    )
    restart_parser.add_argument(
        '--config',
        type=str,
        help='Configuration JSON string'
    )
    restart_parser.add_argument(
        '--config-file',
        type=str,
        help='Configuration JSON file path'
    )

    # Status command
    status_parser = subparsers.add_parser(
        'status',
        parents=[common],
        help='Get daemon status'
    )
    status_parser.add_argument(
        '--verbose',
        action='store_true',
        help='Include health check data'
    )

    # Health command
    health_parser = subparsers.add_parser(
        'health',
        parents=[common],
        help='Get daemon health check'
    )
    health_parser.add_argument(
        '--staleness-threshold',
        type=int,
        default=600,
        help='Seconds after the last storage update before data is stale (default: 600)'
    )

    args = parser.parse_args()

    # Create controller
    try:
        controller = DaemonController(storage_dir=args.storage_dir, log_dir=args.log_dir)
    except Exception as e:
        print(f"Error initializing controller: {e}", file=sys.stderr)
        sys.exit(1)

    # Execute command
    try:
        if args.command == 'start':
            config = None
            config_file = args.config_file

            if args.config:
                try:
                    config = json.loads(args.config)
                except json.JSONDecodeError as e:
                    print(f"Error parsing config JSON: {e}", file=sys.stderr)
                    sys.exit(1)

            success = controller.start(args.device_id, config, config_file)
            sys.exit(0 if success else 1)

        elif args.command == 'stop':
            success = controller.stop(args.device_id, timeout=args.timeout)
            sys.exit(0 if success else 1)

        elif args.command == 'restart':
            config = None
            config_file = args.config_file

            if args.config:
                try:
                    config = json.loads(args.config)
                except json.JSONDecodeError as e:
                    print(f"Error parsing config JSON: {e}", file=sys.stderr)
                    sys.exit(1)

            success = controller.restart(args.device_id, config, config_file)
            sys.exit(0 if success else 1)

        elif args.command == 'status':
            status = controller.status(args.device_id, verbose=args.verbose)
            print(json.dumps(status, indent=2))
            sys.exit(0 if status['running'] else 2)

        elif args.command == 'health':
            health = controller.health(args.device_id, args.staleness_threshold)
            print(json.dumps(health, indent=2))
            sys.exit(0 if health['running'] else 2)

    except KeyboardInterrupt:
        print("\nInterrupted", file=sys.stderr)
        sys.exit(1)
    except Exception as e:
        print(f"Error: {e}", file=sys.stderr)
        sys.exit(1)


if __name__ == '__main__':
    main()

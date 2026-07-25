#!/usr/bin/env python3
"""
gNMI Daemon Monitor - Continuous health monitoring with gap detection.

This script polls daemon health at regular intervals and logs:
- Connection state changes
- Stale data detection
- Reconnection events
- Uptime statistics

Usage:
    gnmi_daemon_monitor.py --device-id <id> [--interval <seconds>] [--output <file>]

Output: CSV log file with timestamps for analysis
"""

import argparse
import csv
import json
import logging
import signal
import sys
import time
from datetime import datetime, timezone
from pathlib import Path
from typing import Dict, Any, Optional

# Add parent directory to path for imports
import os
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

from gnmi_runtime import storage_dir as default_storage_dir
from gnmi_daemon import GNMIDaemon

# Configure logging
logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s - %(levelname)s - %(message)s'
)
logger = logging.getLogger('daemon_monitor')


class DaemonMonitor:
    """
    Monitors daemon health and tracks reliability metrics.
    """

    def __init__(self, device_id: int, storage_dir: str = None,
                 interval: int = 10, output_file: Optional[str] = None):
        if storage_dir is None:
            storage_dir = str(default_storage_dir())
        self.device_id = device_id
        self.storage_dir = storage_dir
        self.interval = interval
        self.output_file = output_file or f"daemon_monitor_{device_id}.csv"

        # State tracking
        self.last_status = None
        self.last_update_time = None
        self.status_change_count = 0
        self.stale_count = 0
        self.check_count = 0
        self.start_time = time.time()

        # Running flag
        self.running = True

        # CSV writer
        self.csv_file = None
        self.csv_writer = None

        logger.info(f"Monitor initialized for device {device_id}")
        logger.info(f"Check interval: {interval}s")
        logger.info(f"Output file: {self.output_file}")

    def setup_csv(self):
        """Initialize CSV output file."""
        try:
            self.csv_file = open(self.output_file, 'a', newline='')
            self.csv_writer = csv.writer(self.csv_file)

            # Write header if file is new
            if os.path.getsize(self.output_file) == 0:
                self.csv_writer.writerow([
                    'timestamp',
                    'check_num',
                    'daemon_running',
                    'daemon_status',
                    'last_update',
                    'age_seconds',
                    'stale',
                    'connection_uptime',
                    'status_change',
                    'notes'
                ])
                self.csv_file.flush()

            logger.info("CSV output initialized")
        except Exception as e:
            logger.error(f"Failed to setup CSV: {e}")
            sys.exit(1)

    def check_health(self) -> Dict[str, Any]:
        """Get daemon health check."""
        try:
            return GNMIDaemon.check_health(self.device_id, self.storage_dir)
        except Exception as e:
            logger.error(f"Health check failed: {e}")
            return {
                "running": False,
                "status": "error",
                "error": str(e)
            }

    def detect_status_change(self, current_status: str) -> bool:
        """Detect if daemon status changed."""
        if self.last_status is None:
            self.last_status = current_status
            return False

        if current_status != self.last_status:
            logger.warning(f"Status change: {self.last_status} → {current_status}")
            self.last_status = current_status
            self.status_change_count += 1
            return True

        return False

    def detect_data_gap(self, last_update_str: Optional[str]) -> Optional[float]:
        """
        Detect data gap by comparing last_update timestamps.

        Returns:
            Gap duration in seconds, or None if no gap
        """
        if not last_update_str:
            return None

        try:
            current_update = datetime.fromisoformat(last_update_str.replace('Z', '+00:00'))

            if self.last_update_time:
                gap = (current_update - self.last_update_time).total_seconds()

                # Gap detected if > 20 seconds (should update every ~10-15s)
                if gap > 20:
                    logger.warning(f"Data gap detected: {gap:.1f}s since last update")
                    return gap

            self.last_update_time = current_update
            return None

        except (ValueError, AttributeError) as e:
            logger.error(f"Invalid timestamp: {e}")
            return None

    def log_health(self, health: Dict[str, Any]):
        """Log health check to CSV."""
        self.check_count += 1

        # Extract fields
        running = health.get('running', False)
        status = health.get('status', 'unknown')
        last_update = health.get('last_update')
        age = health.get('age_seconds', -1)
        stale = health.get('stale', True)
        connection_uptime = health.get('connection_uptime', 0)

        # Detect status change
        status_change = self.detect_status_change(status)

        # Detect data gap
        gap = self.detect_data_gap(last_update)

        # Track stale data
        if stale:
            self.stale_count += 1

        # Build notes
        notes = []
        if status_change:
            notes.append(f"status_changed_to_{status}")
        if gap:
            notes.append(f"data_gap_{gap:.1f}s")
        if stale:
            notes.append("data_stale")

        notes_str = "|".join(notes) if notes else ""

        # Write to CSV
        try:
            self.csv_writer.writerow([
                datetime.now(timezone.utc).isoformat(),
                self.check_count,
                running,
                status,
                last_update or "",
                f"{age:.2f}" if age >= 0 else "",
                stale,
                f"{connection_uptime:.1f}" if connection_uptime > 0 else "",
                status_change,
                notes_str
            ])
            self.csv_file.flush()
        except Exception as e:
            logger.error(f"Failed to write CSV: {e}")

        # Console output
        status_icon = "✅" if running and not stale else "⚠️" if running else "❌"
        logger.info(
            f"{status_icon} Check #{self.check_count}: {status} | "
            f"age={age:.1f}s | stale={stale} | uptime={connection_uptime:.0f}s"
        )

    def print_summary(self):
        """Print monitoring summary."""
        runtime = time.time() - self.start_time
        uptime_pct = ((self.check_count - self.stale_count) / self.check_count * 100) if self.check_count > 0 else 0

        print("\n" + "="*60)
        print("Daemon Monitor Summary")
        print("="*60)
        print(f"Device ID: {self.device_id}")
        print(f"Monitor Runtime: {runtime:.1f}s ({runtime/60:.1f} minutes)")
        print(f"Health Checks: {self.check_count}")
        print(f"Status Changes: {self.status_change_count}")
        print(f"Stale Data Count: {self.stale_count}")
        print(f"Data Freshness: {uptime_pct:.1f}%")
        print(f"Output File: {self.output_file}")
        print("="*60)

    def run(self):
        """Main monitoring loop."""
        logger.info("Starting daemon monitor...")

        self.setup_csv()

        try:
            while self.running:
                # Check daemon health
                health = self.check_health()

                # Log results
                self.log_health(health)

                # Wait for next check
                time.sleep(self.interval)

        except KeyboardInterrupt:
            logger.info("Monitor interrupted by user")
        finally:
            self.shutdown()

    def shutdown(self):
        """Cleanup and print summary."""
        logger.info("Shutting down monitor...")

        if self.csv_file:
            self.csv_file.close()

        self.print_summary()


def signal_handler(signum, frame):
    """Handle termination signals."""
    logger.info(f"Received signal {signum}")
    sys.exit(0)


def main():
    """Main entry point."""
    parser = argparse.ArgumentParser(
        description='gNMI Daemon Monitor - Continuous health monitoring',
        formatter_class=argparse.RawDescriptionHelpFormatter
    )

    parser.add_argument(
        '--device-id',
        type=int,
        required=True,
        help='Device ID to monitor'
    )

    parser.add_argument(
        '--storage-dir',
        type=str,
        default=str(default_storage_dir()),
        help=f'Storage directory (default: {default_storage_dir()})'
    )

    parser.add_argument(
        '--interval',
        type=int,
        default=10,
        help='Check interval in seconds (default: 10)'
    )

    parser.add_argument(
        '--output',
        type=str,
        help='Output CSV file (default: daemon_monitor_<device_id>.csv)'
    )

    parser.add_argument(
        '--duration',
        type=int,
        help='Run for specified duration in seconds (default: infinite)'
    )

    args = parser.parse_args()

    # Create monitor
    monitor = DaemonMonitor(
        device_id=args.device_id,
        storage_dir=args.storage_dir,
        interval=args.interval,
        output_file=args.output
    )

    # Register signal handlers
    signal.signal(signal.SIGTERM, signal_handler)
    signal.signal(signal.SIGINT, signal_handler)

    # Run monitor
    if args.duration:
        logger.info(f"Running for {args.duration} seconds")
        import threading
        timer = threading.Timer(args.duration, lambda: sys.exit(0))
        timer.start()

    monitor.run()


if __name__ == '__main__':
    main()

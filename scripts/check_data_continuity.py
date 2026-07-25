#!/usr/bin/env python3
"""
Data Continuity Checker - Validates no gaps in gNMI data collection.

This script monitors JSON storage for update gaps and validates data completeness.
Useful for identifying intermittent collection failures.

Usage:
    check_data_continuity.py --device-id <id> [--duration <seconds>] [--threshold <seconds>]

Output:
    Real-time gap detection and statistics summary
"""

import argparse
import json
import logging
import signal
import sys
import time
from collections import deque
from datetime import datetime, timezone
from pathlib import Path
from typing import Dict, List, Optional, Tuple

try:
    from gnmi_runtime import storage_dir as default_storage_dir
except ImportError:
    from .gnmi_runtime import storage_dir as default_storage_dir

# Configure logging
logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s - %(levelname)s - %(message)s'
)
logger = logging.getLogger('continuity_checker')


class ContinuityChecker:
    """
    Monitors data continuity by tracking JSON storage updates.
    """

    def __init__(self, device_id: int, storage_dir: str = None,
                 check_interval: float = 5.0, gap_threshold: float = 20.0):
        if storage_dir is None:
            storage_dir = str(default_storage_dir())
        self.device_id = device_id
        self.storage_dir = Path(storage_dir)
        self.storage_file = self.storage_dir / f"device_{device_id}.json"
        self.check_interval = check_interval
        self.gap_threshold = gap_threshold

        # Statistics
        self.check_count = 0
        self.gap_count = 0
        self.gaps: List[Tuple[float, datetime, datetime]] = []
        self.update_times: deque = deque(maxlen=100)  # Keep last 100 timestamps
        self.last_update_seen: Optional[datetime] = None
        self.last_metrics_hash: Optional[str] = None

        # Tracking
        self.start_time = time.time()
        self.running = True

        logger.info(f"Continuity checker initialized for device {device_id}")
        logger.info(f"Check interval: {check_interval}s")
        logger.info(f"Gap threshold: {gap_threshold}s")

    def read_storage(self) -> Optional[Dict]:
        """Read current storage file."""
        try:
            with open(self.storage_file, 'r') as f:
                return json.load(f)
        except FileNotFoundError:
            logger.error(f"Storage file not found: {self.storage_file}")
            return None
        except json.JSONDecodeError as e:
            logger.error(f"Invalid JSON: {e}")
            return None
        except Exception as e:
            logger.error(f"Error reading storage: {e}")
            return None

    def hash_metrics(self, data: Dict) -> str:
        """
        Create hash of metric values to detect changes.

        This helps identify if data is actually updating or just timestamps.
        """
        try:
            metrics = data.get('metric_groups', {}).get('update', {}).get('update', [])
            # Create simple hash from metric values
            values = [str(m.get('val', '')) for m in metrics]
            return "|".join(values)
        except:
            return ""

    def check_update(self) -> Optional[Dict]:
        """
        Check for new update and validate.

        Returns:
            Dict with update info or None if no change
        """
        self.check_count += 1

        # Read storage
        data = self.read_storage()
        if not data:
            return {
                'status': 'error',
                'message': 'Failed to read storage'
            }

        # Parse timestamp
        last_update_str = data.get('last_update')
        if not last_update_str:
            return {
                'status': 'error',
                'message': 'No last_update timestamp'
            }

        try:
            last_update = datetime.fromisoformat(last_update_str.replace('Z', '+00:00'))
        except (ValueError, AttributeError) as e:
            return {
                'status': 'error',
                'message': f'Invalid timestamp: {e}'
            }

        # Calculate age
        age = (datetime.now(timezone.utc) - last_update).total_seconds()

        # Check if this is a new update
        if self.last_update_seen and last_update <= self.last_update_seen:
            return {
                'status': 'no_change',
                'last_update': last_update,
                'age': age,
                'message': 'No new update since last check'
            }

        # Check for gap
        gap_detected = False
        gap_duration = 0

        if self.last_update_seen:
            gap_duration = (last_update - self.last_update_seen).total_seconds()

            if gap_duration > self.gap_threshold:
                gap_detected = True
                self.gap_count += 1
                self.gaps.append((gap_duration, self.last_update_seen, last_update))
                logger.warning(
                    f"⚠️  GAP DETECTED: {gap_duration:.1f}s between updates "
                    f"({self.last_update_seen.strftime('%H:%M:%S')} → {last_update.strftime('%H:%M:%S')})"
                )

        # Check if metrics actually changed
        metrics_hash = self.hash_metrics(data)
        metrics_changed = (metrics_hash != self.last_metrics_hash)
        self.last_metrics_hash = metrics_hash

        # Count metrics
        metric_count = len(data.get('metric_groups', {}).get('update', {}).get('update', []))

        # Update tracking
        self.last_update_seen = last_update
        self.update_times.append(last_update)

        return {
            'status': 'ok',
            'last_update': last_update,
            'age': age,
            'gap_detected': gap_detected,
            'gap_duration': gap_duration,
            'metrics_changed': metrics_changed,
            'metric_count': metric_count,
            'daemon_status': data.get('daemon_status'),
            'connection_uptime': data.get('connection_uptime', 0)
        }

    def calculate_statistics(self) -> Dict:
        """Calculate update frequency statistics."""
        if len(self.update_times) < 2:
            return {}

        # Calculate intervals between updates
        intervals = []
        for i in range(1, len(self.update_times)):
            delta = (self.update_times[i] - self.update_times[i-1]).total_seconds()
            intervals.append(delta)

        if not intervals:
            return {}

        return {
            'min_interval': min(intervals),
            'max_interval': max(intervals),
            'avg_interval': sum(intervals) / len(intervals),
            'update_count': len(self.update_times)
        }

    def print_status(self, result: Dict):
        """Print status update."""
        if result['status'] == 'ok':
            icon = "⚠️" if result.get('gap_detected') else "✅"
            metrics_status = "📊" if result.get('metrics_changed') else "⏸️"

            logger.info(
                f"{icon} Check #{self.check_count}: age={result['age']:.1f}s | "
                f"metrics={result['metric_count']} {metrics_status} | "
                f"status={result.get('daemon_status')} | "
                f"gaps={self.gap_count}"
            )
        elif result['status'] == 'no_change':
            logger.debug(f"Check #{self.check_count}: No update (age={result['age']:.1f}s)")
        else:
            logger.error(f"❌ Check #{self.check_count}: {result.get('message')}")

    def print_summary(self):
        """Print summary statistics."""
        runtime = time.time() - self.start_time
        stats = self.calculate_statistics()

        print("\n" + "="*70)
        print("Data Continuity Report")
        print("="*70)
        print(f"Device ID: {self.device_id}")
        print(f"Check Duration: {runtime:.1f}s ({runtime/60:.1f} minutes)")
        print(f"Total Checks: {self.check_count}")
        print(f"Updates Observed: {len(self.update_times)}")
        print(f"Gaps Detected: {self.gap_count} (threshold: {self.gap_threshold}s)")

        if stats:
            print(f"\nUpdate Interval Statistics:")
            print(f"  Min: {stats['min_interval']:.1f}s")
            print(f"  Max: {stats['max_interval']:.1f}s")
            print(f"  Avg: {stats['avg_interval']:.1f}s")

        if self.gaps:
            print(f"\nGap Details:")
            for i, (duration, start, end) in enumerate(self.gaps, 1):
                print(f"  Gap #{i}: {duration:.1f}s "
                      f"({start.strftime('%H:%M:%S')} → {end.strftime('%H:%M:%S')})")

        # Overall health assessment
        print(f"\n{'='*70}")
        if self.gap_count == 0:
            print("✅ EXCELLENT: No data gaps detected!")
        elif self.gap_count <= 2:
            print("⚠️  WARNING: Minor gaps detected (investigate)")
        else:
            print("❌ CRITICAL: Multiple gaps detected (needs attention)")
        print("="*70)

    def run(self, duration: Optional[int] = None):
        """
        Run continuity check.

        Args:
            duration: Optional duration in seconds (None = infinite)
        """
        logger.info("Starting continuity check...")

        end_time = time.time() + duration if duration else None

        try:
            while self.running:
                # Check for update
                result = self.check_update()

                # Print status
                self.print_status(result)

                # Check if duration exceeded
                if end_time and time.time() >= end_time:
                    logger.info(f"Duration {duration}s completed")
                    break

                # Wait for next check
                time.sleep(self.check_interval)

        except KeyboardInterrupt:
            logger.info("Checker interrupted by user")
        finally:
            self.shutdown()

    def shutdown(self):
        """Cleanup and print summary."""
        logger.info("Shutting down checker...")
        self.print_summary()


def main():
    """Main entry point."""
    parser = argparse.ArgumentParser(
        description='Data Continuity Checker - Detect gaps in gNMI data',
        formatter_class=argparse.RawDescriptionHelpFormatter
    )

    parser.add_argument(
        '--device-id',
        type=int,
        required=True,
        help='Device ID to check'
    )

    parser.add_argument(
        '--storage-dir',
        type=str,
        default=str(default_storage_dir()),
        help=f'Storage directory (default: {default_storage_dir()})'
    )

    parser.add_argument(
        '--interval',
        type=float,
        default=5.0,
        help='Check interval in seconds (default: 5.0)'
    )

    parser.add_argument(
        '--threshold',
        type=float,
        default=20.0,
        help='Gap threshold in seconds (default: 20.0)'
    )

    parser.add_argument(
        '--duration',
        type=int,
        help='Run for specified duration in seconds (default: infinite)'
    )

    parser.add_argument(
        '--debug',
        action='store_true',
        help='Enable debug logging'
    )

    args = parser.parse_args()

    if args.debug:
        logger.setLevel(logging.DEBUG)

    # Create checker
    checker = ContinuityChecker(
        device_id=args.device_id,
        storage_dir=args.storage_dir,
        check_interval=args.interval,
        gap_threshold=args.threshold
    )

    # Register signal handlers
    def signal_handler(signum, frame):
        logger.info(f"Received signal {signum}")
        checker.running = False

    signal.signal(signal.SIGTERM, signal_handler)
    signal.signal(signal.SIGINT, signal_handler)

    # Run checker
    checker.run(duration=args.duration)


if __name__ == '__main__':
    main()

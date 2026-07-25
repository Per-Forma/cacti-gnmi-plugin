#!/usr/bin/env python3
"""
Daemon Log Analyzer - Parse and analyze daemon logs for issues.

This script analyzes daemon log files to identify:
- Connection failures and reconnections
- Data gaps
- Performance issues
- Error patterns

Usage:
    analyze_daemon_logs.py --log-file <path> [--output <json>]
"""

import argparse
import json
import re
import sys
from collections import Counter, defaultdict
from datetime import datetime, timedelta
from pathlib import Path
from typing import Dict, List, Tuple, Optional


class LogAnalyzer:
    """
    Analyzes daemon log files for patterns and issues.
    """

    def __init__(self, log_file: str):
        self.log_file = Path(log_file)
        self.entries: List[Dict] = []

        # Pattern tracking
        self.connection_attempts = []
        self.connection_successes = []
        self.disconnections = []
        self.errors = []
        self.warnings = []
        self.storage_updates = []
        self.reconnect_attempts = []

        # Statistics
        self.stats = {}

        if not self.log_file.exists():
            raise FileNotFoundError(f"Log file not found: {self.log_file}")

    def parse_log_line(self, line: str) -> Optional[Dict]:
        """Parse a single log line."""
        # Format: 2025-10-17 05:56:18,067 - gnmi_daemon - INFO - Message
        pattern = r'^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}),(\d+) - (.+?) - (\w+) - (.+)$'
        match = re.match(pattern, line)

        if not match:
            return None

        timestamp_str, ms, logger_name, level, message = match.groups()

        try:
            timestamp = datetime.strptime(timestamp_str, '%Y-%m-%d %H:%M:%S')
            timestamp = timestamp.replace(microsecond=int(ms) * 1000)
        except ValueError:
            return None

        return {
            'timestamp': timestamp,
            'logger': logger_name,
            'level': level,
            'message': message,
            'raw': line
        }

    def parse_log_file(self):
        """Parse entire log file."""
        print(f"Parsing log file: {self.log_file}")

        with open(self.log_file, 'r') as f:
            for line in f:
                line = line.strip()
                if not line:
                    continue

                entry = self.parse_log_line(line)
                if entry:
                    self.entries.append(entry)
                    self.categorize_entry(entry)

        print(f"Parsed {len(self.entries)} log entries")

    def categorize_entry(self, entry: Dict):
        """Categorize log entry by type."""
        message = entry['message']
        level = entry['level']

        # Connection attempts
        if 'Connecting to' in message:
            self.connection_attempts.append(entry)

        # Successful connections
        elif 'Subscription established' in message or 'Subscription re-established' in message:
            self.connection_successes.append(entry)

        # Disconnections
        elif 'Shutting down' in message or 'disconnected' in message.lower():
            self.disconnections.append(entry)

        # Errors
        elif level == 'ERROR':
            self.errors.append(entry)

        # Warnings (including reconnect attempts)
        elif level == 'WARNING':
            self.warnings.append(entry)
            if 'Reconnect attempt' in message:
                # Parse reconnect info
                match = re.search(r'Reconnect attempt (\d+) after ([\d.]+)s delay', message)
                if match:
                    attempt_num = int(match.group(1))
                    delay = float(match.group(2))
                    self.reconnect_attempts.append({
                        **entry,
                        'attempt_num': attempt_num,
                        'delay': delay
                    })

        # Storage updates
        elif 'Storage updated' in message:
            # Parse storage update info
            match = re.search(r'samples: (\d+), writes: (\d+)', message)
            if match:
                samples = int(match.group(1))
                writes = int(match.group(2))
                self.storage_updates.append({
                    **entry,
                    'samples': samples,
                    'writes': writes
                })

    def analyze_connection_stability(self) -> Dict:
        """Analyze connection stability."""
        if not self.connection_attempts:
            return {}

        # Calculate connection uptime periods
        uptime_periods = []
        current_start = None

        for i, entry in enumerate(self.entries):
            if 'Subscription established' in entry['message'] or 'Subscription re-established' in entry['message']:
                current_start = entry['timestamp']
            elif 'Connection/subscription failed' in entry['message'] and current_start:
                uptime_periods.append((entry['timestamp'] - current_start).total_seconds())
                current_start = None

        # If still connected at end of log, calculate final uptime
        if current_start and self.entries:
            final_uptime = (self.entries[-1]['timestamp'] - current_start).total_seconds()
            uptime_periods.append(final_uptime)

        total_uptime = sum(uptime_periods) if uptime_periods else 0

        # Calculate total log duration
        if len(self.entries) > 1:
            total_duration = (self.entries[-1]['timestamp'] - self.entries[0]['timestamp']).total_seconds()
            uptime_pct = (total_uptime / total_duration * 100) if total_duration > 0 else 0
        else:
            total_duration = 0
            uptime_pct = 0

        return {
            'connection_attempts': len(self.connection_attempts),
            'connection_successes': len(self.connection_successes),
            'reconnections': len(self.reconnect_attempts),
            'uptime_periods': len(uptime_periods),
            'total_uptime_seconds': total_uptime,
            'total_duration_seconds': total_duration,
            'uptime_percentage': uptime_pct,
            'avg_uptime_per_period': (total_uptime / len(uptime_periods)) if uptime_periods else 0
        }

    def analyze_storage_updates(self) -> Dict:
        """Analyze storage update patterns."""
        if not self.storage_updates:
            return {}

        # Calculate intervals between updates
        intervals = []
        for i in range(1, len(self.storage_updates)):
            delta = (self.storage_updates[i]['timestamp'] - self.storage_updates[i-1]['timestamp']).total_seconds()
            intervals.append(delta)

        # Identify gaps (> 20 seconds)
        gaps = [interval for interval in intervals if interval > 20]

        return {
            'total_updates': len(self.storage_updates),
            'avg_interval': sum(intervals) / len(intervals) if intervals else 0,
            'min_interval': min(intervals) if intervals else 0,
            'max_interval': max(intervals) if intervals else 0,
            'gaps_detected': len(gaps),
            'gap_durations': gaps[:10]  # Top 10 gaps
        }

    def analyze_errors(self) -> Dict:
        """Analyze error patterns."""
        if not self.errors:
            return {}

        # Group errors by type
        error_types = Counter()
        for error in self.errors:
            # Extract error type from message
            if 'SSL' in error['message']:
                error_types['SSL/TLS Error'] += 1
            elif 'timeout' in error['message'].lower():
                error_types['Timeout'] += 1
            elif 'Connection' in error['message']:
                error_types['Connection Error'] += 1
            else:
                error_types['Other'] += 1

        # Find error clusters (multiple errors in short time)
        error_clusters = []
        cluster_threshold = 60  # seconds

        for i in range(len(self.errors) - 1):
            delta = (self.errors[i+1]['timestamp'] - self.errors[i]['timestamp']).total_seconds()
            if delta < cluster_threshold:
                error_clusters.append((self.errors[i]['timestamp'], delta))

        return {
            'total_errors': len(self.errors),
            'error_types': dict(error_types),
            'error_clusters': len(error_clusters),
            'first_error': self.errors[0]['timestamp'].isoformat() if self.errors else None,
            'last_error': self.errors[-1]['timestamp'].isoformat() if self.errors else None
        }

    def analyze_reconnections(self) -> Dict:
        """Analyze reconnection patterns."""
        if not self.reconnect_attempts:
            return {}

        # Group reconnections by session
        sessions = []
        current_session = []

        for attempt in self.reconnect_attempts:
            if current_session and attempt['attempt_num'] < current_session[-1]['attempt_num']:
                # New session started
                sessions.append(current_session)
                current_session = []
            current_session.append(attempt)

        if current_session:
            sessions.append(current_session)

        # Analyze session durations
        session_durations = []
        session_attempt_counts = []

        for session in sessions:
            if len(session) > 1:
                duration = (session[-1]['timestamp'] - session[0]['timestamp']).total_seconds()
                session_durations.append(duration)
                session_attempt_counts.append(len(session))

        return {
            'total_reconnect_attempts': len(self.reconnect_attempts),
            'reconnection_sessions': len(sessions),
            'avg_attempts_per_session': sum(session_attempt_counts) / len(session_attempt_counts) if session_attempt_counts else 0,
            'max_attempts_in_session': max(session_attempt_counts) if session_attempt_counts else 0,
            'avg_session_duration': sum(session_durations) / len(session_durations) if session_durations else 0,
            'longest_session_duration': max(session_durations) if session_durations else 0
        }

    def generate_report(self) -> Dict:
        """Generate comprehensive analysis report."""
        return {
            'log_file': str(self.log_file),
            'total_entries': len(self.entries),
            'time_range': {
                'start': self.entries[0]['timestamp'].isoformat() if self.entries else None,
                'end': self.entries[-1]['timestamp'].isoformat() if self.entries else None,
                'duration_hours': ((self.entries[-1]['timestamp'] - self.entries[0]['timestamp']).total_seconds() / 3600) if len(self.entries) > 1 else 0
            },
            'connection_stability': self.analyze_connection_stability(),
            'storage_updates': self.analyze_storage_updates(),
            'errors': self.analyze_errors(),
            'reconnections': self.analyze_reconnections()
        }

    def print_report(self, report: Dict):
        """Print human-readable report."""
        print("\n" + "="*70)
        print("Daemon Log Analysis Report")
        print("="*70)

        print(f"\nLog File: {report['log_file']}")
        print(f"Total Entries: {report['total_entries']}")

        if report['time_range']['start']:
            print(f"\nTime Range:")
            print(f"  Start: {report['time_range']['start']}")
            print(f"  End: {report['time_range']['end']}")
            print(f"  Duration: {report['time_range']['duration_hours']:.2f} hours")

        # Connection stability
        conn = report.get('connection_stability', {})
        if conn:
            print(f"\nConnection Stability:")
            print(f"  Connection Attempts: {conn.get('connection_attempts', 0)}")
            print(f"  Successful Connections: {conn.get('connection_successes', 0)}")
            print(f"  Reconnections: {conn.get('reconnections', 0)}")
            print(f"  Uptime Percentage: {conn.get('uptime_percentage', 0):.1f}%")
            print(f"  Avg Uptime Per Period: {conn.get('avg_uptime_per_period', 0)/60:.1f} minutes")

        # Storage updates
        storage = report.get('storage_updates', {})
        if storage:
            print(f"\nStorage Updates:")
            print(f"  Total Updates: {storage.get('total_updates', 0)}")
            print(f"  Avg Interval: {storage.get('avg_interval', 0):.1f}s")
            print(f"  Min Interval: {storage.get('min_interval', 0):.1f}s")
            print(f"  Max Interval: {storage.get('max_interval', 0):.1f}s")
            print(f"  Gaps Detected (>20s): {storage.get('gaps_detected', 0)}")

            if storage.get('gap_durations'):
                print(f"  Largest Gaps: {', '.join(f'{g:.0f}s' for g in storage['gap_durations'][:5])}")

        # Errors
        errors = report.get('errors', {})
        if errors and errors.get('total_errors', 0) > 0:
            print(f"\nErrors:")
            print(f"  Total Errors: {errors['total_errors']}")
            print(f"  Error Types:")
            for error_type, count in errors.get('error_types', {}).items():
                print(f"    - {error_type}: {count}")
            print(f"  Error Clusters: {errors.get('error_clusters', 0)}")

        # Reconnections
        reconn = report.get('reconnections', {})
        if reconn and reconn.get('total_reconnect_attempts', 0) > 0:
            print(f"\nReconnection Patterns:")
            print(f"  Total Attempts: {reconn['total_reconnect_attempts']}")
            print(f"  Reconnection Sessions: {reconn['reconnection_sessions']}")
            print(f"  Avg Attempts Per Session: {reconn['avg_attempts_per_session']:.1f}")
            print(f"  Max Attempts In Session: {reconn['max_attempts_in_session']}")
            print(f"  Longest Session: {reconn['longest_session_duration']/60:.1f} minutes")

        print(f"\n{'='*70}")

        # Overall assessment
        if conn.get('uptime_percentage', 0) >= 99:
            print("✅ EXCELLENT: High uptime, stable connection")
        elif conn.get('uptime_percentage', 0) >= 95:
            print("✅ GOOD: Mostly stable with minor issues")
        elif conn.get('uptime_percentage', 0) >= 80:
            print("⚠️  WARNING: Moderate stability issues detected")
        else:
            print("❌ CRITICAL: Significant connection problems")

        print("="*70)


def main():
    """Main entry point."""
    parser = argparse.ArgumentParser(
        description='Daemon Log Analyzer - Parse and analyze daemon logs',
        formatter_class=argparse.RawDescriptionHelpFormatter
    )

    parser.add_argument(
        '--log-file',
        type=str,
        required=True,
        help='Path to daemon log file'
    )

    parser.add_argument(
        '--output',
        type=str,
        help='Output JSON file (optional)'
    )

    args = parser.parse_args()

    # Create analyzer
    try:
        analyzer = LogAnalyzer(args.log_file)
    except FileNotFoundError as e:
        print(f"Error: {e}", file=sys.stderr)
        sys.exit(1)

    # Parse log file
    analyzer.parse_log_file()

    # Generate report
    report = analyzer.generate_report()

    # Print report
    analyzer.print_report(report)

    # Save JSON if requested
    if args.output:
        with open(args.output, 'w') as f:
            json.dump(report, f, indent=2, default=str)
        print(f"\nJSON report saved to: {args.output}")


if __name__ == '__main__':
    main()

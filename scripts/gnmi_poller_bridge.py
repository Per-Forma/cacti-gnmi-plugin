#!/usr/bin/env python3
"""
gNMI Poller Bridge - Connects daemon JSON storage to Cacti RRD system.

This script reads metrics from daemon JSON storage, queries the database to determine
which metrics to output for a specific data source, and outputs data in Cacti format.

Usage:
    gnmi_poller_bridge.py --device-id <id> --local-data-id <local_data_id>

Example:
    gnmi_poller_bridge.py --device-id 1 --local-data-id 6

Output Format (to stdout):
    field1:value1 field2:value2 field3:value3

Exit Codes:
    0: Success (data output)
    1: Error (no data output)
    2: Stale data (no data output)

Architecture:
    Daemon writes JSON → Bridge reads → Queries DB for expected metrics →
    Filters metrics → Outputs Cacti format → Cacti updates RRD
"""

import argparse
import json
import logging
import os
import re
import sys
from datetime import datetime, timezone
from pathlib import Path
from typing import Dict, List, Optional, Any, Tuple

try:
    from gnmi_runtime import storage_dir as default_storage_dir
except ImportError:
    from .gnmi_runtime import storage_dir as default_storage_dir

# Configure logging (to stderr, not stdout)
logging.basicConfig(
    level=logging.WARNING,
    format='%(asctime)s - %(name)s - %(levelname)s - %(message)s',
    stream=sys.stderr
)
logger = logging.getLogger('gnmi_poller_bridge')

DEFAULT_CACTI_CONFIG = Path(__file__).resolve().parents[3] / "include/config.php"


def parse_cacti_config(config_path: str = str(DEFAULT_CACTI_CONFIG)) -> Dict[str, str]:
    """
    Parse Cacti's PHP config file to extract database credentials.

    Args:
        config_path: Path to Cacti config.php file

    Returns:
        Dict with keys: hostname, database, username, password

    Raises:
        FileNotFoundError: If config file doesn't exist
        ValueError: If required config values are missing
    """
    config_path_obj = Path(config_path)
    if not config_path_obj.exists():
        raise FileNotFoundError(f"Cacti config file not found: {config_path}")

    config = {}

    try:
        with open(config_path_obj, 'r') as f:
            content = f.read()

        # Parse PHP-style config variables
        # Pattern: $database_hostname = 'db';
        patterns = {
            'hostname': r"\$database_hostname\s*=\s*['\"]([^'\"]+)['\"]",
            'database': r"\$database_default\s*=\s*['\"]([^'\"]+)['\"]",
            'username': r"\$database_username\s*=\s*['\"]([^'\"]+)['\"]",
            'password': r"\$database_password\s*=\s*['\"]([^'\"]+)['\"]"
        }

        for key, pattern in patterns.items():
            match = re.search(pattern, content)
            if match:
                config[key] = match.group(1)
            else:
                raise ValueError(f"Required config value not found: database_{key}")

        return config

    except Exception as e:
        logger.error(f"Error parsing Cacti config: {e}")
        raise


def connect_to_database(config_path: str = str(DEFAULT_CACTI_CONFIG)):
    """
    Connect to Cacti database using credentials from config file.

    Args:
        config_path: Path to Cacti config.php file

    Returns:
        pymysql connection object

    Raises:
        Exception: If connection fails
    """
    try:
        import pymysql
    except ImportError:
        logger.error("pymysql not installed. Install with: pip install pymysql")
        raise ImportError("pymysql module not found")

    config = parse_cacti_config(config_path)

    try:
        conn = pymysql.connect(
            host=config['hostname'],
            user=config['username'],
            password=config['password'],
            database=config['database'],
            charset='utf8mb4',
            cursorclass=pymysql.cursors.DictCursor
        )
        return conn
    except Exception as e:
        logger.error(f"Database connection failed: {e}")
        raise


def get_data_source_metrics(local_data_id: int, db_conn) -> Tuple[List[str], str, Dict[str, str]]:
    """
    Query database for expected metrics and instance identifier for a data source.

    Args:
        local_data_id: Cacti local_data_id (data source ID)
        db_conn: Database connection object

    Returns:
        Tuple of (list of metric names, instance_identifier, field_to_metric_map)
        field_to_metric_map maps field_name -> metric_name for matching storage data

    Raises:
        ValueError: If data source not found or has no metrics
    """
    cursor = db_conn.cursor()

    try:
        # Query 1: Get data source field names and their corresponding metric names
        query = """
            SELECT
                dtr.data_source_name,
                pm.metric_name
            FROM data_template_rrd dtr
            LEFT JOIN plugin_gnmi_metrics pm
                ON pm.cacti_field_name = dtr.data_source_name
                AND pm.local_data_id = dtr.local_data_id
            WHERE dtr.local_data_id = %s
            ORDER BY dtr.id
        """

        cursor.execute(query, (local_data_id,))
        rows = cursor.fetchall()

        if not rows:
            raise ValueError(f"No data source found with local_data_id={local_data_id}")

        # Build list of expected metric names and field-to-metric mapping
        expected_metrics = []
        field_to_metric_map = {}

        for row in rows:
            field_name = row['data_source_name']
            metric_name = row['metric_name']

            if metric_name:
                # Use metric name from database (most accurate)
                expected_metrics.append(metric_name)
                field_to_metric_map[field_name] = metric_name
            else:
                # Fallback: convert field name to metric name
                metric_name = field_name_to_metric_name(field_name)
                expected_metrics.append(metric_name)
                field_to_metric_map[field_name] = metric_name

        if not expected_metrics:
            raise ValueError(f"Data source {local_data_id} has no metrics configured")

        # Query 2: Get instance identifier from subscription
        instance_query = """
            SELECT s.instance_identifier
            FROM plugin_gnmi_subscriptions s
            INNER JOIN plugin_gnmi_metrics m ON m.subscription_id = s.id
            WHERE m.local_data_id = %s
            LIMIT 1
        """

        cursor.execute(instance_query, (local_data_id,))
        instance_row = cursor.fetchone()

        if instance_row:
            instance_identifier = instance_row['instance_identifier']
        else:
            # Fallback: try to get from data_template_data name
            name_query = """
                SELECT name
                FROM data_template_data
                WHERE local_data_id = %s
            """
            cursor.execute(name_query, (local_data_id,))
            name_row = cursor.fetchone()
            if name_row and 'instance' in name_row['name'].lower():
                # Try to extract instance from name like "gNMI - ettp-40"
                match = re.search(r'\(([^)]+)\)', name_row['name'])
                instance_identifier = match.group(1) if match else "default"
            else:
                instance_identifier = "default"

        logger.debug(f"Data source {local_data_id}: {len(expected_metrics)} metrics, instance={instance_identifier}")
        return expected_metrics, instance_identifier, field_to_metric_map

    finally:
        cursor.close()


def field_name_to_metric_name(field_name: str) -> str:
    """
    Convert Cacti field name back to gNMI metric name.

    This reverses the sanitize_metric_name() logic:
    - in_octets → in-octets
    - Handles edge cases (leading numbers, truncation)

    Args:
        field_name: Cacti field name (e.g., "in_octets")

    Returns:
        gNMI metric name (e.g., "in-octets")

    Note: This is a best-effort conversion. The database query should
    provide the actual metric_name, but this serves as a fallback.
    """
    # Handle leading underscore prefix (from sanitization)
    if field_name.startswith('_'):
        field_name = field_name[1:]

    # Handle metric_ prefix (from sanitization)
    if field_name.startswith('metric_'):
        field_name = field_name[7:]

    # Basic conversion: underscores to hyphens
    return field_name.replace('_', '-')


def sanitize_field_name(gnmi_path: str) -> str:
    """
    Convert gNMI path to Cacti-compatible field name.

    Rules:
    - Replace hyphens with underscores
    - Lowercase all characters
    - Remove special characters except underscore
    - Ensure starts with letter or underscore
    - Max 19 characters (RRD DS name limit)

    Args:
        gnmi_path: Original gNMI path (e.g., "in-octets")

    Returns:
        Sanitized field name (e.g., "in_octets")

    Examples:
        >>> sanitize_field_name("in-octets")
        'in_octets'
        >>> sanitize_field_name("in-crc-error-pkts")
        'in_crc_error_pkts'
    """
    # Replace hyphens with underscores
    field = gnmi_path.replace('-', '_')

    # Remove quotes if present
    field = field.replace('"', '')

    # Keep only alphanumeric and underscore
    field = re.sub(r'[^a-zA-Z0-9_]', '', field)

    # Ensure lowercase
    field = field.lower()

    # Ensure starts with letter or underscore
    if field and field[0].isdigit():
        field = '_' + field

    # Truncate to 19 characters (RRD limit)
    field = field[:19]

    return field


def extract_instance_id(path_prefix: str) -> str:
    """
    Extract instance identifier from gNMI path.

    Supports:
    - Ciena format: interface-counters[interface-type=ettp]/interfaces[if-name=40]
    - OpenConfig: /interface[name=eth0]

    Args:
        path_prefix: gNMI path prefix from telemetry

    Returns:
        Instance identifier (e.g., "ettp-40", "eth0", "default")

    Examples:
        >>> extract_instance_id("interface-counters[interface-type=ettp]/interfaces[if-name=40]")
        'ettp-40'
        >>> extract_instance_id("/interfaces/interface[name=eth0]/state")
        'eth0'
    """
    # Try Ciena format first
    iface_type_match = re.search(r'interface-type=([^\]]+)', path_prefix)
    iface_name_match = re.search(r'if-name=([^\]]+)', path_prefix)

    if iface_type_match and iface_name_match:
        iface_type = iface_type_match.group(1)
        iface_name = iface_name_match.group(1)
        return f"{iface_type}-{iface_name}"

    # Try OpenConfig format
    name_match = re.search(r'\[name=([^\]]+)\]', path_prefix)
    if name_match:
        return name_match.group(1)

    # Fallback for system-wide metrics
    return "default"


def read_daemon_storage(device_id: int, storage_dir: str = None, max_retries: int = 3) -> Optional[Dict[str, Any]]:
    """
    Read daemon JSON storage file with retry logic for atomic write race conditions.

    Args:
        device_id: Device ID
        storage_dir: Storage directory path
        max_retries: Number of retry attempts if file is temporarily unavailable

    Returns:
        Parsed JSON data or None if file missing/invalid
    """
    import time

    if storage_dir is None:
        storage_dir = str(default_storage_dir())
    storage_path = Path(storage_dir) / f"device_{device_id}.json"

    last_error = None
    for attempt in range(max_retries):
        try:
            with open(storage_path, 'r') as f:
                return json.load(f)
        except FileNotFoundError as e:
            last_error = e
            if attempt < max_retries - 1:
                logger.debug(f"Storage file not found (attempt {attempt + 1}/{max_retries}), retrying...")
                time.sleep(0.2)
            continue
        except json.JSONDecodeError as e:
            logger.error(f"Invalid JSON in storage file: {e}")
            return None
        except Exception as e:
            last_error = e
            if attempt < max_retries - 1:
                logger.debug(f"Error reading storage file (attempt {attempt + 1}/{max_retries}): {e}")
                time.sleep(0.2)
            continue

    # All retries exhausted
    if isinstance(last_error, FileNotFoundError):
        logger.error(f"Storage file not found after {max_retries} attempts: {storage_path}")
    else:
        logger.error(f"Error reading storage file after {max_retries} attempts: {last_error}")
    return None


def check_staleness(data: Dict[str, Any], threshold: int = 30) -> bool:
    """
    Check if data is stale (too old).

    Args:
        data: Daemon storage data
        threshold: Staleness threshold in seconds. PHP callers pass
            poller_interval * 2; the function default is for standalone use.

    Returns:
        True if data is stale, False if fresh
    """
    last_update_str = data.get('last_update')
    if not last_update_str:
        logger.warning("No last_update timestamp in data")
        return True

    try:
        last_update = datetime.fromisoformat(last_update_str.replace('Z', '+00:00'))
        age = (datetime.now(timezone.utc) - last_update).total_seconds()

        if age > threshold:
            logger.warning(f"Data is stale: {age:.1f}s old (threshold: {threshold}s)")
            return True

        logger.debug(f"Data is fresh: {age:.1f}s old")
        return False

    except (ValueError, AttributeError) as e:
        logger.error(f"Invalid timestamp format: {e}")
        return True


def extract_metrics_from_raw(raw_data: Dict[str, Any], instance_identifier: str,
                             field_to_metric_map: Optional[Dict[str, str]] = None) -> Dict[str, Any]:
    """
    Extract metrics for a specific instance from daemon JSON storage.

    Storage shape (see docs/daemon_storage_format.md):
        {
          "metric_groups": {
            "ettp-40": {"in_octets": 123, "out_octets": 456},
            "ettp-34": {"in_octets": 789, "out_octets": 12}
          }
        }

    Returns gNMI-style metric names ("in-octets") for the requested instance only.
    Returns {} (with a WARNING log) if the instance isn't present — never falls back
    to "all flat metrics", which was the source of cross-instance contamination in
    the pre-fix code.

    Args:
        raw_data: Daemon JSON data
        instance_identifier: Required instance identifier to extract (e.g. "ettp-40")

    Returns:
        Dict of {metric_name: value} (e.g., {"in-octets": 123, "out-octets": 456})
    """
    if not instance_identifier:
        logger.error("extract_metrics_from_raw called with empty instance_identifier")
        return {}

    metrics: Dict[str, Any] = {}
    try:
        metric_groups = raw_data.get('metric_groups', {})
        if not isinstance(metric_groups, dict):
            logger.warning(f"metric_groups is not a dict: {type(metric_groups).__name__}")
            return {}

        instance_metrics = metric_groups.get(instance_identifier)
        if not isinstance(instance_metrics, dict):
            logger.warning(
                f"Instance {instance_identifier!r} not found in metric_groups. "
                f"Available: {list(metric_groups.keys())}"
            )
            return {}

        # Storage uses Cacti field names. Prefer the authoritative database
        # mapping because aliases such as in_pkts -> in-unicast-packets cannot
        # be reconstructed by replacing underscores with hyphens.
        field_to_metric_map = field_to_metric_map or {}
        for field_name, value in instance_metrics.items():
            metric_name = field_to_metric_map.get(field_name, field_name.replace('_', '-'))
            metrics[metric_name] = value

        logger.debug(
            f"Extracted {len(metrics)} metrics for instance={instance_identifier}: {list(metrics.keys())}"
        )
    except Exception as e:
        logger.error(f"Error extracting metrics: {e}", exc_info=True)

    return metrics


def filter_data_source_metrics(all_metrics: Dict[str, Any], expected_metrics: List[str]) -> Dict[str, Any]:
    """
    Filter metrics for specified data source based on expected metric names.

    Only includes metrics that actually exist in the data. Missing metrics
    are silently skipped (no warning) since they may not be available yet.

    Args:
        all_metrics: All available metrics from daemon storage
        expected_metrics: List of expected metric names (gNMI paths) for this data source

    Returns:
        Dict of metrics that exist in both expected list and available data
    """
    filtered = {}

    for metric_name in expected_metrics:
        if metric_name in all_metrics:
            filtered[metric_name] = all_metrics[metric_name]
        else:
            # Silently skip missing metrics - they may not be configured or available yet
            logger.debug(f"Metric '{metric_name}' not found in daemon storage (may not be available)")

    logger.debug(f"Filtered {len(filtered)}/{len(expected_metrics)} expected metrics")
    return filtered


def output_cacti_format(metrics: Dict[str, Any]):
    """
    Output metrics in Cacti format to stdout.

    Format: field1:value1 field2:value2 ...

    Args:
        metrics: Dict of {field_name: value} (field names, not metric names)
    """
    if not metrics:
        logger.warning("No metrics to output")
        return

    # Sanitize field names for RRD/Cacti compatibility (hyphens → underscores,
    # strip special chars, enforce 19-char limit, ensure not digit-leading)
    output_pairs = []
    for field_name, value in metrics.items():
        # Handle string values (like interface name)
        if isinstance(value, str):
            # Remove quotes
            value = value.strip('"')
            # For numeric strings, keep as-is; for text, skip in Cacti output
            if not value.replace('.', '').replace('-', '').isdigit():
                logger.debug(f"Skipping non-numeric value: {field_name}={value}")
                continue

        safe_name = sanitize_field_name(field_name)
        output_pairs.append(f"{safe_name}:{value}")

    # Output to stdout (Cacti reads this)
    output = ' '.join(output_pairs)
    print(output)
    logger.debug(f"Output: {output}")


# ============================================================================
# History Output Functions (for RRD streaming/backfill)
# ============================================================================

def sort_samples_chronologically(samples: List[Dict[str, Any]]) -> List[Dict[str, Any]]:
    """
    Sort samples by epoch timestamp, filtering out samples without epoch.

    Args:
        samples: List of sample dictionaries with optional 'epoch' key

    Returns:
        Sorted list of samples with valid epoch, in chronological order
    """
    # Filter to only samples with epoch
    valid_samples = [s for s in samples if s.get('epoch')]

    # Sort by epoch (chronological)
    return sorted(valid_samples, key=lambda s: s.get('epoch', 0))


def extract_sample_metrics(sample: Dict[str, Any],
                           field_to_metric_map: Optional[Dict[str, str]] = None) -> Dict[str, Any]:
    """
    Extract gNMI metric values from a single sample.

    Storage shape (see docs/daemon_storage_format.md): samples_history is
    {instance: [sample, ...]} where each sample is already flat with Cacti
    field names plus bookkeeping ('epoch', 'timestamp'). The instance routing
    happens at process_history_output's list-selection step — by the time we
    see a single sample, it already belongs to the requested instance.

    Field names are converted back to gNMI metric names (in_octets → in-octets)
    so the caller can filter against expected_metrics (which holds gNMI names
    from the DB).

    Returns:
        Dict of {gnmi_metric_name: value}, excluding bookkeeping and any
        unexpectedly nested values (defensive — old shape would have nested
        dicts under instance keys; post-fix samples are flat).
    """
    excluded_keys = {'epoch', 'timestamp'}
    field_to_metric_map = field_to_metric_map or {}
    metrics: Dict[str, Any] = {}
    for field_name, value in sample.items():
        if field_name in excluded_keys:
            continue
        if isinstance(value, dict):
            logger.warning(
                f"Unexpected nested value in sample under key {field_name!r}; "
                f"skipping (storage shape drift?)"
            )
            continue
        metric_name = field_to_metric_map.get(field_name, field_name.replace('_', '-'))
        metrics[metric_name] = value
    return metrics


def format_history_line(epoch: int, metrics: Dict[str, Any],
                        field_to_metric_map: Dict[str, str]) -> Optional[str]:
    """
    Format a single line for history output.

    Args:
        epoch: Unix timestamp
        metrics: Dict of {metric_name: value} (e.g., {'in-octets': 123})
        field_to_metric_map: Mapping from field names to metric names

    Returns:
        Formatted line "EPOCH field1:value1 field2:value2" or None if no valid metrics

    Output format is designed for parsing by PHP:
        1738561003 in_octets:123456 out_octets:789012
    """
    if not metrics:
        return None

    # Build reverse map: metric_name -> field_name
    metric_to_field = {v: k for k, v in field_to_metric_map.items()}

    output_pairs = []
    for metric_name, value in metrics.items():
        # Skip non-numeric values
        if isinstance(value, str):
            value = value.strip('"')
            if not value.replace('.', '').replace('-', '').isdigit():
                logger.debug(f"Skipping non-numeric value: {metric_name}={value}")
                continue

        # Get field name from mapping, or sanitize metric name
        field_name = metric_to_field.get(metric_name)
        if not field_name:
            field_name = sanitize_field_name(metric_name)

        output_pairs.append(f"{field_name}:{value}")

    if not output_pairs:
        return None

    return f"{epoch} " + ' '.join(output_pairs)


def process_history_output(samples_history: Dict[str, List[Dict[str, Any]]],
                           instance_identifier: str,
                           expected_metrics: List[str],
                           field_to_metric_map: Dict[str, str]) -> List[str]:
    """
    Generate per-sample output lines for RRD backfill for one instance.

    Each output line:
        1738561003 in_octets:100 out_octets:200
        1738561008 in_octets:150 out_octets:250

    samples_history is the instance-keyed dict written by the daemon
    (Dict[instance, List[sample]]). The instance's slice is selected up front;
    cross-instance contamination is impossible by construction.

    Args:
        samples_history: {instance: [sample, ...]} from daemon JSON
        instance_identifier: Instance to extract metrics for (e.g. "ettp-40")
        expected_metrics: gNMI metric names expected by this data source
        field_to_metric_map: Mapping from Cacti field names to gNMI metric names

    Returns:
        List of output lines in chronological order
    """
    if not isinstance(samples_history, dict):
        logger.error(
            f"samples_history is not a dict (got {type(samples_history).__name__}); "
            f"daemon storage shape is out of date — redeploy and restart the daemon"
        )
        return []

    samples_for_instance = samples_history.get(instance_identifier, [])
    if not samples_for_instance:
        logger.debug(
            f"No samples in history for instance={instance_identifier}. "
            f"Available: {list(samples_history.keys())}"
        )
        return []

    sorted_samples = sort_samples_chronologically(samples_for_instance)
    if not sorted_samples:
        return []

    lines = []
    for sample in sorted_samples:
        epoch = sample.get('epoch')
        if not epoch:
            continue

        sample_metrics = extract_sample_metrics(sample, field_to_metric_map)
        if not sample_metrics:
            continue

        filtered_metrics = {
            metric_name: sample_metrics[metric_name]
            for metric_name in expected_metrics
            if metric_name in sample_metrics
        }
        if not filtered_metrics:
            continue

        line = format_history_line(epoch, filtered_metrics, field_to_metric_map)
        if line:
            lines.append(line)

    return lines


def main():
    """Main entry point."""
    parser = argparse.ArgumentParser(
        description='gNMI Poller Bridge - Daemon JSON to Cacti RRD',
        formatter_class=argparse.RawDescriptionHelpFormatter,
        epilog=__doc__
    )

    parser.add_argument(
        '--device-id',
        type=int,
        required=True,
        help='Device ID'
    )

    parser.add_argument(
        '--local-data-id',
        type=int,
        required=True,
        help='Cacti local_data_id (data source ID)'
    )

    parser.add_argument(
        '--storage-dir',
        type=str,
        default=str(default_storage_dir()),
        help=f'Storage directory (default: {default_storage_dir()})'
    )

    parser.add_argument(
        '--config-path',
        type=str,
        default=str(DEFAULT_CACTI_CONFIG),
        help=f'Path to Cacti config.php (default: {DEFAULT_CACTI_CONFIG})'
    )

    parser.add_argument(
        '--staleness-threshold',
        type=int,
        default=30,
        help='Data staleness threshold in seconds; PHP passes poller_interval * 2'
    )

    parser.add_argument(
        '--debug',
        action='store_true',
        help='Enable debug logging'
    )

    parser.add_argument(
        '--output-history',
        action='store_true',
        help='Output all buffered samples with timestamps (for RRD backfill)'
    )

    args = parser.parse_args()

    # Set log level
    if args.debug:
        logger.setLevel(logging.DEBUG)

    db_conn = None

    try:
        # Connect to database
        try:
            db_conn = connect_to_database(args.config_path)
        except Exception as e:
            logger.error(f"Failed to connect to database: {e}")
            sys.exit(1)

        # Query database for expected metrics and instance
        try:
            expected_metrics, instance_identifier, field_to_metric_map = get_data_source_metrics(args.local_data_id, db_conn)
        except ValueError as e:
            logger.error(f"Failed to get data source metrics: {e}")
            sys.exit(1)
        except Exception as e:
            logger.error(f"Database query error: {e}", exc_info=True)
            sys.exit(1)

        # Read daemon storage
        data = read_daemon_storage(args.device_id, args.storage_dir)
        if not data:
            logger.error(f"Cannot read storage for device {args.device_id}")
            sys.exit(1)

        # Check staleness
        if check_staleness(data, args.staleness_threshold):
            logger.error(f"Data is stale for device {args.device_id}")
            sys.exit(2)  # Stale data - Cacti will store 'U' values

        # Check daemon status
        daemon_status = data.get('daemon_status', 'unknown')
        if daemon_status != 'connected':
            logger.warning(f"Daemon not connected: status={daemon_status}")
            sys.exit(2)  # Not connected - no valid data

        # History output mode - output all buffered samples with timestamps
        if args.output_history:
            samples_history = data.get('samples_history', {})
            if not samples_history:
                logger.warning("No samples_history in daemon storage")
                sys.exit(1)

            # Process and output history lines
            lines = process_history_output(
                samples_history,
                instance_identifier,
                expected_metrics,
                field_to_metric_map
            )

            if not lines:
                logger.warning("No valid samples found in samples_history")
                sys.exit(1)

            # Output each line (PHP will parse these)
            for line in lines:
                print(line)

            sys.exit(0)

        # Extract raw metrics (filter by instance if needed)
        # Storage has field names (in_octets), we need to map to metric names (in-octets)
        storage_metrics_by_field = extract_metrics_from_raw(
            data,
            instance_identifier,
            field_to_metric_map,
        )
        if not storage_metrics_by_field:
            logger.error(f"No metrics found in storage for instance '{instance_identifier}'")
            sys.exit(1)

        # Convert storage metrics (field names) to metric names using mapping
        # Then filter to only expected metrics
        storage_metrics_by_metric = {}
        for field_name, value in storage_metrics_by_field.items():
            # Try to find metric name for this field name
            # First check direct mapping from database
            metric_name = None
            for fname, mname in field_to_metric_map.items():
                if fname == field_name:
                    metric_name = mname
                    break

            # If no direct mapping, try reverse sanitization
            if not metric_name:
                metric_name = field_name_to_metric_name(field_name)

            storage_metrics_by_metric[metric_name] = value

        # Filter for expected metrics for this data source
        filtered_metrics = filter_data_source_metrics(storage_metrics_by_metric, expected_metrics)
        if not filtered_metrics:
            logger.error(f"No matching metrics found for data source {args.local_data_id}")
            sys.exit(1)

        # Output in Cacti format (using field names, not metric names)
        # We need to convert back to field names for output
        output_metrics = {}
        for metric_name, value in filtered_metrics.items():
            # Find field name for this metric
            field_name = None
            for fname, mname in field_to_metric_map.items():
                if mname == metric_name:
                    field_name = fname
                    break

            # Fallback: sanitize metric name to field name
            if not field_name:
                field_name = sanitize_field_name(metric_name)

            output_metrics[field_name] = value

        # Output in Cacti format (field names)
        output_cacti_format(output_metrics)

        sys.exit(0)

    except KeyboardInterrupt:
        logger.info("Interrupted")
        sys.exit(1)
    except Exception as e:
        logger.error(f"Unexpected error: {e}", exc_info=True)
        sys.exit(1)
    finally:
        if db_conn:
            db_conn.close()


if __name__ == '__main__':
    main()

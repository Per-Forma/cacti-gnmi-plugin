"""
RRD file operations for gNMI telemetry storage.

This module handles creation and updating of RRD (Round Robin Database) files
for storing time-series gNMI telemetry data. It provides functions for checking
file existence, creating new RRD files with appropriate data sources and archives,
and updating RRD files with new telemetry values.

RRD helpers used by the streaming collector and bridge.
"""

import os
import rrdtool
from typing import List, Dict, Any, Union, Optional
import logging

# Configure logging
logger = logging.getLogger(__name__)


def rrd_file_exists(filename: str) -> bool:
    """
    Check if an RRD file exists at the specified path.

    Args:
        filename: Path to the RRD file

    Returns:
        True if file exists, False otherwise

    Examples:
        >>> rrd_file_exists('/path/to/traffic.rrd')
        True
        >>> rrd_file_exists('/path/to/nonexistent.rrd')
        False
    """
    return os.path.isfile(filename)


def create_rrd(
    filename: str,
    metrics: List[str],
    step: int = 5,
    ds_type: str = 'COUNTER',
    heartbeat: int = None
) -> None:
    """
    Create a new RRD file with the specified metrics as data sources.

    This function creates an RRD file configured for storing gNMI telemetry data.
    By default, it creates:
    - 5-second resolution data stored for 2 years
    - 1-minute average data stored for 5 years

    Args:
        filename: Path where the RRD file should be created
        metrics: List of metric names to store (becomes DS names)
        step: Sampling interval in seconds (default: 5)
        ds_type: RRD data source type (default: 'COUNTER')
                 Supported types: COUNTER, GAUGE, DERIVE, ABSOLUTE
        heartbeat: RRD heartbeat in seconds (default: step * 2). Should be set
                   to poller_interval * 2 so RRD tolerates a full missed poll cycle.

    Raises:
        ValueError: If metrics list is empty, step is invalid, or ds_type is unsupported
        Exception: If rrdtool.create() fails

    Examples:
        >>> create_rrd('interface.rrd', ['in-octets', 'out-octets'], step=5)
        >>> create_rrd('temperature.rrd', ['sensor1'], step=60, ds_type='GAUGE')
        >>> create_rrd('interface.rrd', ['in-octets'], step=5, heartbeat=120)
    """
    # Validate inputs
    if not metrics:
        raise ValueError("Metrics list cannot be empty")

    if step <= 0:
        raise ValueError(f"Step must be positive, got: {step}")

    valid_types = ['COUNTER', 'GAUGE', 'DERIVE', 'ABSOLUTE']
    if ds_type not in valid_types:
        raise ValueError(
            f"Invalid DS type: {ds_type}. "
            f"Supported types: {', '.join(valid_types)}"
        )

    # Build rrdtool.create() arguments
    args = [
        filename,
        '--step', str(step),
        '--start', 'now'
    ]

    # Add data source definitions
    # Heartbeat should match poller cadence (poller_interval * 2) so RRD tolerates
    # one missed poll cycle. Default to step * 2 for backward compatibility when
    # the caller doesn't know the poller interval.
    if heartbeat is None:
        heartbeat = step * 2
    for metric in metrics:
        # DS:name:type:heartbeat:min:max
        # min=0 (counters/gauges can't be negative)
        # max=U (unknown/unlimited)
        ds_def = f"DS:{metric}:{ds_type}:{heartbeat}:0:U"
        args.append(ds_def)

    # Add Round Robin Archives (RRAs)
    # POC configuration:
    # - RRA:AVERAGE:0.5:1:12614400 = 5-second data for ~2 years
    #   (12614400 * 5 seconds = 63072000 seconds = 730 days)
    # - RRA:AVERAGE:0.5:12:2628000 = 1-minute averages for ~5 years
    #   (2628000 * 60 seconds = 157680000 seconds = 1825 days)
    args.append('RRA:AVERAGE:0.5:1:12614400')
    args.append('RRA:AVERAGE:0.5:12:2628000')

    # Create the RRD file
    logger.info(f"Creating RRD file: {filename} with metrics: {metrics}")
    try:
        rrdtool.create(*args)
        logger.info(f"Successfully created RRD file: {filename}")
    except Exception as e:
        logger.error(f"Failed to create RRD file {filename}: {e}")
        raise


def update_rrd(
    filename: str,
    timestamp: Union[int, str],
    values: Dict[str, Union[int, float]]
) -> None:
    """
    Update an RRD file with new metric values.

    Args:
        filename: Path to the RRD file to update
        timestamp: Unix timestamp in seconds, or 'N' for current time
                   Can also be nanoseconds (will be converted to seconds)
        values: Dictionary mapping metric names to their values
                Order should match the DS order in the RRD file

    Raises:
        ValueError: If values dict is empty or timestamp is invalid
        Exception: If rrdtool.update() fails

    Examples:
        >>> update_rrd('traffic.rrd', 1234567890, {'in-octets': 1000, 'out-octets': 2000})
        >>> update_rrd('traffic.rrd', 'N', {'in-octets': 1500, 'out-octets': 2500})
    """
    # Validate inputs
    if not values:
        raise ValueError("Values dictionary cannot be empty")

    # Convert timestamp if needed
    if isinstance(timestamp, str):
        if timestamp != 'N':
            try:
                timestamp = int(timestamp)
            except ValueError:
                raise ValueError(f"Invalid timestamp: {timestamp}")
    elif isinstance(timestamp, int):
        # If timestamp looks like nanoseconds (> 10 billion = beyond year 2286),
        # convert to seconds. Normal Unix timestamps are in billions (10^9 range).
        # Nanosecond timestamps are in quintillions (10^18 range).
        if timestamp > 10**10:
            timestamp = timestamp // 10**9
    else:
        raise TypeError(f"Timestamp must be int or str, got {type(timestamp).__name__}")

    # Build update string: timestamp:value1:value2:...
    update_str = f"{timestamp}"
    for value in values.values():
        update_str += f":{value}"

    # Update the RRD file
    logger.debug(f"Updating RRD file: {filename} with: {update_str}")
    try:
        rrdtool.update(filename, update_str)
    except Exception as e:
        logger.error(f"Failed to update RRD file {filename}: {e}")
        raise


def store_results(
    results: List[Optional[Dict[str, Any]]],
    timestamp: Union[int, str],
    filename: str
) -> None:
    """
    Store POC-format results list to an RRD file.

    This function converts the POC's results format (list of dicts with 'path' and 'val')
    into the format needed for RRD updates.

    Args:
        results: List of result dictionaries with 'path' and 'val' keys
                 None entries are filtered out
        timestamp: Unix timestamp (seconds or nanoseconds) or 'N'
        filename: Path to the RRD file

    Raises:
        ValueError: If results list is empty after filtering
        Exception: If RRD update fails

    Examples:
        >>> results = [
        ...     {'path': 'out-octets', 'val': 1000},
        ...     {'path': 'in-octets', 'val': 2000}
        ... ]
        >>> store_results(results, 1234567890, 'traffic.rrd')
    """
    # Filter out None results and extract values
    filtered_results = [r for r in results if r is not None and 'val' in r]

    if not filtered_results:
        logger.warning("No valid results to store")
        return

    # Convert to values dict
    # Note: For proper RRD updates, the order must match the DS definition order
    # We preserve the order from the results list
    values = {r['path']: r['val'] for r in filtered_results}

    # Update the RRD file
    update_rrd(filename, timestamp, values)

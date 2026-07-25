"""
gNMI subscription and telemetry collection module.

This module handles gNMI subscriptions, connection management, and parsing
of telemetry data from network devices. It provides the core functionality
for collecting metrics via the gNMI protocol.

The collector keeps standard gNMI behavior unless compatibility mode is
explicitly enabled for a device.
"""

from typing import Dict, List, Any, Optional, Generator
import logging

# The compatibility patch is opt-in per call; importing this module must retain
# pygnmi's standard Capabilities and stream handling behavior.
from .pygnmi_patch import patch_pygnmi_for_ciena

from pygnmi.client import gNMIclient, telemetryParser

# Configure logging
logger = logging.getLogger(__name__)


def process_and_store(
    data_list: List[Dict[str, Any]],
    timestamp: int,
    paths_to_find: List[str],
    rrd_filename: str,
    transforms: Optional[List[Dict[str, Any]]] = None
) -> None:
    """
    Process telemetry data and store to RRD file (POC function).

    This function extracts specified paths from the telemetry data,
    applies any configured transforms, and stores the results in an RRD file.

    Args:
        data_list: List of telemetry data items with 'path' and 'val' keys
        timestamp: Timestamp from telemetry (nanoseconds since epoch)
        paths_to_find: List of paths to extract from data
        rrd_filename: Path to RRD file for storage
        transforms: Optional list of transform specifications

    Note:
        This is migrated from POC and will be refactored in Phase 2.
        It imports from local modules to avoid circular dependencies.
    """
    from .transforms import apply_transforms
    from .rrd import store_results

    # Convert timestamp from nanoseconds to seconds
    timestamp_seconds = timestamp // 1000000000

    # Transform the list into a dictionary for fast lookups
    data_dict = {item['path']: item for item in data_list}

    # Extract the values for the specified paths
    results = [data_dict.get(path) for path in paths_to_find]

    # Apply transforms if provided
    if transforms:
        apply_transforms(results, transforms)

    # Store the results
    if results:
        store_results(results, timestamp_seconds, rrd_filename)
        logger.debug(f"Stored {len(results)} metrics to {rrd_filename}")


def subscribe_and_store(
    subscriptions: str,
    target: str,
    port: int,
    records_to_store: List[str],
    rrd_file_string: str,
    username: str,
    password: str,
    path_root: Optional[str] = None,
    path_key: Optional[str] = None,
    path_cert: Optional[str] = None,
    override: Optional[str] = None,
    skip_verify: bool = True,
    transforms: Optional[List[Dict[str, Any]]] = None,
    debug: bool = False,
    insecure: bool = False,
    no_qos_marking: bool = False,
    compatibility_mode: str = 'standard'
) -> Generator[Dict[str, Any], None, None]:
    """
    Subscribe to gNMI telemetry and store results to RRD (POC function).

    This function establishes a gNMI streaming subscription to a device,
    receives telemetry updates, and stores them in an RRD file. It's a
    generator that yields telemetry updates as they arrive.

    Args:
        subscriptions: gNMI path to subscribe to
        target: Device hostname or IP address
        port: gNMI port (typically 9339)
        records_to_store: List of metric names to extract and store
        rrd_file_string: Path to RRD file
        username: Authentication username
        password: Authentication password
        path_root: Path to CA certificate (optional)
        path_key: Path to client key file (optional)
        path_cert: Path to client certificate (optional)
        override: TLS server name override (optional)
        skip_verify: Skip TLS certificate verification (default: True)
        transforms: List of transform specifications (optional)
        debug: Enable debug logging (default: False)
        insecure: Use insecure gRPC connection (default: False)
        no_qos_marking: Disable QoS marking for faster connection (default: False)
        compatibility_mode: Set to 'ciena_saos10' only for affected Ciena targets

    Yields:
        Dict containing raw telemetry data

    Raises:
        Exception: If connection fails or subscription error occurs

    Examples:
        >>> for update in subscribe_and_store(
        ...     subscriptions='interface/state/counters',
        ...     target='192.0.2.10',
        ...     port=9339,
        ...     records_to_store=['in-octets', 'out-octets'],
        ...     rrd_file_string='traffic.rrd',
        ...     username='telemetry-example',
        ...     password='replace-with-test-password'
        ... ):
        ...     print(f"Received update: {update['timestamp']}")

    Note:
        This is migrated from POC. For Phase 2, this will be refactored to
        support one-shot polling (non-streaming) for Cacti poller integration.
    """
    from .rrd import rrd_file_exists, create_rrd

    if compatibility_mode == 'ciena_saos10':
        patch_pygnmi_for_ciena()

    # Check for RRD file and create if needed
    if not rrd_file_exists(rrd_file_string):
        logger.info(f"Creating RRD file: {rrd_file_string}")
        create_rrd(rrd_file_string, records_to_store, step=5, ds_type='COUNTER')

    # Prepare subscription request
    subscribe = {
        'subscription': [
            {
                'path': subscriptions,
                'mode': 'sample',
                'sample_interval': 5000000000  # 5 seconds in nanoseconds
            }
        ],
        'mode': 'stream',
        'encoding': 'json'
    }

    # Build gNMI client arguments
    client_args = {
        'target': (target, port),
        'username': username,
        'password': password,
        'skip_verify': skip_verify,
        'debug': debug,
        'insecure': insecure,
        'no_qos_marking': no_qos_marking
    }

    # Add optional TLS parameters
    if path_root:
        client_args['path_root'] = path_root
    if path_key:
        client_args['path_key'] = path_key
    if path_cert:
        client_args['path_cert'] = path_cert
    if override:
        client_args['override'] = override

    logger.info(f"Connecting to gNMI target: {target}:{port}")
    if insecure:
        logger.info(f"Using insecure mode (bypasses capabilities collection)")
    if no_qos_marking:
        logger.info(f"QoS marking disabled")

    try:
        with gNMIclient(**client_args) as gc:
            logger.info(f"Successfully connected to {target}:{port}")
            logger.info(f"Starting subscription to path: {subscriptions}")

            response = gc.subscribe(subscribe)

            for telemetry_entry in response:
                raw_data = telemetryParser(telemetry_entry)

                if 'update' in raw_data.keys():
                    update_list = raw_data['update']['update']
                    timestamp = raw_data['update'].get('timestamp')

                    # Process and store the data
                    process_and_store(
                        update_list,
                        timestamp,
                        records_to_store,
                        rrd_file_string,
                        transforms
                    )

                    # Yield the raw data for caller to process if needed
                    yield raw_data

    except Exception as e:
        logger.error(f"gNMI subscription error: {e}")
        raise


# Note: For Phase 2, we'll add a one-shot poll function for Cacti integration:
# def collect_metrics_once(target, port, paths, credentials, ...) -> Dict[str, Any]:
#     """Collect metrics once and return results (for Cacti poller)."""
#     pass

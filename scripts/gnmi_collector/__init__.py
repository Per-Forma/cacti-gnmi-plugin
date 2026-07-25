"""
gNMI Collector Package

This package provides utilities for collecting gNMI telemetry data and storing
it in RRD files for use with Cacti.
"""

from .rrd import rrd_file_exists, create_rrd, update_rrd, store_results
from .transforms import octets_to_bits, apply_transform, apply_transforms

__all__ = [
    # RRD operations
    'rrd_file_exists',
    'create_rrd',
    'update_rrd',
    'store_results',
    # Transform operations
    'octets_to_bits',
    'apply_transform',
    'apply_transforms',
]

# Subscriber module will be added in a future commit
# from .subscriber import subscribe_and_collect

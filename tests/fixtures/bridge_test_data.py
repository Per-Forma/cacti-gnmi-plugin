"""
Test data fixtures for bridge tests.

Storage shape: instance-keyed metric_groups (see docs/daemon_storage_format.md).
The pre-fix flat shape and the legacy pygnmi update-format were removed when
the daemon stopped writing them.
"""

from datetime import datetime, timedelta, timezone


def _now_iso():
    return datetime.now(timezone.utc).isoformat()


def get_valid_daemon_storage(instance: str = "ettp-40"):
    """Single-instance daemon storage with 4 traffic metrics."""
    return {
        "device_id": 1,
        "hostname": "192.0.2.10",
        "last_update": _now_iso(),
        "daemon_status": "connected",
        "connection_uptime": 3600.5,
        "subscription_start": "2025-10-18T10:00:00.000000+00:00",
        "error_count": 0,
        "last_error": None,
        "unmatched_instance_count": 0,
        "metric_groups": {
            instance: {
                "in_octets": 1011655537381,
                "out_octets": 2334094681126,
                "in_pkts": 1489233864,
                "out_pkts": 2319763240,
            }
        },
        "samples_history": {instance: []},
    }


def get_ciena_35_metrics(instance: str = "ettp-40"):
    """Full 35-metric Ciena dataset for one instance."""
    return {
        "device_id": 1,
        "hostname": "192.0.2.10",
        "last_update": _now_iso(),
        "daemon_status": "connected",
        "connection_uptime": 3600.5,
        "subscription_start": "2025-10-18T10:00:00.000000+00:00",
        "error_count": 0,
        "last_error": None,
        "unmatched_instance_count": 0,
        "metric_groups": {
            instance: {
                # Traffic
                "in_octets": 1011655537381,
                "out_octets": 2334094681126,
                "in_pkts": 1489233864,
                "out_pkts": 2319763240,
                # Errors
                "in_errors": 0,
                "out_errors": 0,
                "in_crc_error_pkts": 0,
                "in_jabber_pkts": 0,
                "in_oversize_pkts": 0,
                "in_undersize_pkts": 0,
                # Discards
                "in_discards": 386328,
                "in_dropped_pkts": 386328,
                "in_discards_octets": 65445049,
                "in_dropped_octets": 65445049,
                # Multicast / unicast / broadcast
                "in_broadcast_pkts": 143,
                "in_multicast_pkts": 1063161,
                "in_unicast_pkts": 1488170560,
                "out_broadcast_pkts": 0,
                "out_multicast_pkts": 0,
                "out_unicast_pkts": 2319763240,
                # Distribution
                "in_64_octet_pkts": 288509494,
                "in_65_to_127_octet_pkts": 439718750,
                "in_128_to_255_octet_pkts": 61648669,
                "in_256_to_511_octet_pkts": 34815854,
                "in_512_to_1023_octet_pkts": 53621858,
                "in_1024_to_1518_octet_pkts": 610533911,
                "in_1519_to_2047_octet_pkts": 0,
                "in_2048_to_4095_octet_pkts": 0,
                "in_4096_to_9216_octet_pkts": 385328,
                "out_1519_to_2047_octet_pkts": 0,
                "out_2048_to_4095_octet_pkts": 0,
                "out_4096_to_9216_octet_pkts": 0,
                # Other
                "name": '"40"',
                "link_flap_events": 0,
                "last_clear": "2024-01-15T08:30:00.000000Z",
            }
        },
        "samples_history": {instance: []},
    }


def get_multi_instance_storage():
    """
    Storage with two co-resident subscriptions on one device.

    Models the bug-fix scenario: ettp-40 and ettp-34 each get their own
    metric_groups entry and their own samples_history list. Counter
    magnitudes are intentionally distinct (~13.7T vs ~15.2T) so tests can
    assert separation by value.
    """
    now = _now_iso()
    return {
        "device_id": 1124,
        "hostname": "192.0.2.10",
        "last_update": now,
        "daemon_status": "connected",
        "connection_uptime": 4300.0,
        "subscription_start": "2026-05-19T17:40:23.000000+00:00",
        "error_count": 0,
        "last_error": None,
        "unmatched_instance_count": 0,
        "metric_groups": {
            "ettp-40": {
                "in_octets": 13723662856271,
                "out_octets": 35717177925559,
                "in_errors": 0,
                "out_errors": 0,
                "in_crc_error_pkts": 0,
                "timestamp": now,
            },
            "ettp-34": {
                "in_octets": 15221693546136,
                "out_octets": 17166064145066,
                "timestamp": now,
            },
        },
        "samples_history": {
            "ettp-40": [
                {"epoch": 1779216777, "timestamp": now, "in_octets": 13723662856271, "out_octets": 35717177925559},
                {"epoch": 1779216782, "timestamp": now, "in_octets": 13723664630321, "out_octets": 35717193799760},
            ],
            "ettp-34": [
                {"epoch": 1779216777, "timestamp": now, "in_octets": 15221693546136, "out_octets": 17166064145066},
                {"epoch": 1779216782, "timestamp": now, "in_octets": 15221703486077, "out_octets": 17166079123012},
            ],
        },
    }


def get_stale_storage(instance: str = "ettp-40"):
    """Storage with an old last_update timestamp."""
    old_time = datetime.now(timezone.utc) - timedelta(seconds=120)
    return {
        "device_id": 1,
        "hostname": "192.0.2.10",
        "last_update": old_time.isoformat(),
        "daemon_status": "connected",
        "connection_uptime": 3600.5,
        "subscription_start": "2025-10-18T10:00:00.000000+00:00",
        "error_count": 0,
        "last_error": None,
        "unmatched_instance_count": 0,
        "metric_groups": {
            instance: {
                "in_octets": 1011655537381,
                "out_octets": 2334094681126,
            }
        },
        "samples_history": {instance: []},
    }


def get_disconnected_daemon_storage():
    """Storage with disconnected daemon status."""
    return {
        "device_id": 1,
        "hostname": "192.0.2.10",
        "last_update": _now_iso(),
        "daemon_status": "disconnected",
        "connection_uptime": 0,
        "subscription_start": None,
        "error_count": 5,
        "last_error": "Connection timeout",
        "unmatched_instance_count": 0,
        "metric_groups": {},
        "samples_history": {},
    }


def get_error_daemon_storage():
    """Storage with error daemon status."""
    return {
        "device_id": 1,
        "hostname": "192.0.2.10",
        "last_update": _now_iso(),
        "daemon_status": "error",
        "connection_uptime": 0,
        "subscription_start": None,
        "error_count": 10,
        "last_error": "SSL/TLS handshake failure",
        "unmatched_instance_count": 0,
        "metric_groups": {},
        "samples_history": {},
    }

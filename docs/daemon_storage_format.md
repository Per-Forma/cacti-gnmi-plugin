# Daemon Storage Format Specification

**Version:** 1.0
**Phase:** 2.5 - Daemon Infrastructure
**Date:** October 9, 2025

---

## Overview

This document defines the JSON storage format used for communication between gNMI daemons and the Cacti poller bridge. Each daemon writes metrics to a JSON file that the poller bridge reads during Cacti's polling cycle.

---

## Design Principles

1. **Grouped Metrics:** Organize related metrics into logical groups
2. **Instance Support:** Handle multiple instances (e.g., multiple interfaces)
3. **Timestamp Tracking:** Enable staleness detection
4. **Self-Describing:** Include metadata for troubleshooting
5. **Atomic Writes:** Use temp file + rename for atomic updates

---

## File Naming Convention

**Format:** `device_{device_id}.json`

**Location:** `<cacti_base>/plugins/gnmi/runtime/storage/` by default. Set
`GNMI_RUNTIME_DIR` to change the runtime root, or pass `--storage-dir` for
manual script/testing use.

**Examples:**
- `/var/www/html/cacti/plugins/gnmi/runtime/storage/device_1.json` - Device ID 1
- `/var/www/html/cacti/plugins/gnmi/runtime/storage/device_42.json` - Device ID 42

**Permissions:** non-world-readable (`rw-r-----` / 0640), owned by the Cacti
poller/daemon user. The runtime directory tree is created by plugin install.

---

## JSON Structure

### Top-Level Schema (current implementation)

```json
{
  "device_id": 1124,
  "hostname": "192.0.2.10",
  "last_update": "2026-05-19T18:52:07.819256+00:00",
  "daemon_status": "connected",
  "connection_uptime": 4304.5,
  "subscription_start": "2026-05-19T17:40:23.243412+00:00",
  "error_count": 0,
  "last_error": null,
  "unmatched_instance_count": 0,
  "metric_groups": {
    "ettp-40": { "in_octets": 13723662856271, "out_octets": 35717177925559 },
    "ettp-34": { "in_octets": 15221693546136, "out_octets": 17166064145066 }
  },
  "samples_history": {
    "ettp-40": [ { "epoch": 1779216777, "timestamp": "...", "in_octets": 13723662856271, "out_octets": 35717177925559 }, ... ],
    "ettp-34": [ { "epoch": 1779216777, "timestamp": "...", "in_octets": 15221693546136, "out_octets": 17166064145066 }, ... ]
  }
}
```

**Instance keying is mandatory.** Both `metric_groups` and `samples_history` are
keyed by the subscription's `instance_identifier` (matching the DB's
`plugin_gnmi_subscriptions.instance_identifier`). The daemon never writes a
flat `metric_groups` and the bridge no longer falls back to "all flat metrics"
when an instance lookup misses — that fallback was the source of cross-instance
RRD contamination on devices with more than one subscription.

### Top-Level Fields

| Field | Type | Description |
|-------|------|-------------|
| `device_id` | integer | Cacti device ID (matches database) |
| `hostname` | string | gNMI target hostname/IP |
| `last_update` | string (ISO 8601) | Timestamp of last successful update |
| `daemon_status` | string | Status: `connected`, `connecting`, `disconnected`, `error` |
| `connection_uptime` | float | Seconds since successful connection |
| `subscription_start` | string (ISO 8601) | When current subscription started |
| `error_count` | integer | Cumulative error count since daemon start |
| `last_error` | string or null | Most recent error message |
| `unmatched_instance_count` | integer | gNMI notifications the daemon couldn't route to a known subscription instance. Non-zero indicates path/prefix drift between the device and the daemon's config. |
| `metric_groups` | object | `{instance: {field: value, ...}}` — latest sample per instance, keyed by `instance_identifier`. Field names use Cacti field-name convention (underscores). |
| `samples_history` | object | `{instance: [sample, ...]}` — per-instance retention window (default 15 samples). Each sample is a flat dict with field names plus `epoch` and `timestamp`. Used by the bridge's `--output-history` mode for RRD backfill. |

---

## Metric Group Structure

Each metric group follows this pattern:

### Pattern: Single-Instance Metrics

For metrics with no instances (e.g., system-wide):

```json
"system_resources": {
  "last_update": "2025-10-09T18:30:45.123456Z",
  "status": "ok",
  "metrics": {
    "cpu_utilization": 45.2,
    "memory_used": 8589934592,
    "memory_total": 17179869184,
    "disk_used": 107374182400,
    "uptime_seconds": 864000
  }
}
```

### Pattern: Multi-Instance Metrics

For metrics with instances (e.g., per-interface):

```json
"interface_counters": {
  "last_update": "2025-10-09T18:30:45.123456Z",
  "status": "ok",
  "instances": {
    "eth0": {
      "last_update": "2025-10-09T18:30:45.123456Z",
      "metrics": {
        "in_octets": 123456789012,
        "out_octets": 987654321098,
        "in_errors": 0,
        "out_errors": 0,
        "in_discards": 5,
        "out_discards": 2
      }
    },
    "eth1": {
      "last_update": "2025-10-09T18:30:44.987654Z",
      "metrics": {
        "in_octets": 555666777888,
        "out_octets": 111222333444,
        "in_errors": 3,
        "out_errors": 0,
        "in_discards": 0,
        "out_discards": 0
      }
    }
  }
}
```

### Group-Level Fields

| Field | Type | Description |
|-------|------|-------------|
| `last_update` | string (ISO 8601) | Last update for this group |
| `status` | string | `ok`, `stale`, `error` |
| `instances` | object | Container for instance-specific metrics (multi-instance only) |
| `metrics` | object | Key-value pairs of metric names to values (single-instance only) |

---

## Complete Example

### Example: Single Device with Multiple Metric Groups

```json
{
  "device_id": 1,
  "hostname": "switch01.example.com",
  "last_update": "2025-10-09T18:30:45.123456Z",
  "daemon_status": "connected",
  "connection_uptime": 3600.5,
  "subscription_start": "2025-10-09T17:30:45.000000Z",
  "error_count": 0,
  "last_error": null,
  "metric_groups": {
    "interface_counters": {
      "last_update": "2025-10-09T18:30:45.123456Z",
      "status": "ok",
      "instances": {
        "eth0": {
          "last_update": "2025-10-09T18:30:45.123456Z",
          "metrics": {
            "in_octets": 123456789012,
            "out_octets": 987654321098,
            "in_errors": 0,
            "out_errors": 0,
            "in_discards": 5,
            "out_discards": 2
          }
        },
        "eth1": {
          "last_update": "2025-10-09T18:30:44.987654Z",
          "metrics": {
            "in_octets": 555666777888,
            "out_octets": 111222333444,
            "in_errors": 3,
            "out_errors": 0,
            "in_discards": 0,
            "out_discards": 0
          }
        }
      }
    },
    "interface_state": {
      "last_update": "2025-10-09T18:30:45.123456Z",
      "status": "ok",
      "instances": {
        "eth0": {
          "last_update": "2025-10-09T18:30:45.123456Z",
          "metrics": {
            "admin_status": "UP",
            "oper_status": "UP",
            "speed_mbps": 10000,
            "mtu": 9000,
            "duplex": "FULL"
          }
        },
        "eth1": {
          "last_update": "2025-10-09T18:30:44.987654Z",
          "metrics": {
            "admin_status": "UP",
            "oper_status": "UP",
            "speed_mbps": 10000,
            "mtu": 9000,
            "duplex": "FULL"
          }
        }
      }
    },
    "system_resources": {
      "last_update": "2025-10-09T18:30:45.123456Z",
      "status": "ok",
      "metrics": {
        "cpu_utilization": 45.2,
        "memory_used": 8589934592,
        "memory_total": 17179869184,
        "memory_available": 8589934592,
        "uptime_seconds": 864000
      }
    }
  }
}
```

---

## Metric Naming Conventions

### Field Name Generation

gNMI paths are automatically converted to valid field names:

**Rules:**
1. Extract leaf name from path
2. Replace special characters with underscores
3. Convert to lowercase
4. Remove consecutive underscores
5. Ensure unique within group

**Examples:**

| gNMI Path | Generated Field Name |
|-----------|---------------------|
| `/interfaces/interface[name=eth0]/state/counters/in-octets` | `in_octets` |
| `/interfaces/interface[name=eth0]/state/oper-status` | `oper_status` |
| `/system/memory/state/used` | `used` |
| `/components/component[name=CPU0]/state/temperature` | `temperature` |

### Instance Naming

Instance identifiers come from gNMI path keys:

| gNMI Path | Instance ID |
|-----------|-------------|
| `/interfaces/interface[name=eth0]/...` | `eth0` |
| `/components/component[name=Linecard1]/...` | `Linecard1` |
| `/network-instances/network-instance[name=default]/...` | `default` |

---

## File Operations

### Atomic Write Pattern

To prevent partial reads, use atomic write pattern:

```python
import json
import tempfile
import os

def atomic_write_json(filepath, data):
    """Write JSON data atomically to prevent partial reads."""
    # Create temp file in same directory (same filesystem)
    dir_path = os.path.dirname(filepath)
    with tempfile.NamedTemporaryFile(
        mode='w',
        dir=dir_path,
        delete=False,
        prefix='.tmp_',
        suffix='.json'
    ) as tmp:
        json.dump(data, tmp, indent=2)
        tmp_path = tmp.name

    # Atomic rename
    os.rename(tmp_path, filepath)
    os.chmod(filepath, 0o644)
```

### Read Pattern (Poller Bridge)

```python
import json
from datetime import datetime, timedelta

def read_device_metrics(device_id, staleness_threshold):
    """
    Read device metrics with staleness check.

    Args:
        device_id: Device ID
        staleness_threshold: Max age in seconds (`poller_interval * 2`)

    Returns:
        dict: Metric data or None if stale/missing
    """
    filepath = f"/var/www/html/cacti/plugins/gnmi/runtime/storage/device_{device_id}.json"

    try:
        with open(filepath, 'r') as f:
            data = json.load(f)

        # Check staleness
        last_update = datetime.fromisoformat(data['last_update'].replace('Z', '+00:00'))
        age = (datetime.now(timezone.utc) - last_update).total_seconds()

        if age > staleness_threshold:
            return None  # Data too old

        return data

    except (FileNotFoundError, json.JSONDecodeError, KeyError):
        return None
```

---

## Error States

### Daemon Status Values

| Status | Description | Poller Action |
|--------|-------------|---------------|
| `connected` | Active subscription, receiving data | Read and output metrics |
| `connecting` | Initial connection in progress | Output nothing (U values) |
| `disconnected` | Cleanly disconnected, no errors | Output nothing (U values) |
| `error` | Error state, retrying with backoff | Check last_update staleness |

### Group Status Values

| Status | Description |
|--------|-------------|
| `ok` | Group has fresh data |
| `stale` | No updates received recently |
| `error` | Error collecting this group |

---

## Staleness Detection

The poller bridge determines data staleness:

**Thresholds:**
- **Warning:** older than one configured Cacti poller interval
- **Stale:** older than `poller_interval * 2`

**Actions:**
- **Fresh:** Output metrics normally
- **Warning:** Output metrics with warning log
- **Stale:** Output "U" (undefined) for all values in group

---

## Storage Location Configuration

**Default:** `<cacti_base>/plugins/gnmi/runtime/storage/`

**Requirements:**
- Readable by Cacti poller user (www-data or cactiuser)
- Writable by daemon user
- Persistent across reboots
- Sufficient disk space (~1KB per device)
- Not directly readable over HTTP. Plugin install writes Apache `.htaccess`
  deny files; non-Apache installs need equivalent web-server rules.

**Directory Setup:**
```bash
# Normally handled by plugin install:
sudo mkdir -p /var/www/html/cacti/plugins/gnmi/runtime/{storage,certs,logs}
sudo chown -R daemon-user:cacti-group /var/www/html/cacti/plugins/gnmi/runtime
sudo chmod 750 /var/www/html/cacti/plugins/gnmi/runtime \
  /var/www/html/cacti/plugins/gnmi/runtime/{storage,certs,logs}
```

---

## Versioning

**Schema Version:** Include in future iterations for compatibility

```json
{
  "schema_version": "1.0",
  "device_id": 1,
  ...
}
```

This allows graceful handling of format changes in future phases.

---

## Related Documents

- [Architecture Overview](architecture.md) - Overall system design
- [Poller API](poller_api.md) - How the bridge reads and emits data
- [Metric Groups](metric_groups.md) - Metric group definitions

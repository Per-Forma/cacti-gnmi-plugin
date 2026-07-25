# gNMI Metric Groups Definition

**Phase:** 2.6 - Cacti Data Source Integration
**Date:** October 13, 2025
**Purpose:** Define logical groupings of gNMI metrics for Cacti integration

---

## Overview

Metrics collected from gNMI devices are organized into logical groups. Each group becomes a Cacti Data Input Method and Data Source Template, allowing flexible and efficient data collection.

---

## Grouping Strategy (Option B: Logical Groups)

Metrics are grouped by function/purpose rather than having one large group or many tiny groups. This provides:
- ✅ Balance between efficiency (fewer bridge calls) and flexibility
- ✅ Logical organization for users
- ✅ Reusable templates across devices
- ✅ Clear separation of concerns

---

## Core Metric Groups

### Group 1: `interface_traffic` (Primary - Phase 2 MVP)

**Purpose:** Basic interface throughput metrics
**RRD Type:** COUNTER (cumulative, rate calculated by RRD)
**Priority:** HIGH - Essential for network monitoring

**Metrics (4):**

| gNMI Path      | Cacti Field Name | Description        | Type    | Units   |
|----------------|------------------|--------------------|---------|---------|
| `in-octets`    | `in_octets`      | Inbound bytes      | COUNTER | bytes   |
| `out-octets`   | `out_octets`     | Outbound bytes     | COUNTER | bytes   |
| `in-pkts`      | `in_pkts`        | Inbound packets    | COUNTER | packets |
| `out-pkts`     | `out_pkts`       | Outbound packets   | COUNTER | packets |


**Cacti Graphs:**
- Interface Traffic (bits/sec): in_octets × 8, out_octets × 8
- Interface Packets (pkts/sec): in_pkts, out_pkts

**Use Cases:**
- Monitor interface bandwidth utilization
- Detect traffic spikes
- Capacity planning
- Billing/accounting

---

### Group 2: `interface_errors` (Phase 3)

**Purpose:** Error and invalid packet counters
**RRD Type:** COUNTER
**Priority:** MEDIUM - Important for troubleshooting

**Metrics (6):**

| gNMI Path | Cacti Field Name | Description | Type |
|-----------|------------------|-------------|------|
| `in-errors` | `in_errors` | Inbound errors | COUNTER |
| `out-errors` | `out_errors` | Outbound errors | COUNTER |
| `in-crc-error-pkts` | `in_crc_error_pkts` | CRC errors | COUNTER |
| `in-jabber-pkts` | `in_jabber_pkts` | Jabber packets | COUNTER |
| `in-oversize-pkts` | `in_oversize_pkts` | Oversize packets | COUNTER |
| `in-undersize-pkts` | `in_undersize_pkts` | Undersize packets | COUNTER |

**Cacti Graphs:**
- Interface Errors: in_errors, out_errors
- Invalid Packets: CRC, jabber, oversize, undersize

**Use Cases:**
- Detect link quality issues
- Identify faulty cables/optics
- Troubleshoot packet corruption

---

### Group 3: `interface_discards` (Phase 3)

**Purpose:** Dropped and discarded packet tracking
**RRD Type:** COUNTER
**Priority:** MEDIUM - Important for congestion monitoring

**Metrics (4):**

| gNMI Path | Cacti Field Name | Description | Type |
|-----------|------------------|-------------|------|
| `in-discards` | `in_discards` | Inbound discards | COUNTER |
| `in-dropped-pkts` | `in_dropped_pkts` | Inbound drops | COUNTER |
| `in-discards-octets` | `in_discards_octets` | Discarded bytes | COUNTER |
| `in-dropped-octets` | `in_dropped_octets` | Dropped bytes | COUNTER |

**Cacti Graphs:**
- Interface Discards: Packets and bytes discarded

**Use Cases:**
- Detect congestion/buffer overflows
- Monitor QoS drop rates
- Capacity planning

---

### Group 4: `interface_multicast` (Phase 3)

**Purpose:** Broadcast, multicast, and unicast traffic breakdown
**RRD Type:** COUNTER
**Priority:** LOW - Useful for specific troubleshooting

**Metrics (6):**

| gNMI Path | Cacti Field Name | Description | Type |
|-----------|------------------|-------------|------|
| `in-broadcast-pkts` | `in_broadcast_pkts` | Inbound broadcast | COUNTER |
| `in-multicast-pkts` | `in_multicast_pkts` | Inbound multicast | COUNTER |
| `in-unicast-pkts` | `in_unicast_pkts` | Inbound unicast | COUNTER |
| `out-broadcast-pkts` | `out_broadcast_pkts` | Outbound broadcast | COUNTER |
| `out-multicast-pkts` | `out_multicast_pkts` | Outbound multicast | COUNTER |
| `out-unicast-pkts` | `out_unicast_pkts` | Outbound unicast | COUNTER |

**Cacti Graphs:**
- Traffic Type Distribution: Broadcast/multicast/unicast breakdown

**Use Cases:**
- Detect broadcast storms
- Monitor multicast efficiency
- Analyze traffic patterns

---

### Group 5: `interface_distribution` (Phase 4)

**Purpose:** Packet size distribution histograms
**RRD Type:** COUNTER
**Priority:** LOW - Advanced troubleshooting

**Metrics (16):**

| gNMI Path | Cacti Field Name | Description |
|-----------|------------------|-------------|
| `in-64-octet-pkts` | `in_64_octet_pkts` | 64-byte packets inbound |
| `in-65-to-127-octet-pkts` | `in_65_to_127_octet_pkts` | 65-127 byte packets |
| `in-128-to-255-octet-pkts` | `in_128_to_255_octet_pkts` | 128-255 byte packets |
| `in-256-to-511-octet-pkts` | `in_256_to_511_octet_pkts` | 256-511 byte packets |
| `in-512-to-1023-octet-pkts` | `in_512_to_1023_octet_pkts` | 512-1023 byte packets |
| `in-1024-to-1518-octet-pkts` | `in_1024_to_1518_octet_pkts` | 1024-1518 byte packets |
| `in-1519-to-2047-octet-pkts` | `in_1519_to_2047_octet_pkts` | 1519-2047 byte packets |
| `in-2048-to-4095-octet-pkts` | `in_2048_to_4095_octet_pkts` | 2048-4095 byte packets |
| `in-4096-to-9216-octet-pkts` | `in_4096_to_9216_octet_pkts` | 4096-9216 byte packets (jumbo) |
| `out-1519-to-2047-octet-pkts` | `out_1519_to_2047_octet_pkts` | Outbound 1519-2047 |
| `out-2048-to-4095-octet-pkts` | `out_2048_to_4095_octet_pkts` | Outbound 2048-4095 |
| `out-4096-to-9216-octet-pkts` | `out_4096_to_9216_octet_pkts` | Outbound jumbo frames |

**Cacti Graphs:**
- Packet Size Distribution: Histogram of packet sizes

**Use Cases:**
- Analyze traffic patterns
- Detect MTU issues
- Identify jumbo frame usage

---

## Additional Metrics (Informational)

**Not Grouped (Status/Config):**

| gNMI Path | Cacti Field | Type | Notes |
|-----------|-------------|------|-------|
| `name` | `interface_name` | STRING | Interface identifier |
| `link-flap-events` | `link_flap_events` | COUNTER | Link state changes |
| `last-clear` | `last_clear` | GAUGE | Counter reset timestamp |

**Note:** These may be added to `interface_status` group in Phase 3

---

## Field Name Generation Rules

### Transformation Rules

1. **Replace hyphens with underscores:**
   - `in-octets` → `in_octets`
   - `in-crc-error-pkts` → `in_crc_error_pkts`

2. **Lowercase all characters:**
   - Already lowercase in Ciena paths

3. **Remove special characters:**
   - Keep: alphanumeric, underscore
   - Remove: brackets, quotes, spaces

4. **Sanitize for Cacti:**
   - Must start with letter or underscore
   - Max length: 19 characters (RRD DS name limit)
   - Unique within data source

### Examples

| Original gNMI Path | After Transformation | Notes |
|-------------------|----------------------|-------|
| `in-octets` | `in_octets` | Simple replacement |
| `in-64-octet-pkts` | `in_64_octet_pkts` | Numbers allowed |
| `in-1024-to-1518-octet-pkts` | `in_1024_to_1518_octet_pkts` | Complex but valid |
| `out-broadcast-pkts` | `out_broadcast_pkts` | Standard format |

---

## Instance Identifier Extraction

### Strategy

Extract instance identifier from gNMI path prefix to support multiple interfaces.

### Ciena Path Format

**Full Path:**
```
Ciena:cn-if:interface-telemetry-state/interface-counters[interface-type=ettp]/interfaces[if-name=40]/counters
```

**Extraction Logic:**
1. Find `[interface-type=X]` → interface type: "ettp"
2. Find `[if-name=Y]` → interface name: "40"
3. Combine: `ettp-40`

**Code Pattern:**
```python
# Extract interface-type
if 'interface-type=' in path:
    iface_type = extract_between(path, 'interface-type=', ']')
else:
    iface_type = 'unknown'

# Extract if-name
if 'if-name=' in path:
    iface_name = extract_between(path, 'if-name=', ']')
else:
    iface_name = 'default'

# Combine
instance_id = f"{iface_type}-{iface_name}"  # "ettp-40"
```

### OpenConfig Path Format

**Full Path:**
```
/interfaces/interface[name=eth0]/state/counters/in-octets
```

**Extraction Logic:**
1. Find `/interface[name=X]` → interface name: "eth0"
2. Instance ID: `eth0`

**Code Pattern:**
```python
if '/interface[name=' in path:
    instance_id = extract_between(path, '[name=', ']')
else:
    instance_id = 'default'
```

### Fallback Strategy

If no instance identifier found in path:
- Use `"default"` as instance ID
- Log warning
- Suitable for system-wide metrics (CPU, memory, etc.)

### User Override (Phase 3)

Database field `instance_override` allows manual specification:
```sql
ALTER TABLE plugin_gnmi_device_metrics
ADD COLUMN instance_override VARCHAR(50) DEFAULT NULL;
```

If set, use override instead of auto-extracted value.

---

## Phase 2 MVP Scope

### Implement for Phase 2:
- ✅ `interface_traffic` group (4 metrics)
- ✅ Instance extraction for Ciena paths
- ✅ Field name generation
- ✅ Poller bridge script
- ✅ Manual template creation guide

### Defer to Phase 3:
- ⏭️ `interface_errors` group
- ⏭️ `interface_discards` group
- ⏭️ `interface_multicast` group
- ⏭️ `interface_distribution` group
- ⏭️ OpenConfig path support
- ⏭️ Auto-discovery of interfaces
- ⏭️ Instance override in database

---

## Data Source Type Summary

All 35 Ciena metrics are **COUNTER type** (cumulative counters).

**COUNTER Characteristics:**
- Values always increase (or wrap at max)
- RRDtool calculates rate automatically (value/second)
- Graph shows rate of change, not absolute value
- Suitable for: octets, packets, errors, discards

**No GAUGE metrics** in current Ciena dataset.

**Future:** System metrics (CPU %, memory %) would be GAUGE type.

---

## Metric Group Registry

### Current Groups (Phase 2-4)

| Group Name | Metrics | RRD Type | Phase | Priority |
|------------|---------|----------|-------|----------|
| `interface_traffic` | 4 | COUNTER | 2 | HIGH |
| `interface_errors` | 6 | COUNTER | 3 | MEDIUM |
| `interface_discards` | 4 | COUNTER | 3 | MEDIUM |
| `interface_multicast` | 6 | COUNTER | 3 | LOW |
| `interface_distribution` | 16 | COUNTER | 4 | LOW |

### Future Groups (Phase 4+)

| Group Name | Metrics | RRD Type | Notes |
|------------|---------|----------|-------|
| `interface_state` | TBD | GAUGE | Admin/oper status, speed, duplex |
| `system_cpu` | TBD | GAUGE | CPU utilization |
| `system_memory` | TBD | GAUGE | Memory usage |
| `system_temperature` | TBD | GAUGE | Component temperatures |

---

## Implementation Notes

### Poller Bridge Grouping Logic

The poller bridge (`gnmi_poller_bridge.py`) reads raw metrics from daemon JSON and groups them on-the-fly:

```python
METRIC_GROUPS = {
    'interface_traffic': [
        'in-octets',
        'out-octets',
        'in-pkts',
        'out-pkts'
    ],
    'interface_errors': [
        'in-errors',
        'out-errors',
        'in-crc-error-pkts',
        'in-jabber-pkts',
        'in-oversize-pkts',
        'in-undersize-pkts'
    ],
    # ... more groups
}

def get_group_metrics(raw_metrics, group_name):
    """Extract metrics belonging to specified group."""
    group_paths = METRIC_GROUPS.get(group_name, [])
    return {path: value for path, value in raw_metrics.items() if path in group_paths}
```

### Why Bridge Does Grouping (Not Daemon)

**Advantages:**
- ✅ Daemon stays simple (just collects and stores raw data)
- ✅ Can change grouping without restarting daemons
- ✅ Easy to add new groups
- ✅ Testing easier (daemon unchanged)

**Tradeoffs:**
- ⚠️ Bridge does extra work per call
- ⚠️ Grouping logic in Python, not database

---

## Vendor-Specific Considerations

### Ciena Paths

**Namespace:** `Ciena:cn-if:interface-telemetry-state`

**Path Structure:**
```
Ciena:cn-if:interface-telemetry-state/interface-counters[interface-type=ettp]/interfaces[if-name=40]/counters
```

**Characteristics:**
- Proprietary Ciena namespace
- Complex filtering syntax
- Interface type + name tuple identifies instance

### OpenConfig Paths (Future)

**Namespace:** `/interfaces/interface[name=X]/state/counters`

**Path Structure:**
```
/interfaces/interface[name=eth0]/state/counters/in-octets
```

**Characteristics:**
- Standard OpenConfig model
- Simpler path structure
- Interface name in brackets

**Phase 3:** Add OpenConfig path support alongside Ciena

---

## Testing Data

### Sample Raw Metrics (from Ciena)

```json
{
  "in-octets": 1011655537381,
  "out-octets": 2334094681126,
  "in-pkts": 1489233864,
  "out-pkts": 2319763240,
  "in-errors": 0,
  "out-errors": 0,
  "in-discards": 386328,
  "in-broadcast-pkts": 143,
  "in-multicast-pkts": 1063161,
  "in-64-octet-pkts": 288509494,
  ...
}
```

### Grouped Output (interface_traffic)

**Poller Bridge Output:**
```
in_octets:1011655537381 out_octets:2334094681126 in_pkts:1489233864 out_pkts:2319763240
```

**Cacti Parses:**
- in_octets = 1011655537381
- out_octets = 2334094681126
- in_pkts = 1489233864
- out_pkts = 2319763240

**RRD Stores:**
- Calculates rate: (current - previous) / 10 seconds
- Stores: bits/sec, packets/sec

---

## Documentation Cross-References

- **Storage Format:** `docs/daemon_storage_format.md`
- **Architecture:** `docs/architecture.md`
- **Poller Bridge:** `plugins/gnmi/scripts/gnmi_poller_bridge.py`
- **Cacti Templates:** `docs/cacti_data_input_methods.md`

---

## Future Enhancements (Phase 4)

- [ ] User-defined metric groups via UI
- [ ] Dynamic group creation
- [ ] Vendor-specific group presets (Ciena, Arista, Juniper)
- [ ] Metric filtering (exclude unwanted metrics)
- [ ] Custom field name mappings
- [ ] GAUGE metric support (for system resources)

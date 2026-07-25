# gNMI Plugin Database Schema

## Overview
The gNMI plugin uses a consolidated schema with a single authoritative table for device configuration, eliminating duplication between Phase 2 and Phase 3.1 implementations.

## Schema Version
- **Current Version:** 0.2.0-dev (Phase 2.7)
- **Previous Version:** 0.1.0-dev (Phase 2 + Phase 3.1 fragmented)
- **Created:** Phase 1 - Foundation
- **Consolidated:** Phase 2.7 - Device-Host Integration
- **Last Updated:** October 2025

---

## Table: `plugin_gnmi_devices` (Consolidated Phase 2.7)

Stores all gNMI device configuration with a single source of truth. Merges configuration from Phase 2 daemon management and Phase 3.1 UI form data.

### Columns

| Column | Type | Null | Default | Description |
|--------|------|------|---------|-------------|
| `id` | int(11) unsigned | NO | AUTO_INCREMENT | Primary key - daemon process identifier |
| `host_id` | MEDIUMINT(8) unsigned | NO | - | Foreign key to Cacti `host.id` - which Cacti device owns this config (UNIQUE) |
| `enabled` | tinyint(1) | NO | 1 | Enable/disable gNMI collection |
| `hostname` | varchar(255) | NO | - | gNMI target hostname or IP address (may differ from Cacti device hostname) |
| `port` | int(5) unsigned | NO | 9339 | gNMI port (default 9339) |
| `username` | varchar(255) | NO | - | Authentication username (plaintext Phase 2, encrypted Phase 3+) |
| `password` | text | YES | NULL | Authentication password (plaintext Phase 2, encrypted Phase 3+) |
| `use_tls` | boolean | NO | TRUE | Enable TLS for gNMI connection |
| `ca_cert_path` | varchar(255) | YES | NULL | Path to CA certificate file for server verification |
| `client_key_path` | varchar(255) | YES | NULL | Path to client private key file (for mTLS) |
| `client_cert_path` | varchar(255) | YES | NULL | Path to client certificate file (for mTLS) |
| `tls_override` | varchar(255) | YES | NULL | Override server name for certificate validation (lab environments) |
| `skip_verify` | boolean | NO | FALSE | Skip TLS certificate verification (NOT recommended for production) |
| `collection_interval` | int(5) unsigned | NO | 10 | Collection interval in seconds (must align with Cacti poll interval) |
| `encoding` | varchar(50) | NO | JSON_IETF | gNMI encoding format (JSON_IETF, PROTO, etc) |
| `last_poll_time` | timestamp | YES | NULL | Last successful poll timestamp |
| `last_poll_status` | varchar(50) | NO | never | Status: success, error, never |
| `last_error_message` | text | YES | NULL | Most recent error message if poll failed |
| `created_on` | timestamp | NO | CURRENT_TIMESTAMP | When this configuration was created |
| `modified_on` | timestamp | NO | CURRENT_TIMESTAMP ON UPDATE | Last modification timestamp |

### Indexes
- **PRIMARY KEY:** `id` (daemon process identifier)
- **UNIQUE KEY:** `uk_host_id` (`host_id`) - ensures one gNMI config per Cacti device
- **KEY:** `idx_enabled` (`enabled`) - for filtering active devices in poller
- **KEY:** `idx_created_on` (`created_on`) - for audit trail queries
- **FOREIGN KEY:** `fk_gnmi_host` (host_id → host.id) with CASCADE delete

### Relationships
- `host_id` references Cacti's `host` table with ON DELETE CASCADE
  - When a Cacti host is deleted, the gNMI configuration is automatically deleted
  - Enforces referential integrity

### Security Notes
⚠️ **Phase 2 Warning:** Credentials stored in plaintext.
- Secure database access with proper MySQL user permissions
- Use network isolation for database server
- Phase 3 will implement encryption or credential store integration

### Design Rationale
- **Single Source of Truth:** Consolidated from Phase 2 `plugin_gnmi_devices` and Phase 3.1 `plugin_gnmi_device_settings`
- **Clear Field Names:**
  - `hostname` (not `host` - clarity about which host)
  - `host_id` (not `device_id` - explicit Cacti host reference)
  - `use_tls` (not `tls_enabled` - consistency)
  - `skip_verify` (not `tls_skip_verify` - brevity)
- **Status Fields:** Enable troubleshooting and UI health indicators
- **Enabled Flag:** Allows temporarily disabling collection without deleting configuration
- **Audit Trail:** `created_on` and `modified_on` track configuration changes

### Consolidation from Phase 3.1
The following fields from old `plugin_gnmi_device_settings` table are now in the consolidated table:
- `ca_cert_path`, `client_key_path`, `client_cert_path`, `tls_override`
- `collection_interval`
- `created_on`, `modified_on` (audit trail)

---

## Table: `plugin_gnmi_metrics`

Defines reusable metric templates with gNMI paths, transforms, and RRD parameters.

### Columns

| Column | Type | Null | Default | Description |
|--------|------|------|---------|-------------|
| `id` | int(11) unsigned | NO | AUTO_INCREMENT | Primary key |
| `name` | varchar(255) | NO | - | Human-readable metric name (unique) |
| `description` | text | YES | NULL | Detailed description of what this metric measures |
| `gnmi_path` | text | NO | - | gNMI subscription path (e.g., `/interfaces/interface[name=*]/state/counters/in-octets`) |
| `sample_interval` | int(11) unsigned | NO | 300 | Polling interval in seconds |
| `transform` | varchar(100) | YES | 'none' | Transform function: none, octets_to_bits, rate, etc |
| `rrd_ds_type` | varchar(20) | NO | 'GAUGE' | RRD data source type: GAUGE, COUNTER, DERIVE, ABSOLUTE |
| `rrd_heartbeat` | int(11) unsigned | NO | 600 | RRD heartbeat (seconds) |
| `rrd_min` | varchar(20) | YES | '0' | RRD minimum value (U for unknown) |
| `rrd_max` | varchar(20) | YES | 'U' | RRD maximum value (U for unknown) |

### Indexes
- **PRIMARY KEY:** `id`
- **UNIQUE KEY:** `name` (ensures unique metric template names)
- **KEY:** `sample_interval` (for grouping metrics by polling frequency)

### Design Rationale
- Template approach allows reusing metric definitions across multiple devices
- Separate transform and RRD config enables flexibility without Python code changes
- `gnmi_path` stored as TEXT to accommodate complex path expressions

### Common Metric Examples

**Interface In-Octets:**
```
name: interface_in_octets
gnmi_path: /interfaces/interface[name=*]/state/counters/in-octets
transform: octets_to_bits
rrd_ds_type: COUNTER
```

**CPU Utilization:**
```
name: cpu_utilization
gnmi_path: /components/component[name=CPU]/state/utilization
transform: none
rrd_ds_type: GAUGE
rrd_min: 0
rrd_max: 100
```

---

## Table: `plugin_gnmi_device_metrics`

Links devices to metrics and tracks RRD storage paths.

### Columns

| Column | Type | Null | Default | Description |
|--------|------|------|---------|-------------|
| `id` | int(11) unsigned | NO | AUTO_INCREMENT | Primary key |
| `device_id` | int(11) unsigned | NO | - | Reference to `plugin_gnmi_devices.id` |
| `metric_id` | int(11) unsigned | NO | - | Reference to `plugin_gnmi_metrics.id` |
| `enabled` | tinyint(1) | NO | 1 | Enable/disable this metric for this device |
| `rrd_path` | varchar(512) | YES | NULL | Path to RRD file for this device-metric pair |
| `created_at` | timestamp | NO | CURRENT_TIMESTAMP | When this assignment was created |

### Indexes
- **PRIMARY KEY:** `id`
- **UNIQUE KEY:** `device_metric` (device_id, metric_id) - prevents duplicate assignments
- **KEY:** `device_id` (for querying all metrics for a device)
- **KEY:** `metric_id` (for finding all devices using a metric)
- **KEY:** `enabled` (for filtering active assignments)

### Relationships
- `device_id` → `plugin_gnmi_devices.id`
- `metric_id` → `plugin_gnmi_metrics.id`

### Design Rationale
- Many-to-many relationship: one device can have many metrics, one metric can be used by many devices
- `enabled` flag allows temporarily disabling individual metrics without deleting assignment
- `rrd_path` tracks where data is stored per device-metric pair (path varies by device)
- `created_at` helps with auditing and troubleshooting

---

## Upgrade Strategy

### Version 0.1.0-dev → 0.2.0 (Future)
Schema migrations handled in `plugin_gnmi_upgrade()` function in `setup.php`:

```php
function plugin_gnmi_upgrade() {
    $stored_version = db_fetch_cell("SELECT version FROM plugin_config WHERE directory='gnmi'");

    if (version_compare($stored_version, '0.2.0', '<')) {
        // Example: Add new field
        db_execute('ALTER TABLE plugin_gnmi_devices ADD COLUMN tls_cert_path varchar(512) DEFAULT NULL');
        cacti_log('gNMI Plugin: Upgraded schema to 0.2.0', false, 'INSTALL');
    }

    return true;
}
```

### Rollback Procedure
If upgrade fails:
1. Restore database from backup
2. Reinstall previous plugin version
3. Re-enable plugin in Cacti Plugin Manager

**Best Practice:** Always backup database before upgrading plugin

---

## Data Flow

### Initial Setup
1. Admin adds device to Cacti (normal process)
2. Admin configures gNMI settings for device → `plugin_gnmi_devices`
3. Admin creates/assigns metric templates → `plugin_gnmi_metrics`, `plugin_gnmi_device_metrics`

### During Poller Cycle
1. Poller queries enabled devices from `plugin_gnmi_devices`
2. For each device, queries assigned metrics from `plugin_gnmi_device_metrics` JOIN `plugin_gnmi_metrics`
3. Builds JSON payload and invokes Python collector
4. Updates `last_poll_time`, `last_poll_status` in `plugin_gnmi_devices`
5. Python collector writes to RRD files (paths from `plugin_gnmi_device_metrics.rrd_path`)

---

## Maintenance

### Regular Tasks
- Monitor `last_error_message` field for recurring issues
- Archive old error logs (future enhancement)
- Validate RRD file paths match `rrd_path` entries

### Performance Considerations
- Indexes on `enabled` fields optimize poller queries
- `device_metric` unique key prevents duplicate work
- Consider partitioning `plugin_gnmi_devices` if managing 1000+ devices (Phase 4)

---

---

## Cacti Data Source Profile Schema Integration

### Overview
The gNMI plugin integrates with Cacti's native data source profile system to create high-frequency (10-second) collection profiles. Understanding this schema is critical for proper plugin installation and RRD management.

### Core Tables

#### `data_source_profiles`
Main profile table defining collection intervals and heartbeat settings.

| Column | Type | Description |
|--------|------|-------------|
| `id` | mediumint(8) unsigned | Primary key |
| `hash` | varchar(32) | Profile hash identifier |
| `name` | varchar(255) | Human-readable profile name |
| `step` | int(10) unsigned | Collection interval in seconds; Cacti default is 300 |
| `heartbeat` | int(10) unsigned | Timeout threshold in seconds (default: 600) |
| `x_files_factor` | double | RRD X-files factor (default: 0.5) |
| `default` | char(2) | Whether this is the default profile |

#### `data_source_profiles_cf`
Many-to-many relationship table linking profiles to consolidation functions.

| Column | Type | Description |
|--------|------|-------------|
| `data_source_profile_id` | mediumint(8) unsigned | Foreign key to profiles table |
| `consolidation_function_id` | smallint(5) unsigned | Consolidation function ID (1-4) |

#### `data_source_profiles_rra`
RRA (Round Robin Archive) definitions per profile.

| Column | Type | Description |
|--------|------|-------------|
| `id` | mediumint(8) unsigned | Primary key |
| `data_source_profile_id` | mediumint(8) unsigned | Foreign key to profiles table |
| `name` | varchar(255) | RRA description (e.g., "Daily (5 Minute Average)") |
| `steps` | int(10) unsigned | Consolidation steps (default: 1) |
| `rows` | int(10) unsigned | Number of data points to store |
| `timespan` | int(10) unsigned | Time span in seconds |

### Consolidation Functions

**Critical Discovery:** Cacti does NOT use a separate `consolidation_functions` table. Instead, consolidation functions are hardcoded in `/var/www/html/cacti/include/global_arrays.php`:

```php
$consolidation_functions = array(1 =>
    'AVERAGE',    // ID = 1
    'MIN',        // ID = 2
    'MAX',        // ID = 3
    'LAST'        // ID = 4
);
```

### gNMI Plugin Integration

#### Profile Creation Process
1. **Check for existing profile:**
   ```sql
   SELECT id FROM data_source_profiles
   WHERE name = 'gNMI - 10 Second Collection'
   ```

2. **Create profile if missing:**
   ```sql
   INSERT INTO data_source_profiles (name, step, heartbeat)
   VALUES ('gNMI - 10 Second Collection', 10, 30)
   ```

3. **Link consolidation functions:**
   ```sql
   INSERT INTO data_source_profiles_cf (data_source_profile_id, consolidation_function_id)
   VALUES (profile_id, 1), (profile_id, 2), (profile_id, 3), (profile_id, 4)
   ```

4. **Create RRA definitions:**
   ```sql
   INSERT INTO data_source_profiles_rra (data_source_profile_id, name, steps, rows, timespan)
   VALUES
   (profile_id, '6 Hours (10 Second Average)', 1, 2160, 21600),
   (profile_id, '1 Day (1 Minute Average)', 6, 1440, 86400),
   (profile_id, '1 Week (10 Minute Average)', 60, 1680, 604800),
   (profile_id, '31 Days (1 Hour Average)', 360, 744, 2678400)
   ```

#### Common Profile Examples

**Standard 5-Minute Profile (ID: 1):**
- Step: 300 seconds
- Heartbeat: 600 seconds
- RRAs: Daily (5min), Weekly (30min), Monthly (2hr), Yearly (1day)

**30-Second Profile (ID: 2):**
- Step: 30 seconds
- Heartbeat: 1200 seconds
- RRAs: Similar structure with 30-second base resolution

**gNMI 10-Second Profile (Custom):**
- Step: 10 seconds
- Heartbeat: `poller_interval * 2` (20 seconds when Cacti polling is configured for 10s; 600 seconds when the plugin falls back to the 300s default)
- RRAs: 6hr (10s), 1day (1min), 1week (10min), 31days (1hr)

### Setup.php Implementation Notes

**❌ INCORRECT (Previous Implementation):**
```php
// This fails because consolidation_functions table doesn't exist
$cf_id = db_fetch_cell("
    SELECT id FROM consolidation_functions
    WHERE name = '$cf'
");
```

**✅ CORRECT (Fixed Implementation):**
```php
// Use hardcoded consolidation function IDs directly
$consolidation_functions = array(
    'AVERAGE' => 1,
    'MIN' => 2,
    'MAX' => 3,
    'LAST' => 4
);

foreach ($consolidation_functions as $cf_name => $cf_id) {
    db_execute("
        INSERT INTO data_source_profiles_cf (data_source_profile_id, consolidation_function_id)
        VALUES ($profile_id, $cf_id)
    ");
}
```

### Troubleshooting

#### Common Issues
1. **"Failed to create 10-second data source profile"**
   - Cause: Querying non-existent `consolidation_functions` table
   - Fix: Use hardcoded consolidation function IDs (1-4)

2. **Profile exists but RRAs missing**
   - Cause: Failed RRA creation after profile creation
   - Fix: Check `data_source_profiles_rra` table for entries

3. **Step mismatch errors**
   - Cause: RRD files created with different step than profile
   - Fix: Delete RRD files, recreate with correct step

#### Verification Queries
```sql
-- Check if gNMI profile exists
SELECT * FROM data_source_profiles WHERE name LIKE '%gNMI%';

-- Verify consolidation function links
SELECT p.name, cf.consolidation_function_id
FROM data_source_profiles p
JOIN data_source_profiles_cf cf ON p.id = cf.data_source_profile_id
WHERE p.name LIKE '%gNMI%';

-- Check RRA definitions
SELECT p.name, r.name as rra_name, r.steps, r.rows, r.timespan
FROM data_source_profiles p
JOIN data_source_profiles_rra r ON p.id = r.data_source_profile_id
WHERE p.name LIKE '%gNMI%';
```

---

## Related Documentation
- [setup.php](../setup.php) - Table creation SQL
- [architecture.md](architecture.md) - Overall plugin design

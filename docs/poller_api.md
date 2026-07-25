# Poller Bridge API

This document describes the calling convention for `plugins/gnmi/scripts/gnmi_poller_bridge.py`, the bridge between daemon JSON storage and Cacti data source updates.

## Invocation

```bash
gnmi_poller_bridge.py \
  --device-id <device_id> \
  --local-data-id <local_data_id> \
  [--storage-dir <path>] \
  [--output-history] \
  [--staleness-threshold <seconds>] \
  [--debug] \
  [--cacti-config <path>]
```

Required arguments:

- `--device-id`: `plugin_gnmi_devices.id`; selects the daemon storage file `device_<id>.json`.
- `--local-data-id`: Cacti `data_local.id`; selects the data source whose configured RRD fields should be emitted.

Optional arguments:

- `--storage-dir`: daemon JSON storage directory. PHP passes the plugin storage path from `gnmi_get_storage_dir()`.
- `--output-history`: emit timestamped sample history lines for RRD backfill instead of only the latest sample.
- `--staleness-threshold`: maximum acceptable data age in seconds. PHP passes `gnmi_get_poller_interval() * 2`.
- `--debug`: enable debug logging on stderr.
- `--cacti-config`: path to Cacti `include/config.php` for database credentials.

## Metric Selection

The bridge is database-driven. It does not accept `--group` or `--instance` arguments in the current interface.

For each `--local-data-id`, the bridge queries Cacti/plugin tables to find the RRD field names expected by that data source, then filters daemon storage to those fields. This keeps daemon output, Cacti data source metadata, and RRD update order aligned.

## Output Modes

Latest-sample mode prints one Cacti-compatible line to stdout:

```text
field1:value1 field2:value2 field3:value3
```

History mode (`--output-history`) prints one line per timestamped sample from daemon `samples_history`, sorted chronologically:

```text
1700000000 field1:value1 field2:value2
1700000005 field1:value3 field2:value4
```

The PHP poller path uses history mode for RRD backfill so Cacti can update one RRD with multiple timestamped samples in one poll cycle.

## Staleness

The authoritative formula is:

```text
staleness_threshold = poller_interval * 2
```

PHP computes this with:

```php
gnmi_get_poller_interval() * 2
```

At 10s, 60s, and 300s poller intervals, the bridge threshold is 20s, 120s, and 600s respectively. Data older than the supplied threshold is considered stale.

## Exit Codes

- `0`: success; data was emitted.
- `1`: error; no usable data was emitted.
- `2`: stale data; no data was emitted so Cacti/RRD can record unknown values.

All diagnostics are written to stderr so stdout remains parseable by Cacti.

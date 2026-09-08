# gNMI Daemon Documentation

**Status:** Public-beta operator reference

---

## Overview

The gNMI daemon maintains persistent gNMI subscriptions for high-frequency telemetry collection. It runs as a long-lived background process per device, continuously streaming data and writing to JSON storage for Cacti's poller to read.

---

## Architecture

```
gNMI Device (5s) → Daemon (buffer) → JSON Storage (10s) → Poller Bridge → Cacti → RRD
```

### Key Features

- **Persistent Subscriptions:** Maintains 24/7 connection to gNMI device
- **Sample Buffering:** Collects samples every 5 seconds, buffers 2 samples
- **10-Second Writes:** Writes to JSON storage every 10 seconds (Cacti poll aligned)
- **Exponential Backoff:** Auto-reconnect with 1s, 2s, 4s, 8s... max 60s delays
- **Health Monitoring:** PID files and health check for poller hook
- **Graceful Shutdown:** Handles SIGTERM/SIGINT, flushes buffers
- **Config Reload:** Detects changes to the generated per-device configuration

---

## Components

### 1. `gnmi_daemon.py` - Main Daemon

The core daemon process that manages gNMI subscriptions.

**Features:**
- `ExponentialBackoff` class for connection retries
- `SampleBuffer` class for buffering 5-second samples
- `GNMIDaemon` class for main daemon logic
- Atomic JSON writes (temp file + rename pattern)
- PID file management
- Health check static method

**Usage:**
```bash
# Run daemon with a protected configuration file
./gnmi_daemon.py --device-id 1 --config-file test_daemon_config.json

# Run with custom storage directory
./gnmi_daemon.py --device-id 1 --config-file config.json --storage-dir /tmp/gnmi

# Enable debug logging
./gnmi_daemon.py --device-id 1 --config-file config.json --debug

# Check health
./gnmi_daemon.py --device-id 1 --check-health
```

**Configuration File Format:**
```json
{
  "host": "192.0.2.10",
  "port": 57400,
  "username": "telemetry-example",
  "password": "replace-with-test-password",
  "use_tls": true,
  "insecure": false,
  "skip_verify": true,
  "tls_cipher_policy": "default",
  "compatibility_mode": "standard",
  "encoding": "JSON_IETF",
  "subscription_mode": "STREAM",
  "sample_interval": 5
}
```

`compatibility_mode` defaults to `standard`. Use `ciena_saos10` only for an
affected Ciena SAOS 10.x device; it bypasses Capabilities and tolerates the
device's `None` subscription responses. `JSON_IETF` is sent as `json_ietf`.

`tls_cipher_policy` is independent of vendor protocol compatibility. It
defaults to `default`; select `legacy_compatibility` only for a target whose
TLS service cannot negotiate gRPC's defaults. The daemon controller applies
that policy only to the selected device process.

### 2. `gnmi_daemon_ctl.py` - Control Script

Management script for daemon lifecycle operations. Used by Cacti poller hook.

**Usage:**
```bash
# Start daemon
./gnmi_daemon_ctl.py start --device-id 1 --config-file config.json

# Start with inline config
./gnmi_daemon_ctl.py start --device-id 1 --config '{"host":"192.0.2.10", ...}'

# Stop daemon (graceful with 10s timeout)
./gnmi_daemon_ctl.py stop --device-id 1

# Stop with longer timeout
./gnmi_daemon_ctl.py stop --device-id 1 --timeout 30

# Restart daemon
./gnmi_daemon_ctl.py restart --device-id 1 --config-file config.json

# Check status
./gnmi_daemon_ctl.py status --device-id 1

# Verbose status with health check
./gnmi_daemon_ctl.py status --device-id 1 --verbose

# Health check only
./gnmi_daemon_ctl.py health --device-id 1
```

**Exit Codes:**
- `0`: Success
- `1`: Error
- `2`: Daemon not running (status/health commands)

---

## Storage Format

### Directory Structure

```
<cacti_root>/plugins/gnmi/runtime/
├── storage/
│   ├── device_1.json          # Metric data for device 1
│   ├── device_1.pid           # PID file for device 1
│   ├── device_1_config.json   # Config file for device 1
│   ├── device_2.json          # Metric data for device 2
│   └── ...
├── logs/
│   └── device_1.log           # Log file for device 1
└── certs/
    └── ...                    # Operator-installed TLS material
```

### JSON Storage Format

See `docs/daemon_storage_format.md` for complete specification.

**Example:**
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
          "metrics": {
            "in_octets": 123456789012,
            "out_octets": 987654321098
          }
        }
      }
    }
  }
}
```

---

## Testing

### Test Daemon Locally

```bash
# 1. Navigate to scripts directory
cd /path/to/cacti-plugin/plugins/gnmi/scripts

# 2. Activate venv
source ../venv/bin/activate

# 3. Test with example config
./gnmi_daemon.py --device-id 999 --config-file test_daemon_config.json --storage-dir /tmp/gnmi_test --debug
```

### Test Control Script

```bash
# Start daemon in background
./gnmi_daemon_ctl.py start --device-id 999 --config-file test_daemon_config.json --storage-dir /tmp/gnmi_test

# Check status
./gnmi_daemon_ctl.py status --device-id 999 --storage-dir /tmp/gnmi_test --verbose

# Watch JSON output
watch -n 1 cat /tmp/gnmi_test/device_999.json

# Stop daemon
./gnmi_daemon_ctl.py stop --device-id 999 --storage-dir /tmp/gnmi_test
```

### Manual Testing Checklist

- [ ] Daemon starts successfully
- [ ] PID file created
- [ ] Connects to gNMI device
- [ ] Samples received every 5 seconds
- [ ] JSON written every 10 seconds
- [ ] Health check returns correct status
- [ ] Graceful shutdown on SIGTERM
- [ ] Auto-reconnect after network interruption
- [ ] Buffered samples flushed on shutdown
- [ ] Stale PID files cleaned up

---

## Integration with Cacti

### Poller hook usage

The Cacti poller hook uses the control script to maintain enabled devices.
The following is a simplified illustration; the shipped implementation also
applies locking, path resolution, configuration generation, and error handling:

```php
function plugin_gnmi_poller_bottom() {
    // Get all enabled devices
    $devices = db_fetch_assoc("SELECT * FROM plugin_gnmi_devices WHERE enabled=1");

    foreach ($devices as $device) {
        $device_id = $device['id'];

        // Check daemon health
        $health_cmd = escapeshellcmd("/path/to/gnmi_daemon_ctl.py health --device-id $device_id");
        $health_json = shell_exec($health_cmd);
        $health = json_decode($health_json, true);

        // Start if not running
        if (!$health['running']) {
            $config_json = json_encode([
                'host' => $device['host'],
                'port' => $device['port'],
                'username' => $device['username'],
                'password' => $device['password'],
                'tls_skip_verify' => $device['tls_skip_verify']
            ]);

            $start_cmd = escapeshellcmd("/path/to/gnmi_daemon_ctl.py start --device-id $device_id --config " . escapeshellarg($config_json));
            shell_exec($start_cmd);
        }
    }
}
```

---

## Troubleshooting

### Daemon Won't Start

**Check:**
```bash
# View daemon log
tail -f <cacti_root>/plugins/gnmi/runtime/logs/device_1.log

# Check if port is accessible
telnet <host> 57400

# Test gNMI connection using a protected temporary JSON configuration
./venv/bin/python3 scripts/gnmi_connection_test.py /path/to/test-config.json
```

### Daemon Crashes Repeatedly

**Check:**
- Network connectivity to gNMI device
- Authentication credentials
- gNMI paths (some devices don't support certain paths)
- Log file for Python tracebacks

### JSON Not Being Written

**Check:**
- Daemon is actually running: `./gnmi_daemon_ctl.py status --device-id 1`
- Runtime storage directory permissions
- Disk space
- Check log for write errors

### Stale Data

**Check:**
- `last_update` timestamp in JSON (should be < 30 seconds old)
- Daemon status: `cat <cacti_root>/plugins/gnmi/runtime/storage/device_1.json | grep daemon_status`
- Network interruptions causing reconnection loops

---

## Performance

### Resource Usage (Estimated)

**Per Daemon:**
- CPU: ~1-5% (mostly idle, spikes on sample processing)
- Memory: ~20-50 MB (Python + pygnmi + buffers)
- Disk I/O: 1 write every 10 seconds (~1KB per write)
- Network: Continuous gNMI stream (~1-10 KB/s depending on metrics)

**100 Devices:**
- CPU: ~5-10% total
- Memory: ~2-5 GB total
- Disk I/O: 100 writes/10s = 10 writes/second
- Network: ~100-1000 KB/s total

### Scaling note

The public beta uses one daemon per enabled device. Validate resource use at the intended
device count before broader testing.

---

## Security Considerations

### Current public-beta behavior

- ⚠️ Passwords stored in plaintext in config files
- ⚠️ Config files readable by daemon user
- ⚠️ JSON storage readable by Cacti user

**Mitigations:**
- Secure file permissions (600 for configs, 644 for storage)
- Network isolation (gNMI devices on management network)
- Restrict daemon user permissions

TLS certificate validation is supported when verification is enabled and the
required CA material is installed beneath `runtime/certs/`. Credential
encryption/vaulting and automated credential rotation are not provided.

---

## Potential future enhancements

- [ ] Multi-device daemon consolidation
- [ ] SQLite storage instead of JSON files
- [ ] Advanced aggregation strategies (avg/min/max)
- [ ] Prometheus metrics export
- [ ] Systemd service files

---

## Related Documentation

- **Storage Format:** [`../docs/daemon_storage_format.md`](../docs/daemon_storage_format.md)
- **Architecture:** [`../docs/architecture.md`](../docs/architecture.md)
- **Collector API:** [`README.md`](README.md)

---

## Changelog

### October 9, 2025 - Initial Implementation
- ✅ Core daemon with 5s sampling, 10s writes
- ✅ Exponential backoff reconnection
- ✅ Sample buffering
- ✅ Health check mechanism
- ✅ Graceful shutdown
- ✅ Daemon control script
- ✅ PID file management
- ✅ Atomic JSON writes

Current public-beta validation and known limitations are recorded in the
top-level `RELEASE_NOTES.md` included with the distribution.

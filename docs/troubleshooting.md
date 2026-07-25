# gNMI Plugin Troubleshooting Guide

**Version:** 1.0
**Date:** October 17, 2025
**Phase:** 2.5/2.6 - Daemon & Integration

---

## Quick Diagnostics

### Check Daemon Status

```bash
docker exec cacti_app python3 /var/www/html/cacti/plugins/gnmi/scripts/gnmi_daemon_ctl.py health --device-id 1
```

**Expected Output:**
```json
{
  "running": true,
  "status": "connected",
  "stale": false,
  "age_seconds": 5.2
}
```

### Check Recent Logs

```bash
docker exec cacti_app tail -50 /var/www/html/cacti/plugins/gnmi/runtime/logs/device_1.log
```

Look for:
- ✅ "Subscription established successfully"
- ✅ "Storage updated" every ~10-15 seconds
- ❌ "Connection/subscription failed"
- ❌ "Reconnect attempt"

### Test Bridge Manually

```bash
docker exec cacti_app python3 /var/www/html/cacti/plugins/gnmi/scripts/gnmi_poller_bridge.py \
  --device-id 1 --local-data-id 6
```

**Expected Output:**
```
in_octets:123456789 out_octets:987654321 in_pkts:1234567 out_pkts:9876543
```

---

## Common Issues

### Issue X: Missing Python rrdtool Module

Problem:
- Poller logs: "gNMI: Dependency missing (python3-rrdtool). Skipping daemon management."
- Installer shows a warning banner: "Missing Python rrdtool module"

Diagnosis:
```bash
docker exec cacti_app python3 -c 'import rrdtool' && echo OK || echo MISSING
```

Solution (choose one):
- Docker (running container):
```bash
docker exec -u root cacti_app bash -lc 'apt-get update && apt-get install -y python3-rrdtool'
```
- Docker (image hardening): add to `cacti-docker-compose/Dockerfile`:
```Dockerfile
RUN apt-get update && apt-get install -y --no-install-recommends python3-rrdtool \
    && rm -rf /var/lib/apt/lists/*
```
- Debian/Ubuntu host: `apt install python3-rrdtool`
- RHEL/CentOS host: `yum install python3-rrdtool`

Verification:
1. Reload the plugin page; the banner disappears.
2. Poller logs show normal daemon management; daemons start as expected.

### Issue 0: Plugin Installation Failures

**Symptoms:**
- Plugin installation shows "WARNING - Failed to create 10-second data source profile"
- Plugin appears installed but data source profile missing
- RRD creation fails with step mismatch errors

**Root Cause:**
The `setup.php` script incorrectly queries a non-existent `consolidation_functions` table instead of using hardcoded consolidation function IDs.

**Diagnosis:**
```bash
# Check if gNMI profile exists
docker exec cacti_db mysql -u cacti -pchangeme cacti -e "
SELECT * FROM data_source_profiles WHERE name LIKE '%gNMI%';
"

# Check consolidation function links
docker exec cacti_db mysql -u cacti -pchangeme cacti -e "
SELECT p.name, cf.consolidation_function_id
FROM data_source_profiles p
JOIN data_source_profiles_cf cf ON p.id = cf.data_source_profile_id
WHERE p.name LIKE '%gNMI%';
"

# Check RRA definitions
docker exec cacti_db mysql -u cacti -pchangeme cacti -e "
SELECT p.name, r.name as rra_name, r.steps, r.rows, r.timespan
FROM data_source_profiles p
JOIN data_source_profiles_rra r ON p.id = r.data_source_profile_id
WHERE p.name LIKE '%gNMI%';
"
```

**Solution:**
1. **Fix setup.php script** - Replace consolidation_functions table query with hardcoded IDs
2. **Manual profile creation** (if needed):
   ```sql
   -- Create profile
   INSERT INTO data_source_profiles (name, step, heartbeat)
   VALUES ('gNMI - 10 Second Collection', 10, 30);

   -- Get profile ID
   SET @profile_id = LAST_INSERT_ID();

   -- Link consolidation functions (IDs: 1=AVERAGE, 2=MIN, 3=MAX, 4=LAST)
   INSERT INTO data_source_profiles_cf (data_source_profile_id, consolidation_function_id)
   VALUES (@profile_id, 1), (@profile_id, 2), (@profile_id, 3), (@profile_id, 4);

   -- Create RRA definitions
   INSERT INTO data_source_profiles_rra (data_source_profile_id, name, steps, rows, timespan)
   VALUES
   (@profile_id, '6 Hours (10 Second Average)', 1, 2160, 21600),
   (@profile_id, '1 Day (1 Minute Average)', 6, 1440, 86400),
   (@profile_id, '1 Week (10 Minute Average)', 60, 1680, 604800),
   (@profile_id, '31 Days (1 Hour Average)', 360, 744, 2678400);
   ```

**Prevention:**
- Always test plugin installation in fresh Cacti environment
- Verify data source profile creation during installation
- Check Cacti logs for installation warnings

---

### Issue 1: Spikey/Missing Data in RRD Graphs

**Symptoms:**
- Graphs show spikes or gaps
- Data appears inconsistent
- Missing time periods

**Root Cause:**
Daemon connection failures create data gaps. When connection resumes, counter deltas appear as spikes.

**Diagnosis:**
```bash
# Run continuity checker for 5 minutes
docker exec cacti_app python3 /var/www/html/cacti/plugins/gnmi/scripts/check_data_continuity.py \
  --device-id 1 --duration 300 --threshold 20
```

This will identify gaps > 20 seconds.

**Solution:**
1. **Fix connection issues** - See "Connection Failures" below
2. **Monitor connection stability** - Use daemon monitor
3. **Check device availability** - Ensure device is reachable

**Prevention:**
- Set up connection monitoring
- Configure device-side logging
- Use network path redundancy
- Enable SNMP alerting for device issues

---

### Issue 2: Connection Failures

**Symptoms:**
- Daemon status: "error" or "disconnected"
- Log shows "Connection/subscription failed"
- Many "Reconnect attempt" messages

**Common Causes:**

#### A. SSL/TLS Errors

**Log Message:**
```
ERROR - The SSL certificate cannot be retrieved from ('192.0.2.10', 9339)
ssl.SSLError: [SSL: SSLV3_ALERT_HANDSHAKE_FAILURE]
```

**Solutions:**
1. **Verify certificates exist:**
   ```bash
   docker exec cacti_app ls -la /path/to/certs/
   ```

2. **Check certificate paths in config:**
   ```bash
   docker exec cacti_app cat /tmp/gnmi_config.json
   ```

3. **Verify TLS override is set** (for Ciena devices):
   ```json
   {
     "tls_override": "gnmi-lab.example.invalid",
     "skip_verify": true
   }
   ```

4. **Test certificate manually:**
   ```bash
   openssl s_client -connect 192.0.2.10:9339 -showcerts
   ```

#### B. Network Connectivity

**Log Message:**
```
ERROR - Connection timeout
ERROR - [Errno 111] Connection refused
```

**Solutions:**
1. **Test network connectivity:**
   ```bash
   docker exec cacti_app ping -c 3 192.0.2.10
   docker exec cacti_app telnet 192.0.2.10 9339
   ```

2. **Check firewall rules:**
   - Ensure port 9339 (gNMI) is open
   - Verify no Docker network issues

3. **Verify device gNMI service:**
   - Check device config
   - Ensure gNMI server is running
   - Verify port binding

#### C. Authentication Failures

**Log Message:**
```
ERROR - Authentication failed
ERROR - Invalid credentials
```

**Solutions:**
1. **Verify credentials:**
   Confirm the configured username in the Cacti device form. Do not print the
   password or raw daemon configuration into a terminal transcript.

2. **Test manually with gnmi_get:**
   ```bash
   python3 -m pygnmi.client \
     --target 192.0.2.10:9339 \
     --username telemetry-example \
     --password replace-with-test-password
   ```

---

### Issue 3: Stale Data

**Symptoms:**
- Health check shows `"stale": true`
- Last update is older than `poller_interval * 2`
- Graphs show flat lines or gaps

**Diagnosis:**
```bash
# Check health with details
docker exec cacti_app python3 /var/www/html/cacti/plugins/gnmi/scripts/gnmi_daemon_ctl.py health --device-id 1
```

**Solutions:**

1. **If daemon not running:**
   ```bash
   docker exec cacti_app python3 /var/www/html/cacti/plugins/gnmi/scripts/gnmi_daemon_ctl.py start \
     --device-id 1 --config-file /tmp/gnmi_config.json
   ```

2. **If daemon running but disconnected:**
   - Check logs for connection errors
   - Restart daemon:
     ```bash
     docker exec cacti_app python3 /var/www/html/cacti/plugins/gnmi/scripts/gnmi_daemon_ctl.py restart \
       --device-id 1 --config-file /tmp/gnmi_config.json
     ```

3. **If daemon stuck:**
   - Force kill and restart:
     ```bash
     docker exec cacti_app python3 /var/www/html/cacti/plugins/gnmi/scripts/gnmi_daemon_ctl.py stop --device-id 1
     sleep 2
     docker exec cacti_app python3 /var/www/html/cacti/plugins/gnmi/scripts/gnmi_daemon_ctl.py start \
       --device-id 1 --config-file /tmp/gnmi_config.json
     ```

---

### Issue 4: Slow Write Intervals

**Symptoms:**
- Logs show `write_interval: 15.0s` (expected ~10s)
- Data updates slower than expected

**Diagnosis:**
```bash
# Check recent logs for write_interval values
docker exec cacti_app tail -100 /var/www/html/cacti/plugins/gnmi/runtime/logs/device_1.log | grep "write_interval"
```

**Acceptable Range:** 10-15 seconds

**Causes:**
- Sample timing variance (gNMI device-side)
- Network latency
- Buffer logic timing

**Solution:**
If consistently > 15s:
1. Check network latency to device
2. Verify device gNMI sample_interval setting
3. Compare the configured collection interval with the Cacti poller interval
4. Retain sanitized daemon logs if timing remains outside the expected range

---

### Issue 5: Bridge Script Failures

**Symptoms:**
- Cacti shows "U" values in RRD
- Manual bridge test fails
- Exit code non-zero

**Diagnosis:**
```bash
# Test bridge with debug
docker exec cacti_app python3 /var/www/html/cacti/plugins/gnmi/scripts/gnmi_poller_bridge.py \
  --device-id 1 --local-data-id 6 --debug
```

**Common Errors:**

#### Exit Code 1: Storage Read Error
```
ERROR - Cannot read storage for device 1
```

**Solution:**
- Check storage file exists:
  ```bash
  docker exec cacti_app ls -la /var/www/html/cacti/plugins/gnmi/runtime/storage/device_1.json
  ```
- Verify permissions (should be readable by www-data)

#### Exit Code 2: Stale Data
```
ERROR - Data is stale for device 1
```

**Solution:**
- Data age exceeded `poller_interval * 2`
- Check daemon status
- Restart daemon if needed

#### No Output
**Solution:**
- Check group/instance parameters match data
- Verify metrics exist in storage

---

## Diagnostic Tools

### 1. Daemon Monitor

Continuously monitors daemon health and logs state changes.

**Usage:**
```bash
docker exec cacti_app python3 /var/www/html/cacti/plugins/gnmi/scripts/gnmi_daemon_monitor.py \
  --device-id 1 --interval 10 --duration 300
```

**Output:** CSV file with health checks every 10 seconds

**Use Cases:**
- Long-term stability testing
- Detecting connection flaps
- Measuring uptime percentage

### 2. Data Continuity Checker

Detects gaps in data collection.

**Usage:**
```bash
docker exec cacti_app python3 /var/www/html/cacti/plugins/gnmi/scripts/check_data_continuity.py \
  --device-id 1 --duration 300 --threshold 20
```

**Output:** Reports gaps > 20 seconds with statistics

**Use Cases:**
- Investigating spikey graphs
- Verifying consistent data flow
- Identifying intermittent issues

### 3. Log Analyzer

Analyzes historical daemon logs.

**Usage:**
```bash
# Copy log locally
docker exec cacti_app cat /var/www/html/cacti/plugins/gnmi/runtime/logs/device_1.log > /tmp/daemon.log

# Analyze
cd /path/to/cacti-plugin/plugins/gnmi
source venv/bin/activate
python3 scripts/analyze_daemon_logs.py --log-file /tmp/daemon.log
```

**Output:** Comprehensive analysis including:
- Connection stability metrics
- Storage update patterns
- Error categorization
- Reconnection analysis

**Use Cases:**
- Post-incident analysis
- Understanding historical issues
- Performance trending

### 4. Bridge Reliability Tester

Stress tests the poller bridge.

**Usage:**
```bash
# Sequential bridge smoke test
for i in $(seq 1 100); do
  docker exec cacti_app python3 /var/www/html/cacti/plugins/gnmi/scripts/gnmi_poller_bridge.py \
    --device-id 1 --local-data-id 6 >/dev/null || exit 1
done

# Concurrent bridge smoke test
seq 1 20 | xargs -n1 -P20 -I{} docker exec cacti_app python3 \
  /var/www/html/cacti/plugins/gnmi/scripts/gnmi_poller_bridge.py \
  --device-id 1 --local-data-id 6 >/dev/null
```

**Output:** Performance stats and consistency analysis

**Use Cases:**
- Verifying bridge reliability
- Testing concurrent Cacti polling
- Performance benchmarking

---

## Comprehensive Testing

Run all diagnostic tests at once:

```bash
cd /path/to/cacti-plugin/docker_helpers
./run_robustness_tests.sh 1 300  # device_id=1, duration=300s
```

This runs:
1. Health check
2. 5-minute continuity check
3. 100 sequential bridge calls
4. 20 concurrent bridge calls
5. Full log analysis
6. Storage state snapshot

**Output:** Timestamped directory in `/tmp/` with all results

---

## Monitoring Best Practices

### Daily Health Checks

Add to cron:
```bash
# Check daemon health daily
0 9 * * * docker exec cacti_app python3 /var/www/html/cacti/plugins/gnmi/scripts/gnmi_daemon_ctl.py health --device-id 1 | mail -s "gNMI Health" admin@example.com
```

### Weekly Log Analysis

```bash
# Analyze logs weekly
0 0 * * 0 docker exec cacti_app cat /var/www/html/cacti/plugins/gnmi/runtime/logs/device_1.log > /tmp/daemon.log && python3 /path/to/analyze_daemon_logs.py --log-file /tmp/daemon.log > /tmp/weekly_report.txt
```

### Alert on Connection Issues

Monitor for reconnection attempts:
```bash
# Alert if > 10 reconnections in 1 hour
docker exec cacti_app tail -1000 /var/www/html/cacti/plugins/gnmi/runtime/logs/device_1.log | grep "Reconnect attempt" | wc -l
```

---

## Performance Expectations

### Normal Operation

- **Sample interval:** 5 seconds
- **Write interval:** 10-15 seconds
- **Write time:** < 10ms
- **Connection uptime:** > 99%
- **Bridge response:** < 100ms
- **Storage file size:** ~3-5KB

### Warning Thresholds

- **Sample interval:** > 7 seconds (logged as warning)
- **Write interval:** > 20 seconds (possible issue)
- **Data age:** > `poller_interval * 2` (stale)
- **Connection uptime:** < 95% (investigate)
- **Bridge response:** > 500ms (performance issue)

### Critical Thresholds

- **Data age:** > `poller_interval * 2` (critical)
- **Connection uptime:** < 80% (serious issues)
- **Bridge failures:** > 5% (unreliable)

---

## Device-Specific Notes

### Ciena Devices

**Requirements:**
- mTLS client certificates required
- TLS override needed (SAN mismatch)
- Capabilities() patch applied automatically
- Use a dedicated, least-privilege telemetry account; no default credential is
  supplied or assumed by the plugin.

**Config Example:**
```json
{
  "host": "192.0.2.10",
  "port": 9339,
  "username": "telemetry-example",
  "password": "replace-with-test-password",
  "ca_cert": "/path/to/ca.cert.pem",
  "client_key": "/path/to/client.key.pem",
  "client_cert": "/path/to/client.cert.pem",
  "tls_override": "gnmi-lab.example.invalid",
  "skip_verify": true,
  "insecure": false
}
```

---

## Getting Help

### Enable Debug Logging

```bash
# Restart daemon with debug logging
docker exec cacti_app python3 /var/www/html/cacti/plugins/gnmi/scripts/gnmi_daemon_ctl.py restart \
  --device-id 1 --config-file /tmp/gnmi_config.json

# View logs with debug info
docker exec cacti_app tail -f /var/www/html/cacti/plugins/gnmi/runtime/logs/device_1.log
```

### Collect Diagnostic Bundle

```bash
# Create diagnostic bundle
BUNDLE=/tmp/gnmi_diagnostics_$(date +%Y%m%d_%H%M%S)
mkdir -p ${BUNDLE}

# Collect files
docker exec cacti_app cat /var/www/html/cacti/plugins/gnmi/runtime/logs/device_1.log > ${BUNDLE}/daemon.log
docker exec cacti_app cat /var/www/html/cacti/plugins/gnmi/runtime/storage/device_1.json > ${BUNDLE}/storage.json
docker exec cacti_app cat /tmp/gnmi_config.json > ${BUNDLE}/config.json
docker exec cacti_app python3 /var/www/html/cacti/plugins/gnmi/scripts/gnmi_daemon_ctl.py health --device-id 1 > ${BUNDLE}/health.json

# Run analysis
cd /path/to/plugin && source venv/bin/activate
python3 scripts/analyze_daemon_logs.py --log-file ${BUNDLE}/daemon.log --output ${BUNDLE}/analysis.json

echo "Diagnostic bundle: ${BUNDLE}"
```

---

## Related Documentation

- [Architecture Overview](architecture.md) - System design
- [Daemon Storage Format](daemon_storage_format.md) - JSON structure
- [Metric Groups](metric_groups.md) - Available metrics

---

## Changelog

**2025-10-17:**
- Initial version with enhanced diagnostic tools
- Added comprehensive troubleshooting workflows
- Documented common issues and solutions

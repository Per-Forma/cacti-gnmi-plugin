# gNMI Collector Scripts

This directory contains the Python-based gNMI data collector and associated utilities for the Cacti gNMI plugin.

## Directory Structure

```
scripts/
├── README.md                    # This file
├── requirements.txt             # Python dependencies with pinned versions
├── requirements-installed.txt   # Exact resolved runtime versions
├── requirements-dev.txt         # Test and coverage dependencies
└── gnmi_collector/             # Reusable collector library
    ├── __init__.py
    ├── subscriber.py           # gNMI subscription handling
    ├── transforms.py           # Data transformations (octets→bits, etc.)
    └── rrd.py                  # RRD file operations
```

## Environment Setup

### Prerequisites

**System Requirements:**
- Python 3.12 or higher
- rrdtool system package with development headers
- PHP CLI (for plugin development and linting)

**macOS Installation:**
```bash
# Install rrdtool via Homebrew
brew install rrdtool

# Install PHP (optional, for plugin development)
brew install php
```

**Ubuntu/Debian Installation:**
```bash
# Install rrdtool development libraries
sudo apt-get install librrd-dev

# Install PHP CLI
sudo apt-get install php-cli
```

**RHEL/CentOS Installation:**
```bash
# Install rrdtool development libraries
sudo yum install rrdtool-devel

# Install PHP CLI
sudo yum install php-cli
```

### Python Virtual Environment Setup

1. **Create virtual environment:**
   ```bash
   cd /path/to/cacti-plugin/plugins/gnmi
   python3 -m venv venv
   ```

2. **Activate virtual environment:**
   ```bash
   # macOS/Linux
   source venv/bin/activate

   # Windows (if applicable)
   venv\Scripts\activate
   ```

3. **Install dependencies:**
   ```bash
   # macOS (requires CFLAGS for rrdtool headers)
   export CFLAGS="-I/opt/homebrew/include"
   export LDFLAGS="-L/opt/homebrew/lib"
   pip install --upgrade pip
   pip install -r scripts/requirements.txt

   # Linux (usually works without extra flags)
   pip install --upgrade pip
   pip install -r scripts/requirements.txt
   ```

4. **Verify installation:**
   ```bash
   python -c "import rrdtool; import pygnmi; print('✓ All imports successful')"
   ```

## Dependencies

### Core Dependencies
- **pygnmi** (0.8.15) - gNMI protocol client library
- **rrdtool-bindings** (0.5.0) - maintained Python bindings for RRDtool
- **grpcio** (1.59.3) - gRPC runtime (pygnmi dependency)
- **protobuf** (5.29.6) - Protocol buffers (pygnmi dependency)

### Testing Dependencies
- **pytest** (9.0.3) - Testing framework
- **pytest-mock** (3.12.0) - Mocking utilities for pytest

### Utility Dependencies
- **pyyaml** (6.0.1) - YAML configuration parsing
- **cryptography** - TLS/certificate handling (resolved pygnmi dependency)

See `requirements-installed.txt` for the resolved runtime dependency tree. Use
`requirements-dev.txt` when running tests or coverage locally.

## Collector module API

### gnmi_collector.subscriber

Handles gNMI subscriptions and connection management.

```python
# Example usage (to be implemented)
from gnmi_collector.subscriber import subscribe_to_metrics

# Subscribe to metrics from a device
results = subscribe_to_metrics(
    target="192.0.2.10",
    port=9339,
    username="telemetry-example",
    password="replace-with-test-password",
    paths=["interface/state/counters"],
    sample_interval=5  # seconds
)
```

**Key Functions:**
- `subscribe_to_metrics()` - Establish subscription and collect data
- `parse_telemetry()` - Parse gNMI telemetry responses
- Connection error handling and retry logic

### gnmi_collector.transforms

Data transformation utilities for converting gNMI metric values.

```python
# Example usage (to be implemented)
from gnmi_collector.transforms import octets_to_bits, apply_transform

# Convert octets to bits (multiply by 8)
bits_value = octets_to_bits(octets_value)

# Apply arbitrary transform
result = apply_transform(value, operation="multiply", factor=8)
```

**Key Functions:**
- `octets_to_bits()` - Convert octet counters to bits
- `apply_transform()` - Generic transformation function
- `rate_calculation()` - Calculate rates from counters (Phase 4)

### gnmi_collector.rrd

RRD file creation and update operations.

```python
# Example usage (to be implemented)
from gnmi_collector.rrd import create_rrd, update_rrd

# Create RRD file with metric definitions
create_rrd(
    filename="interface-traffic.rrd",
    metrics=["in-octets", "out-octets"],
    step=5,  # 5-second intervals
    ds_type="COUNTER"
)

# Update RRD with new data
update_rrd(
    filename="interface-traffic.rrd",
    timestamp=1234567890,
    values={"in-octets": 12345, "out-octets": 67890}
)
```

**Key Functions:**
- `create_rrd()` - Create new RRD file with data sources
- `update_rrd()` - Update RRD with new metric values
- `rrd_file_exists()` - Check if RRD file exists
- RRD configuration and retention policies

### gnmi_poller_bridge.py (Poller Bridge)

Connects daemon JSON storage to Cacti RRD system. Queries database to determine which metrics to output for each data source.

**Usage:**
```bash
gnmi_poller_bridge.py --device-id <id> --local-data-id <local_data_id> [options]
```

**Arguments:**
- `--device-id` (required): gNMI device ID from plugin_gnmi_devices table
- `--local-data-id` (required): Cacti local_data_id (data source ID)
- `--storage-dir` (optional): Storage directory path (default: `<plugin>/runtime/storage`, or `GNMI_RUNTIME_DIR/storage`)
- `--config-path` (optional): Path to Cacti `config.php` (default: derived from the bridge script's installed location as `<cacti_root>/include/config.php`)
- `--staleness-threshold` (optional): Data staleness threshold in seconds. PHP passes `gnmi_get_poller_interval() * 2`; the standalone default is only a fallback.
- `--debug` (optional): Enable debug logging

**Example:**
```bash
gnmi_poller_bridge.py --device-id 1 --local-data-id 6
```

**How It Works:**
1. Connects to Cacti database using credentials from config.php
2. Queries database for expected metrics and instance identifier for the data source
3. Reads daemon JSON storage file for the device
4. Filters metrics to only those expected for this data source
5. Outputs metrics in Cacti format: `field1:value1 field2:value2 ...`

**Output Format:**
- Success: Field-value pairs to stdout (e.g., `in_octets:123456 out_octets:789012`)
- Failure: Non-zero exit code with error message to stderr
- Exit code 2: Stale data (daemon not connected or data too old)

**Database Queries:**
- Queries `data_template_rrd` and `plugin_gnmi_metrics` to get expected metrics
- Queries `plugin_gnmi_subscriptions` to get instance identifier
- Maps Cacti field names (e.g., `in_octets`) to gNMI metric names (e.g., `in-octets`)

**Architecture:**
- One bridge call per data source (supports future per-data-source query intervals)
- Bridge queries database each time (stateless)
- Handles any metric type dynamically (no hardcoded groups)

## Testing

### Running Unit Tests

```bash
# Install the development-only test dependencies first
./venv/bin/python3 -m pip install -r scripts/requirements-dev.txt

# Run all tests with the configured production coverage scope and gate
./venv/bin/pytest

# Run specific test file
./venv/bin/pytest tests/test_transforms.py

# Run with verbose output
./venv/bin/pytest -v

# Reports are written to htmlcov/ and coverage.xml
```

Coverage configuration lives in `../.coveragerc`. It measures production
Python from the `scripts` package without importing collector modules before
measurement, and enforces the repository's 85% combined coverage floor.

### Test Organization (Phase 2, Task 2.1)
- `tests/test_transforms.py` - Transform function tests with sample data
- `tests/test_subscriber.py` - Subscription logic with mocked gNMI responses
- `tests/test_rrd.py` - RRD operations (mocked rrdtool calls)

## Development Notes

### Runtime entry points

- `gnmi_daemon.py` maintains persistent device subscriptions.
- `gnmi_daemon_ctl.py` manages daemon lifecycle and health.
- `gnmi_poller_bridge.py` converts stored samples into Cacti poller output.
- `gnmi_connection_test.py` performs the staged connection probe.

### Error Handling Standards
- Log all connection errors with device context
- Use Python logging module with appropriate levels (INFO, WARNING, ERROR)
- Exit with non-zero code on failure
- Provide actionable error messages for Cacti poller

### Code Style
- Follow PEP 8 style guidelines
- Use type hints where applicable
- Document all public functions with docstrings
- Comment complex gRPC/gNMI operations
- Generous inline comments for business logic

## Troubleshooting

### Import Errors

**Problem:** `ModuleNotFoundError: No module named 'rrdtool'`
**Solution:** Ensure virtual environment is activated and dependencies are installed:
```bash
source venv/bin/activate
pip install -r scripts/requirements.txt
```

**Problem:** `rrdtool` build fails with "rrd.h not found"
**Solution:** Install rrdtool development headers and set compiler flags (see macOS installation above)

### gNMI Connection Issues

**Problem:** Connection timeout or "connection refused"
**Diagnosis:**
- Verify device is reachable: `ping <device_ip>`
- Check gNMI port is open: `telnet <device_ip> 9339`
- Verify gNMI service is enabled on device
- Check firewall rules

**Problem:** Authentication failures
**Diagnosis:**
- Verify credentials are correct
- Check device authentication method (username/password vs. certificates)
- Review device logs for auth failures

### RRD File Issues

**Problem:** "illegal attempt to update using time X when last update time is Y"
**Solution:** RRD files reject out-of-order updates. Ensure timestamps are monotonically increasing.

**Problem:** RRD file not found
**Solution:** The collector will auto-create RRD files. Verify directory permissions and disk space.

## References

- [pygnmi Documentation](https://github.com/akarneliuk/pygnmi)
- [gNMI Protocol Specification](https://github.com/openconfig/reference/blob/master/rpc/gnmi/gnmi-specification.md)
- [RRDtool Documentation](https://oss.oetiker.ch/rrdtool/doc/index.en.html)
- [Cacti Plugin Development](https://docs.cacti.net/Plugin-Development)

"""
Unit tests for gnmi_poller_bridge.py

Tests all bridge functions for correctness, error handling, and edge cases.
"""

import pytest
import json
import tempfile
import threading
from datetime import datetime, timedelta, timezone
from pathlib import Path
from unittest.mock import patch, MagicMock
from io import StringIO
import sys
import os

# Import bridge functions
sys.path.insert(0, str(Path(__file__).parent.parent))
from scripts.gnmi_poller_bridge import (
    sanitize_field_name,
    extract_instance_id,
    read_daemon_storage,
    check_staleness,
    extract_metrics_from_raw,
    filter_data_source_metrics,
    output_cacti_format,
)

# Import test data fixtures
from tests.fixtures.bridge_test_data import (
    get_valid_daemon_storage,
    get_ciena_35_metrics,
    get_stale_storage,
    get_disconnected_daemon_storage,
    get_error_daemon_storage
)


# Fixtures

@pytest.fixture
def temp_storage_dir():
    """Create temporary storage directory for tests."""
    with tempfile.TemporaryDirectory() as tmpdir:
        yield tmpdir


@pytest.fixture
def valid_storage_json():
    """Return valid daemon storage JSON."""
    return get_valid_daemon_storage()


@pytest.fixture
def ciena_35_metrics_json():
    """Return full 35-metric Ciena dataset."""
    return get_ciena_35_metrics()


# Note: Using pytest's built-in capsys fixture instead of custom capture


# Test Classes

class TestSanitizeFieldName:
    """Test field name sanitization for Cacti/RRD compatibility."""

    @pytest.mark.unit
    def test_basic_hyphen_replacement(self):
        """Test basic hyphen to underscore conversion."""
        assert sanitize_field_name("in-octets") == "in_octets"

    @pytest.mark.unit
    def test_multiple_hyphens(self):
        """Test multiple hyphens are converted."""
        assert sanitize_field_name("in-crc-error-pkts") == "in_crc_error_pkts"

    @pytest.mark.unit
    def test_with_numbers(self):
        """Test field names containing numbers."""
        assert sanitize_field_name("in-64-octet-pkts") == "in_64_octet_pkts"

    @pytest.mark.unit
    def test_uppercase_conversion(self):
        """Test uppercase letters converted to lowercase."""
        assert sanitize_field_name("IN-OCTETS") == "in_octets"

    @pytest.mark.unit
    def test_mixed_case(self):
        """Test mixed case conversion."""
        assert sanitize_field_name("In-Octets-Total") == "in_octets_total"

    @pytest.mark.unit
    def test_special_chars_removed(self):
        """Test special characters are removed."""
        result = sanitize_field_name("in@octets#pkts")
        assert result == "inoctetspkts"

    @pytest.mark.unit
    def test_quotes_removed(self):
        """Test quotes are stripped."""
        assert sanitize_field_name('"in-octets"') == "in_octets"

    @pytest.mark.unit
    def test_starts_with_digit(self):
        """Test field starting with digit gets underscore prefix."""
        result = sanitize_field_name("64-octet-pkts")
        assert result == "_64_octet_pkts"
        assert result[0] == '_'

    @pytest.mark.unit
    def test_19_char_limit(self):
        """Test field name truncated to 19 characters (RRD limit)."""
        long_name = "very-long-field-name-exceeding-nineteen-character-limit"
        result = sanitize_field_name(long_name)
        assert len(result) == 19
        assert result == "very_long_field_nam"

    @pytest.mark.unit
    def test_empty_string(self):
        """Test empty string returns empty."""
        assert sanitize_field_name("") == ""

    @pytest.mark.unit
    def test_only_special_chars(self):
        """Test string with only special characters."""
        assert sanitize_field_name("@#$%^&") == ""

    @pytest.mark.unit
    def test_underscore_preserved(self):
        """Test underscores are preserved (already valid)."""
        assert sanitize_field_name("in_octets") == "in_octets"


class TestExtractInstanceId:
    """Test instance identifier extraction from gNMI paths."""

    @pytest.mark.unit
    def test_ciena_format_basic(self):
        """Test extraction from basic Ciena path."""
        path = "interface-counters[interface-type=ettp]/interfaces[if-name=40]/counters"
        assert extract_instance_id(path) == "ettp-40"

    @pytest.mark.unit
    def test_ciena_format_multiple_types(self):
        """Test different Ciena interface types."""
        assert extract_instance_id("interface-counters[interface-type=ettp]/interfaces[if-name=40]") == "ettp-40"
        assert extract_instance_id("interface-counters[interface-type=otu]/interfaces[if-name=1]") == "otu-1"
        assert extract_instance_id("interface-counters[interface-type=odu]/interfaces[if-name=2-3]") == "odu-2-3"

    @pytest.mark.unit
    def test_ciena_format_complex_name(self):
        """Test Ciena format with complex interface name."""
        path = "interface-counters[interface-type=ettp]/interfaces[if-name=1-2-3]/counters"
        assert extract_instance_id(path) == "ettp-1-2-3"

    @pytest.mark.unit
    def test_openconfig_format_basic(self):
        """Test extraction from OpenConfig format."""
        path = "/interfaces/interface[name=eth0]/state/counters"
        assert extract_instance_id(path) == "eth0"

    @pytest.mark.unit
    def test_openconfig_format_complex(self):
        """Test OpenConfig with complex interface name."""
        path = "/interfaces/interface[name=GigabitEthernet0/0/1]/state"
        assert extract_instance_id(path) == "GigabitEthernet0/0/1"

    @pytest.mark.unit
    def test_only_interface_type(self):
        """Test path with only interface-type (missing if-name)."""
        path = "interface-counters[interface-type=ettp]/counters"
        # Should fall back to OpenConfig or default
        result = extract_instance_id(path)
        assert result == "default"

    @pytest.mark.unit
    def test_only_interface_name(self):
        """Test path with only if-name (missing interface-type)."""
        path = "interfaces[if-name=40]/counters"
        # Should fall back to OpenConfig or default
        result = extract_instance_id(path)
        assert result == "default"

    @pytest.mark.unit
    def test_no_identifiers(self):
        """Test path without identifiers (system-wide metrics)."""
        path = "/system/memory/state/used"
        assert extract_instance_id(path) == "default"

    @pytest.mark.unit
    def test_empty_path(self):
        """Test empty path returns default."""
        assert extract_instance_id("") == "default"

    @pytest.mark.unit
    def test_malformed_brackets(self):
        """Test malformed bracket syntax."""
        path = "interface[name=eth0"  # Missing closing bracket
        # Should fail to match and return default
        assert extract_instance_id(path) == "default"


class TestReadDaemonStorage:
    """Test JSON file reading from daemon storage."""

    @pytest.mark.unit
    def test_read_valid_json(self, temp_storage_dir, valid_storage_json):
        """Test successfully reading well-formed JSON."""
        device_id = 1
        storage_file = Path(temp_storage_dir) / f"device_{device_id}.json"

        with open(storage_file, 'w') as f:
            json.dump(valid_storage_json, f)

        data = read_daemon_storage(device_id, temp_storage_dir)
        assert data is not None
        assert data['device_id'] == device_id
        assert data['daemon_status'] == 'connected'

    @pytest.mark.unit
    def test_file_not_found(self, temp_storage_dir):
        """Test returns None for missing file."""
        data = read_daemon_storage(999, temp_storage_dir)
        assert data is None

    @pytest.mark.unit
    def test_invalid_json_syntax(self, temp_storage_dir):
        """Test returns None for malformed JSON."""
        device_id = 2
        storage_file = Path(temp_storage_dir) / f"device_{device_id}.json"

        # Write invalid JSON
        with open(storage_file, 'w') as f:
            f.write('{"invalid": json syntax}')

        data = read_daemon_storage(device_id, temp_storage_dir)
        assert data is None

    @pytest.mark.unit
    def test_empty_file(self, temp_storage_dir):
        """Test returns None for empty file."""
        device_id = 3
        storage_file = Path(temp_storage_dir) / f"device_{device_id}.json"

        # Create empty file
        storage_file.touch()

        data = read_daemon_storage(device_id, temp_storage_dir)
        assert data is None

    @pytest.mark.unit
    def test_custom_storage_dir(self, valid_storage_json):
        """Test reading from non-default directory."""
        with tempfile.TemporaryDirectory() as custom_dir:
            device_id = 4
            storage_file = Path(custom_dir) / f"device_{device_id}.json"

            with open(storage_file, 'w') as f:
                json.dump(valid_storage_json, f)

            data = read_daemon_storage(device_id, custom_dir)
            assert data is not None
            assert data['device_id'] == valid_storage_json['device_id']

    @pytest.mark.unit
    def test_permission_denied(self, temp_storage_dir):
        """Test handles permission errors gracefully."""
        device_id = 5
        storage_file = Path(temp_storage_dir) / f"device_{device_id}.json"

        # Create file with no read permissions
        with open(storage_file, 'w') as f:
            json.dump({"test": "data"}, f)

        os.chmod(storage_file, 0o000)

        try:
            data = read_daemon_storage(device_id, temp_storage_dir)
            assert data is None
        finally:
            # Restore permissions for cleanup
            os.chmod(storage_file, 0o644)

    @pytest.mark.unit
    def test_large_json_file(self, temp_storage_dir, ciena_35_metrics_json):
        """Test reading JSON with 35 metrics."""
        device_id = 6
        storage_file = Path(temp_storage_dir) / f"device_{device_id}.json"

        with open(storage_file, 'w') as f:
            json.dump(ciena_35_metrics_json, f)

        data = read_daemon_storage(device_id, temp_storage_dir)
        assert data is not None
        assert 'metric_groups' in data
        metrics = extract_metrics_from_raw(data, "ettp-40")
        assert len(metrics) == 35

    @pytest.mark.unit
    def test_concurrent_reads(self, temp_storage_dir, valid_storage_json):
        """Test multiple simultaneous reads (thread safety)."""
        device_id = 7
        storage_file = Path(temp_storage_dir) / f"device_{device_id}.json"

        with open(storage_file, 'w') as f:
            json.dump(valid_storage_json, f)

        results = []

        def read_storage():
            data = read_daemon_storage(device_id, temp_storage_dir)
            results.append(data is not None)

        threads = [threading.Thread(target=read_storage) for _ in range(10)]
        for t in threads:
            t.start()
        for t in threads:
            t.join()

        # All reads should succeed
        assert all(results)
        assert len(results) == 10


class TestCheckStaleness:
    """Test timestamp freshness detection."""

    @pytest.mark.unit
    def test_fresh_data_5_seconds(self):
        """Test data 5 seconds old is fresh (threshold 30s)."""
        timestamp = datetime.now(timezone.utc) - timedelta(seconds=5)
        data = {"last_update": timestamp.isoformat()}
        assert check_staleness(data, threshold=30) == False

    @pytest.mark.unit
    def test_fresh_data_edge_29_seconds(self):
        """Test data at edge (29s old) is still fresh."""
        timestamp = datetime.now(timezone.utc) - timedelta(seconds=29)
        data = {"last_update": timestamp.isoformat()}
        assert check_staleness(data, threshold=30) == False

    @pytest.mark.unit
    def test_stale_data_edge_31_seconds(self):
        """Test data just over threshold (31s) is stale."""
        timestamp = datetime.now(timezone.utc) - timedelta(seconds=31)
        data = {"last_update": timestamp.isoformat()}
        assert check_staleness(data, threshold=30) == True

    @pytest.mark.unit
    def test_stale_data_60_seconds(self):
        """Test data 60 seconds old is stale."""
        timestamp = datetime.now(timezone.utc) - timedelta(seconds=60)
        data = {"last_update": timestamp.isoformat()}
        assert check_staleness(data, threshold=30) == True

    @pytest.mark.unit
    def test_stale_data_hours_old(self):
        """Test data hours old is definitely stale."""
        timestamp = datetime.now(timezone.utc) - timedelta(hours=3)
        data = {"last_update": timestamp.isoformat()}
        assert check_staleness(data, threshold=30) == True

    @pytest.mark.unit
    def test_missing_timestamp(self):
        """Test missing timestamp field is considered stale."""
        data = {"device_id": 1}  # No last_update field
        assert check_staleness(data, threshold=30) == True

    @pytest.mark.unit
    def test_invalid_timestamp_format(self):
        """Test malformed timestamp is considered stale."""
        data = {"last_update": "not-a-valid-timestamp"}
        assert check_staleness(data, threshold=30) == True

    @pytest.mark.unit
    def test_future_timestamp(self):
        """Test timestamp in future is considered fresh."""
        timestamp = datetime.now(timezone.utc) + timedelta(seconds=30)
        data = {"last_update": timestamp.isoformat()}
        # Future timestamp has negative age, so not > threshold
        assert check_staleness(data, threshold=30) == False

    @pytest.mark.unit
    def test_custom_threshold_10s(self):
        """Test with custom 10-second threshold."""
        timestamp = datetime.now(timezone.utc) - timedelta(seconds=15)
        data = {"last_update": timestamp.isoformat()}
        assert check_staleness(data, threshold=10) == True
        assert check_staleness(data, threshold=20) == False

    @pytest.mark.unit
    def test_timezone_handling(self):
        """Test proper timezone handling with Z suffix."""
        # Test with 'Z' suffix
        timestamp = datetime.now(timezone.utc) - timedelta(seconds=10)
        timestamp_z = timestamp.isoformat().replace('+00:00', 'Z')
        data = {"last_update": timestamp_z}
        assert check_staleness(data, threshold=30) == False

    # ------------------------------------------------------------------
    # Parametrized tests for poller-interval-derived thresholds
    # Spec: staleness_threshold = poller_interval * 2
    # ------------------------------------------------------------------

    @pytest.mark.unit
    @pytest.mark.parametrize("poller_interval,age_seconds,expected_stale", [
        # 10s poller -> threshold=20
        (10, 15,  False),   # fresh: 15s < 20s threshold
        (10, 25,  True),    # stale: 25s > 20s threshold
        # 60s poller -> threshold=120
        (60, 60,  False),   # fresh: 60s < 120s threshold (was false-positive with hardcoded 30)
        (60, 130, True),    # stale: 130s > 120s threshold
        # 300s poller -> threshold=600
        (300, 300, False),  # fresh: 300s < 600s threshold (was false-positive with hardcoded 30)
        (300, 650, True),   # stale: 650s > 600s threshold
    ])
    def test_poller_interval_derived_threshold(self, poller_interval, age_seconds, expected_stale):
        """Staleness result is correct when threshold = poller_interval * 2."""
        threshold = poller_interval * 2
        timestamp = datetime.now(timezone.utc) - timedelta(seconds=age_seconds)
        data = {"last_update": timestamp.isoformat()}
        assert check_staleness(data, threshold=threshold) == expected_stale


class TestExtractMetricsFromRaw:
    """Test extraction of metrics from the instance-keyed daemon storage shape."""

    @pytest.mark.unit
    def test_extract_basic_metrics(self):
        """Extracting 4 traffic metrics for a given instance returns gNMI names."""
        raw_data = {
            "metric_groups": {
                "ettp-40": {
                    "in_octets": 123456,
                    "out_octets": 789012,
                    "in_pkts": 5678,
                    "out_pkts": 9012,
                }
            }
        }

        metrics = extract_metrics_from_raw(raw_data, "ettp-40")
        assert len(metrics) == 4
        assert metrics["in-octets"] == 123456
        assert metrics["out-octets"] == 789012
        assert metrics["in-pkts"] == 5678
        assert metrics["out-pkts"] == 9012

    @pytest.mark.unit
    def test_extract_35_ciena_metrics(self, ciena_35_metrics_json):
        """Extract full 35-metric Ciena dataset for ettp-40."""
        metrics = extract_metrics_from_raw(ciena_35_metrics_json, "ettp-40")
        assert len(metrics) == 35
        assert "in-octets" in metrics
        assert "in-64-octet-pkts" in metrics
        assert metrics["in-octets"] == 1011655537381

    @pytest.mark.unit
    def test_missing_instance_returns_empty(self):
        """Requesting an unknown instance returns {} (never falls back to flat)."""
        raw_data = {
            "metric_groups": {
                "ettp-40": {"in_octets": 1}
            }
        }
        metrics = extract_metrics_from_raw(raw_data, "ettp-34")
        assert metrics == {}

    @pytest.mark.unit
    def test_missing_metric_groups(self):
        """Missing metric_groups key returns empty dict."""
        raw_data = {"device_id": 1}
        metrics = extract_metrics_from_raw(raw_data, "ettp-40")
        assert metrics == {}

    @pytest.mark.unit
    def test_empty_instance_identifier_returns_empty(self):
        """Empty instance_identifier returns {} (programming error path)."""
        raw_data = {
            "metric_groups": {
                "ettp-40": {"in_octets": 1}
            }
        }
        metrics = extract_metrics_from_raw(raw_data, "")
        assert metrics == {}

    @pytest.mark.unit
    def test_metrics_with_zero_values_included(self):
        """Zero values are included (not falsy-dropped)."""
        raw_data = {
            "metric_groups": {
                "ettp-40": {
                    "in_errors": 0,
                    "out_errors": 0,
                }
            }
        }
        metrics = extract_metrics_from_raw(raw_data, "ettp-40")
        assert len(metrics) == 2
        assert metrics["in-errors"] == 0
        assert metrics["out-errors"] == 0

    @pytest.mark.unit
    def test_instance_separation(self):
        """Asking for instance A doesn't return any of instance B's values."""
        # Counter magnitudes chosen so any contamination would show up obviously.
        raw_data = {
            "metric_groups": {
                "ettp-40": {"in_octets": 13_700_000_000_000},
                "ettp-34": {"in_octets": 15_200_000_000_000},
            }
        }
        m40 = extract_metrics_from_raw(raw_data, "ettp-40")
        m34 = extract_metrics_from_raw(raw_data, "ettp-34")
        assert m40 == {"in-octets": 13_700_000_000_000}
        assert m34 == {"in-octets": 15_200_000_000_000}

    @pytest.mark.unit
    def test_metric_groups_is_not_dict(self):
        """If metric_groups is the wrong type, return {} cleanly."""
        raw_data = {"metric_groups": "not a dict"}
        assert extract_metrics_from_raw(raw_data, "ettp-40") == {}


class TestFilterDataSourceMetrics:
    """Test filtering metrics by explicit expected metric list.

    The bridge no longer uses named metric groups (METRIC_GROUPS was removed when
    the architecture moved to database-driven per-data-source filtering). Tests
    now pass explicit metric name lists the same way the PHP layer passes them.
    """

    # Metric lists that mirror what the old METRIC_GROUPS contained. Defined
    # here for reuse across tests without importing a removed constant.
    TRAFFIC_METRICS = ['in-octets', 'out-octets', 'in-pkts', 'out-pkts']
    ERROR_METRICS = [
        'in-errors', 'out-errors', 'in-crc-error-pkts',
        'in-jabber-pkts', 'in-oversize-pkts', 'in-undersize-pkts',
    ]
    DISCARD_METRICS = [
        'in-discards', 'in-dropped-pkts', 'in-discards-octets', 'in-dropped-octets',
    ]
    MULTICAST_METRICS = [
        'in-broadcast-pkts', 'in-multicast-pkts', 'in-unicast-pkts',
        'out-broadcast-pkts', 'out-multicast-pkts', 'out-unicast-pkts',
    ]

    @pytest.mark.unit
    def test_filter_interface_traffic(self):
        """Test filtering to traffic metrics only."""
        all_metrics = {
            "in-octets": 123,
            "out-octets": 456,
            "in-pkts": 789,
            "out-pkts": 12,
            "in-errors": 0,   # not in expected list
            "out-errors": 0   # not in expected list
        }

        result = filter_data_source_metrics(all_metrics, self.TRAFFIC_METRICS)
        assert len(result) == 4
        assert 'in-octets' in result
        assert 'out-octets' in result
        assert 'in-pkts' in result
        assert 'out-pkts' in result
        assert 'in-errors' not in result

    @pytest.mark.unit
    def test_filter_interface_errors(self):
        """Test filtering to error metrics only."""
        all_metrics = {
            "in-errors": 5,
            "out-errors": 3,
            "in-crc-error-pkts": 2,
            "in-jabber-pkts": 0,
            "in-oversize-pkts": 1,
            "in-undersize-pkts": 0,
            "in-octets": 123456   # not in expected list
        }

        result = filter_data_source_metrics(all_metrics, self.ERROR_METRICS)
        assert len(result) == 6
        assert 'in-errors' in result
        assert 'out-errors' in result
        assert 'in-octets' not in result

    @pytest.mark.unit
    def test_filter_interface_discards(self):
        """Test filtering to discard metrics only."""
        all_metrics = {
            "in-discards": 100,
            "in-dropped-pkts": 100,
            "in-discards-octets": 50000,
            "in-dropped-octets": 50000
        }

        result = filter_data_source_metrics(all_metrics, self.DISCARD_METRICS)
        assert len(result) == 4

    @pytest.mark.unit
    def test_filter_interface_multicast(self):
        """Test filtering to multicast metrics only."""
        all_metrics = {
            "in-broadcast-pkts": 10,
            "in-multicast-pkts": 20,
            "in-unicast-pkts": 1000,
            "out-broadcast-pkts": 5,
            "out-multicast-pkts": 15,
            "out-unicast-pkts": 900
        }

        result = filter_data_source_metrics(all_metrics, self.MULTICAST_METRICS)
        assert len(result) == 6

    @pytest.mark.unit
    def test_no_metrics_expected(self):
        """Test empty expected list returns empty dict."""
        all_metrics = {"in-octets": 123}
        # An empty expected list means no metrics are requested for this data source
        result = filter_data_source_metrics(all_metrics, [])
        assert result == {}

    @pytest.mark.unit
    def test_partial_metrics_available(self):
        """Test when only some expected metrics are present in storage."""
        all_metrics = {
            "in-octets": 123,
            "out-octets": 456
            # in-pkts and out-pkts not yet available
        }

        # Bridge silently skips missing metrics; only returns what exists
        result = filter_data_source_metrics(all_metrics, self.TRAFFIC_METRICS)
        assert len(result) == 2
        assert result["in-octets"] == 123
        assert result["out-octets"] == 456

    @pytest.mark.unit
    def test_no_metrics_match(self):
        """Test when none of the expected metrics appear in storage."""
        all_metrics = {
            "some-other-metric": 999
        }

        result = filter_data_source_metrics(all_metrics, self.TRAFFIC_METRICS)
        assert result == {}

    @pytest.mark.unit
    def test_all_metrics_match(self):
        """Test when all expected metrics are present in storage."""
        all_metrics = {
            "in-octets": 123,
            "out-octets": 456,
            "in-pkts": 789,
            "out-pkts": 12
        }

        result = filter_data_source_metrics(all_metrics, self.TRAFFIC_METRICS)
        assert len(result) == 4
        assert len(result) == len(self.TRAFFIC_METRICS)


class TestOutputCactiFormat:
    """Test Cacti output string formatting."""

    @pytest.mark.unit
    def test_output_format_basic(self, capsys):
        """Test basic output format."""
        metrics = {"in-octets": 123, "out-octets": 456}
        output_cacti_format(metrics)
        captured = capsys.readouterr()
        output = captured.out.strip()
        assert "in_octets:123" in output
        assert "out_octets:456" in output

    @pytest.mark.unit
    def test_output_field_order(self, capsys):
        """Test output contains all fields."""
        metrics = {
            "in-octets": 100,
            "out-octets": 200,
            "in-pkts": 10,
            "out-pkts": 20
        }
        output_cacti_format(metrics)
        captured = capsys.readouterr()
        output = captured.out.strip()

        # All fields should be present
        assert "in_octets:100" in output
        assert "out_octets:200" in output
        assert "in_pkts:10" in output
        assert "out_pkts:20" in output

    @pytest.mark.unit
    def test_empty_metrics(self, capsys):
        """Test no output for empty dict."""
        metrics = {}
        output_cacti_format(metrics)
        captured = capsys.readouterr()
        output = captured.out.strip()
        assert output == ""

    @pytest.mark.unit
    def test_single_metric(self, capsys):
        """Test single field output."""
        metrics = {"in-octets": 123456}
        output_cacti_format(metrics)
        captured = capsys.readouterr()
        output = captured.out.strip()
        assert output == "in_octets:123456"

    @pytest.mark.unit
    def test_numeric_string_values(self, capsys):
        """Test numeric string values are output."""
        metrics = {"in-octets": "123456"}
        output_cacti_format(metrics)
        captured = capsys.readouterr()
        output = captured.out.strip()
        assert "in_octets:123456" in output

    @pytest.mark.unit
    def test_text_string_values(self, capsys):
        """Test text string values are skipped."""
        metrics = {
            "name": "eth0",  # Should be skipped
            "in-octets": 123
        }
        output_cacti_format(metrics)
        captured = capsys.readouterr()
        output = captured.out.strip()
        assert "name:" not in output
        assert "eth0" not in output
        assert "in_octets:123" in output

    @pytest.mark.unit
    def test_string_with_quotes(self, capsys):
        """Test quotes are stripped from string values."""
        metrics = {
            "in-octets": 123,
            "name": '"interface-40"'  # Quoted string, non-numeric
        }
        output_cacti_format(metrics)
        captured = capsys.readouterr()
        output = captured.out.strip()
        # Non-numeric text should be skipped
        assert "name:" not in output

    @pytest.mark.unit
    def test_zero_values(self, capsys):
        """Test zero values are included."""
        metrics = {
            "in-errors": 0,
            "out-errors": 0
        }
        output_cacti_format(metrics)
        captured = capsys.readouterr()
        output = captured.out.strip()
        assert "in_errors:0" in output
        assert "out_errors:0" in output

    @pytest.mark.unit
    def test_large_counter_values(self, capsys):
        """Test large 64-bit counter values."""
        metrics = {
            "in-octets": 1011655537381,
            "out-octets": 2334094681126
        }
        output_cacti_format(metrics)
        captured = capsys.readouterr()
        output = captured.out.strip()
        assert "in_octets:1011655537381" in output
        assert "out_octets:2334094681126" in output

    @pytest.mark.unit
    def test_sanitization_in_output(self, capsys):
        """Test field names are sanitized in output."""
        metrics = {
            "in-crc-error-pkts": 5,
            "in-64-octet-pkts": 1000
        }
        output_cacti_format(metrics)
        captured = capsys.readouterr()
        output = captured.out.strip()
        # Hyphens should be converted to underscores
        assert "in_crc_error_pkts:5" in output
        assert "in_64_octet_pkts:1000" in output


class TestIntegrationScenarios:
    """Test end-to-end workflow scenarios."""

    @pytest.mark.integration
    def test_full_pipeline_success(self, temp_storage_dir, capsys):
        """Test complete pipeline: read → extract → filter → output."""
        device_id = 999
        storage_file = Path(temp_storage_dir) / f"device_{device_id}.json"
        mock_data = {
            "device_id": 999,
            "last_update": datetime.now(timezone.utc).isoformat(),
            "daemon_status": "connected",
            "metric_groups": {
                "ettp-40": {
                    "in_octets": 123456,
                    "out_octets": 789012,
                    "in_pkts": 5678,
                    "out_pkts": 9012,
                }
            },
            "samples_history": {"ettp-40": []},
        }
        with open(storage_file, 'w') as f:
            json.dump(mock_data, f)

        data = read_daemon_storage(device_id, temp_storage_dir)
        assert data is not None
        assert check_staleness(data, threshold=30) == False

        all_metrics = extract_metrics_from_raw(data, "ettp-40")
        assert len(all_metrics) == 4

        traffic_expected = ['in-octets', 'out-octets', 'in-pkts', 'out-pkts']
        group_metrics = filter_data_source_metrics(all_metrics, traffic_expected)
        assert len(group_metrics) == 4

        output_cacti_format(group_metrics)
        captured = capsys.readouterr()
        output = captured.out.strip()
        assert "in_octets:123456" in output
        assert "out_octets:789012" in output

    @pytest.mark.integration
    def test_pipeline_with_stale_data(self, temp_storage_dir):
        """Test pipeline detects staleness early."""
        device_id = 998
        storage_file = Path(temp_storage_dir) / f"device_{device_id}.json"

        old_time = datetime.now(timezone.utc) - timedelta(seconds=120)
        mock_data = {
            "device_id": 998,
            "last_update": old_time.isoformat(),
            "daemon_status": "connected",
            "metric_groups": {
                "ettp-40": {"in_octets": 123456}
            },
            "samples_history": {"ettp-40": []},
        }
        with open(storage_file, 'w') as f:
            json.dump(mock_data, f)

        data = read_daemon_storage(device_id, temp_storage_dir)
        assert data is not None

        # Should detect staleness
        assert check_staleness(data, threshold=30) == True

    @pytest.mark.integration
    def test_pipeline_with_missing_file(self, temp_storage_dir):
        """Test pipeline handles missing file gracefully."""
        device_id = 997

        # File doesn't exist
        data = read_daemon_storage(device_id, temp_storage_dir)
        assert data is None

    @pytest.mark.integration
    def test_pipeline_with_disconnected_daemon(self, temp_storage_dir):
        """Test pipeline with disconnected daemon status."""
        device_id = 996
        storage_file = Path(temp_storage_dir) / f"device_{device_id}.json"

        mock_data = get_disconnected_daemon_storage()
        mock_data['device_id'] = device_id

        with open(storage_file, 'w') as f:
            json.dump(mock_data, f)

        data = read_daemon_storage(device_id, temp_storage_dir)
        assert data is not None
        assert data['daemon_status'] == 'disconnected'

    @pytest.mark.integration
    def test_pipeline_with_no_group_metrics(self, temp_storage_dir):
        """Test when requested group has no metrics."""
        device_id = 995
        storage_file = Path(temp_storage_dir) / f"device_{device_id}.json"

        mock_data = {
            "device_id": 995,
            "last_update": datetime.now(timezone.utc).isoformat(),
            "daemon_status": "connected",
            "metric_groups": {
                "ettp-40": {
                    "in_octets": 123456,
                    "out_octets": 789012,
                }
            },
            "samples_history": {"ettp-40": []},
        }
        with open(storage_file, 'w') as f:
            json.dump(mock_data, f)

        data = read_daemon_storage(device_id, temp_storage_dir)
        all_metrics = extract_metrics_from_raw(data, "ettp-40")

        errors_expected = [
            'in-errors', 'out-errors', 'in-crc-error-pkts',
            'in-jabber-pkts', 'in-oversize-pkts', 'in-undersize-pkts',
        ]
        group_metrics = filter_data_source_metrics(all_metrics, errors_expected)
        assert group_metrics == {}

    @pytest.mark.integration
    def test_multiple_groups_sequentially(self, temp_storage_dir, ciena_35_metrics_json):
        """Test processing all 4 groups sequentially."""
        device_id = 994
        storage_file = Path(temp_storage_dir) / f"device_{device_id}.json"

        ciena_35_metrics_json['device_id'] = device_id

        with open(storage_file, 'w') as f:
            json.dump(ciena_35_metrics_json, f)

        data = read_daemon_storage(device_id, temp_storage_dir)
        assert data is not None

        all_metrics = extract_metrics_from_raw(data, "ettp-40")
        assert len(all_metrics) == 35

        # Test all 4 metric sets using explicit expected lists
        traffic = filter_data_source_metrics(
            all_metrics, ['in-octets', 'out-octets', 'in-pkts', 'out-pkts']
        )
        assert len(traffic) == 4

        errors = filter_data_source_metrics(
            all_metrics, [
                'in-errors', 'out-errors', 'in-crc-error-pkts',
                'in-jabber-pkts', 'in-oversize-pkts', 'in-undersize-pkts',
            ]
        )
        assert len(errors) == 6

        discards = filter_data_source_metrics(
            all_metrics, [
                'in-discards', 'in-dropped-pkts', 'in-discards-octets', 'in-dropped-octets',
            ]
        )
        assert len(discards) == 4

        multicast = filter_data_source_metrics(
            all_metrics, [
                'in-broadcast-pkts', 'in-multicast-pkts', 'in-unicast-pkts',
                'out-broadcast-pkts', 'out-multicast-pkts', 'out-unicast-pkts',
            ]
        )
        assert len(multicast) == 6

"""
Unit tests for gnmi_collector.rrd module

Tests RRD file operations for the gNMI collector.
These tests cover RRD operations used by the streaming collector.
"""

import pytest
from unittest.mock import Mock, patch, call
from scripts.gnmi_collector.rrd import (
    rrd_file_exists,
    create_rrd,
    update_rrd,
    store_results
)


class TestRrdFileExists:
    """Test RRD file existence checking."""

    @pytest.mark.unit
    def test_file_exists(self):
        """Test that existing file returns True."""
        with patch('os.path.isfile', return_value=True):
            assert rrd_file_exists('/path/to/file.rrd') is True

    @pytest.mark.unit
    def test_file_not_exists(self):
        """Test that non-existent file returns False."""
        with patch('os.path.isfile', return_value=False):
            assert rrd_file_exists('/path/to/file.rrd') is False

    @pytest.mark.unit
    def test_empty_path(self):
        """Test that empty path returns False."""
        with patch('os.path.isfile', return_value=False):
            assert rrd_file_exists('') is False

    @pytest.mark.unit
    def test_none_path(self):
        """Test that None path raises appropriate error."""
        with pytest.raises((TypeError, AttributeError)):
            rrd_file_exists(None)


class TestCreateRrd:
    """Test RRD file creation."""

    @pytest.mark.unit
    def test_create_with_single_metric(self):
        """Test creating RRD with single COUNTER metric."""
        with patch('rrdtool.create') as mock_create:
            create_rrd(
                filename='test.rrd',
                metrics=['in-octets'],
                step=5,
                ds_type='COUNTER'
            )

            # Verify rrdtool.create was called
            mock_create.assert_called_once()
            args = mock_create.call_args[0]

            # Check filename
            assert args[0] == 'test.rrd'
            # Check step parameter
            assert '--step' in args
            assert '5' in args
            # Check start parameter
            assert '--start' in args
            # Check data source definition
            assert 'DS:in-octets:COUNTER:10:0:U' in args

    @pytest.mark.unit
    def test_create_with_multiple_metrics(self):
        """Test creating RRD with multiple metrics (POC scenario)."""
        with patch('rrdtool.create') as mock_create:
            metrics = ['in-octets', 'out-octets']
            create_rrd(
                filename='interface-traffic.rrd',
                metrics=metrics,
                step=5,
                ds_type='COUNTER'
            )

            args = mock_create.call_args[0]

            # Both data sources should be defined
            assert 'DS:in-octets:COUNTER:10:0:U' in args
            assert 'DS:out-octets:COUNTER:10:0:U' in args

    @pytest.mark.unit
    def test_create_with_custom_step(self):
        """Test creating RRD with custom step interval."""
        with patch('rrdtool.create') as mock_create:
            create_rrd(
                filename='test.rrd',
                metrics=['metric1'],
                step=10,
                ds_type='COUNTER'
            )

            args = mock_create.call_args[0]

            # Heartbeat should be 2x step
            assert 'DS:metric1:COUNTER:20:0:U' in args

    @pytest.mark.unit
    def test_create_with_gauge_type(self):
        """Test creating RRD with GAUGE data source type."""
        with patch('rrdtool.create') as mock_create:
            create_rrd(
                filename='test.rrd',
                metrics=['temperature'],
                step=60,
                ds_type='GAUGE'
            )

            args = mock_create.call_args[0]

            assert 'DS:temperature:GAUGE:120:0:U' in args

    @pytest.mark.unit
    def test_create_with_rra_archives(self):
        """Test that RRA archives are created (POC format)."""
        with patch('rrdtool.create') as mock_create:
            create_rrd(
                filename='test.rrd',
                metrics=['metric1'],
                step=5
            )

            args = mock_create.call_args[0]

            # POC creates two RRAs:
            # - 5-second data for 2 years: RRA:AVERAGE:0.5:1:12614400
            # - 1-minute average for 5 years: RRA:AVERAGE:0.5:12:2628000
            assert 'RRA:AVERAGE:0.5:1:12614400' in args
            assert 'RRA:AVERAGE:0.5:12:2628000' in args

    @pytest.mark.unit
    def test_create_empty_metrics_list(self):
        """Test that empty metrics list raises error."""
        with pytest.raises(ValueError):
            create_rrd(filename='test.rrd', metrics=[], step=5)

    @pytest.mark.unit
    def test_create_invalid_step(self):
        """Test that invalid step raises error."""
        with pytest.raises(ValueError):
            create_rrd(filename='test.rrd', metrics=['m1'], step=0)
        with pytest.raises(ValueError):
            create_rrd(filename='test.rrd', metrics=['m1'], step=-5)

    @pytest.mark.unit
    def test_create_invalid_ds_type(self):
        """Test that invalid DS type raises error."""
        with pytest.raises(ValueError):
            create_rrd(
                filename='test.rrd',
                metrics=['m1'],
                step=5,
                ds_type='INVALID'
            )

    @pytest.mark.unit
    def test_create_rrdtool_error(self):
        """Test handling of rrdtool creation errors."""
        with patch('rrdtool.create', side_effect=Exception("RRD error")):
            with pytest.raises(Exception, match="RRD error"):
                create_rrd(
                    filename='test.rrd',
                    metrics=['m1'],
                    step=5
                )


class TestUpdateRrd:
    """Test RRD file updates."""

    @pytest.mark.unit
    def test_update_single_value(self):
        """Test updating RRD with single metric value."""
        with patch('rrdtool.update') as mock_update:
            update_rrd(
                filename='test.rrd',
                timestamp=1234567890,
                values={'metric1': 1000}
            )

            # RRD update format: timestamp:value
            mock_update.assert_called_once_with('test.rrd', '1234567890:1000')

    @pytest.mark.unit
    def test_update_multiple_values(self):
        """Test updating RRD with multiple metrics (POC scenario)."""
        with patch('rrdtool.update') as mock_update:
            update_rrd(
                filename='interface-traffic.rrd',
                timestamp=1234567890,
                values={'in-octets': 1000, 'out-octets': 2000}
            )

            # Values should be in order
            args = mock_update.call_args[0]
            assert args[0] == 'interface-traffic.rrd'
            # Update string should contain timestamp and both values
            assert '1234567890' in args[1]
            assert '1000' in args[1]
            assert '2000' in args[1]

    @pytest.mark.unit
    def test_update_with_current_timestamp(self):
        """Test updating with 'N' for current time."""
        with patch('rrdtool.update') as mock_update:
            update_rrd(
                filename='test.rrd',
                timestamp='N',
                values={'metric1': 500}
            )

            args = mock_update.call_args[0]
            assert 'N:500' in args[1]

    @pytest.mark.unit
    def test_update_empty_values(self):
        """Test that empty values dict raises error."""
        with pytest.raises(ValueError):
            update_rrd(
                filename='test.rrd',
                timestamp=1234567890,
                values={}
            )

    @pytest.mark.unit
    def test_update_none_values(self):
        """Test that None values raises error."""
        with pytest.raises((ValueError, TypeError)):
            update_rrd(
                filename='test.rrd',
                timestamp=1234567890,
                values=None
            )

    @pytest.mark.unit
    def test_update_invalid_timestamp(self):
        """Test that invalid timestamp raises error."""
        with pytest.raises((ValueError, TypeError)):
            update_rrd(
                filename='test.rrd',
                timestamp='invalid',
                values={'m1': 100}
            )

    @pytest.mark.unit
    def test_update_rrdtool_error(self):
        """Test handling of rrdtool update errors."""
        with patch('rrdtool.update', side_effect=Exception("illegal attempt to update")):
            with pytest.raises(Exception, match="illegal attempt"):
                update_rrd(
                    filename='test.rrd',
                    timestamp=1234567890,
                    values={'m1': 100}
                )


class TestStoreResults:
    """Test storing POC-format results to RRD."""

    @pytest.mark.unit
    def test_store_results_basic(self):
        """Test storing POC-style results list to RRD."""
        with patch('rrdtool.update') as mock_update:
            # POC format: list of dicts with 'path' and 'val' keys
            results = [
                {'path': 'out-octets', 'val': 1000},
                {'path': 'in-octets', 'val': 2000}
            ]

            store_results(
                results=results,
                timestamp=1234567890,
                filename='test.rrd'
            )

            # Should call update with timestamp and values
            mock_update.assert_called_once()
            args = mock_update.call_args[0]
            assert args[0] == 'test.rrd'
            update_str = args[1]
            assert update_str.startswith('1234567890:')
            assert ':1000:2000' in update_str

    @pytest.mark.unit
    def test_store_results_with_none_values(self):
        """Test that None results are filtered out."""
        with patch('rrdtool.update') as mock_update:
            results = [
                {'path': 'metric1', 'val': 100},
                None,  # Should be skipped
                {'path': 'metric2', 'val': 200}
            ]

            store_results(
                results=results,
                timestamp=1234567890,
                filename='test.rrd'
            )

            # Should only include non-None values
            args = mock_update.call_args[0]
            assert ':100:200' in args[1]

    @pytest.mark.unit
    def test_store_results_nanosecond_timestamp(self):
        """Test converting nanosecond timestamp to seconds (POC behavior)."""
        with patch('rrdtool.update') as mock_update:
            results = [{'path': 'm1', 'val': 100}]

            # POC uses nanosecond timestamps
            ns_timestamp = 1234567890 * 1000000000

            store_results(
                results=results,
                timestamp=ns_timestamp,
                filename='test.rrd'
            )

            # Should convert to seconds
            args = mock_update.call_args[0]
            assert args[1].startswith('1234567890:')

    @pytest.mark.unit
    def test_store_results_empty_list(self):
        """Test that empty results list raises error or skips update."""
        with patch('rrdtool.update') as mock_update:
            results = []

            # Should either raise error or not call update
            try:
                store_results(
                    results=results,
                    timestamp=1234567890,
                    filename='test.rrd'
                )
                # If it doesn't raise, it should not call update
                mock_update.assert_not_called()
            except ValueError:
                # If it raises ValueError, that's also acceptable
                pass

    @pytest.mark.unit
    def test_store_results_preserves_order(self):
        """Test that metric order is preserved for RRD update."""
        with patch('rrdtool.update') as mock_update:
            # Order matters for RRD updates (must match DS definition order)
            results = [
                {'path': 'first', 'val': 111},
                {'path': 'second', 'val': 222},
                {'path': 'third', 'val': 333}
            ]

            store_results(
                results=results,
                timestamp=1234567890,
                filename='test.rrd'
            )

            args = mock_update.call_args[0]
            # Values should be in order
            assert ':111:222:333' in args[1]


class TestCreateRrdHeartbeat:
    """Test heartbeat parameter in RRD file creation (P1.3)."""

    @pytest.mark.unit
    def test_default_heartbeat_is_step_times_2(self):
        """Default heartbeat is step * 2 when no heartbeat arg is passed."""
        with patch('rrdtool.create') as mock_create:
            create_rrd(filename='test.rrd', metrics=['m1'], step=5)
            args = mock_create.call_args[0]
            assert 'DS:m1:COUNTER:10:0:U' in args  # heartbeat = 5 * 2 = 10

    @pytest.mark.unit
    def test_explicit_heartbeat_overrides_default(self):
        """Explicit heartbeat parameter overrides step * 2 default."""
        with patch('rrdtool.create') as mock_create:
            create_rrd(filename='test.rrd', metrics=['m1'], step=5, heartbeat=120)
            args = mock_create.call_args[0]
            assert 'DS:m1:COUNTER:120:0:U' in args

    @pytest.mark.unit
    @pytest.mark.parametrize('poller_interval,expected_heartbeat', [
        (10, 20),
        (60, 120),
        (300, 600),
    ])
    def test_poller_interval_derived_heartbeat(self, poller_interval, expected_heartbeat):
        """Heartbeat derived from poller_interval * 2 is used correctly."""
        with patch('rrdtool.create') as mock_create:
            create_rrd(
                filename='test.rrd',
                metrics=['in_octets'],
                step=5,
                heartbeat=poller_interval * 2
            )
            args = mock_create.call_args[0]
            assert f'DS:in_octets:COUNTER:{expected_heartbeat}:0:U' in args

    @pytest.mark.unit
    def test_heartbeat_applies_to_all_metrics(self):
        """Custom heartbeat applies to every DS in the RRD."""
        with patch('rrdtool.create') as mock_create:
            create_rrd(
                filename='test.rrd',
                metrics=['in_octets', 'out_octets'],
                step=5,
                heartbeat=600
            )
            args = mock_create.call_args[0]
            assert 'DS:in_octets:COUNTER:600:0:U' in args
            assert 'DS:out_octets:COUNTER:600:0:U' in args

    @pytest.mark.unit
    def test_heartbeat_none_falls_back_to_step_times_2(self):
        """Passing heartbeat=None explicitly still uses step * 2."""
        with patch('rrdtool.create') as mock_create:
            create_rrd(filename='test.rrd', metrics=['m1'], step=10, heartbeat=None)
            args = mock_create.call_args[0]
            assert 'DS:m1:COUNTER:20:0:U' in args  # 10 * 2 = 20


class TestRrdIntegration:
    """Integration-style tests for RRD workflow."""

    @pytest.mark.unit
    def test_full_workflow_create_and_update(self):
        """Test complete workflow: check existence, create if needed, update."""
        with patch('os.path.isfile', return_value=False):
            with patch('rrdtool.create') as mock_create:
                with patch('rrdtool.update') as mock_update:
                    filename = 'new.rrd'
                    metrics = ['in-octets', 'out-octets']

                    # Check if file exists
                    if not rrd_file_exists(filename):
                        # Create RRD file
                        create_rrd(filename, metrics, step=5)

                    # Update with data
                    update_rrd(
                        filename=filename,
                        timestamp=1234567890,
                        values={'in-octets': 1000, 'out-octets': 2000}
                    )

                    # Verify both operations occurred
                    mock_create.assert_called_once()
                    mock_update.assert_called_once()

    @pytest.mark.unit
    def test_workflow_existing_file_skip_create(self):
        """Test that existing RRD files skip creation."""
        with patch('os.path.isfile', return_value=True):
            with patch('rrdtool.create') as mock_create:
                with patch('rrdtool.update') as mock_update:
                    filename = 'existing.rrd'
                    metrics = ['metric1']

                    # Check if file exists
                    if not rrd_file_exists(filename):
                        create_rrd(filename, metrics, step=5)

                    # Update with data
                    update_rrd(
                        filename=filename,
                        timestamp=1234567890,
                        values={'metric1': 500}
                    )

                    # Create should NOT be called
                    mock_create.assert_not_called()
                    # But update should be called
                    mock_update.assert_called_once()

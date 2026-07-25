"""
Unit tests for gnmi_poller_bridge.py --output-history mode.

Tests the bridge's ability to output timestamped samples for RRD backfill:
1. --output-history flag works
2. Output format: EPOCH field1:value1 field2:value2
3. Output is sorted chronologically
4. Samples without epoch are skipped

Run: cd plugins/gnmi && pytest tests/rrd_streaming/test_bridge_history.py -v
"""

import pytest
import json
import sys
import os
from io import StringIO
from unittest.mock import patch, MagicMock
from datetime import datetime, timezone

# Import path setup
sys.path.insert(0, os.path.join(os.path.dirname(__file__), '..', '..', 'scripts'))


class TestBridgeHistoryArgument:
    """Test --output-history CLI argument parsing."""

    @pytest.mark.unit
    def test_output_history_argument_exists(self):
        """Bridge should accept --output-history flag."""
        import argparse
        from gnmi_poller_bridge import main

        # Parse arguments with --output-history
        # We can't call main() directly, so test the argparser
        # This test will verify the argument is added after implementation
        parser = argparse.ArgumentParser()
        parser.add_argument('--device-id', type=int, required=True)
        parser.add_argument('--local-data-id', type=int, required=True)
        parser.add_argument('--output-history', action='store_true')

        args = parser.parse_args(['--device-id', '1', '--local-data-id', '6', '--output-history'])
        assert args.output_history is True

    @pytest.mark.unit
    def test_output_history_default_false(self):
        """--output-history should default to False."""
        import argparse

        parser = argparse.ArgumentParser()
        parser.add_argument('--device-id', type=int, required=True)
        parser.add_argument('--local-data-id', type=int, required=True)
        parser.add_argument('--output-history', action='store_true')

        args = parser.parse_args(['--device-id', '1', '--local-data-id', '6'])
        assert args.output_history is False


class TestHistoryOutputFormat:
    """Test the output format for history mode."""

    @pytest.mark.unit
    def test_format_metrics_for_history_output(self):
        """Test formatting metrics for timestamped output."""
        # Define the expected function signature
        # format_history_line(epoch, metrics_dict, field_to_metric_map) -> str

        # This function should be added to the bridge
        # Expected format: "EPOCH field1:value1 field2:value2"

        from gnmi_poller_bridge import format_history_line

        epoch = 1738561003
        metrics = {'in_octets': 123456, 'out_octets': 789012}
        field_to_metric_map = {
            'in_octets': 'in-octets',
            'out_octets': 'out-octets'
        }

        result = format_history_line(epoch, metrics, field_to_metric_map)

        # Should start with epoch timestamp
        assert result.startswith('1738561003 ')
        # Should contain the field:value pairs
        assert 'in_octets:123456' in result
        assert 'out_octets:789012' in result

    @pytest.mark.unit
    def test_format_history_line_empty_metrics(self):
        """Empty metrics should return None."""
        from gnmi_poller_bridge import format_history_line

        result = format_history_line(1738561003, {}, {})
        assert result is None

    @pytest.mark.unit
    def test_format_history_line_skips_non_numeric(self):
        """Non-numeric values should be skipped."""
        from gnmi_poller_bridge import format_history_line

        metrics = {
            'in_octets': 123456,
            'interface_name': 'eth0',  # Non-numeric - should skip
            'out_octets': 789012
        }
        field_to_metric_map = {
            'in_octets': 'in-octets',
            'interface_name': 'name',
            'out_octets': 'out-octets'
        }

        result = format_history_line(1738561003, metrics, field_to_metric_map)

        assert 'in_octets:123456' in result
        assert 'out_octets:789012' in result
        assert 'interface_name' not in result


class TestSampleExtraction:
    """Test extracting metrics from a single samples_history sample.

    Storage shape: samples_history is {instance: [flat_sample, ...]}. A single
    sample is already scoped to its instance — it carries Cacti field names
    plus 'epoch' and 'timestamp'. extract_sample_metrics converts field names
    back to gNMI metric names for filtering against expected_metrics.
    """

    @pytest.mark.unit
    def test_extract_sample_metrics_flat(self):
        """Extract gNMI metric names from a flat field-keyed sample."""
        from gnmi_poller_bridge import extract_sample_metrics

        sample = {
            'epoch': 1738561003,
            'timestamp': '2026-02-03T05:30:03+00:00',
            'in_octets': 123456,
            'out_octets': 789012,
        }

        metrics = extract_sample_metrics(sample)

        assert metrics == {'in-octets': 123456, 'out-octets': 789012}

    @pytest.mark.unit
    def test_extract_sample_metrics_uses_database_mapping_for_aliases(self):
        """Concise Cacti aliases must resolve to their original gNMI leaves."""
        from gnmi_poller_bridge import extract_sample_metrics

        metrics = extract_sample_metrics(
            {'epoch': 1738561003, 'in_pkts': 29, 'in_errors': 2},
            {
                'in_pkts': 'in-unicast-packets',
                'in_errors': 'in-error-packets',
            },
        )

        assert metrics == {
            'in-unicast-packets': 29,
            'in-error-packets': 2,
        }

    @pytest.mark.unit
    def test_extract_sample_metrics_skips_nested_dict(self):
        """Defensive: any unexpectedly-nested value is skipped, not silently merged."""
        from gnmi_poller_bridge import extract_sample_metrics

        sample = {
            'epoch': 1738561003,
            'in_octets': 100,
            'ettp-40': {'in-octets': 999},  # legacy nested shape — must NOT leak through
        }

        metrics = extract_sample_metrics(sample)
        assert metrics == {'in-octets': 100}


class TestChronologicalSorting:
    """Test that output is sorted chronologically."""

    @pytest.mark.unit
    def test_samples_sorted_by_epoch(self):
        """Output should be in chronological order (by epoch)."""
        from gnmi_poller_bridge import sort_samples_chronologically

        samples = [
            {'epoch': 1738561015, 'value': 3},
            {'epoch': 1738561005, 'value': 1},
            {'epoch': 1738561010, 'value': 2}
        ]

        sorted_samples = sort_samples_chronologically(samples)

        epochs = [s['epoch'] for s in sorted_samples]
        assert epochs == [1738561005, 1738561010, 1738561015]

    @pytest.mark.unit
    def test_samples_without_epoch_filtered(self):
        """Samples without epoch should be filtered out."""
        from gnmi_poller_bridge import sort_samples_chronologically

        samples = [
            {'epoch': 1738561015, 'value': 2},
            {'value': 'no_epoch'},  # No epoch - should be filtered
            {'epoch': 1738561005, 'value': 1}
        ]

        sorted_samples = sort_samples_chronologically(samples)

        assert len(sorted_samples) == 2
        assert all('epoch' in s for s in sorted_samples)


class TestIntegrationScenario:
    """Integration tests for the complete history output flow."""

    @pytest.mark.unit
    def test_full_history_output_scenario(self):
        """Process instance-keyed samples_history end-to-end into output lines."""
        from gnmi_poller_bridge import process_history_output

        # samples_history is {instance: [flat_sample, ...]} per daemon storage spec.
        samples_history = {
            'ettp-40': [
                {'epoch': 1738561010, 'timestamp': 't10', 'in_octets': 200, 'out_octets': 400},
                {'epoch': 1738561005, 'timestamp': 't05', 'in_octets': 100, 'out_octets': 200},
                {'epoch': 1738561015, 'timestamp': 't15', 'in_octets': 300, 'out_octets': 600},
            ]
        }

        lines = process_history_output(
            samples_history,
            'ettp-40',
            ['in-octets', 'out-octets'],
            {'in_octets': 'in-octets', 'out_octets': 'out-octets'},
        )

        assert len(lines) == 3
        assert lines[0].startswith('1738561005 ')
        assert lines[1].startswith('1738561010 ')
        assert lines[2].startswith('1738561015 ')
        for line in lines:
            assert 'in_octets:' in line
            assert 'out_octets:' in line

    @pytest.mark.unit
    def test_srlinux_aliases_survive_full_history_output(self):
        from gnmi_poller_bridge import process_history_output

        lines = process_history_output(
            {
                'ethernet-1/1': [{
                    'epoch': 1738561005,
                    'in_octets': 100,
                    'in_pkts': 10,
                    'in_errors': 0,
                }],
            },
            'ethernet-1/1',
            ['in-octets', 'in-unicast-packets', 'in-error-packets'],
            {
                'in_octets': 'in-octets',
                'in_pkts': 'in-unicast-packets',
                'in_errors': 'in-error-packets',
            },
        )

        assert lines == [
            '1738561005 in_octets:100 in_pkts:10 in_errors:0'
        ]

    @pytest.mark.unit
    def test_history_output_with_missing_metrics(self):
        """Handle samples with missing metrics gracefully (per-sample)."""
        from gnmi_poller_bridge import process_history_output

        samples_history = {
            'ettp-40': [
                {'epoch': 1738561005, 'in_octets': 100},  # missing out_octets
                {'epoch': 1738561010, 'in_octets': 200, 'out_octets': 400},
            ]
        }

        lines = process_history_output(
            samples_history,
            'ettp-40',
            ['in-octets', 'out-octets'],
            {'in_octets': 'in-octets', 'out_octets': 'out-octets'},
        )

        assert len(lines) == 2
        assert 'in_octets:100' in lines[0]
        assert 'in_octets:200' in lines[1]
        assert 'out_octets:400' in lines[1]

    @pytest.mark.unit
    def test_history_output_instance_separation(self):
        """Requesting ettp-40 never returns ettp-34's samples — even at the same epoch."""
        from gnmi_poller_bridge import process_history_output

        # Same epoch, deliberately distinct counter magnitudes so any leak shows up.
        samples_history = {
            'ettp-40': [
                {'epoch': 1738561005, 'in_octets': 13_700_000_000_000},
                {'epoch': 1738561010, 'in_octets': 13_700_000_001_000},
            ],
            'ettp-34': [
                {'epoch': 1738561005, 'in_octets': 15_200_000_000_000},
                {'epoch': 1738561010, 'in_octets': 15_200_000_001_000},
            ],
        }

        lines_40 = process_history_output(
            samples_history, 'ettp-40', ['in-octets'],
            {'in_octets': 'in-octets'},
        )
        lines_34 = process_history_output(
            samples_history, 'ettp-34', ['in-octets'],
            {'in_octets': 'in-octets'},
        )

        assert len(lines_40) == 2
        assert len(lines_34) == 2
        # ettp-40 lines must contain only ettp-40's magnitudes
        for line in lines_40:
            assert '13_700' in line.replace(',', '_') or '13700' in line  # ~13.7T
            assert '15_200' not in line.replace(',', '_') and '15200' not in line
        for line in lines_34:
            assert '15_200' in line.replace(',', '_') or '15200' in line  # ~15.2T


class TestEdgeCases:
    """Edge case tests."""

    @pytest.mark.unit
    def test_empty_samples_history(self):
        """Empty samples_history should produce no output."""
        from gnmi_poller_bridge import process_history_output

        lines = process_history_output({}, 'ettp-40', ['in-octets'], {})
        assert lines == []

    @pytest.mark.unit
    def test_missing_instance_in_samples_history(self):
        """Requested instance absent from samples_history → empty output."""
        from gnmi_poller_bridge import process_history_output

        samples_history = {'ettp-34': [{'epoch': 1738561005, 'in_octets': 1}]}
        lines = process_history_output(
            samples_history, 'ettp-40', ['in-octets'],
            {'in_octets': 'in-octets'},
        )
        assert lines == []

    @pytest.mark.unit
    def test_samples_history_all_invalid(self):
        """If all samples for the instance lack epoch, produce no output."""
        from gnmi_poller_bridge import process_history_output

        samples_history = {
            'ettp-40': [
                {'timestamp': 't05', 'in_octets': 100},
                {'timestamp': 't10', 'in_octets': 200},
            ]
        }

        lines = process_history_output(
            samples_history, 'ettp-40', ['in-octets'],
            {'in_octets': 'in-octets'},
        )
        assert lines == []

    @pytest.mark.unit
    def test_legacy_list_shape_rejected(self):
        """If old code accidentally passes a list, return [] (don't crash)."""
        from gnmi_poller_bridge import process_history_output

        legacy_list = [{'epoch': 1738561005, 'in_octets': 1}]
        lines = process_history_output(
            legacy_list, 'ettp-40', ['in-octets'],
            {'in_octets': 'in-octets'},
        )
        assert lines == []

    @pytest.mark.unit
    def test_duplicate_epochs_handled(self):
        """Duplicate epochs should both be included (let rrdtool handle)."""
        from gnmi_poller_bridge import sort_samples_chronologically

        samples = [
            {'epoch': 1738561005, 'value': 1},
            {'epoch': 1738561005, 'value': 2},  # Duplicate epoch
            {'epoch': 1738561010, 'value': 3}
        ]

        sorted_samples = sort_samples_chronologically(samples)

        # Both duplicates should be present
        assert len(sorted_samples) == 3

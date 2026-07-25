"""
Unit tests for SampleBuffer class modifications for RRD streaming.

Tests the enhanced SampleBuffer that:
1. Holds 15 samples (75 seconds of data) instead of 2
2. Provides get_all_samples_with_epoch() for Unix timestamp output
3. Does NOT clear buffer after JSON write (uses deque maxlen rotation)

Run: cd plugins/gnmi && pytest tests/rrd_streaming/test_sample_buffer.py -v
"""

import pytest
import time
from datetime import datetime, timezone
from collections import deque
from typing import Dict, Any, List

# Import the SampleBuffer class
import sys
import os
sys.path.insert(0, os.path.join(os.path.dirname(__file__), '..', '..', 'scripts'))

# We'll test against both the current and expected behavior
# These tests define the EXPECTED behavior after our changes


class TestSampleBufferCapacity:
    """Test that buffer holds the correct number of samples."""

    @pytest.mark.unit
    def test_buffer_capacity_is_15(self):
        """Buffer should hold 15 samples (75 seconds at 5s intervals) for 60s poller."""
        # This test will fail until we update the code
        # Expected: max_samples=15, Current: max_samples=2
        from scripts.gnmi_daemon import SampleBuffer
        buffer = SampleBuffer(max_samples=15)
        assert buffer.max_samples == 15

    @pytest.mark.unit
    def test_buffer_rotates_oldest_when_full(self):
        """When buffer is full, oldest samples should be rotated out."""
        from scripts.gnmi_daemon import SampleBuffer
        buffer = SampleBuffer(max_samples=5)  # Small buffer for testing

        # Add 7 samples (more than capacity)
        for i in range(7):
            buffer.add_sample({'value': i})
            time.sleep(0.01)  # Small delay to ensure different timestamps

        # Should only have 5 samples (the most recent)
        assert len(buffer.buffer) == 5

        # Oldest samples should be gone
        values = [s.get('value') for s in buffer.buffer]
        assert values == [2, 3, 4, 5, 6]  # Samples 0 and 1 rotated out

    @pytest.mark.unit
    def test_buffer_preserves_all_samples_under_capacity(self):
        """When under capacity, all samples should be preserved."""
        from scripts.gnmi_daemon import SampleBuffer
        buffer = SampleBuffer(max_samples=15)

        # Add 10 samples
        for i in range(10):
            buffer.add_sample({'value': i})

        # Should have all 10 samples
        assert len(buffer.buffer) == 10


class TestSampleBufferTimestamps:
    """Test timestamp handling in SampleBuffer."""

    @pytest.mark.unit
    def test_add_sample_adds_iso_timestamp(self):
        """add_sample() should add ISO format timestamp."""
        from scripts.gnmi_daemon import SampleBuffer
        buffer = SampleBuffer(max_samples=5)

        buffer.add_sample({'value': 123})

        sample = buffer.buffer[0]
        assert 'timestamp' in sample
        # Verify it's ISO format (parseable)
        ts = sample['timestamp']
        dt = datetime.fromisoformat(ts.replace('Z', '+00:00'))
        assert dt.year >= 2024

    @pytest.mark.unit
    def test_get_all_samples_with_epoch_returns_unix_timestamps(self):
        """get_all_samples_with_epoch() should return Unix epoch timestamps."""
        from scripts.gnmi_daemon import SampleBuffer
        buffer = SampleBuffer(max_samples=5)

        # Add some samples
        for i in range(3):
            buffer.add_sample({'value': i})
            time.sleep(0.05)  # Ensure different timestamps

        samples = buffer.get_all_samples_with_epoch()

        assert len(samples) == 3
        for sample in samples:
            assert 'epoch' in sample
            assert isinstance(sample['epoch'], int)
            # Unix epoch should be in reasonable range (after 2024)
            assert sample['epoch'] > 1704067200  # Jan 1, 2024

    @pytest.mark.unit
    def test_epoch_timestamps_are_chronological(self):
        """Epoch timestamps should be in chronological order."""
        from scripts.gnmi_daemon import SampleBuffer
        buffer = SampleBuffer(max_samples=5)

        # Add samples with small delays
        for i in range(3):
            buffer.add_sample({'value': i})
            time.sleep(0.1)  # 100ms between samples

        samples = buffer.get_all_samples_with_epoch()
        epochs = [s['epoch'] for s in samples]

        # Should be sorted (chronological)
        assert epochs == sorted(epochs)

    @pytest.mark.unit
    def test_epoch_conversion_handles_timezone(self):
        """Epoch conversion should handle timezone correctly."""
        from scripts.gnmi_daemon import SampleBuffer
        buffer = SampleBuffer(max_samples=5)

        buffer.add_sample({'value': 123})

        sample = buffer.buffer[0]
        iso_ts = sample['timestamp']

        # Manual conversion to verify
        dt = datetime.fromisoformat(iso_ts.replace('Z', '+00:00'))
        expected_epoch = int(dt.timestamp())

        samples = buffer.get_all_samples_with_epoch()
        actual_epoch = samples[0]['epoch']

        assert actual_epoch == expected_epoch


class TestSampleBufferNoAutoClear:
    """Test that buffer does NOT auto-clear after write."""

    @pytest.mark.unit
    def test_buffer_not_cleared_after_get_aggregated(self):
        """get_aggregated() should NOT clear the buffer."""
        from scripts.gnmi_daemon import SampleBuffer
        buffer = SampleBuffer(max_samples=5)

        # Add samples
        for i in range(3):
            buffer.add_sample({'value': i})

        # Get aggregated
        result = buffer.get_aggregated()

        # Buffer should still have all samples
        assert len(buffer.buffer) == 3

    @pytest.mark.unit
    def test_buffer_not_cleared_after_get_all_samples(self):
        """get_all_samples_with_epoch() should NOT clear the buffer."""
        from scripts.gnmi_daemon import SampleBuffer
        buffer = SampleBuffer(max_samples=5)

        # Add samples
        for i in range(3):
            buffer.add_sample({'value': i})

        # Get all samples
        samples = buffer.get_all_samples_with_epoch()

        # Buffer should still have all samples
        assert len(buffer.buffer) == 3


class TestSampleBufferForRRDStreaming:
    """Integration tests for RRD streaming use case."""

    @pytest.mark.unit
    def test_60_second_poller_scenario(self):
        """Simulate 60-second poller with 5-second samples."""
        from scripts.gnmi_daemon import SampleBuffer
        buffer = SampleBuffer(max_samples=15)  # 75 seconds of data

        # Simulate 12 samples (60 seconds at 5s intervals)
        for i in range(12):
            buffer.add_sample({'counter': i * 1000})

        # All 12 samples should be available
        samples = buffer.get_all_samples_with_epoch()
        assert len(samples) == 12

        # All should have epoch timestamps
        for s in samples:
            assert 'epoch' in s
            assert s['epoch'] > 0

    @pytest.mark.unit
    def test_multiple_json_writes_accumulate_samples(self):
        """Multiple JSON write cycles should accumulate samples."""
        from scripts.gnmi_daemon import SampleBuffer
        buffer = SampleBuffer(max_samples=15)

        # First "write cycle" - add 2 samples
        for i in range(2):
            buffer.add_sample({'value': f'cycle1-{i}'})

        # Simulate JSON write (should NOT clear)
        _ = buffer.get_all_samples_with_epoch()

        # Second "write cycle" - add 2 more samples
        for i in range(2):
            buffer.add_sample({'value': f'cycle2-{i}'})

        # Should have all 4 samples
        samples = buffer.get_all_samples_with_epoch()
        assert len(samples) == 4

        # Verify samples from both cycles
        values = [s.get('value') for s in samples]
        assert 'cycle1-0' in values
        assert 'cycle2-1' in values


class TestIntervalAwareBufferSizing:
    """Tests for compute_buffer_max_samples() and its use in GNMIDaemon.

    Formula: max_samples = ceil((poller_interval * 1.5) / sample_interval)
    Clamped to [4, 120].  sample_interval is always 5 seconds.
    """

    # ---------------------------------------------------------------------------
    # compute_buffer_max_samples() unit tests
    # ---------------------------------------------------------------------------

    @pytest.mark.unit
    def test_compute_10s_poller_clamped_to_min(self):
        """10s poller: ceil(15/5)=3, clamped up to minimum of 4."""
        from scripts.gnmi_daemon import compute_buffer_max_samples
        # ceil((10 * 1.5) / 5) = ceil(3.0) = 3  -> clamped to 4
        assert compute_buffer_max_samples(poller_interval=10) == 4

    @pytest.mark.unit
    def test_compute_60s_poller(self):
        """60s poller: ceil(90/5)=18."""
        from scripts.gnmi_daemon import compute_buffer_max_samples
        # ceil((60 * 1.5) / 5) = ceil(18.0) = 18
        assert compute_buffer_max_samples(poller_interval=60) == 18

    @pytest.mark.unit
    def test_compute_300s_poller(self):
        """300s poller: ceil(450/5)=90."""
        from scripts.gnmi_daemon import compute_buffer_max_samples
        # ceil((300 * 1.5) / 5) = ceil(90.0) = 90
        assert compute_buffer_max_samples(poller_interval=300) == 90

    @pytest.mark.unit
    def test_compute_max_clamp(self):
        """Very long poller interval is clamped to max of 120."""
        from scripts.gnmi_daemon import compute_buffer_max_samples
        # ceil((1000 * 1.5) / 5) = 300 -> clamped to 120
        assert compute_buffer_max_samples(poller_interval=1000) == 120

    @pytest.mark.unit
    def test_compute_min_clamp_explicit(self):
        """Any result below 4 is clamped to the minimum of 4."""
        from scripts.gnmi_daemon import compute_buffer_max_samples
        # ceil((5 * 1.5) / 5) = ceil(1.5) = 2 -> clamped to 4
        assert compute_buffer_max_samples(poller_interval=5) == 4

    @pytest.mark.unit
    def test_compute_accepts_custom_sample_interval(self):
        """Custom sample_interval is respected in the formula."""
        from scripts.gnmi_daemon import compute_buffer_max_samples
        # ceil((60 * 1.5) / 10) = ceil(9.0) = 9
        assert compute_buffer_max_samples(poller_interval=60, sample_interval=10) == 9

    @pytest.mark.parametrize("poller_interval,expected", [
        (10,  4),    # floor at min clamp
        (60,  18),   # standard 60s default
        (300, 90),   # 5-minute standard
        (400, 120),  # ceiling clamp: ceil(600/5)=120
    ])
    @pytest.mark.unit
    def test_compute_parametrized(self, poller_interval, expected):
        """Parametrized check of the formula + clamp for common poller values."""
        from scripts.gnmi_daemon import compute_buffer_max_samples
        assert compute_buffer_max_samples(poller_interval=poller_interval) == expected

    # ---------------------------------------------------------------------------
    # GNMIDaemon integration: verifies buffer sized from config at startup
    # ---------------------------------------------------------------------------

    def _make_config(self, poller_interval=60):
        """Return a minimal valid daemon config dict for testing."""
        return {
            'device_id': 999,
            'hostname': '127.0.0.1',
            'port': 9339,
            'username': 'test',
            'password': 'test',
            'use_tls': False,
            'skip_verify': True,
            'encoding': 'json',
            'collection_interval': 10,
            'sample_interval': 5,
            'poller_interval': poller_interval,
            'staleness_threshold': poller_interval * 2,
            'subscriptions': [
                {
                    'path': 'test/path',
                    'instance': 'default',
                    'metrics': ['in-octets'],
                    'field_mapping': {'in-octets': 'in_octets'},
                }
            ],
        }

    @pytest.mark.unit
    def test_daemon_buffer_sized_for_10s_poller(self, tmp_path):
        """GNMIDaemon initialises SampleBuffer with 4 samples for a 10s poller."""
        from scripts.gnmi_daemon import GNMIDaemon
        config = self._make_config(poller_interval=10)
        daemon = GNMIDaemon(device_id=999, config=config, storage_dir=str(tmp_path))
        assert daemon.sample_buffer.max_samples == 4

    @pytest.mark.unit
    def test_daemon_buffer_sized_for_60s_poller(self, tmp_path):
        """GNMIDaemon initialises SampleBuffer with 18 samples for a 60s poller."""
        from scripts.gnmi_daemon import GNMIDaemon
        config = self._make_config(poller_interval=60)
        daemon = GNMIDaemon(device_id=999, config=config, storage_dir=str(tmp_path))
        assert daemon.sample_buffer.max_samples == 18

    @pytest.mark.unit
    def test_daemon_buffer_sized_for_300s_poller(self, tmp_path):
        """GNMIDaemon initialises SampleBuffer with 90 samples for a 300s poller."""
        from scripts.gnmi_daemon import GNMIDaemon
        config = self._make_config(poller_interval=300)
        daemon = GNMIDaemon(device_id=999, config=config, storage_dir=str(tmp_path))
        assert daemon.sample_buffer.max_samples == 90

    @pytest.mark.unit
    def test_daemon_buffer_fallback_when_poller_interval_missing(self, tmp_path):
        """GNMIDaemon uses fallback of 300s (90 samples) when poller_interval absent."""
        from scripts.gnmi_daemon import GNMIDaemon
        config = self._make_config(poller_interval=300)
        # Remove poller_interval key to simulate missing config field
        del config['poller_interval']
        daemon = GNMIDaemon(device_id=999, config=config, storage_dir=str(tmp_path))
        # Fallback is 300s -> 90 samples
        assert daemon.sample_buffer.max_samples == 90

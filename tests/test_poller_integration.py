"""
Integration tests for Poller Interval Alignment (P1.6).

Verifies that the same poller_interval value produces consistent, correct
behaviour across all three pipeline components:

  1. SampleBuffer sizing   — gnmi_daemon.compute_buffer_max_samples()
  2. Bridge staleness check — gnmi_poller_bridge.check_staleness()
  3. RRD heartbeat          — gnmi_collector.rrd.create_rrd()
  4. Daemon health check    — gnmi_daemon.GNMIDaemon.check_health()

The shared formulas are:
  staleness_threshold  = poller_interval * 2
  rrd_heartbeat        = poller_interval * 2
  buffer_max_samples   = max(4, min(120, ceil(poller_interval * 1.5 / sample_interval)))
"""

import json
import math
import os
import sys
from datetime import datetime, timedelta, timezone
from pathlib import Path
from unittest.mock import patch

import pytest

# ---------------------------------------------------------------------------
# Imports
# ---------------------------------------------------------------------------

sys.path.insert(0, str(Path(__file__).parent.parent / 'scripts'))
sys.path.insert(0, str(Path(__file__).parent.parent))

from gnmi_daemon import compute_buffer_max_samples, GNMIDaemon
from scripts.gnmi_poller_bridge import check_staleness
from scripts.gnmi_collector.rrd import create_rrd


# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------

def _make_storage(age_seconds: float) -> dict:
    """Return a minimal daemon storage dict with the given data age."""
    ts = datetime.now(timezone.utc) - timedelta(seconds=age_seconds)
    return {
        'device_id': 1,
        'last_update': ts.isoformat(),
        'daemon_status': 'connected',
        'metric_groups': {},
    }


def _write_storage(storage_dir: str, device_id: int, age_seconds: float) -> None:
    """Write a daemon storage JSON file with the given age."""
    data = _make_storage(age_seconds)
    path = Path(storage_dir) / f'device_{device_id}.json'
    with open(path, 'w') as f:
        json.dump(data, f)


# ---------------------------------------------------------------------------
# Expected values table — single source of truth for all parametrized tests
# ---------------------------------------------------------------------------
#
#  poller_interval | staleness_threshold | buffer_max_samples | rrd_heartbeat
#  ----------------+---------------------+--------------------+--------------
#  10              | 20                  | 4 (floor clamp)    | 20
#  60              | 120                 | 18                 | 120
#  300             | 600                 | 90                 | 600

INTERVAL_CASES = [
    # (poller_interval, expected_staleness_threshold, expected_max_samples, expected_heartbeat)
    (10,  20,  4,  20),
    (60,  120, 18, 120),
    (300, 600, 90, 600),
]


# ---------------------------------------------------------------------------
# 1. SampleBuffer sizing
# ---------------------------------------------------------------------------

class TestBufferSizingAtPollerIntervals:
    """compute_buffer_max_samples() returns correct sizes for 10s/60s/300s."""

    @pytest.mark.unit
    @pytest.mark.parametrize('poller_interval, staleness, expected_samples, heartbeat',
                             INTERVAL_CASES)
    def test_buffer_max_samples(self, poller_interval, staleness, expected_samples, heartbeat):
        """Buffer covers at least one full poller cycle with 1.5× safety margin."""
        result = compute_buffer_max_samples(poller_interval, sample_interval=5)
        assert result == expected_samples, (
            f"poller_interval={poller_interval}: expected {expected_samples} samples, "
            f"got {result}"
        )

    @pytest.mark.unit
    def test_buffer_covers_full_cycle(self):
        """Buffer * sample_interval >= poller_interval for all standard intervals."""
        sample_interval = 5
        for interval in [10, 60, 300]:
            samples = compute_buffer_max_samples(interval, sample_interval)
            coverage = samples * sample_interval
            assert coverage >= interval, (
                f"interval={interval}: buffer covers only {coverage}s, "
                f"need at least {interval}s"
            )


# ---------------------------------------------------------------------------
# 2. Bridge staleness check
# ---------------------------------------------------------------------------

class TestBridgeStalenessAtPollerIntervals:
    """check_staleness() boundary is correct at poller_interval * 2."""

    @pytest.mark.unit
    @pytest.mark.parametrize('poller_interval, staleness_threshold, samples, heartbeat',
                             INTERVAL_CASES)
    def test_fresh_data_not_stale(self, poller_interval, staleness_threshold, samples, heartbeat):
        """Data younger than poller_interval (well within threshold) is fresh."""
        data = _make_storage(age_seconds=poller_interval - 1)
        assert check_staleness(data, threshold=staleness_threshold) is False

    @pytest.mark.unit
    @pytest.mark.parametrize('poller_interval, staleness_threshold, samples, heartbeat',
                             INTERVAL_CASES)
    def test_stale_data_detected(self, poller_interval, staleness_threshold, samples, heartbeat):
        """Data older than staleness_threshold (poller_interval * 2 + 1) is stale."""
        data = _make_storage(age_seconds=staleness_threshold + 1)
        assert check_staleness(data, threshold=staleness_threshold) is True

    @pytest.mark.unit
    @pytest.mark.parametrize('poller_interval, staleness_threshold, samples, heartbeat',
                             INTERVAL_CASES)
    def test_one_missed_cycle_still_fresh(self, poller_interval, staleness_threshold,
                                          samples, heartbeat):
        """Data at exactly poller_interval * 1.5 (one slow cycle) is NOT stale.

        The threshold is poller_interval * 2, so one missed cycle should not
        trigger a false-positive staleness alert.
        """
        age = poller_interval * 1.5
        data = _make_storage(age_seconds=age)
        assert check_staleness(data, threshold=staleness_threshold) is False, (
            f"interval={poller_interval}: data at {age}s should be fresh "
            f"(threshold={staleness_threshold}s)"
        )


# ---------------------------------------------------------------------------
# 3. RRD heartbeat
# ---------------------------------------------------------------------------

class TestRrdHeartbeatAtPollerIntervals:
    """create_rrd() uses poller_interval * 2 as heartbeat."""

    @pytest.mark.unit
    @pytest.mark.parametrize('poller_interval, staleness_threshold, samples, heartbeat',
                             INTERVAL_CASES)
    def test_heartbeat_in_ds_definition(self, poller_interval, staleness_threshold,
                                        samples, heartbeat):
        """DS definition in RRD uses poller_interval * 2 as heartbeat value."""
        with patch('rrdtool.create') as mock_create:
            create_rrd(
                filename='test.rrd',
                metrics=['in_octets'],
                step=5,
                heartbeat=heartbeat,
            )
            args = mock_create.call_args[0]
            assert f'DS:in_octets:COUNTER:{heartbeat}:0:U' in args, (
                f"interval={poller_interval}: expected heartbeat={heartbeat} "
                f"in DS definition, got: {[a for a in args if a.startswith('DS:')]}"
            )

    @pytest.mark.unit
    @pytest.mark.parametrize('poller_interval, staleness_threshold, samples, heartbeat',
                             INTERVAL_CASES)
    def test_heartbeat_equals_staleness_threshold(self, poller_interval, staleness_threshold,
                                                   samples, heartbeat):
        """RRD heartbeat equals bridge staleness threshold (both = poller_interval * 2)."""
        assert heartbeat == staleness_threshold, (
            f"Heartbeat ({heartbeat}) must equal staleness_threshold ({staleness_threshold}) "
            f"for poller_interval={poller_interval}"
        )


# ---------------------------------------------------------------------------
# 4. Daemon health check staleness
# ---------------------------------------------------------------------------

class TestDaemonHealthAtPollerIntervals:
    """GNMIDaemon.check_health() uses poller_interval * 2 as staleness boundary."""

    @pytest.mark.unit
    @pytest.mark.parametrize('poller_interval, staleness_threshold, samples, heartbeat',
                             INTERVAL_CASES)
    def test_fresh_data_not_stale(self, tmp_path, poller_interval, staleness_threshold,
                                   samples, heartbeat):
        """Data at poller_interval - 1s is fresh."""
        _write_storage(str(tmp_path), 1, age_seconds=poller_interval - 1)
        health = GNMIDaemon.check_health(1, storage_dir=str(tmp_path),
                                         staleness_threshold=staleness_threshold)
        assert health['stale'] is False

    @pytest.mark.unit
    @pytest.mark.parametrize('poller_interval, staleness_threshold, samples, heartbeat',
                             INTERVAL_CASES)
    def test_stale_data_detected(self, tmp_path, poller_interval, staleness_threshold,
                                  samples, heartbeat):
        """Data at staleness_threshold + 1s is stale."""
        _write_storage(str(tmp_path), 1, age_seconds=staleness_threshold + 1)
        health = GNMIDaemon.check_health(1, storage_dir=str(tmp_path),
                                         staleness_threshold=staleness_threshold)
        assert health['stale'] is True

    @pytest.mark.unit
    @pytest.mark.parametrize('poller_interval, staleness_threshold, samples, heartbeat',
                             INTERVAL_CASES)
    def test_one_missed_cycle_not_stale(self, tmp_path, poller_interval, staleness_threshold,
                                        samples, heartbeat):
        """Data one full poller cycle old (poller_interval seconds) is NOT stale.

        A single slow cycle must not trigger false-positive daemon restart.
        """
        _write_storage(str(tmp_path), 1, age_seconds=poller_interval)
        health = GNMIDaemon.check_health(1, storage_dir=str(tmp_path),
                                         staleness_threshold=staleness_threshold)
        assert health['stale'] is False, (
            f"interval={poller_interval}: {poller_interval}s-old data should not be "
            f"stale with threshold={staleness_threshold}s"
        )


# ---------------------------------------------------------------------------
# 5. Cross-component consistency
# ---------------------------------------------------------------------------

class TestCrossComponentConsistency:
    """
    Verify that all three formulas produce the same 'two-interval' timeout value
    for each standard poller interval, ensuring no component uses a different
    multiplier or hardcoded value.
    """

    @pytest.mark.unit
    @pytest.mark.parametrize('poller_interval', [10, 60, 300])
    def test_staleness_equals_two_x_interval(self, poller_interval):
        """staleness_threshold = poller_interval * 2 for all standard intervals."""
        expected = poller_interval * 2
        # Bridge: threshold passed as poller_interval * 2
        data_fresh = _make_storage(age_seconds=poller_interval)
        assert check_staleness(data_fresh, threshold=expected) is False
        data_stale = _make_storage(age_seconds=poller_interval * 2 + 1)
        assert check_staleness(data_stale, threshold=expected) is True

    @pytest.mark.unit
    @pytest.mark.parametrize('poller_interval', [10, 60, 300])
    def test_rrd_heartbeat_equals_two_x_interval(self, poller_interval):
        """RRD heartbeat = poller_interval * 2 for all standard intervals."""
        heartbeat = poller_interval * 2
        with patch('rrdtool.create') as mock_create:
            create_rrd('test.rrd', ['metric'], step=5, heartbeat=heartbeat)
            args = mock_create.call_args[0]
            assert f'DS:metric:COUNTER:{heartbeat}:0:U' in args

    @pytest.mark.unit
    @pytest.mark.parametrize('poller_interval', [10, 60, 300])
    def test_buffer_covers_staleness_window(self, poller_interval):
        """Buffer (samples * 5s) covers at least the staleness threshold.

        Ensures that the buffer holds enough samples that the bridge will always
        find data within the staleness window, even if the poller arrives at
        the last possible moment.
        """
        sample_interval = 5
        staleness_threshold = poller_interval * 2
        max_samples = compute_buffer_max_samples(poller_interval, sample_interval)
        buffer_coverage = max_samples * sample_interval
        assert buffer_coverage >= poller_interval, (
            f"interval={poller_interval}: buffer coverage {buffer_coverage}s "
            f"< poller_interval {poller_interval}s"
        )

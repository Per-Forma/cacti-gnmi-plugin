"""
Unit tests for GNMIDaemon.check_health() staleness threshold behaviour.

Verifies that the staleness check uses the poller-interval-derived threshold
instead of a hardcoded value, so daemons are not incorrectly flagged as stale
at 60s or 300s poller intervals.

Spec formula: staleness_threshold = poller_interval * 2
"""

import json
import os
import sys
import tempfile
from datetime import datetime, timedelta, timezone
from pathlib import Path

import pytest

# Make scripts importable
sys.path.insert(0, os.path.join(os.path.dirname(__file__), '..', 'scripts'))


# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------

def _write_storage(storage_dir: str, device_id: int, age_seconds: float,
                   status: str = 'connected') -> None:
    """Write a minimal daemon JSON storage file with a specific data age."""
    ts = datetime.now(timezone.utc) - timedelta(seconds=age_seconds)
    data = {
        'device_id': device_id,
        'last_update': ts.isoformat(),
        'daemon_status': status,
        'metric_groups': {},
    }
    path = Path(storage_dir) / f'device_{device_id}.json'
    with open(path, 'w') as f:
        json.dump(data, f)


def _write_pid(storage_dir: str, device_id: int, pid: int) -> None:
    """Write a PID file for a device."""
    path = Path(storage_dir) / f'device_{device_id}.pid'
    with open(path, 'w') as f:
        f.write(str(pid))


# ---------------------------------------------------------------------------
# Tests: threshold parameter on check_health()
# ---------------------------------------------------------------------------

class TestCheckHealthStalenessThreshold:
    """
    GNMIDaemon.check_health() must accept and honour a staleness_threshold
    parameter so PHP can pass poller_interval * 2 from each call site.
    """

    @pytest.mark.unit
    def test_not_stale_within_threshold(self, tmp_path):
        """Data younger than the threshold is not stale."""
        from gnmi_daemon import GNMIDaemon

        _write_storage(str(tmp_path), 1, age_seconds=15)
        health = GNMIDaemon.check_health(1, storage_dir=str(tmp_path),
                                         staleness_threshold=20)
        assert health['stale'] is False

    @pytest.mark.unit
    def test_stale_beyond_threshold(self, tmp_path):
        """Data older than the threshold is stale."""
        from gnmi_daemon import GNMIDaemon

        _write_storage(str(tmp_path), 1, age_seconds=25)
        health = GNMIDaemon.check_health(1, storage_dir=str(tmp_path),
                                         staleness_threshold=20)
        assert health['stale'] is True

    # ------------------------------------------------------------------
    # 10s poller: threshold = 10 * 2 = 20s
    # ------------------------------------------------------------------

    @pytest.mark.unit
    def test_10s_poller_fresh(self, tmp_path):
        """10s poller (threshold=20): 15s old data is fresh."""
        from gnmi_daemon import GNMIDaemon

        _write_storage(str(tmp_path), 1, age_seconds=15)
        health = GNMIDaemon.check_health(1, storage_dir=str(tmp_path),
                                         staleness_threshold=20)
        assert health['stale'] is False

    @pytest.mark.unit
    def test_10s_poller_stale(self, tmp_path):
        """10s poller (threshold=20): 25s old data is stale."""
        from gnmi_daemon import GNMIDaemon

        _write_storage(str(tmp_path), 1, age_seconds=25)
        health = GNMIDaemon.check_health(1, storage_dir=str(tmp_path),
                                         staleness_threshold=20)
        assert health['stale'] is True

    # ------------------------------------------------------------------
    # 60s poller: threshold = 60 * 2 = 120s
    # ------------------------------------------------------------------

    @pytest.mark.unit
    def test_60s_poller_fresh(self, tmp_path):
        """60s poller (threshold=120): 60s old data is fresh (NOT false-positive)."""
        from gnmi_daemon import GNMIDaemon

        _write_storage(str(tmp_path), 1, age_seconds=60)
        health = GNMIDaemon.check_health(1, storage_dir=str(tmp_path),
                                         staleness_threshold=120)
        assert health['stale'] is False

    @pytest.mark.unit
    def test_60s_poller_stale(self, tmp_path):
        """60s poller (threshold=120): 130s old data is stale."""
        from gnmi_daemon import GNMIDaemon

        _write_storage(str(tmp_path), 1, age_seconds=130)
        health = GNMIDaemon.check_health(1, storage_dir=str(tmp_path),
                                         staleness_threshold=120)
        assert health['stale'] is True

    # ------------------------------------------------------------------
    # 300s poller: threshold = 300 * 2 = 600s
    # ------------------------------------------------------------------

    @pytest.mark.unit
    def test_300s_poller_fresh(self, tmp_path):
        """300s poller (threshold=600): 300s old data is fresh (NOT false-positive)."""
        from gnmi_daemon import GNMIDaemon

        _write_storage(str(tmp_path), 1, age_seconds=300)
        health = GNMIDaemon.check_health(1, storage_dir=str(tmp_path),
                                         staleness_threshold=600)
        assert health['stale'] is False

    @pytest.mark.unit
    def test_300s_poller_stale(self, tmp_path):
        """300s poller (threshold=600): 650s old data is stale."""
        from gnmi_daemon import GNMIDaemon

        _write_storage(str(tmp_path), 1, age_seconds=650)
        health = GNMIDaemon.check_health(1, storage_dir=str(tmp_path),
                                         staleness_threshold=600)
        assert health['stale'] is True

    # ------------------------------------------------------------------
    # Default fallback: when staleness_threshold not passed
    # ------------------------------------------------------------------

    @pytest.mark.unit
    def test_default_threshold_uses_600(self, tmp_path):
        """Without an explicit threshold, default (300s * 2 = 600s) is used."""
        from gnmi_daemon import GNMIDaemon

        # 300s old data should be fresh with the 600s default
        _write_storage(str(tmp_path), 1, age_seconds=300)
        health = GNMIDaemon.check_health(1, storage_dir=str(tmp_path))
        assert health['stale'] is False

    @pytest.mark.unit
    def test_default_threshold_marks_very_old_data_stale(self, tmp_path):
        """Without an explicit threshold, data older than 600s is stale."""
        from gnmi_daemon import GNMIDaemon

        _write_storage(str(tmp_path), 1, age_seconds=700)
        health = GNMIDaemon.check_health(1, storage_dir=str(tmp_path))
        assert health['stale'] is True

    # ------------------------------------------------------------------
    # age_seconds is still reported correctly
    # ------------------------------------------------------------------

    @pytest.mark.unit
    def test_age_seconds_reported(self, tmp_path):
        """check_health() should report age_seconds in the result dict."""
        from gnmi_daemon import GNMIDaemon

        _write_storage(str(tmp_path), 1, age_seconds=45)
        health = GNMIDaemon.check_health(1, storage_dir=str(tmp_path),
                                         staleness_threshold=120)
        assert 'age_seconds' in health
        # Allow ±2s tolerance for execution time
        assert 43 <= health['age_seconds'] <= 47

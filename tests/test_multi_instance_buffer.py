"""
Unit tests for MultiInstanceBuffer.

The MultiInstanceBuffer is the data structure that prevents samples from one
gNMI subscription mingling with another's on the same device. Each subscription
instance gets its own SampleBuffer (independent maxlen retention), and the
storage APIs return instance-keyed dicts.
"""

import os
import sys

import pytest

sys.path.insert(0, os.path.join(os.path.dirname(__file__), '..', 'scripts'))

from gnmi_daemon import MultiInstanceBuffer  # noqa: E402


@pytest.fixture
def buf():
    return MultiInstanceBuffer(max_samples_per_instance=5)


class TestAddSample:
    @pytest.mark.unit
    def test_adds_to_new_instance(self, buf):
        buf.add_sample("ettp-40", {"in_octets": 1})
        agg = buf.get_aggregated_by_instance()
        assert "ettp-40" in agg
        assert agg["ettp-40"]["in_octets"] == 1

    @pytest.mark.unit
    def test_lazy_creates_per_instance_buffers(self, buf):
        buf.add_sample("ettp-40", {"in_octets": 1})
        buf.add_sample("ettp-34", {"in_octets": 2})
        assert "ettp-40" in buf.buffers
        assert "ettp-34" in buf.buffers
        # Two separate SampleBuffer instances
        assert buf.buffers["ettp-40"] is not buf.buffers["ettp-34"]


class TestInstanceSeparation:
    @pytest.mark.unit
    def test_samples_never_cross_instances(self, buf):
        """The core bug-fix invariant: instance A's samples stay out of B's history."""
        for i in range(5):
            buf.add_sample("ettp-40", {"in_octets": 13_700_000_000_000 + i})
            buf.add_sample("ettp-34", {"in_octets": 15_200_000_000_000 + i})

        hist = buf.get_samples_history_by_instance()
        assert "ettp-40" in hist
        assert "ettp-34" in hist

        # ettp-40's samples must be in 13.7T range; ettp-34's in 15.2T range
        for s in hist["ettp-40"]:
            assert 13_700_000_000_000 <= s["in_octets"] < 13_700_000_000_010
        for s in hist["ettp-34"]:
            assert 15_200_000_000_000 <= s["in_octets"] < 15_200_000_000_010

    @pytest.mark.unit
    def test_aggregated_returns_latest_per_instance(self, buf):
        buf.add_sample("ettp-40", {"in_octets": 1})
        buf.add_sample("ettp-40", {"in_octets": 2})
        buf.add_sample("ettp-40", {"in_octets": 3})
        buf.add_sample("ettp-34", {"in_octets": 100})

        agg = buf.get_aggregated_by_instance()
        # Latest sample wins (within each instance), independent of cross-instance order.
        assert agg["ettp-40"]["in_octets"] == 3
        assert agg["ettp-34"]["in_octets"] == 100


class TestRetentionWindow:
    @pytest.mark.unit
    def test_each_instance_has_full_capacity_independent(self):
        """Adding 50 interfaces does not shrink ettp-40's retention to 1 sample.

        This is the scaling guarantee that B has and C couldn't provide. With a
        shared flat deque (Option C), 5 samples shared across 50 instances = ~0.1
        samples each on average.
        """
        buf = MultiInstanceBuffer(max_samples_per_instance=5)
        for i in range(50):
            buf.add_sample(f"if-{i}", {"v": 0})  # 50 different instances
        for k in range(5):
            buf.add_sample("ettp-40", {"v": k})  # 5 samples to ettp-40

        hist = buf.get_samples_history_by_instance()
        # ettp-40 retained all 5 of its own samples, independent of the 50 others.
        assert len(hist["ettp-40"]) == 5
        assert [s["v"] for s in hist["ettp-40"]] == [0, 1, 2, 3, 4]

    @pytest.mark.unit
    def test_rotation_within_instance(self, buf):
        for i in range(8):
            buf.add_sample("ettp-40", {"v": i})
        hist = buf.get_samples_history_by_instance()
        # maxlen=5, so the oldest 3 rotated out
        values = [s["v"] for s in hist["ettp-40"]]
        assert values == [3, 4, 5, 6, 7]


class TestSamplesHistoryShape:
    @pytest.mark.unit
    def test_returns_dict_not_list(self, buf):
        buf.add_sample("ettp-40", {"in_octets": 1})
        hist = buf.get_samples_history_by_instance()
        assert isinstance(hist, dict)
        assert isinstance(hist["ettp-40"], list)

    @pytest.mark.unit
    def test_omits_instances_with_empty_buffers(self, buf):
        # No samples added — get_samples_history_by_instance returns empty dict
        assert buf.get_samples_history_by_instance() == {}
        assert buf.get_aggregated_by_instance() == {}

    @pytest.mark.unit
    def test_samples_carry_epoch_and_timestamp(self, buf):
        buf.add_sample("ettp-40", {"in_octets": 1})
        hist = buf.get_samples_history_by_instance()
        sample = hist["ettp-40"][0]
        assert "epoch" in sample
        assert "timestamp" in sample
        assert sample["in_octets"] == 1


class TestEvictMissing:
    @pytest.mark.unit
    def test_evicts_removed_instances(self, buf):
        buf.add_sample("ettp-40", {"v": 1})
        buf.add_sample("ettp-34", {"v": 2})
        buf.add_sample("ettp-9", {"v": 3})

        # Only ettp-40 and ettp-34 are still active — ettp-9 removed from config
        buf.evict_missing(["ettp-40", "ettp-34"])

        agg = buf.get_aggregated_by_instance()
        assert "ettp-40" in agg
        assert "ettp-34" in agg
        assert "ettp-9" not in agg

    @pytest.mark.unit
    def test_evict_missing_with_empty_active_set_drops_all(self, buf):
        buf.add_sample("ettp-40", {"v": 1})
        buf.evict_missing([])
        assert buf.get_aggregated_by_instance() == {}


class TestWriteClock:
    @pytest.mark.unit
    def test_should_write_false_when_recently_written(self, buf):
        # The buffer's last_write_time is set on construction; immediately
        # after construction should_write(10.0) is False.
        assert buf.should_write(interval=10.0) is False

    @pytest.mark.unit
    def test_mark_written_resets_clock(self, buf):
        import time
        buf.last_write_time = time.time() - 100  # pretend it's been 100s
        assert buf.should_write(interval=10.0) is True
        buf.mark_written()
        assert buf.should_write(interval=10.0) is False

    @pytest.mark.unit
    def test_has_data_reflects_buffer_state(self, buf):
        assert buf.has_data() is False
        buf.add_sample("ettp-40", {"v": 1})
        assert buf.has_data() is True

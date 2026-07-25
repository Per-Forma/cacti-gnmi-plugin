"""
Regression test for the multi-subscription cross-contamination bug.

Reproduces the user-reported scenario on device 1124: two subscriptions
(ettp-40 with ~13.7T counters, ettp-34 with ~15.2T counters) feeding one daemon.
Pre-fix, samples mingled in a flat buffer and the bridge returned interleaved
values that produced NaN-spike RRD graphs.

This test feeds interleaved telemetry through process_sample_with_config and
asserts that the resulting in-memory storage shape is instance-keyed and that
each instance's samples_history contains only its own counters.
"""

import os
import sys
import tempfile

import pytest

sys.path.insert(0, os.path.join(os.path.dirname(__file__), '..', 'scripts'))

from gnmi_daemon import GNMIDaemon, _extract_instance_id  # noqa: E402


CIENA_PATH_40 = "Ciena:cn-if:interface-telemetry-state/interface-counters[interface-type=ettp]/interfaces[if-name=40]/counters"
CIENA_PATH_34 = "Ciena:cn-if:interface-telemetry-state/interface-counters[interface-type=ettp]/interfaces[if-name=34]/counters"


@pytest.fixture
def daemon_with_two_subscriptions():
    """Build a GNMIDaemon matching the device 1124 production config."""
    config = {
        "device_id": 1124,
        "hostname": "192.0.2.10",
        "port": 9339,
        "username": "telemetry-test",
        "password": "not-a-real-password",
        "poller_interval": 60,
        "sample_interval": 5,
        "subscriptions": [
            {
                "path": CIENA_PATH_40,
                "instance": "ettp-40",
                "metrics": ["in-octets", "out-octets", "in-errors", "out-errors", "in-crc-error-pkts"],
                "field_mapping": {
                    "in-octets": "in_octets",
                    "out-octets": "out_octets",
                    "in-errors": "in_errors",
                    "out-errors": "out_errors",
                    "in-crc-error-pkts": "in_crc_error_pkts",
                },
            },
            {
                "path": CIENA_PATH_34,
                "instance": "ettp-34",
                "metrics": ["in-octets", "out-octets"],
                "field_mapping": {"in-octets": "in_octets", "out-octets": "out_octets"},
            },
        ],
    }
    with tempfile.TemporaryDirectory() as tmpdir:
        daemon = GNMIDaemon(device_id=1124, config=config, storage_dir=tmpdir)
        for sub in config["subscriptions"]:
            canonical = sub["instance"]
            daemon._subscription_by_instance[canonical] = sub
            daemon._instance_lookup[canonical] = canonical
            daemon._instance_lookup[_extract_instance_id(sub["path"])] = canonical
        yield daemon


class TestMultiSubscriptionRegression:
    @pytest.mark.unit
    def test_interleaved_samples_stay_separated_in_metric_groups(self, daemon_with_two_subscriptions):
        d = daemon_with_two_subscriptions
        # Simulate the production pattern: samples alternate between interfaces.
        for cycle in range(5):
            d.process_sample_with_config("ettp-40", {
                "in-octets": 13_723_662_856_271 + cycle,
                "out-octets": 35_717_177_925_559 + cycle,
                "in-errors": 0,
                "out-errors": 0,
                "in-crc-error-pkts": 0,
            })
            d.process_sample_with_config("ettp-34", {
                "in-octets": 15_221_693_546_136 + cycle,
                "out-octets": 17_166_064_145_066 + cycle,
            })

        agg = d.sample_buffer.get_aggregated_by_instance()
        # Both instances present at the top level
        assert set(agg.keys()) == {"ettp-40", "ettp-34"}

        # ettp-40 values are in the 13.7T / 35.7T range (no ettp-34 leakage)
        assert 13_700_000_000_000 < agg["ettp-40"]["in_octets"] < 13_800_000_000_000
        assert 35_700_000_000_000 < agg["ettp-40"]["out_octets"] < 35_800_000_000_000

        # ettp-34 values are in the 15.2T / 17.1T range (no ettp-40 leakage)
        assert 15_200_000_000_000 < agg["ettp-34"]["in_octets"] < 15_300_000_000_000
        assert 17_100_000_000_000 < agg["ettp-34"]["out_octets"] < 17_200_000_000_000

        # ettp-34 must not carry ettp-40-only fields (errors)
        assert "in_errors" not in agg["ettp-34"]
        assert "in_crc_error_pkts" not in agg["ettp-34"]

    @pytest.mark.unit
    def test_samples_history_is_instance_keyed(self, daemon_with_two_subscriptions):
        d = daemon_with_two_subscriptions
        d.process_sample_with_config("ettp-40", {"in-octets": 13_723_662_856_271})
        d.process_sample_with_config("ettp-34", {"in-octets": 15_221_693_546_136})

        hist = d.sample_buffer.get_samples_history_by_instance()
        assert isinstance(hist, dict)
        assert set(hist.keys()) == {"ettp-40", "ettp-34"}

        # Every sample in ettp-40's history has 13.7T-range values, never 15.2T.
        for s in hist["ettp-40"]:
            assert 13_700_000_000_000 < s["in_octets"] < 13_800_000_000_000
        for s in hist["ettp-34"]:
            assert 15_200_000_000_000 < s["in_octets"] < 15_300_000_000_000

    @pytest.mark.unit
    def test_bridge_history_output_matches_only_one_instance(self, daemon_with_two_subscriptions):
        """End-to-end: feed the daemon, then run the bridge's history processor
        directly and confirm cross-instance contamination is impossible."""
        from gnmi_poller_bridge import process_history_output

        d = daemon_with_two_subscriptions
        for cycle in range(3):
            d.process_sample_with_config("ettp-40", {"in-octets": 13_700_000_000_000 + cycle})
            d.process_sample_with_config("ettp-34", {"in-octets": 15_200_000_000_000 + cycle})

        hist = d.sample_buffer.get_samples_history_by_instance()
        field_to_metric = {"in_octets": "in-octets"}

        lines_40 = process_history_output(hist, "ettp-40", ["in-octets"], field_to_metric)
        lines_34 = process_history_output(hist, "ettp-34", ["in-octets"], field_to_metric)

        assert len(lines_40) == 3
        assert len(lines_34) == 3

        for line in lines_40:
            assert "13700000" in line  # ~13.7T magnitude
            assert "15200000" not in line  # NEVER the other instance's value
        for line in lines_34:
            assert "15200000" in line
            assert "13700000" not in line

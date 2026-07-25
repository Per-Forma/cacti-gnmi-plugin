"""
Tests for the daemon's _match_instance routing logic and its _extract_instance_id helper.

These guard against the substring-collision class of bugs (e.g. if-name=4 vs
if-name=40) and verify that pygnmi prefix-format variation does not break
instance routing.
"""

import os
import sys
import tempfile

import pytest

sys.path.insert(0, os.path.join(os.path.dirname(__file__), '..', 'scripts'))

from gnmi_daemon import GNMIDaemon, _extract_instance_id  # noqa: E402


CIENA_PATH_40 = "Ciena:cn-if:interface-telemetry-state/interface-counters[interface-type=ettp]/interfaces[if-name=40]/counters"
CIENA_PATH_34 = "Ciena:cn-if:interface-telemetry-state/interface-counters[interface-type=ettp]/interfaces[if-name=34]/counters"
CIENA_PATH_4 = "Ciena:cn-if:interface-telemetry-state/interface-counters[interface-type=ettp]/interfaces[if-name=4]/counters"


def _make_daemon(subscriptions):
    """Build a GNMIDaemon with routing tables populated, no real connection."""
    with tempfile.TemporaryDirectory() as tmpdir:
        config = {
            "device_id": 1,
            "hostname": "test",
            "port": 9339,
            "username": "u",
            "password": "p",
            "subscriptions": subscriptions,
        }
        daemon = GNMIDaemon(device_id=1, config=config, storage_dir=tmpdir)
        for sub in subscriptions:
            canonical = sub["instance"]
            daemon._subscription_by_instance[canonical] = sub
            daemon._instance_lookup[canonical] = canonical
            daemon._instance_lookup[_extract_instance_id(sub["path"])] = canonical
        return daemon


class TestExtractInstanceId:
    @pytest.mark.unit
    def test_ciena_two_key_path(self):
        assert _extract_instance_id(CIENA_PATH_40) == "ettp-40"
        assert _extract_instance_id(CIENA_PATH_34) == "ettp-34"
        assert _extract_instance_id(CIENA_PATH_4) == "ettp-4"

    @pytest.mark.unit
    def test_openconfig_single_key(self):
        assert _extract_instance_id("/interfaces/interface[name=eth0]/state") == "eth0"
        assert _extract_instance_id("/network-instances/network-instance[name=default]") == "default"

    @pytest.mark.unit
    def test_unkeyed_path_returns_default(self):
        assert _extract_instance_id("/system/cpu/utilization") == "default"

    @pytest.mark.unit
    def test_empty_input(self):
        assert _extract_instance_id("") == "default"
        assert _extract_instance_id(None) == "default"


class TestMatchInstanceSubstringCollision:
    """The canonical bug class this fix has to defeat."""

    @pytest.mark.unit
    def test_if_name_4_and_40_resolve_distinctly(self):
        """Substring matching would have routed if-name=40 traffic to if-name=4."""
        daemon = _make_daemon([
            {"path": CIENA_PATH_4, "instance": "ettp-4", "metrics": ["in-octets"], "field_mapping": {"in-octets": "in_octets"}},
            {"path": CIENA_PATH_40, "instance": "ettp-40", "metrics": ["in-octets"], "field_mapping": {"in-octets": "in_octets"}},
        ])

        # Two notifications with prefixes that differ only in keyed portion
        assert daemon._match_instance(CIENA_PATH_4, []) == "ettp-4"
        assert daemon._match_instance(CIENA_PATH_40, []) == "ettp-40"


class TestMatchInstancePrefixVariants:
    """pygnmi can deliver the prefix in several slightly different shapes."""

    @pytest.mark.unit
    def test_exact_prefix(self):
        daemon = _make_daemon([
            {"path": CIENA_PATH_40, "instance": "ettp-40", "metrics": [], "field_mapping": {}},
        ])
        assert daemon._match_instance(CIENA_PATH_40, []) == "ettp-40"

    @pytest.mark.unit
    def test_prefix_without_origin(self):
        """Some pygnmi versions strip the `Ciena:cn-if:` origin from prefix strings."""
        without_origin = CIENA_PATH_40.replace("Ciena:cn-if:", "")
        daemon = _make_daemon([
            {"path": CIENA_PATH_40, "instance": "ettp-40", "metrics": [], "field_mapping": {}},
        ])
        # _extract_instance_id only looks at the keyed portion, so this resolves
        assert daemon._match_instance(without_origin, []) == "ettp-40"

    @pytest.mark.unit
    def test_prefix_with_leading_slash(self):
        daemon = _make_daemon([
            {"path": CIENA_PATH_40, "instance": "ettp-40", "metrics": [], "field_mapping": {}},
        ])
        assert daemon._match_instance("/" + CIENA_PATH_40, []) == "ettp-40"

    @pytest.mark.unit
    def test_empty_prefix_falls_back_to_first_leaf_path(self):
        """Some devices emit prefix='' and put the full path on every leaf."""
        daemon = _make_daemon([
            {"path": CIENA_PATH_40, "instance": "ettp-40", "metrics": [], "field_mapping": {}},
        ])
        update_list = [
            {"path": CIENA_PATH_40 + "/in-octets", "val": 1},
            {"path": CIENA_PATH_40 + "/out-octets", "val": 2},
        ]
        assert daemon._match_instance("", update_list) == "ettp-40"

    @pytest.mark.unit
    def test_unknown_prefix_returns_none(self):
        daemon = _make_daemon([
            {"path": CIENA_PATH_40, "instance": "ettp-40", "metrics": [], "field_mapping": {}},
        ])
        unknown = "Ciena:cn-if:other/interfaces[if-name=99]"
        assert daemon._match_instance(unknown, []) is None


class TestMatchInstanceOpenConfig:
    @pytest.mark.unit
    def test_openconfig_interface(self):
        daemon = _make_daemon([
            {"path": "/interfaces/interface[name=eth0]/state", "instance": "eth0", "metrics": [], "field_mapping": {}},
        ])
        assert daemon._match_instance("/interfaces/interface[name=eth0]/state", []) == "eth0"


class TestMatchInstanceDefault:
    @pytest.mark.unit
    def test_unkeyed_path_routes_to_default(self):
        daemon = _make_daemon([
            {"path": "/system/cpu", "instance": "default", "metrics": [], "field_mapping": {}},
        ])
        assert daemon._match_instance("/system/cpu", []) == "default"

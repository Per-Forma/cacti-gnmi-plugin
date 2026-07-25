#!/usr/bin/env python3
"""
gNMI Plugin Phase 3.3 Python Daemon Multi-Subscription Tests

Tests for Python daemon handling multiple subscriptions per device.
Follows TDD approach - write tests first, then implement to pass.
"""

import json
import os
import sys
import tempfile
import unittest
from unittest.mock import Mock, patch, MagicMock
from typing import Dict, List, Any

# Add the scripts directory to the path
sys.path.insert(0, os.path.join(os.path.dirname(__file__), '..', '..', 'scripts'))

class TestDaemonMultiSubscription(unittest.TestCase):

    def setUp(self):
        """Set up test environment before each test."""
        self.temp_dir = tempfile.mkdtemp()
        self.test_config = {
            'device_id': 1,
            'hostname': 'test-device.example.com',
            'port': 9339,
            'username': 'testuser',
            'password': 'testpass',
            'use_tls': True,
            'skip_verify': True,
            'encoding': 'json',
            'collection_interval': 10,
            'subscriptions': [
                {
                    'path': 'test/path1',
                    'instance': 'instance1',
                    'metrics': ['metric1', 'metric2'],
                    'field_mapping': {'metric1': 'metric1', 'metric2': 'metric2'}
                },
                {
                    'path': 'test/path2',
                    'instance': 'instance2',
                    'metrics': ['metric3', 'metric4'],
                    'field_mapping': {'metric3': 'metric3', 'metric4': 'metric4'}
                }
            ]
        }

    def tearDown(self):
        """Clean up test environment after each test."""
        import shutil
        shutil.rmtree(self.temp_dir, ignore_errors=True)

    def test_load_config_validates_required_fields(self):
        """Test that config loading validates all required fields."""
        from gnmi_daemon import load_config

        # Test missing required fields
        required_fields = ['device_id', 'hostname', 'port', 'username', 'password', 'subscriptions']

        for field in required_fields:
            config = self.test_config.copy()
            del config[field]

            with self.assertRaises(ValueError) as context:
                load_config(json.dumps(config))

            self.assertIn(f"Missing required config field: {field}", str(context.exception))

    def test_load_config_validates_subscriptions_array(self):
        """Test that config validates subscriptions is non-empty array."""
        from gnmi_daemon import load_config

        # Test empty subscriptions
        config = self.test_config.copy()
        config['subscriptions'] = []

        with self.assertRaises(ValueError) as context:
            load_config(json.dumps(config))

        self.assertIn("Config must include at least one subscription", str(context.exception))

        # Test subscriptions not a list
        config['subscriptions'] = "not a list"

        with self.assertRaises(ValueError) as context:
            load_config(json.dumps(config))

        self.assertIn("Config must include at least one subscription", str(context.exception))

    def test_load_config_validates_subscription_structure(self):
        """Test that each subscription has required fields."""
        from gnmi_daemon import load_config

        # Test missing subscription fields
        config = self.test_config.copy()
        config['subscriptions'] = [{'path': 'test/path'}]  # Missing instance, metrics, field_mapping

        with self.assertRaises(ValueError) as context:
            load_config(json.dumps(config))

        self.assertIn("Subscription 0 missing required field", str(context.exception))

    def test_build_gnmi_subscriptions_creates_correct_format(self):
        """Test that gNMI subscription request is built correctly."""
        from gnmi_daemon import build_gnmi_subscriptions

        result = build_gnmi_subscriptions(self.test_config)

        # Verify structure
        self.assertIn('subscription', result)
        self.assertIn('mode', result)
        self.assertIn('encoding', result)

        # Verify mode and encoding
        self.assertEqual(result['mode'], 'stream')
        self.assertEqual(result['encoding'], 'json')

        # Verify subscription count
        self.assertEqual(len(result['subscription']), 2)

        # Verify subscription structure
        for i, sub in enumerate(result['subscription']):
            self.assertIn('path', sub)
            self.assertIn('mode', sub)
            self.assertIn('sample_interval', sub)

            self.assertEqual(sub['path'], f'test/path{i+1}')
            self.assertEqual(sub['mode'], 'sample')
            self.assertEqual(sub['sample_interval'], 10000000000)  # 10 seconds in nanoseconds

    def test_build_gnmi_subscriptions_handles_single_subscription(self):
        """Test that single subscription is handled correctly."""
        from gnmi_daemon import build_gnmi_subscriptions

        config = self.test_config.copy()
        config['subscriptions'] = [config['subscriptions'][0]]  # Keep only first subscription

        result = build_gnmi_subscriptions(config)

        self.assertEqual(len(result['subscription']), 1)
        self.assertEqual(result['subscription'][0]['path'], 'test/path1')

    def _make_daemon(self, config=None):
        """Build a GNMIDaemon with routing tables populated, no real connection."""
        from gnmi_daemon import GNMIDaemon, _extract_instance_id

        config = config or self.test_config
        daemon = GNMIDaemon(device_id=config['device_id'], config=config, storage_dir=self.temp_dir)
        # Mirror what connect_and_subscribe builds before the telemetry loop.
        for sub in config['subscriptions']:
            canonical = sub['instance']
            daemon._subscription_by_instance[canonical] = sub
            daemon._instance_lookup[canonical] = canonical
            daemon._instance_lookup[_extract_instance_id(sub['path'])] = canonical
        return daemon

    def test_process_sample_routes_to_instance_buffer(self):
        """A sample tagged with an instance lands in that instance's buffer only."""
        daemon = self._make_daemon()
        daemon.process_sample_with_config('instance1', {'metric1': 100, 'metric2': 200})

        agg = daemon.sample_buffer.get_aggregated_by_instance()
        self.assertIn('instance1', agg)
        self.assertNotIn('instance2', agg)
        self.assertEqual(agg['instance1']['metric1'], 100)
        self.assertEqual(agg['instance1']['metric2'], 200)

    def test_process_sample_filters_unwanted_metrics(self):
        """Metrics not listed in the subscription's metrics array are dropped."""
        daemon = self._make_daemon()
        daemon.process_sample_with_config('instance1', {
            'metric1': 100,
            'unwanted_metric': 999,
        })

        agg = daemon.sample_buffer.get_aggregated_by_instance()
        self.assertEqual(agg['instance1']['metric1'], 100)
        self.assertNotIn('unwanted_metric', agg['instance1'])

    def test_process_sample_empty_metrics_skips_buffer(self):
        """A sample with zero matching metrics is dropped (no empty rows)."""
        daemon = self._make_daemon()
        daemon.process_sample_with_config('instance1', {})

        agg = daemon.sample_buffer.get_aggregated_by_instance()
        self.assertEqual(agg, {})

    def test_process_sample_applies_field_mapping(self):
        """field_mapping converts gNMI names to Cacti field names before storage."""
        config = self.test_config.copy()
        config['subscriptions'] = [{
            'path': 'test/path1',
            'instance': 'instance1',
            'metrics': ['metric1', 'metric2'],
            'field_mapping': {'metric1': 'in_octets', 'metric2': 'out_octets'},
        }]

        daemon = self._make_daemon(config)
        daemon.process_sample_with_config('instance1', {'metric1': 100, 'metric2': 200})

        agg = daemon.sample_buffer.get_aggregated_by_instance()
        self.assertEqual(agg['instance1']['in_octets'], 100)
        self.assertEqual(agg['instance1']['out_octets'], 200)
        self.assertNotIn('metric1', agg['instance1'])
        self.assertNotIn('metric2', agg['instance1'])

    def test_daemon_handles_multiple_subscriptions_per_device(self):
        """Test that daemon can handle multiple subscriptions for one device."""
        from gnmi_daemon import load_config, build_gnmi_subscriptions

        # Load config
        config = load_config(json.dumps(self.test_config))

        # Build gNMI subscriptions
        gnmi_subs = build_gnmi_subscriptions(config)

        # Verify multiple subscriptions are created
        self.assertEqual(len(gnmi_subs['subscription']), 2)

        # Verify each subscription has correct path
        paths = [sub['path'] for sub in gnmi_subs['subscription']]
        self.assertIn('test/path1', paths)
        self.assertIn('test/path2', paths)

    def test_daemon_handles_single_subscription(self):
        """Test that daemon handles single subscription correctly."""
        from gnmi_daemon import load_config, build_gnmi_subscriptions

        # Single subscription config
        config = self.test_config.copy()
        config['subscriptions'] = [config['subscriptions'][0]]

        # Load config
        loaded_config = load_config(json.dumps(config))

        # Build gNMI subscriptions
        gnmi_subs = build_gnmi_subscriptions(loaded_config)

        # Verify single subscription
        self.assertEqual(len(gnmi_subs['subscription']), 1)
        self.assertEqual(gnmi_subs['subscription'][0]['path'], 'test/path1')

    def test_daemon_handles_zero_subscriptions(self):
        """Test that daemon handles zero subscriptions correctly."""
        from gnmi_daemon import load_config

        # Empty subscriptions config
        config = self.test_config.copy()
        config['subscriptions'] = []

        # Should raise ValueError
        with self.assertRaises(ValueError) as context:
            load_config(json.dumps(config))

        self.assertIn("Config must include at least one subscription", str(context.exception))

    def test_daemon_handles_mixed_metric_types(self):
        """Counter (int) and gauge (float) values both round-trip through the buffer."""
        config = self.test_config.copy()
        config['subscriptions'] = [{
            'path': 'test/path1',
            'instance': 'instance1',
            'metrics': ['counter_metric', 'gauge_metric'],
            'field_mapping': {'counter_metric': 'in_octets', 'gauge_metric': 'cpu_percent'},
        }]

        daemon = self._make_daemon(config)
        daemon.process_sample_with_config('instance1', {
            'counter_metric': 12345,
            'gauge_metric': 75.5,
        })

        agg = daemon.sample_buffer.get_aggregated_by_instance()
        self.assertEqual(agg['instance1']['in_octets'], 12345)
        self.assertEqual(agg['instance1']['cpu_percent'], 75.5)

    def test_daemon_handles_large_and_negative_numbers(self):
        """Very large, zero, and negative values are stored unmodified."""
        daemon = self._make_daemon()
        daemon.process_sample_with_config('instance1', {'metric1': 999999999999, 'metric2': 0})
        daemon.process_sample_with_config('instance2', {'metric3': -100, 'metric4': 0})

        agg = daemon.sample_buffer.get_aggregated_by_instance()
        self.assertEqual(agg['instance1']['metric1'], 999999999999)
        self.assertEqual(agg['instance1']['metric2'], 0)
        self.assertEqual(agg['instance2']['metric3'], -100)


class TestDaemonIntegration(unittest.TestCase):
    """Integration tests for daemon multi-subscription functionality."""

    def setUp(self):
        """Set up test environment."""
        self.temp_dir = tempfile.mkdtemp()

    def tearDown(self):
        """Clean up test environment."""
        import shutil
        shutil.rmtree(self.temp_dir, ignore_errors=True)

    def test_daemon_startup_with_multi_subscription_config(self):
        """Test that daemon starts successfully with multi-subscription config."""
        from gnmi_daemon import load_config, build_gnmi_subscriptions

        config = {
            'device_id': 1,
            'hostname': 'test-device.example.com',
            'port': 9339,
            'username': 'testuser',
            'password': 'testpass',
            'use_tls': True,
            'skip_verify': True,
            'encoding': 'json',
            'collection_interval': 10,
            'subscriptions': [
                {
                    'path': 'test/path1',
                    'instance': 'instance1',
                    'metrics': ['metric1', 'metric2'],
                    'field_mapping': {'metric1': 'metric1', 'metric2': 'metric2'}
                },
                {
                    'path': 'test/path2',
                    'instance': 'instance2',
                    'metrics': ['metric3', 'metric4'],
                    'field_mapping': {'metric3': 'metric3', 'metric4': 'metric4'}
                }
            ]
        }

        # Test config loading
        loaded_config = load_config(json.dumps(config))
        self.assertEqual(loaded_config['device_id'], 1)
        self.assertEqual(len(loaded_config['subscriptions']), 2)

        # Test gNMI subscription building
        gnmi_subs = build_gnmi_subscriptions(loaded_config)
        self.assertEqual(len(gnmi_subs['subscription']), 2)
        self.assertEqual(gnmi_subs['mode'], 'stream')

    def test_daemon_handles_config_changes(self):
        """Test that daemon handles configuration changes correctly."""
        from gnmi_daemon import load_config, build_gnmi_subscriptions

        # Initial config
        config1 = {
            'device_id': 1,
            'hostname': 'test-device.example.com',
            'port': 9339,
            'username': 'testuser',
            'password': 'testpass',
            'use_tls': True,
            'skip_verify': True,
            'encoding': 'json',
            'collection_interval': 10,
            'subscriptions': [
                {
                    'path': 'test/path1',
                    'instance': 'instance1',
                    'metrics': ['metric1'],
                    'field_mapping': {'metric1': 'metric1'}
                }
            ]
        }

        # Updated config (added subscription)
        config2 = {
            'device_id': 1,
            'hostname': 'test-device.example.com',
            'port': 9339,
            'username': 'testuser',
            'password': 'testpass',
            'use_tls': True,
            'skip_verify': True,
            'encoding': 'json',
            'collection_interval': 10,
            'subscriptions': [
                {
                    'path': 'test/path1',
                    'instance': 'instance1',
                    'metrics': ['metric1'],
                    'field_mapping': {'metric1': 'metric1'}
                },
                {
                    'path': 'test/path2',
                    'instance': 'instance2',
                    'metrics': ['metric2'],
                    'field_mapping': {'metric2': 'metric2'}
                }
            ]
        }

        # Test both configs
        loaded1 = load_config(json.dumps(config1))
        loaded2 = load_config(json.dumps(config2))

        gnmi_subs1 = build_gnmi_subscriptions(loaded1)
        gnmi_subs2 = build_gnmi_subscriptions(loaded2)

        # Verify subscription count changes
        self.assertEqual(len(gnmi_subs1['subscription']), 1)
        self.assertEqual(len(gnmi_subs2['subscription']), 2)


if __name__ == '__main__':
    # Run tests
    unittest.main(verbosity=2)

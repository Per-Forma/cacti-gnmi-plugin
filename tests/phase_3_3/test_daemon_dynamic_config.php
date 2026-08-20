<?php
/**
 * gNMI Plugin Phase 3.3 Daemon Dynamic Configuration Tests
 *
 * Tests for daemon configuration generation from database subscriptions.
 * Follows TDD approach - write tests first, then implement to pass.
 */

require_once('/var/www/html/cacti/include/global.php');
require_once('/var/www/html/cacti/lib/database.php');
require_once('/var/www/html/cacti/plugins/gnmi/include/subscription_functions.php');
require_once('/var/www/html/cacti/plugins/gnmi/include/functions.php');

class TestDaemonDynamicConfig {

    public $test_device_id = null;
    public $test_subscription_id = null;
    public $test_metric_id = null;

    public function setUp() {
        // Clean up any existing test data
        db_execute("DELETE FROM plugin_gnmi_devices WHERE hostname = 'test-device.example.com'");

        // Create test device
        $result = db_execute("
            INSERT INTO plugin_gnmi_devices (
                host_id, enabled, hostname, port, username, password,
                use_tls, collection_interval, encoding
            ) VALUES (
                1, 1, 'test-device.example.com', 9339, 'testuser', 'testpass',
                1, 10, 'JSON_IETF'
            )
        ");

        if ($result) {
            $this->test_device_id = db_fetch_cell('SELECT LAST_INSERT_ID()');
        }
    }

    public function tearDown() {
        // Clean up test data
        if ($this->test_metric_id) {
            db_execute_prepared('DELETE FROM plugin_gnmi_metrics WHERE id = ?', array($this->test_metric_id));
        }
        if ($this->test_subscription_id) {
            db_execute_prepared('DELETE FROM plugin_gnmi_subscriptions WHERE id = ?', array($this->test_subscription_id));
        }
        if ($this->test_device_id) {
            db_execute_prepared('DELETE FROM plugin_gnmi_devices WHERE id = ?', array($this->test_device_id));
        }
    }

    /**
     * Test 1: Daemon queries subscriptions for device_id
     */
    public function testDaemonQueriesSubscriptionsForDevice() {
        if (!$this->test_device_id) {
            throw new Exception('Test device not created');
        }

        // Create test subscription
        $sub_id = gnmi_create_subscription(
            $this->test_device_id,
            'test/path',
            'test-instance'
        );

        if ($sub_id === false) {
            throw new Exception('Failed to create test subscription');
        }

        $this->test_subscription_id = $sub_id;

        // Add test metric to subscription
        $metric_id = gnmi_add_metric_to_subscription($sub_id, 'test-metric', 'COUNTER');
        if ($metric_id === false) {
            throw new Exception('Failed to create test metric');
        }

        $this->test_metric_id = $metric_id;

        // Test daemon config generation
        $config = gnmi_build_daemon_config($this->test_device_id);

        if ($config === false) {
            throw new Exception('Daemon config generation failed');
        }

        // Verify subscriptions are included
        if (!isset($config['subscriptions']) || !is_array($config['subscriptions'])) {
            throw new Exception('Config missing subscriptions array');
        }

        if (count($config['subscriptions']) !== 1) {
            throw new Exception('Expected 1 subscription, got ' . count($config['subscriptions']));
        }

        $subscription = $config['subscriptions'][0];
        if ($subscription['path'] !== 'test/path') {
            throw new Exception('Subscription path not correct');
        }

        if ($subscription['instance'] !== 'test-instance') {
            throw new Exception('Subscription instance not correct');
        }

        return true;
    }

    /**
     * Test 2: Daemon skips disabled subscriptions
     */
    public function testDaemonSkipsDisabledSubscriptions() {
        if (!$this->test_device_id) {
            throw new Exception('Test device not created');
        }

        // Create enabled subscription
        $sub_id1 = gnmi_create_subscription(
            $this->test_device_id,
            'test/path1',
            'test-instance1'
        );

        if ($sub_id1 === false) {
            throw new Exception('Failed to create test subscription 1');
        }

        // Add metric to enabled subscription
        $metric_id = gnmi_add_metric_to_subscription($sub_id1, 'metric1', 'COUNTER');
        if ($metric_id === false) {
            throw new Exception('Failed to create metric for subscription 1');
        }

        // Create disabled subscription
        $sub_id2 = gnmi_create_subscription(
            $this->test_device_id,
            'test/path2',
            'test-instance2',
            ['enabled' => false]
        );

        if ($sub_id2 === false) {
            throw new Exception('Failed to create test subscription 2');
        }

        // Add metric to disabled subscription (should be ignored)
        $metric_id2 = gnmi_add_metric_to_subscription($sub_id2, 'metric2', 'COUNTER');
        if ($metric_id2 === false) {
            throw new Exception('Failed to create metric for subscription 2');
        }

        $this->test_subscription_id = $sub_id1; // Track first one for cleanup
        $this->test_metric_id = $metric_id;

        // Test daemon config generation
        $config = gnmi_build_daemon_config($this->test_device_id);

        if ($config === false) {
            throw new Exception('Daemon config generation failed');
        }

        // Verify only enabled subscription is included
        if (count($config['subscriptions']) !== 1) {
            throw new Exception('Expected 1 enabled subscription, got ' . count($config['subscriptions']));
        }

        $subscription = $config['subscriptions'][0];
        if ($subscription['path'] !== 'test/path1') {
            throw new Exception('Wrong subscription included');
        }

        return true;
    }

    /**
     * Test 3: Daemon builds subscription list from DB
     */
    public function testDaemonBuildsSubscriptionListFromDB() {
        if (!$this->test_device_id) {
            throw new Exception('Test device not created');
        }

        // Create multiple subscriptions
        $sub_id1 = gnmi_create_subscription(
            $this->test_device_id,
            'test/path1',
            'test-instance1'
        );

        $sub_id2 = gnmi_create_subscription(
            $this->test_device_id,
            'test/path2',
            'test-instance2'
        );

        if ($sub_id1 === false || $sub_id2 === false) {
            throw new Exception('Failed to create test subscriptions');
        }

        // Add metrics to both subscriptions
        $metric_id1 = gnmi_add_metric_to_subscription($sub_id1, 'metric1', 'COUNTER');
        $metric_id2 = gnmi_add_metric_to_subscription($sub_id2, 'metric2', 'COUNTER');

        if ($metric_id1 === false || $metric_id2 === false) {
            throw new Exception('Failed to create test metrics');
        }

        $this->test_subscription_id = $sub_id1; // Track first one for cleanup
        $this->test_metric_id = $metric_id1;

        // Test daemon config generation
        $config = gnmi_build_daemon_config($this->test_device_id);

        if ($config === false) {
            throw new Exception('Daemon config generation failed');
        }

        // Verify both subscriptions are included
        if (count($config['subscriptions']) !== 2) {
            throw new Exception('Expected 2 subscriptions, got ' . count($config['subscriptions']));
        }

        // Verify subscription details
        $paths = array_column($config['subscriptions'], 'path');
        if (!in_array('test/path1', $paths) || !in_array('test/path2', $paths)) {
            throw new Exception('Subscription paths not found in config');
        }

        $instances = array_column($config['subscriptions'], 'instance');
        if (!in_array('test-instance1', $instances) || !in_array('test-instance2', $instances)) {
            throw new Exception('Subscription instances not found in config');
        }

        return true;
    }

    /**
     * Test 4: Daemon handles multiple subscriptions per device
     */
    public function testDaemonHandlesMultipleSubscriptionsPerDevice() {
        if (!$this->test_device_id) {
            throw new Exception('Test device not created');
        }

        // Create multiple subscriptions with different instances
        $subscriptions = [
            ['path' => 'test/path1', 'instance' => 'instance1'],
            ['path' => 'test/path2', 'instance' => 'instance2'],
            ['path' => 'test/path3', 'instance' => 'instance3']
        ];

        $sub_ids = [];
        foreach ($subscriptions as $sub) {
            $sub_id = gnmi_create_subscription(
                $this->test_device_id,
                $sub['path'],
                $sub['instance']
            );

            if ($sub_id === false) {
                throw new Exception('Failed to create subscription: ' . $sub['path']);
            }

            // Add metric to each subscription
            $metric_id = gnmi_add_metric_to_subscription($sub_id, 'metric-' . $sub['instance'], 'COUNTER');
            if ($metric_id === false) {
                throw new Exception('Failed to create metric for subscription: ' . $sub['path']);
            }

            $sub_ids[] = $sub_id;
        }

        $this->test_subscription_id = $sub_ids[0]; // Track first one for cleanup
        $this->test_metric_id = $metric_id; // Track last metric for cleanup

        // Test daemon config generation
        $config = gnmi_build_daemon_config($this->test_device_id);

        if ($config === false) {
            throw new Exception('Daemon config generation failed');
        }

        // Verify all subscriptions are included
        if (count($config['subscriptions']) !== 3) {
            throw new Exception('Expected 3 subscriptions, got ' . count($config['subscriptions']));
        }

        // Verify each subscription has correct structure
        foreach ($config['subscriptions'] as $subscription) {
            if (!isset($subscription['path']) || !isset($subscription['instance'])) {
                throw new Exception('Subscription missing required fields');
            }

            if (!isset($subscription['metrics']) || !is_array($subscription['metrics'])) {
                throw new Exception('Subscription missing metrics array');
            }

            if (!isset($subscription['field_mapping']) || !is_array($subscription['field_mapping'])) {
                throw new Exception('Subscription missing field_mapping array');
            }
        }

        return true;
    }

    /**
     * Test 5: Daemon handles zero subscriptions (no-op)
     */
    public function testDaemonHandlesZeroSubscriptions() {
        if (!$this->test_device_id) {
            throw new Exception('Test device not created');
        }

        // Test daemon config generation with no subscriptions
        $config = gnmi_build_daemon_config($this->test_device_id);

        // Should return false when no subscriptions exist
        if ($config !== false) {
            throw new Exception('Expected false when no subscriptions exist');
        }

        return true;
    }

    /**
     * Test 6: Daemon config includes all enabled metrics
     */
    public function testDaemonConfigIncludesAllEnabledMetrics() {
        if (!$this->test_device_id) {
            throw new Exception('Test device not created');
        }

        // Create subscription
        $sub_id = gnmi_create_subscription(
            $this->test_device_id,
            'test/path',
            'test-instance'
        );

        if ($sub_id === false) {
            throw new Exception('Failed to create test subscription');
        }

        $this->test_subscription_id = $sub_id;

        // Add multiple metrics
        $metric_names = ['metric1', 'metric2', 'metric3'];
        foreach ($metric_names as $metric_name) {
            $metric_id = gnmi_add_metric_to_subscription($sub_id, $metric_name, 'COUNTER');
            if ($metric_id === false) {
                throw new Exception('Failed to create metric: ' . $metric_name);
            }
        }

        // Test daemon config generation
        $config = gnmi_build_daemon_config($this->test_device_id);

        if ($config === false) {
            throw new Exception('Daemon config generation failed');
        }

        // Verify metrics are included
        $subscription = $config['subscriptions'][0];
        if (count($subscription['metrics']) !== 3) {
            throw new Exception('Expected 3 metrics, got ' . count($subscription['metrics']));
        }

        foreach ($metric_names as $metric_name) {
            if (!in_array($metric_name, $subscription['metrics'])) {
                throw new Exception('Metric not found in config: ' . $metric_name);
            }
        }

        return true;
    }

    /**
     * Test 7: Daemon config excludes disabled metrics
     */
    public function testDaemonConfigExcludesDisabledMetrics() {
        if (!$this->test_device_id) {
            throw new Exception('Test device not created');
        }

        // Create subscription
        $sub_id = gnmi_create_subscription(
            $this->test_device_id,
            'test/path',
            'test-instance'
        );

        if ($sub_id === false) {
            throw new Exception('Failed to create test subscription');
        }

        $this->test_subscription_id = $sub_id;

        // Add enabled metric
        $metric_id1 = gnmi_add_metric_to_subscription($sub_id, 'metric1', 'COUNTER');
        if ($metric_id1 === false) {
            throw new Exception('Failed to create metric1');
        }

        // Add disabled metric
        $metric_id2 = gnmi_add_metric_to_subscription($sub_id, 'metric2', 'COUNTER', ['enabled' => false]);
        if ($metric_id2 === false) {
            throw new Exception('Failed to create metric2');
        }

        // Test daemon config generation
        $config = gnmi_build_daemon_config($this->test_device_id);

        if ($config === false) {
            throw new Exception('Daemon config generation failed');
        }

        // Verify only enabled metric is included
        $subscription = $config['subscriptions'][0];
        if (count($subscription['metrics']) !== 1) {
            throw new Exception('Expected 1 enabled metric, got ' . count($subscription['metrics']));
        }

        if ($subscription['metrics'][0] !== 'metric1') {
            throw new Exception('Wrong metric included');
        }

        return true;
    }

    /**
     * Test 8: Daemon config includes field mapping
     */
    public function testDaemonConfigIncludesFieldMapping() {
        if (!$this->test_device_id) {
            throw new Exception('Test device not created');
        }

        // Create subscription
        $sub_id = gnmi_create_subscription(
            $this->test_device_id,
            'test/path',
            'test-instance'
        );

        if ($sub_id === false) {
            throw new Exception('Failed to create test subscription');
        }

        $this->test_subscription_id = $sub_id;

        // Add metric with special characters
        $metric_id = gnmi_add_metric_to_subscription($sub_id, 'in-octets', 'COUNTER');
        if ($metric_id === false) {
            throw new Exception('Failed to create metric');
        }

        // Test daemon config generation
        $config = gnmi_build_daemon_config($this->test_device_id);

        if ($config === false) {
            throw new Exception('Daemon config generation failed');
        }

        // Verify field mapping is included
        $subscription = $config['subscriptions'][0];
        if (!isset($subscription['field_mapping']['in-octets'])) {
            throw new Exception('Field mapping not found');
        }

        $cacti_field = $subscription['field_mapping']['in-octets'];
        if ($cacti_field !== 'in_octets') {
            throw new Exception('Field mapping incorrect: expected in_octets, got ' . $cacti_field);
        }

        return true;
    }

    /**
     * Test 9: Daemon config includes device connection info
     */
    public function testDaemonConfigIncludesDeviceConnectionInfo() {
        if (!$this->test_device_id) {
            throw new Exception('Test device not created');
        }

        // Create subscription
        $sub_id = gnmi_create_subscription(
            $this->test_device_id,
            'test/path',
            'test-instance'
        );

        if ($sub_id === false) {
            throw new Exception('Failed to create test subscription');
        }

        // Add metric to subscription
        $metric_id = gnmi_add_metric_to_subscription($sub_id, 'test-metric', 'COUNTER');
        if ($metric_id === false) {
            throw new Exception('Failed to create test metric');
        }

        $this->test_subscription_id = $sub_id;
        $this->test_metric_id = $metric_id;

        // Test daemon config generation
        $config = gnmi_build_daemon_config($this->test_device_id);

        if ($config === false) {
            throw new Exception('Daemon config generation failed');
        }

        // Verify device connection info is included
        $required_fields = ['device_id', 'hostname', 'port', 'username', 'password', 'use_tls', 'insecure', 'compatibility_mode', 'tls_cipher_policy', 'encoding', 'collection_interval'];
        foreach ($required_fields as $field) {
            if (!isset($config[$field])) {
                throw new Exception('Config missing field: ' . $field);
            }
        }

        // Verify values are correct
        if ($config['device_id'] != $this->test_device_id) {
            throw new Exception('Device ID incorrect');
        }

        if ($config['hostname'] !== 'test-device.example.com') {
            throw new Exception('Hostname incorrect');
        }

        if ($config['port'] != 9339) {
            throw new Exception('Port incorrect');
        }

        if ($config['insecure'] !== false || $config['compatibility_mode'] !== 'standard') {
            throw new Exception('Standard TLS/compatibility defaults are incorrect');
        }

        return true;
    }

    /**
     * Test 10: Daemon config handles subscriptions with no metrics
     */
    public function testDaemonConfigHandlesSubscriptionsWithNoMetrics() {
        if (!$this->test_device_id) {
            throw new Exception('Test device not created');
        }

        // Create subscription with no metrics
        $sub_id = gnmi_create_subscription(
            $this->test_device_id,
            'test/path',
            'test-instance'
        );

        if ($sub_id === false) {
            throw new Exception('Failed to create test subscription');
        }

        $this->test_subscription_id = $sub_id;

        // Test daemon config generation
        $config = gnmi_build_daemon_config($this->test_device_id);

        // Should return false when subscription has no metrics
        if ($config !== false) {
            throw new Exception('Expected false when subscription has no metrics');
        }

        return true;
    }

    /**
     * Test 11: Daemon config handles mixed enabled/disabled subscriptions
     */
    public function testDaemonConfigHandlesMixedEnabledDisabledSubscriptions() {
        if (!$this->test_device_id) {
            throw new Exception('Test device not created');
        }

        // Create enabled subscription with metrics
        $sub_id1 = gnmi_create_subscription(
            $this->test_device_id,
            'test/path1',
            'test-instance1'
        );

        if ($sub_id1 === false) {
            throw new Exception('Failed to create test subscription 1');
        }

        $metric_id = gnmi_add_metric_to_subscription($sub_id1, 'metric1', 'COUNTER');
        if ($metric_id === false) {
            throw new Exception('Failed to create metric');
        }

        // Create disabled subscription with metrics
        $sub_id2 = gnmi_create_subscription(
            $this->test_device_id,
            'test/path2',
            'test-instance2',
            ['enabled' => false]
        );

        if ($sub_id2 === false) {
            throw new Exception('Failed to create test subscription 2');
        }

        $this->test_subscription_id = $sub_id1; // Track first one for cleanup

        // Test daemon config generation
        $config = gnmi_build_daemon_config($this->test_device_id);

        if ($config === false) {
            throw new Exception('Daemon config generation failed');
        }

        // Verify only enabled subscription is included
        if (count($config['subscriptions']) !== 1) {
            throw new Exception('Expected 1 enabled subscription, got ' . count($config['subscriptions']));
        }

        $subscription = $config['subscriptions'][0];
        if ($subscription['path'] !== 'test/path1') {
            throw new Exception('Wrong subscription included');
        }

        return true;
    }

    /**
     * Test 11a: Disk JSON (bool false) vs DB skip_verify/use_tls as "0"/"1" must not report changed.
     *
     * Regression: (string)false === "" but MySQL returns "0", which previously forced restart every poller cycle.
     */
    public function testConfigChangeDetectionBoolFalseMatchesMysqlZero() {
        if (!$this->test_device_id) {
            throw new Exception('Test device not created');
        }

        $sub_id = gnmi_create_subscription(
            $this->test_device_id,
            'test/bool-compare/path',
            'bool-compare-instance'
        );
        if ($sub_id === false) {
            throw new Exception('Failed to create test subscription');
        }
        $this->test_subscription_id = $sub_id;

        $metric_id = gnmi_add_metric_to_subscription($sub_id, 'bool-compare-metric', 'COUNTER');
        if ($metric_id === false) {
            throw new Exception('Failed to create test metric');
        }
        $this->test_metric_id = $metric_id;

        $cfg = gnmi_build_daemon_config($this->test_device_id);
        if ($cfg === false) {
            throw new Exception('Daemon config generation failed');
        }

        $storage = sys_get_temp_dir() . '/gnmi_bool_cfg_' . getmypid();
        if (!@mkdir($storage, 0755, true) && !is_dir($storage)) {
            throw new Exception('Failed to create temp storage dir');
        }
        $path = $storage . '/device_' . $this->test_device_id . '_config.json';
        file_put_contents($path, json_encode($cfg));

        $changed = gnmi_check_subscription_config_changed($this->test_device_id, null, $storage);

        @unlink($path);
        @rmdir($storage);

        if ($changed !== false) {
            throw new Exception('Expected no config change when disk JSON matches DB (bool/MySQL 0-1 normalization)');
        }

        return true;
    }

    /**
     * Test 11b: Changing device password on disk must trigger config-changed detection.
     *
     * Regression: $fields_to_check included 'username' but omitted 'password', so a
     * credential change in the DB would never restart the daemon.
     */
    public function testConfigChangeDetectionPasswordField() {
        if (!$this->test_device_id) {
            throw new Exception('Test device not created');
        }

        $sub_id = gnmi_create_subscription(
            $this->test_device_id,
            'test/password-change/path',
            'password-change-instance'
        );
        if ($sub_id === false) {
            throw new Exception('Failed to create test subscription');
        }
        $this->test_subscription_id = $sub_id;

        $metric_id = gnmi_add_metric_to_subscription($sub_id, 'pw-change-metric', 'COUNTER');
        if ($metric_id === false) {
            throw new Exception('Failed to create test metric');
        }
        $this->test_metric_id = $metric_id;

        // Build the "current" daemon config from the DB
        $cfg = gnmi_build_daemon_config($this->test_device_id);
        if ($cfg === false) {
            throw new Exception('Daemon config generation failed');
        }

        // Simulate stale disk config with a different password
        $cfg['password'] = 'stale-old-password-that-differs';

        $storage = sys_get_temp_dir() . '/gnmi_pw_cfg_' . getmypid();
        if (!@mkdir($storage, 0755, true) && !is_dir($storage)) {
            throw new Exception('Failed to create temp storage dir');
        }
        $path = $storage . '/device_' . $this->test_device_id . '_config.json';
        file_put_contents($path, json_encode($cfg));

        $changed = gnmi_check_subscription_config_changed($this->test_device_id, null, $storage);

        @unlink($path);
        @rmdir($storage);

        if ($changed !== true) {
            throw new Exception('Expected config change detected when password differs between disk and DB');
        }

        return true;
    }

    /**
     * TLS and Ciena compatibility settings must be carried into daemon JSON.
     */
    public function testDaemonConfigTransportAndCompatibilityMode() {
        if (!$this->test_device_id) {
            throw new Exception('Test device not created');
        }

        db_execute_prepared(
            'UPDATE plugin_gnmi_devices SET use_tls = 0, compatibility_mode = ? WHERE id = ?',
            array('ciena_saos10', $this->test_device_id)
        );
        $sub_id = gnmi_create_subscription($this->test_device_id, 'test/transport/path', 'transport-instance');
        $this->test_subscription_id = $sub_id;
        $metric_id = gnmi_add_metric_to_subscription($sub_id, 'transport-metric', 'COUNTER');
        $this->test_metric_id = $metric_id;

        $config = gnmi_build_daemon_config($this->test_device_id);
        if ($config === false) {
            throw new Exception('Daemon config generation failed');
        }
        if ($config['use_tls'] !== false || $config['insecure'] !== true) {
            throw new Exception('use_tls=false must generate insecure=true for pygnmi');
        }
        if ($config['compatibility_mode'] !== 'ciena_saos10') {
            throw new Exception('Ciena compatibility mode missing from daemon config');
        }

        return true;
    }

    /**
     * Test 12: Daemon config validates required fields
     */
    public function testDaemonConfigValidatesRequiredFields() {
        // Test with invalid device_id
        $config = gnmi_build_daemon_config(99999);

        if ($config !== false) {
            throw new Exception('Expected false for invalid device_id');
        }

        // Test with null device_id
        $config = gnmi_build_daemon_config(null);

        if ($config !== false) {
            throw new Exception('Expected false for null device_id');
        }

        return true;
    }
}

// Simple test runner
$test = new TestDaemonDynamicConfig();

echo "Running Phase 3.3 Daemon Dynamic Configuration Tests...\n";
echo "====================================================\n";

$reflection = new ReflectionClass($test);
$methods = $reflection->getMethods(ReflectionMethod::IS_PUBLIC);

$passed = 0;
$skipped = 0;
$failed = 0;

foreach ($methods as $method) {
    if (strpos($method->name, 'test') === 0) {
        $test->setUp(); // Run setup before each test
        echo "Running " . $method->name . "... ";
        try {
            $result = $method->invoke($test);
            if ($result === true) {
                echo "PASS\n";
                $passed++;
            } elseif (is_string($result) && strpos($result, 'SKIP') === 0) {
                echo "SKIP\n";
                $skipped++;
            } else {
                echo "FAIL: Unexpected test result\n";
                $failed++;
            }
        } catch (Exception $e) {
            echo "FAIL: " . $e->getMessage() . "\n";
            $failed++;
        }
        $test->tearDown(); // Run teardown after each test
    }
}

echo "\nTest Results:\n";
echo "=============\n";
echo "Passed: $passed\n";
echo "Skipped: $skipped\n";
echo "Failed: $failed\n";
echo "\nTest run complete.\n";

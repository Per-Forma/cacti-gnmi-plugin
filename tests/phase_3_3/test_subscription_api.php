<?php
/**
 * gNMI Plugin Phase 3.3 Subscription API Tests
 *
 * Tests for subscription CRUD operations and validation.
 * Follows TDD approach - write tests first, then implement to pass.
 */

require_once('/var/www/html/cacti/include/global.php');
require_once('/var/www/html/cacti/lib/database.php');
require_once('/var/www/html/cacti/plugins/gnmi/include/subscription_functions.php');

class TestSubscriptionAPI {

    private $test_device_id = null;
    private $test_subscription_id = null;
    private $test_metric_id = null;

    public function setUp() {
        // Create test device for API testing
        $this->createTestDevice();
    }

    public function tearDown() {
        // Clean up test data
        $this->cleanupTestData();
    }

    private function createTestDevice() {
        // Clean up any existing test device first
        db_execute("DELETE FROM plugin_gnmi_devices WHERE hostname = 'test-device.example.com'");

        // Create a test device entry
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
        } else {
            $this->test_device_id = null;
        }
    }

    private function cleanupTestData() {
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
     * Test 1: Create subscription with valid data
     */
    public function testCreateSubscriptionValid() {
        // Ensure we have a test device
        if (!$this->test_device_id) {
            $this->createTestDevice();
        }

        $result = gnmi_create_subscription(
            $this->test_device_id,
            'Ciena:cn-if:interface-telemetry-state/interface-counters[interface-type=ettp]/interfaces[if-name=40]/counters',
            'ettp-40'
        );

        if ($result === false) {
            throw new Exception('Expected subscription creation to succeed');
        }

        $this->test_subscription_id = $result;

        // Verify subscription was created
        $subscription = gnmi_get_subscription($result);
        if (!$subscription) {
            throw new Exception('Created subscription not found');
        }

        if ($subscription['device_id'] != $this->test_device_id) {
            throw new Exception('Device ID mismatch');
        }

        return true;
    }

    /**
     * Test 2: Create subscription with missing required fields
     */
    public function testCreateSubscriptionMissingFields() {
        // Test missing device_id
        $result = gnmi_create_subscription(null, 'test/path', 'test-instance');
        if ($result !== false) {
            throw new Exception('Expected subscription creation to fail with null device_id');
        }

        // Test missing subscription_path
        $result = gnmi_create_subscription($this->test_device_id, '', 'test-instance');
        if ($result !== false) {
            throw new Exception('Expected subscription creation to fail with empty path');
        }

        // Test missing instance_identifier
        $result = gnmi_create_subscription($this->test_device_id, 'test/path', '');
        if ($result !== false) {
            throw new Exception('Expected subscription creation to fail with empty instance');
        }

        return true;
    }

    /**
     * Test 3: Create subscription with invalid device_id
     */
    public function testCreateSubscriptionInvalidDevice() {
        $result = gnmi_create_subscription(99999, 'test/path', 'test-instance');
        if ($result !== false) {
            throw new Exception('Expected subscription creation to fail with invalid device_id');
        }

        return true;
    }

    /**
     * Test 4: Read subscription by ID
     */
    public function testGetSubscription() {
        // Ensure we have a test device
        if (!$this->test_device_id) {
            $this->createTestDevice();
        }

        // Create test subscription first
        $sub_id = gnmi_create_subscription(
            $this->test_device_id,
            'test/path',
            'test-instance'
        );

        if ($sub_id === false) {
            throw new Exception('Failed to create test subscription');
        }

        $this->test_subscription_id = $sub_id;

        // Test reading it back
        $subscription = gnmi_get_subscription($sub_id);
        if (!$subscription) {
            throw new Exception('Failed to read subscription');
        }

        if ($subscription['subscription_path'] !== 'test/path') {
            throw new Exception('Subscription path mismatch');
        }

        if ($subscription['instance_identifier'] !== 'test-instance') {
            throw new Exception('Instance identifier mismatch');
        }

        return true;
    }

    /**
     * Test 5: List subscriptions for device
     */
    public function testGetDeviceSubscriptions() {
        // Create test subscriptions
        $sub1 = gnmi_create_subscription($this->test_device_id, 'path1/test', 'instance1');
        $sub2 = gnmi_create_subscription($this->test_device_id, 'path2/test', 'instance2');

        if ($sub1 === false || $sub2 === false) {
            throw new Exception('Failed to create test subscriptions');
        }

        $this->test_subscription_id = $sub1; // For cleanup

        // Test listing
        $subscriptions = gnmi_get_device_subscriptions($this->test_device_id);
        if (!is_array($subscriptions)) {
            throw new Exception('Expected array of subscriptions');
        }

        if (count($subscriptions) < 2) {
            throw new Exception('Expected at least 2 subscriptions');
        }

        // Test enabled_only filter
        $enabled_subscriptions = gnmi_get_device_subscriptions($this->test_device_id, true);
        if (!is_array($enabled_subscriptions)) {
            throw new Exception('Expected array of enabled subscriptions');
        }

        return true;
    }

    /**
     * Test 6: Update subscription path
     */
    public function testUpdateSubscriptionPath() {
        // Create test subscription
        $sub_id = gnmi_create_subscription($this->test_device_id, 'old/path', 'test-instance');
        if ($sub_id === false) {
            throw new Exception('Failed to create test subscription');
        }

        $this->test_subscription_id = $sub_id;

        // Update path
        $result = gnmi_update_subscription($sub_id, ['subscription_path' => 'new/path']);
        if ($result === false) {
            throw new Exception('Failed to update subscription');
        }

        // Verify update
        $subscription = gnmi_get_subscription($sub_id);
        if ($subscription['subscription_path'] !== 'new/path') {
            throw new Exception('Subscription path not updated');
        }

        return true;
    }

    /**
     * Test 7: Update subscription enabled flag
     */
    public function testUpdateSubscriptionEnabled() {
        // Create test subscription
        $sub_id = gnmi_create_subscription($this->test_device_id, 'test/path', 'test-instance');
        if ($sub_id === false) {
            throw new Exception('Failed to create test subscription');
        }

        $this->test_subscription_id = $sub_id;

        // Disable subscription
        $result = gnmi_update_subscription($sub_id, ['enabled' => false]);
        if ($result === false) {
            throw new Exception('Failed to disable subscription');
        }

        // Verify disabled
        $subscription = gnmi_get_subscription($sub_id);
        if ($subscription['enabled'] != 0) {
            throw new Exception('Subscription not disabled');
        }

        return true;
    }

    /**
     * Test 8: Delete subscription
     */
    public function testDeleteSubscription() {
        // Create test subscription
        $sub_id = gnmi_create_subscription($this->test_device_id, 'test/path', 'test-instance');
        if ($sub_id === false) {
            throw new Exception('Failed to create test subscription');
        }

        // Delete subscription
        $result = gnmi_delete_subscription($sub_id);
        if ($result === false) {
            throw new Exception('Failed to delete subscription');
        }

        // Verify deleted
        $subscription = gnmi_get_subscription($sub_id);
        if ($subscription !== false) {
            throw new Exception('Subscription still exists after deletion');
        }

        return true;
    }

    /**
     * Test 9: Delete subscription CASCADE deletes metrics
     */
    public function testDeleteSubscriptionCascade() {
        // Create test subscription
        $sub_id = gnmi_create_subscription($this->test_device_id, 'test/path', 'test-instance');
        if ($sub_id === false) {
            throw new Exception('Failed to create test subscription');
        }

        // Add test metric
        $metric_id = gnmi_add_metric_to_subscription($sub_id, 'test-metric', 'COUNTER');
        if ($metric_id === false) {
            throw new Exception('Failed to create test metric');
        }

        $this->test_metric_id = $metric_id;

        // Delete subscription (should cascade to metric)
        $result = gnmi_delete_subscription($sub_id);
        if ($result === false) {
            throw new Exception('Failed to delete subscription');
        }

        // Verify metric was also deleted
        $metric = db_fetch_row_prepared('SELECT * FROM plugin_gnmi_metrics WHERE id = ?', array($metric_id));
        if (!empty($metric)) {
            throw new Exception('Metric not deleted by CASCADE');
        }

        return true;
    }

    /**
     * Test 10: Add metric to subscription
     */
    public function testAddMetricToSubscription() {
        // Create test subscription
        $sub_id = gnmi_create_subscription($this->test_device_id, 'test/path', 'test-instance');
        if ($sub_id === false) {
            throw new Exception('Failed to create test subscription');
        }

        $this->test_subscription_id = $sub_id;

        // Add metric
        $metric_id = gnmi_add_metric_to_subscription($sub_id, 'in-octets', 'COUNTER');
        if ($metric_id === false) {
            throw new Exception('Failed to add metric');
        }

        $this->test_metric_id = $metric_id;

        // Verify metric was created
        $metric = gnmi_get_subscription_metrics($sub_id);
        if (!is_array($metric) || count($metric) !== 1) {
            throw new Exception('Expected one metric');
        }

        if ($metric[0]['metric_name'] !== 'in-octets') {
            throw new Exception('Metric name mismatch');
        }

        return true;
    }

    /**
     * Test 11: Add duplicate metric should fail
     */
    public function testAddDuplicateMetric() {
        // Create test subscription
        $sub_id = gnmi_create_subscription($this->test_device_id, 'test/path', 'test-instance');
        if ($sub_id === false) {
            throw new Exception('Failed to create test subscription');
        }

        $this->test_subscription_id = $sub_id;

        // Add first metric
        $metric_id1 = gnmi_add_metric_to_subscription($sub_id, 'in-octets', 'COUNTER');
        if ($metric_id1 === false) {
            throw new Exception('Failed to add first metric');
        }

        $this->test_metric_id = $metric_id1;

        // Try to add duplicate metric
        $metric_id2 = gnmi_add_metric_to_subscription($sub_id, 'in-octets', 'COUNTER');
        if ($metric_id2 !== false) {
            throw new Exception('Expected duplicate metric to fail');
        }

        return true;
    }

    /**
     * Test 12: Delete metric
     */
    public function testDeleteMetric() {
        // Create test subscription and metric
        $sub_id = gnmi_create_subscription($this->test_device_id, 'test/path', 'test-instance');
        $metric_id = gnmi_add_metric_to_subscription($sub_id, 'in-octets', 'COUNTER');

        if ($sub_id === false || $metric_id === false) {
            throw new Exception('Failed to create test data');
        }

        $this->test_subscription_id = $sub_id;

        // Delete metric
        $result = gnmi_delete_metric($metric_id);
        if ($result === false) {
            throw new Exception('Failed to delete metric');
        }

        // Verify deleted
        $metric = db_fetch_row_prepared('SELECT * FROM plugin_gnmi_metrics WHERE id = ?', array($metric_id));
        if (!empty($metric)) {
            throw new Exception('Metric still exists after deletion');
        }

        return true;
    }

    /**
     * Test 13: Metric field name sanitization
     */
    public function testMetricFieldNameSanitization() {
        $test_cases = [
            'in-octets' => 'in_octets',
            'in-crc-error-pkts' => 'in_crc_error_pkts',
            'in-1024-to-1518-octet-pkts' => 'in_1024_to_1518_oct', // Truncated to 19 chars
            'metric@#$%name' => 'metric____name',
            '64-octet-pkts' => 'octet_pkts_64', // Leading number moved to end
            'In-Octets' => 'in_octets' // Lowercase
        ];

        foreach ($test_cases as $input => $expected) {
            $result = gnmi_sanitize_metric_name($input);
            if ($result !== $expected) {
                throw new Exception("Sanitization failed for '$input': expected '$expected', got '$result'");
            }
        }

        return true;
    }

    /**
     * Test 14: Metric field name truncation
     */
    public function testMetricFieldNameTruncation() {
        $long_name = 'very-long-metric-name-that-exceeds-19-chars';
        $result = gnmi_sanitize_metric_name($long_name);

        if (strlen($result) > 19) {
            throw new Exception('Metric name not truncated to 19 characters');
        }

        return true;
    }

    /**
     * Test 15: Metric collision detection
     */
    public function testMetricCollisionDetection() {
        // Create test subscription
        $sub_id = gnmi_create_subscription($this->test_device_id, 'test/path', 'test-instance');
        if ($sub_id === false) {
            throw new Exception('Failed to create test subscription');
        }

        $this->test_subscription_id = $sub_id;

        // Add first metric
        $metric_id1 = gnmi_add_metric_to_subscription($sub_id, 'in-octets', 'COUNTER');
        if ($metric_id1 === false) {
            throw new Exception('Failed to add first metric');
        }

        $this->test_metric_id = $metric_id1;

        // Test collision detection
        $collision_name = gnmi_detect_metric_collision($sub_id, 'in_octets');
        if ($collision_name === 'in_octets') {
            throw new Exception('Expected collision detection to return different name');
        }

        if (!preg_match('/^in_octets\d+$/', $collision_name)) {
            throw new Exception('Expected collision name to have numeric suffix');
        }

        return true;
    }

    /**
     * Test 16: Validate subscription path
     */
    public function testValidateSubscriptionPath() {
        $valid_paths = [
            'Ciena:cn-if:interface-telemetry-state/interface-counters[interface-type=ettp]/interfaces[if-name=40]/counters',
            '/interfaces/interface[name=eth0]/state/counters',
            'openconfig-interfaces:interfaces/interface[name=GigabitEthernet0/0/0]/state/counters'
        ];

        foreach ($valid_paths as $path) {
            $result = gnmi_validate_subscription_path($path);
            if ($result === false) {
                throw new Exception("Valid path rejected: $path");
            }
        }

        $invalid_paths = [
            '',
            '   ',
            'invalid-path-without-slashes',
            null
        ];

        foreach ($invalid_paths as $path) {
            $result = gnmi_validate_subscription_path($path);
            if ($result !== false) {
                throw new Exception("Invalid path accepted: " . var_export($path, true));
            }
        }

        return true;
    }

    /**
     * Test 17: Update metric fields
     */
    public function testUpdateMetric() {
        // Create test subscription and metric
        $sub_id = gnmi_create_subscription($this->test_device_id, 'test/path', 'test-instance');
        $metric_id = gnmi_add_metric_to_subscription($sub_id, 'in-octets', 'COUNTER');

        if ($sub_id === false || $metric_id === false) {
            throw new Exception('Failed to create test data');
        }

        $this->test_subscription_id = $sub_id;
        $this->test_metric_id = $metric_id;

        // Update metric
        $result = gnmi_update_metric($metric_id, [
            'rrd_type' => 'GAUGE',
            'enabled' => false
        ]);

        if ($result === false) {
            throw new Exception('Failed to update metric');
        }

        // Verify update
        $metric = db_fetch_row_prepared('SELECT * FROM plugin_gnmi_metrics WHERE id = ?', array($metric_id));
        if ($metric['rrd_type'] !== 'GAUGE') {
            throw new Exception('RRD type not updated');
        }

        if ($metric['enabled'] != 0) {
            throw new Exception('Enabled flag not updated');
        }

        return true;
    }

    /**
     * Test 18: Get subscription metrics with enabled filter
     */
    public function testGetSubscriptionMetricsFiltered() {
        // Create test subscription
        $sub_id = gnmi_create_subscription($this->test_device_id, 'test/path', 'test-instance');
        if ($sub_id === false) {
            throw new Exception('Failed to create test subscription');
        }

        $this->test_subscription_id = $sub_id;

        // Add enabled and disabled metrics
        $metric1 = gnmi_add_metric_to_subscription($sub_id, 'metric1', 'COUNTER');
        $metric2 = gnmi_add_metric_to_subscription($sub_id, 'metric2', 'COUNTER');

        if ($metric1 === false || $metric2 === false) {
            throw new Exception('Failed to create test metrics');
        }

        // Disable second metric
        gnmi_update_metric($metric2, ['enabled' => false]);

        // Test enabled only
        $enabled_metrics = gnmi_get_subscription_metrics($sub_id, true);
        if (count($enabled_metrics) !== 1) {
            throw new Exception('Expected 1 enabled metric');
        }

        // Test all metrics
        $all_metrics = gnmi_get_subscription_metrics($sub_id, false);
        if (count($all_metrics) !== 2) {
            throw new Exception('Expected 2 total metrics');
        }

        return true;
    }

    /**
     * Test 19: Input sanitization
     */
    public function testInputSanitization() {
        // Test XSS prevention
        $malicious_path = '<script>alert("xss")</script>';
        $sub_id = gnmi_create_subscription($this->test_device_id, $malicious_path, 'test-instance');

        if ($sub_id === false) {
            throw new Exception('Failed to create subscription with malicious input');
        }

        $this->test_subscription_id = $sub_id;

        // Verify input was sanitized
        $subscription = gnmi_get_subscription($sub_id);
        if (strpos($subscription['subscription_path'], '<script>') !== false) {
            throw new Exception('XSS input not sanitized');
        }

        return true;
    }

    /**
     * Test 20: Error logging
     */
    public function testErrorLogging() {
        // This test would require mocking the logging system
        // For now, just verify that invalid operations return false
        $result = gnmi_create_subscription(99999, 'test', 'test');
        if ($result !== false) {
            throw new Exception('Expected error for invalid device_id');
        }

        return true;
    }
}

// Simple test runner
$test = new TestSubscriptionAPI();

echo "Running Phase 3.3 Subscription API Tests...\n";
echo "==========================================\n";

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

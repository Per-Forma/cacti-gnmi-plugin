#!/usr/bin/env php
<?php
/**
 * gNMI Plugin Phase 3.3 End-to-End Integration Tests
 *
 * Tests the complete workflow from UI to data collection.
 * Follows TDD approach - write tests first, then implement to pass.
 */

require_once('/var/www/html/cacti/include/global.php');
require_once('/var/www/html/cacti/lib/database.php');
require_once('/var/www/html/cacti/plugins/gnmi/include/subscription_functions.php');
require_once('/var/www/html/cacti/plugins/gnmi/include/functions.php');

class TestEndToEndIntegration {

    public $test_device_id = null;
    public $test_subscription_id = null;
    public $test_metric_id = null;
    public $test_data_local_id = null;

    public function setUp() {
        // Clean up any existing test data
        db_execute("DELETE FROM plugin_gnmi_devices WHERE hostname = 'integration-test.example.com'");

        // Create test device
        $result = db_execute("
            INSERT INTO plugin_gnmi_devices (
                host_id, enabled, hostname, port, username, password,
                use_tls, collection_interval, encoding
            ) VALUES (
                1, 1, 'integration-test.example.com', 9339, 'testuser', 'testpass',
                1, 10, 'JSON_IETF'
            )
        ");

        if ($result) {
            $this->test_device_id = db_fetch_cell('SELECT LAST_INSERT_ID()');
        }
    }

    public function tearDown() {
        // Clean up test data
        if ($this->test_data_local_id) {
            db_execute_prepared('DELETE FROM data_template_rrd WHERE local_data_id = ?', array($this->test_data_local_id));
            db_execute_prepared('DELETE FROM data_template_data WHERE local_data_id = ?', array($this->test_data_local_id));
            db_execute_prepared('DELETE FROM data_local WHERE id = ?', array($this->test_data_local_id));
        }
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
     * Test 1: Complete workflow - Create subscription → Add metric → Create data source
     */
    public function testCompleteWorkflow() {
        if (!$this->test_device_id) {
            throw new Exception('Test device not created');
        }

        // Step 1: Create subscription
        $sub_id = gnmi_create_subscription(
            $this->test_device_id,
            'test/path',
            'test-instance'
        );

        if ($sub_id === false) {
            throw new Exception('Failed to create subscription');
        }

        $this->test_subscription_id = $sub_id;

        // Step 2: Add metric
        $metric_id = gnmi_add_metric_to_subscription($sub_id, 'test-metric', 'COUNTER');
        if ($metric_id === false) {
            throw new Exception('Failed to create metric');
        }

        $this->test_metric_id = $metric_id;

        // Step 3: Create data source
        $data_local_id = gnmi_create_data_sources_for_subscription($sub_id);
        if ($data_local_id === false) {
            throw new Exception('Failed to create data source');
        }

        $this->test_data_local_id = $data_local_id;

        // Verify all components exist
        $subscription = gnmi_get_subscription($sub_id);
        if (!$subscription) {
            throw new Exception('Subscription not found');
        }

        $metric = db_fetch_row_prepared('SELECT * FROM plugin_gnmi_metrics WHERE id = ?', array($metric_id));
        if (!$metric) {
            throw new Exception('Metric not found');
        }

        $data_local = db_fetch_row_prepared('SELECT * FROM data_local WHERE id = ?', array($data_local_id));
        if (!$data_local) {
            throw new Exception('Data source not found');
        }

        // Verify relationships
        if ($metric['subscription_id'] != $sub_id) {
            throw new Exception('Metric not linked to subscription');
        }

        if ($metric['local_data_id'] != $data_local_id) {
            throw new Exception('Metric not linked to data source');
        }

        if ($data_local['host_id'] != 1) {
            throw new Exception('Data source not linked to correct host');
        }

        return true;
    }

    /**
     * Test 2: Multiple metrics in single subscription
     */
    public function testMultipleMetricsInSubscription() {
        if (!$this->test_device_id) {
            throw new Exception('Test device not created');
        }

        // Create subscription
        $sub_id = gnmi_create_subscription($this->test_device_id, 'test/path', 'test-instance');
        $this->test_subscription_id = $sub_id;

        // Add multiple metrics
        $metric_names = ['metric1', 'metric2', 'metric3'];
        $metric_ids = [];

        foreach ($metric_names as $metric_name) {
            $metric_id = gnmi_add_metric_to_subscription($sub_id, $metric_name, 'COUNTER');
            if ($metric_id === false) {
                throw new Exception('Failed to create metric: ' . $metric_name);
            }
            $metric_ids[] = $metric_id;
        }

        $this->test_metric_id = $metric_ids[0]; // Track first one for cleanup

        // Create data source
        $data_local_id = gnmi_create_data_sources_for_subscription($sub_id);
        $this->test_data_local_id = $data_local_id;

        // Verify all metrics are in the same data source
        $metrics = db_fetch_assoc_prepared(
            'SELECT * FROM plugin_gnmi_metrics WHERE subscription_id = ?',
            array($sub_id)
        );

        if (count($metrics) !== 3) {
            throw new Exception('Expected 3 metrics, got ' . count($metrics));
        }

        foreach ($metrics as $metric) {
            if ($metric['local_data_id'] != $data_local_id) {
                throw new Exception('Metric not in correct data source');
            }
        }

        // Verify RRD entries created
        $rrd_entries = db_fetch_assoc_prepared(
            'SELECT * FROM data_template_rrd WHERE local_data_id = ?',
            array($data_local_id)
        );

        if (count($rrd_entries) !== 3) {
            throw new Exception('Expected 3 RRD entries, got ' . count($rrd_entries));
        }

        return true;
    }

    /**
     * Test 3: Multiple subscriptions for same device
     */
    public function testMultipleSubscriptionsPerDevice() {
        if (!$this->test_device_id) {
            throw new Exception('Test device not created');
        }

        // Create first subscription
        $sub1_id = gnmi_create_subscription($this->test_device_id, 'test/path1', 'instance1');
        $metric1_id = gnmi_add_metric_to_subscription($sub1_id, 'metric1', 'COUNTER');
        $ds1_id = gnmi_create_data_sources_for_subscription($sub1_id);

        // Create second subscription
        $sub2_id = gnmi_create_subscription($this->test_device_id, 'test/path2', 'instance2');
        $metric2_id = gnmi_add_metric_to_subscription($sub2_id, 'metric2', 'GAUGE');
        $ds2_id = gnmi_create_data_sources_for_subscription($sub2_id);

        $this->test_subscription_id = $sub1_id; // Track first for cleanup
        $this->test_metric_id = $metric1_id;
        $this->test_data_local_id = $ds1_id;

        // Verify separate data sources
        if ($ds1_id === $ds2_id) {
            throw new Exception('Subscriptions should have separate data sources');
        }

        // Verify metrics are in correct data sources
        $metric1 = db_fetch_row_prepared('SELECT * FROM plugin_gnmi_metrics WHERE id = ?', array($metric1_id));
        $metric2 = db_fetch_row_prepared('SELECT * FROM plugin_gnmi_metrics WHERE id = ?', array($metric2_id));

        if ($metric1['local_data_id'] != $ds1_id) {
            throw new Exception('Metric1 not in correct data source');
        }

        if ($metric2['local_data_id'] != $ds2_id) {
            throw new Exception('Metric2 not in correct data source');
        }

        // Cleanup second subscription
        db_execute_prepared('DELETE FROM data_template_rrd WHERE local_data_id = ?', array($ds2_id));
        db_execute_prepared('DELETE FROM data_template_data WHERE local_data_id = ?', array($ds2_id));
        db_execute_prepared('DELETE FROM data_local WHERE id = ?', array($ds2_id));
        db_execute_prepared('DELETE FROM plugin_gnmi_metrics WHERE id = ?', array($metric2_id));
        db_execute_prepared('DELETE FROM plugin_gnmi_subscriptions WHERE id = ?', array($sub2_id));

        return true;
    }

    /**
     * Test 4: Daemon configuration generation
     */
    public function testDaemonConfigurationGeneration() {
        if (!$this->test_device_id) {
            throw new Exception('Test device not created');
        }

        // Create subscription with metrics
        $sub_id = gnmi_create_subscription($this->test_device_id, 'test/path', 'test-instance');
        $metric_id = gnmi_add_metric_to_subscription($sub_id, 'test-metric', 'COUNTER');

        $this->test_subscription_id = $sub_id;
        $this->test_metric_id = $metric_id;

        // Generate daemon config
        $config = gnmi_build_daemon_config($this->test_device_id);

        if ($config === false) {
            throw new Exception('Daemon config generation failed');
        }

        // Verify config structure
        if (!isset($config['device_id']) || $config['device_id'] != $this->test_device_id) {
            throw new Exception('Config missing or incorrect device_id');
        }

        if (!isset($config['subscriptions']) || !is_array($config['subscriptions'])) {
            throw new Exception('Config missing subscriptions array');
        }

        if (count($config['subscriptions']) !== 1) {
            throw new Exception('Expected 1 subscription, got ' . count($config['subscriptions']));
        }

        $subscription = $config['subscriptions'][0];
        if ($subscription['path'] !== 'test/path') {
            throw new Exception('Subscription path incorrect');
        }

        if ($subscription['instance'] !== 'test-instance') {
            throw new Exception('Subscription instance incorrect');
        }

        if (!isset($subscription['metrics']) || !in_array('test-metric', $subscription['metrics'])) {
            throw new Exception('Subscription missing test-metric');
        }

        if (!isset($subscription['field_mapping']) || !isset($subscription['field_mapping']['test-metric'])) {
            throw new Exception('Subscription missing field mapping');
        }

        return true;
    }

    /**
     * Test 5: Disable metric and verify exclusion
     */
    public function testDisableMetricExclusion() {
        if (!$this->test_device_id) {
            throw new Exception('Test device not created');
        }

        // Create subscription with multiple metrics
        $sub_id = gnmi_create_subscription($this->test_device_id, 'test/path', 'test-instance');
        $metric1_id = gnmi_add_metric_to_subscription($sub_id, 'metric1', 'COUNTER');
        $metric2_id = gnmi_add_metric_to_subscription($sub_id, 'metric2', 'COUNTER');

        $this->test_subscription_id = $sub_id;
        $this->test_metric_id = $metric1_id;

        // Disable second metric
        $result = gnmi_update_metric($metric2_id, ['enabled' => false]);
        if (!$result) {
            throw new Exception('Failed to disable metric');
        }

        // Generate daemon config
        $config = gnmi_build_daemon_config($this->test_device_id);

        if ($config === false) {
            throw new Exception('Daemon config generation failed');
        }

        $subscription = $config['subscriptions'][0];

        // Verify only enabled metric is included
        if (in_array('metric2', $subscription['metrics'])) {
            throw new Exception('Disabled metric should not be in config');
        }

        if (!in_array('metric1', $subscription['metrics'])) {
            throw new Exception('Enabled metric should be in config');
        }

        return true;
    }

    /**
     * Test 6: Delete subscription and verify cleanup
     */
    public function testDeleteSubscriptionCleanup() {
        if (!$this->test_device_id) {
            throw new Exception('Test device not created');
        }

        // Create subscription with metric and data source
        $sub_id = gnmi_create_subscription($this->test_device_id, 'test/path', 'test-instance');
        $metric_id = gnmi_add_metric_to_subscription($sub_id, 'test-metric', 'COUNTER');
        $data_local_id = gnmi_create_data_sources_for_subscription($sub_id);

        // Verify data exists
        $subscription = gnmi_get_subscription($sub_id);
        $metric = db_fetch_row_prepared('SELECT * FROM plugin_gnmi_metrics WHERE id = ?', array($metric_id));
        $data_local = db_fetch_row_prepared('SELECT * FROM data_local WHERE id = ?', array($data_local_id));

        if (!$subscription || !$metric || !$data_local) {
            throw new Exception('Test data not created properly');
        }

        // Delete subscription
        $result = gnmi_delete_subscription($sub_id);
        if (!$result) {
            throw new Exception('Failed to delete subscription');
        }

        // Verify subscription deleted
        $subscription = gnmi_get_subscription($sub_id);
        if ($subscription) {
            throw new Exception('Subscription not deleted');
        }

        // Verify metric deleted (CASCADE)
        $metric = db_fetch_row_prepared('SELECT * FROM plugin_gnmi_metrics WHERE id = ?', array($metric_id));
        if ($metric) {
            throw new Exception('Metric not deleted (CASCADE failed)');
        }

        // Verify data source still exists (Cacti cleanup separate)
        $data_local = db_fetch_row_prepared('SELECT * FROM data_local WHERE id = ?', array($data_local_id));
        if (!$data_local) {
            throw new Exception('Data source should still exist (Cacti cleanup separate)');
        }

        // Cleanup data source manually
        db_execute_prepared('DELETE FROM data_template_rrd WHERE local_data_id = ?', array($data_local_id));
        db_execute_prepared('DELETE FROM data_template_data WHERE local_data_id = ?', array($data_local_id));
        db_execute_prepared('DELETE FROM data_local WHERE id = ?', array($data_local_id));

        return true;
    }

    /**
     * Test 7: Data source naming convention
     */
    public function testDataSourceNamingConvention() {
        if (!$this->test_device_id) {
            throw new Exception('Test device not created');
        }

        // Create subscription with specific instance name
        $sub_id = gnmi_create_subscription($this->test_device_id, 'test/path', 'ettp-40');
        $metric_id = gnmi_add_metric_to_subscription($sub_id, 'test-metric', 'COUNTER');

        $this->test_subscription_id = $sub_id;
        $this->test_metric_id = $metric_id;

        // Create data source
        $data_local_id = gnmi_create_data_sources_for_subscription($sub_id);
        $this->test_data_local_id = $data_local_id;

        // Verify naming convention
        $template_data = db_fetch_row_prepared(
            'SELECT name FROM data_template_data WHERE local_data_id = ?',
            array($data_local_id)
        );

        $expected_name = 'gNMI - ettp-40';
        if ($template_data['name'] !== $expected_name) {
            throw new Exception('Data source name incorrect. Expected: ' . $expected_name . ', Got: ' . $template_data['name']);
        }

        return true;
    }

    /**
     * Test 8: RRD type mapping
     */
    public function testRRDTypeMapping() {
        if (!$this->test_device_id) {
            throw new Exception('Test device not created');
        }

        // Create subscription
        $sub_id = gnmi_create_subscription($this->test_device_id, 'test/path', 'test-instance');

        // Add metrics with different RRD types
        $metric_types = [
            ['name' => 'counter-metric', 'type' => 'COUNTER'],
            ['name' => 'gauge-metric', 'type' => 'GAUGE'],
            ['name' => 'derive-metric', 'type' => 'DERIVE'],
            ['name' => 'absolute-metric', 'type' => 'ABSOLUTE']
        ];

        $metric_ids = [];
        foreach ($metric_types as $metric) {
            $metric_id = gnmi_add_metric_to_subscription($sub_id, $metric['name'], $metric['type']);
            if ($metric_id === false) {
                throw new Exception('Failed to create metric: ' . $metric['name']);
            }
            $metric_ids[] = $metric_id;
        }

        $this->test_subscription_id = $sub_id;
        $this->test_metric_id = $metric_ids[0]; // Track first one for cleanup

        // Create data source
        $data_local_id = gnmi_create_data_sources_for_subscription($sub_id);
        $this->test_data_local_id = $data_local_id;

        // Verify RRD types are mapped correctly
        $rrd_entries = db_fetch_assoc_prepared(
            'SELECT data_source_name, data_source_type_id FROM data_template_rrd WHERE local_data_id = ?',
            array($data_local_id)
        );

        $type_mapping = [
            'COUNTER' => 2,
            'GAUGE' => 1,
            'DERIVE' => 3,
            'ABSOLUTE' => 4
        ];

        foreach ($rrd_entries as $entry) {
            $metric_name = $entry['data_source_name'];
            $type_id = $entry['data_source_type_id'];

            // Find the expected type for this metric
            $expected_type = null;
            foreach ($metric_types as $metric) {
                if (gnmi_sanitize_metric_name($metric['name']) === $metric_name) {
                    $expected_type = $type_mapping[$metric['type']];
                    break;
                }
            }

            if ($expected_type && $type_id != $expected_type) {
                throw new Exception("RRD type mapping incorrect for $metric_name. Expected: $expected_type, Got: $type_id");
            }
        }

        return true;
    }

    /**
     * Test 9: Error handling - invalid device
     */
    public function testErrorHandlingInvalidDevice() {
        // Try to create subscription for non-existent device
        $sub_id = gnmi_create_subscription(999999, 'test/path', 'test-instance');

        if ($sub_id !== false) {
            throw new Exception('Should have failed for non-existent device');
        }

        return true;
    }

    /**
     * Test 10: Error handling - empty subscription
     */
    public function testErrorHandlingEmptySubscription() {
        if (!$this->test_device_id) {
            throw new Exception('Test device not created');
        }

        // Create subscription with no metrics
        $sub_id = gnmi_create_subscription($this->test_device_id, 'test/path', 'test-instance');
        $this->test_subscription_id = $sub_id;

        // Try to create data source - should fail
        $data_local_id = gnmi_create_data_sources_for_subscription($sub_id);

        if ($data_local_id !== false) {
            throw new Exception('Should have failed for subscription with no metrics');
        }

        return true;
    }
}

// Simple test runner
$test = new TestEndToEndIntegration();

echo "Running Phase 3.3 End-to-End Integration Tests...\n";
echo "===============================================\n";

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

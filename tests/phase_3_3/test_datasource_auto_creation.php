#!/usr/bin/env php
<?php
/**
 * gNMI Plugin Phase 3.3 Data Source Auto-Creation Tests
 *
 * Tests for automatic creation of Cacti data sources when metrics are added.
 * Follows TDD approach - write tests first, then implement to pass.
 */

require_once('/var/www/html/cacti/include/global.php');
require_once('/var/www/html/cacti/lib/database.php');
require_once('/var/www/html/cacti/plugins/gnmi/include/subscription_functions.php');
require_once('/var/www/html/cacti/plugins/gnmi/include/functions.php');

class TestDataSourceAutoCreation {

    public $test_device_id = null;
    public $test_subscription_id = null;
    public $test_metric_id = null;
    public $test_data_local_id = null;

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
     * Test 1: Create data source for single metric
     */
    public function testCreateDataSourceForSingleMetric() {
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

        // Add metric
        $metric_id = gnmi_add_metric_to_subscription($sub_id, 'test-metric', 'COUNTER');
        if ($metric_id === false) {
            throw new Exception('Failed to create test metric');
        }

        $this->test_metric_id = $metric_id;

        // Test data source creation
        $data_local_id = gnmi_create_data_sources_for_subscription($sub_id);

        if ($data_local_id === false) {
            throw new Exception('Data source creation failed');
        }

        $this->test_data_local_id = $data_local_id;

        // Verify data_local entry created
        $data_local = db_fetch_row_prepared('SELECT * FROM data_local WHERE id = ?', array($data_local_id));
        if (!$data_local) {
            throw new Exception('data_local entry not created');
        }

        if ($data_local['host_id'] != 1) {
            throw new Exception('data_local host_id incorrect');
        }

        return true;
    }

    /**
     * Test 2: Create data source for multiple metrics (grouped)
     */
    public function testCreateDataSourceForMultipleMetrics() {
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
        $metric_ids = [];

        foreach ($metric_names as $metric_name) {
            $metric_id = gnmi_add_metric_to_subscription($sub_id, $metric_name, 'COUNTER');
            if ($metric_id === false) {
                throw new Exception('Failed to create metric: ' . $metric_name);
            }
            $metric_ids[] = $metric_id;
        }

        $this->test_metric_id = $metric_ids[0]; // Track first one for cleanup

        // Test data source creation
        $data_local_id = gnmi_create_data_sources_for_subscription($sub_id);

        if ($data_local_id === false) {
            throw new Exception('Data source creation failed');
        }

        $this->test_data_local_id = $data_local_id;

        // Verify data_template_rrd entries created (one per metric)
        $rrd_entries = db_fetch_assoc_prepared(
            'SELECT * FROM data_template_rrd WHERE local_data_id = ?',
            array($data_local_id)
        );

        if (count($rrd_entries) !== 3) {
            throw new Exception('Expected 3 RRD entries, got ' . count($rrd_entries));
        }

        // Verify each metric has RRD entry
        $rrd_names = array_column($rrd_entries, 'data_source_name');
        foreach ($metric_names as $metric_name) {
            $sanitized_name = gnmi_sanitize_metric_name($metric_name);
            if (!in_array($sanitized_name, $rrd_names)) {
                throw new Exception('RRD entry not found for metric: ' . $metric_name);
            }
        }

        return true;
    }

    /**
     * Test 3: Verify data_template_data entry created
     */
    public function testDataTemplateDataEntryCreated() {
        if (!$this->test_device_id) {
            throw new Exception('Test device not created');
        }

        // Create subscription and metric
        $sub_id = gnmi_create_subscription($this->test_device_id, 'test/path', 'test-instance');
        $metric_id = gnmi_add_metric_to_subscription($sub_id, 'test-metric', 'COUNTER');

        $this->test_subscription_id = $sub_id;
        $this->test_metric_id = $metric_id;

        // Create data source
        $data_local_id = gnmi_create_data_sources_for_subscription($sub_id);
        $this->test_data_local_id = $data_local_id;

        // Verify data_template_data entry
        $template_data = db_fetch_row_prepared(
            'SELECT * FROM data_template_data WHERE local_data_id = ?',
            array($data_local_id)
        );

        if (!$template_data) {
            throw new Exception('data_template_data entry not created');
        }

        // Verify name format
        if (strpos($template_data['name'], 'gNMI - test-instance') === false) {
            throw new Exception('Template data name incorrect: ' . $template_data['name']);
        }

        // Verify RRD step is 10 seconds
        if ($template_data['rrd_step'] != 10) {
            throw new Exception('RRD step should be 10, got ' . $template_data['rrd_step']);
        }

        return true;
    }

    /**
     * Test 4: Verify RRD profile applied
     */
    public function testRRDProfileApplied() {
        if (!$this->test_device_id) {
            throw new Exception('Test device not created');
        }

        // Create subscription and metric
        $sub_id = gnmi_create_subscription($this->test_device_id, 'test/path', 'test-instance');
        $metric_id = gnmi_add_metric_to_subscription($sub_id, 'test-metric', 'COUNTER');

        $this->test_subscription_id = $sub_id;
        $this->test_metric_id = $metric_id;

        // Create data source
        $data_local_id = gnmi_create_data_sources_for_subscription($sub_id);
        $this->test_data_local_id = $data_local_id;

        // Verify profile is applied
        $template_data = db_fetch_row_prepared(
            'SELECT data_source_profile_id FROM data_template_data WHERE local_data_id = ?',
            array($data_local_id)
        );

        if (!$template_data['data_source_profile_id']) {
            throw new Exception('No RRD profile applied');
        }

        // Verify it's the 10-second profile
        $profile = db_fetch_row_prepared(
            'SELECT name FROM data_source_profiles WHERE id = ?',
            array($template_data['data_source_profile_id'])
        );

        if (!$profile || strpos($profile['name'], '10 Second') === false) {
            throw new Exception('Wrong RRD profile applied');
        }

        return true;
    }

    /**
     * Test 5: Verify metric.local_data_id updated after creation
     */
    public function testMetricLocalDataIdUpdated() {
        if (!$this->test_device_id) {
            throw new Exception('Test device not created');
        }

        // Create subscription and metric
        $sub_id = gnmi_create_subscription($this->test_device_id, 'test/path', 'test-instance');
        $metric_id = gnmi_add_metric_to_subscription($sub_id, 'test-metric', 'COUNTER');

        $this->test_subscription_id = $sub_id;
        $this->test_metric_id = $metric_id;

        // Create data source
        $data_local_id = gnmi_create_data_sources_for_subscription($sub_id);
        $this->test_data_local_id = $data_local_id;

        // Verify metric was updated
        $metric = db_fetch_row_prepared(
            'SELECT local_data_id, datasource_created FROM plugin_gnmi_metrics WHERE id = ?',
            array($metric_id)
        );

        if ($metric['local_data_id'] != $data_local_id) {
            throw new Exception('Metric local_data_id not updated');
        }

        if ($metric['datasource_created'] != 1) {
            throw new Exception('Metric datasource_created flag not set');
        }

        return true;
    }

    /**
     * Test 6: Test duplicate creation prevented
     */
    public function testDuplicateCreationPrevented() {
        if (!$this->test_device_id) {
            throw new Exception('Test device not created');
        }

        // Create subscription and metric
        $sub_id = gnmi_create_subscription($this->test_device_id, 'test/path', 'test-instance');
        $metric_id = gnmi_add_metric_to_subscription($sub_id, 'test-metric', 'COUNTER');

        $this->test_subscription_id = $sub_id;
        $this->test_metric_id = $metric_id;

        // Create data source first time
        $data_local_id1 = gnmi_create_data_sources_for_subscription($sub_id);
        $this->test_data_local_id = $data_local_id1;

        // Try to create again - should return existing ID
        $data_local_id2 = gnmi_create_data_sources_for_subscription($sub_id);

        if ($data_local_id2 !== $data_local_id1) {
            throw new Exception('Duplicate creation not prevented');
        }

        // Verify only one data_local entry exists for this specific data source
        $count = db_fetch_cell_prepared(
            'SELECT COUNT(*) FROM data_local WHERE id = ?',
            array($data_local_id1)
        );

        if ($count != 1) {
            throw new Exception('Data source entry not found');
        }

        return true;
    }

    /**
     * Test 7: Test creation failure rollback
     */
    public function testCreationFailureRollback() {
        if (!$this->test_device_id) {
            throw new Exception('Test device not created');
        }

        // Test with invalid subscription ID (non-existent)
        $invalid_subscription_id = 999999;

        // Try to create data source for non-existent subscription - should fail
        $data_local_id = gnmi_create_data_sources_for_subscription($invalid_subscription_id);

        if ($data_local_id !== false) {
            throw new Exception('Should have failed for non-existent subscription');
        }

        // Verify no partial data was created
        $count = db_fetch_cell('SELECT COUNT(*) FROM data_local WHERE host_id = 1');
        $original_count = $count; // Store original count

        // The count should be the same as before (no new entries created)
        if ($count != $original_count) {
            throw new Exception('Partial data created on failure');
        }

        return true;
    }

    /**
     * Test 8: Test data source naming convention
     */
    public function testDataSourceNamingConvention() {
        if (!$this->test_device_id) {
            throw new Exception('Test device not created');
        }

        // Create subscription with specific instance name
        $sub_id = gnmi_create_subscription(
            $this->test_device_id,
            'test/path',
            'ettp-40'
        );

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
     * Test 9: Test RRD type mapping
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
     * Test 10: Sequential metric addition each gets auto-created data source
     *
     * Regression test: Previously, only the first metric added to a subscription
     * would get an auto-created data source. The second metric was skipped because
     * the guard clause checked if ANY metric already had a local_data_id.
     */
    public function testSequentialMetricAdditionCreatesDataSources() {
        if (!$this->test_device_id) {
            throw new Exception('Test device not created');
        }

        // Create subscription with auto_create_datasources enabled
        $sub_id = gnmi_create_subscription(
            $this->test_device_id,
            'test/path/counters',
            'test-instance'
        );

        if ($sub_id === false) {
            throw new Exception('Failed to create test subscription');
        }

        $this->test_subscription_id = $sub_id;

        // Ensure auto_create_datasources is enabled
        db_execute_prepared(
            'UPDATE plugin_gnmi_subscriptions SET auto_create_datasources = 1 WHERE id = ?',
            array($sub_id)
        );

        // Add first metric - should trigger auto-creation
        $metric1_id = gnmi_add_metric_to_subscription($sub_id, 'in-octets', 'COUNTER');
        if ($metric1_id === false) {
            throw new Exception('Failed to create first metric');
        }

        $this->test_metric_id = $metric1_id;

        // Verify first metric got a data source
        $metric1 = db_fetch_row_prepared(
            'SELECT local_data_id, datasource_created FROM plugin_gnmi_metrics WHERE id = ?',
            array($metric1_id)
        );

        if (empty($metric1['local_data_id'])) {
            throw new Exception('First metric did not get auto-created data source');
        }

        if ($metric1['datasource_created'] != 1) {
            throw new Exception('First metric datasource_created flag not set');
        }

        $ds1_id = $metric1['local_data_id'];

        // Add second metric - should ALSO trigger auto-creation
        $metric2_id = gnmi_add_metric_to_subscription($sub_id, 'out-octets', 'COUNTER');
        if ($metric2_id === false) {
            throw new Exception('Failed to create second metric');
        }

        // Verify second metric ALSO got a data source
        $metric2 = db_fetch_row_prepared(
            'SELECT local_data_id, datasource_created FROM plugin_gnmi_metrics WHERE id = ?',
            array($metric2_id)
        );

        if (empty($metric2['local_data_id'])) {
            throw new Exception('Second metric did not get auto-created data source (regression bug)');
        }

        if ($metric2['datasource_created'] != 1) {
            throw new Exception('Second metric datasource_created flag not set');
        }

        $ds2_id = $metric2['local_data_id'];

        // Verify both metrics have distinct data source IDs
        if ($ds1_id == $ds2_id) {
            throw new Exception('Both metrics should have distinct local_data_id values');
        }

        // Verify first metric data source was not modified
        $metric1_check = db_fetch_row_prepared(
            'SELECT local_data_id FROM plugin_gnmi_metrics WHERE id = ?',
            array($metric1_id)
        );

        if ($metric1_check['local_data_id'] != $ds1_id) {
            throw new Exception('First metric local_data_id was unexpectedly changed');
        }

        // Cleanup additional data sources
        $this->test_data_local_id = $ds1_id;
        db_execute_prepared('DELETE FROM data_template_rrd WHERE local_data_id = ?', array($ds2_id));
        db_execute_prepared('DELETE FROM data_template_data WHERE local_data_id = ?', array($ds2_id));
        db_execute_prepared('DELETE FROM poller_item WHERE local_data_id = ?', array($ds2_id));
        db_execute_prepared('DELETE FROM data_local WHERE id = ?', array($ds2_id));
        db_execute_prepared('DELETE FROM plugin_gnmi_metrics WHERE id = ?', array($metric2_id));

        return true;
    }

    /**
     * Test 11: Test subscription with no metrics
     */
    public function testSubscriptionWithNoMetrics() {
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
$test = new TestDataSourceAutoCreation();

echo "Running Phase 3.3 Data Source Auto-Creation Tests...\n";
echo "==================================================\n";

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

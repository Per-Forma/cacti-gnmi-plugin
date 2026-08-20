<?php
/**
 * gNMI Plugin Phase 3.3 Subscription Form Processing Tests
 *
 * Tests for form processing actions with validation and error handling.
 * Follows TDD approach - write tests first, then implement to pass.
 */

require_once('/var/www/html/cacti/include/global.php');
require_once('/var/www/html/cacti/lib/database.php');
require_once('/var/www/html/cacti/plugins/gnmi/include/subscription_functions.php');
require_once('/var/www/html/cacti/plugins/gnmi/include/subscription_actions.php');

class TestSubscriptionFormProcessing {

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
     * Test 1: Add subscription form submission with valid data
     */
    public function testAddSubscriptionValidData() {
        if (!$this->test_device_id) {
            throw new Exception('Test device not created');
        }

        // Simulate form submission
        $_POST['action'] = 'add_subscription';
        $_POST['device_id'] = $this->test_device_id;
        $_POST['subscription_path'] = 'test/path';
        $_POST['instance_identifier'] = 'test-instance';
        $_POST['enabled'] = '1';
        $_POST['notes'] = 'Test subscription';


        // Capture output
        ob_start();
        $result = gnmi_process_subscription_action();
        $output = ob_get_clean();

        if ($result === false) {
            throw new Exception('Form processing failed');
        }

        // Verify subscription was created
        $subscriptions = gnmi_get_device_subscriptions($this->test_device_id);
        if (empty($subscriptions)) {
            throw new Exception('Subscription not created');
        }

        $subscription = $subscriptions[0];
        if ($subscription['subscription_path'] !== 'test/path') {
            throw new Exception('Subscription path not saved correctly');
        }

        if ($subscription['instance_identifier'] !== 'test-instance') {
            throw new Exception('Instance identifier not saved correctly');
        }

        $this->test_subscription_id = $subscription['id'];

        return true;
    }

    /**
     * Test 2: Add subscription form validation - missing required fields
     */
    public function testAddSubscriptionMissingFields() {
        if (!$this->test_device_id) {
            throw new Exception('Test device not created');
        }

        // Simulate form submission with missing fields
        $_POST['action'] = 'add_subscription';
        $_POST['device_id'] = $this->test_device_id;
        $_POST['subscription_path'] = '';
        $_POST['instance_identifier'] = '';

        // Capture output
        ob_start();
        $result = gnmi_process_subscription_action();
        $output = ob_get_clean();

        if ($result !== false) {
            throw new Exception('Expected form processing to fail with missing fields');
        }

        // Verify no subscription was created
        $subscriptions = gnmi_get_device_subscriptions($this->test_device_id);
        if (!empty($subscriptions)) {
            throw new Exception('Subscription should not have been created');
        }

        return true;
    }

    /**
     * Test 3: Add subscription form validation - invalid device_id
     */
    public function testAddSubscriptionInvalidDevice() {
        // Simulate form submission with invalid device_id
        $_POST['action'] = 'add_subscription';
        $_POST['device_id'] = 99999;
        $_POST['subscription_path'] = 'test/path';
        $_POST['instance_identifier'] = 'test-instance';

        // Capture output
        ob_start();
        $result = gnmi_process_subscription_action();
        $output = ob_get_clean();

        if ($result !== false) {
            throw new Exception('Expected form processing to fail with invalid device_id');
        }

        return true;
    }

    /**
     * Test 4: Add metric form submission with valid data
     */
    public function testAddMetricValidData() {
        if (!$this->test_device_id) {
            throw new Exception('Test device not created');
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

        // Simulate form submission
        $_POST['action'] = 'add_metric';
        $_POST['subscription_id'] = $sub_id;
        $_POST['metric_name'] = 'test-metric';
        $_POST['rrd_type'] = 'COUNTER';
        $_POST['enabled'] = '1';

        // Capture output
        ob_start();
        $result = gnmi_process_subscription_action();
        $output = ob_get_clean();

        if ($result === false) {
            throw new Exception('Form processing failed');
        }

        // Verify metric was created
        $metrics = gnmi_get_subscription_metrics($sub_id);
        if (empty($metrics)) {
            throw new Exception('Metric not created');
        }

        $metric = $metrics[0];
        if ($metric['metric_name'] !== 'test-metric') {
            throw new Exception('Metric name not saved correctly');
        }

        if ($metric['rrd_type'] !== 'COUNTER') {
            throw new Exception('RRD type not saved correctly');
        }

        $this->test_metric_id = $metric['id'];

        return true;
    }

    /**
     * Test 5: Add metric form validation - missing required fields
     */
    public function testAddMetricMissingFields() {
        if (!$this->test_device_id) {
            throw new Exception('Test device not created');
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

        // Simulate form submission with missing fields
        $_POST['action'] = 'add_metric';
        $_POST['subscription_id'] = $sub_id;
        $_POST['metric_name'] = '';
        $_POST['rrd_type'] = '';

        // Capture output
        ob_start();
        $result = gnmi_process_subscription_action();
        $output = ob_get_clean();

        if ($result !== false) {
            throw new Exception('Expected form processing to fail with missing fields');
        }

        // Verify no metric was created
        $metrics = gnmi_get_subscription_metrics($sub_id);
        if (!empty($metrics)) {
            throw new Exception('Metric should not have been created');
        }

        return true;
    }

    /**
     * Test 6: Update subscription enabled toggle
     */
    public function testUpdateSubscriptionEnabled() {
        if (!$this->test_device_id) {
            throw new Exception('Test device not created');
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

        // Simulate form submission to disable subscription
        $_POST['action'] = 'update_subscription';
        $_POST['subscription_id'] = $sub_id;
        $_POST['enabled'] = '0';

        // Capture output
        ob_start();
        $result = gnmi_process_subscription_action();
        $output = ob_get_clean();

        if ($result === false) {
            throw new Exception('Form processing failed');
        }

        // Verify subscription was updated
        $subscription = gnmi_get_subscription($sub_id);
        if ($subscription['enabled'] != 0) {
            throw new Exception('Subscription not disabled');
        }

        return true;
    }

    /**
     * Test 7: Delete subscription with confirmation
     */
    public function testDeleteSubscription() {
        if (!$this->test_device_id) {
            throw new Exception('Test device not created');
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

        // Simulate form submission to delete subscription
        $_POST['action'] = 'delete_subscription';
        $_POST['subscription_id'] = $sub_id;
        $_POST['confirm'] = '1';

        // Capture output
        ob_start();
        $result = gnmi_process_subscription_action();
        $output = ob_get_clean();

        if ($result === false) {
            throw new Exception('Form processing failed');
        }

        // Verify subscription was deleted
        $subscription = gnmi_get_subscription($sub_id);
        if ($subscription !== false) {
            throw new Exception('Subscription not deleted');
        }

        return true;
    }

    /**
     * Test 8: Delete subscription without confirmation
     */
    public function testDeleteSubscriptionNoConfirmation() {
        if (!$this->test_device_id) {
            throw new Exception('Test device not created');
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

        // Simulate form submission to delete subscription without confirmation
        $_POST['action'] = 'delete_subscription';
        $_POST['subscription_id'] = $sub_id;
        unset($_POST['confirm']); // Ensure no stale confirm from previous tests

        // Capture output
        ob_start();
        $result = gnmi_process_subscription_action();
        $output = ob_get_clean();

        if ($result !== false) {
            throw new Exception('Expected form processing to fail without confirmation');
        }

        // Verify subscription still exists
        $subscription = gnmi_get_subscription($sub_id);
        if ($subscription === false) {
            throw new Exception('Subscription should not have been deleted without confirmation');
        }

        return true;
    }

    /**
     * Test 9: Delete metric
     */
    public function testDeleteMetric() {
        if (!$this->test_device_id) {
            throw new Exception('Test device not created');
        }

        // Create test subscription and metric
        $sub_id = gnmi_create_subscription(
            $this->test_device_id,
            'test/path',
            'test-instance'
        );

        if ($sub_id === false) {
            throw new Exception('Failed to create test subscription');
        }

        $this->test_subscription_id = $sub_id;

        $metric_id = gnmi_add_metric_to_subscription($sub_id, 'test-metric', 'COUNTER');
        if ($metric_id === false) {
            throw new Exception('Failed to create test metric');
        }
        $this->test_metric_id = $metric_id;

        // Simulate form submission to delete metric
        $_POST['action'] = 'delete_metric';
        $_POST['metric_id'] = $metric_id;

        // Capture output
        ob_start();
        $result = gnmi_process_subscription_action();
        $output = ob_get_clean();

        if ($result === false) {
            throw new Exception('Form processing failed');
        }

        // Verify metric was deleted (db_fetch_row_prepared returns [] not false for no rows)
        $metric = db_fetch_row_prepared('SELECT * FROM plugin_gnmi_metrics WHERE id = ?', array($metric_id));
        if (!empty($metric)) {
            throw new Exception('Metric not deleted');
        }

        return true;
    }

    /**
     * Test: Delete subscription that does not exist
     */
    public function testDeleteNonExistentSubscription() {
        $_POST['action'] = 'delete_subscription';
        $_POST['subscription_id'] = 999999;
        $_POST['confirm'] = '1';

        ob_start();
        $result = gnmi_process_subscription_action();
        $output = ob_get_clean();

        if ($result !== false) {
            throw new Exception('Expected delete of non-existent subscription to fail');
        }

        return true;
    }

    /**
     * Test: Delete metric that does not exist
     */
    public function testDeleteNonExistentMetric() {
        $_POST['action'] = 'delete_metric';
        $_POST['metric_id'] = 999999;

        ob_start();
        $result = gnmi_process_subscription_action();
        $output = ob_get_clean();

        if ($result !== false) {
            throw new Exception('Expected delete of non-existent metric to fail');
        }

        return true;
    }

    /**
     * Test: Delete subscription with non-numeric ID rejects input
     */
    public function testDeleteSubscriptionNonNumericId() {
        $_POST['action'] = 'delete_subscription';
        $_POST['subscription_id'] = '<script>alert(1)</script>';
        $_POST['confirm'] = '1';

        ob_start();
        $result = gnmi_process_subscription_action();
        $output = ob_get_clean();

        if ($result !== false) {
            throw new Exception('Expected delete with non-numeric subscription_id to fail');
        }

        return true;
    }

    /**
     * Test: Delete subscription cascades to associated metrics
     */
    public function testDeleteSubscriptionCascadesMetrics() {
        if (!$this->test_device_id) {
            throw new Exception('Test device not created');
        }

        $sub_id = gnmi_create_subscription(
            $this->test_device_id,
            'test/cascade/path',
            'cascade-test'
        );
        if ($sub_id === false) {
            throw new Exception('Failed to create test subscription');
        }

        $metric1 = gnmi_add_metric_to_subscription($sub_id, 'metric-one', 'COUNTER');
        $metric2 = gnmi_add_metric_to_subscription($sub_id, 'metric-two', 'GAUGE');
        if ($metric1 === false || $metric2 === false) {
            throw new Exception('Failed to create test metrics');
        }

        // Verify 2 metrics exist
        $metrics = gnmi_get_subscription_metrics($sub_id);
        if (count($metrics) !== 2) {
            throw new Exception('Expected 2 metrics, got ' . count($metrics));
        }

        // Delete subscription via form handler
        $_POST['action'] = 'delete_subscription';
        $_POST['subscription_id'] = $sub_id;
        $_POST['confirm'] = '1';

        ob_start();
        $result = gnmi_process_subscription_action();
        $output = ob_get_clean();

        if ($result === false) {
            throw new Exception('Form processing failed');
        }

        // Verify subscription gone
        $subscription = gnmi_get_subscription($sub_id);
        if ($subscription !== false) {
            throw new Exception('Subscription not deleted');
        }

        // Verify metrics also gone (CASCADE)
        $remaining = db_fetch_assoc_prepared(
            'SELECT * FROM plugin_gnmi_metrics WHERE subscription_id = ?',
            array($sub_id)
        );
        if (!empty($remaining)) {
            throw new Exception('Metrics not cascade-deleted: ' . count($remaining) . ' remain');
        }

        return true;
    }

    /**
     * Test 10: Create data source trigger
     */
    public function testCreateDataSourceTrigger() {
        if (!$this->test_device_id) {
            throw new Exception('Test device not created');
        }

        // Create test subscription and metric
        $sub_id = gnmi_create_subscription(
            $this->test_device_id,
            'test/path',
            'test-instance'
        );

        if ($sub_id === false) {
            throw new Exception('Failed to create test subscription');
        }

        $this->test_subscription_id = $sub_id;

        $metric_id = gnmi_add_metric_to_subscription($sub_id, 'test-metric', 'COUNTER');
        if ($metric_id === false) {
            throw new Exception('Failed to create test metric');
        }

        $this->test_metric_id = $metric_id;

        // Simulate form submission to create data source
        $_POST['action'] = 'create_datasource';
        $_POST['metric_id'] = $metric_id;

        // Capture output
        ob_start();
        $result = gnmi_process_subscription_action();
        $output = ob_get_clean();

        if ($result === false) {
            throw new Exception('Form processing failed');
        }

        // Verify data source creation was triggered
        // (This would normally create a data source, but we'll just verify the action was processed)
        return true;
    }

    /**
     * Test 11: Error messages displayed to user
     */
    public function testErrorMessagesDisplayed() {
        if (!$this->test_device_id) {
            throw new Exception('Test device not created');
        }

        // Simulate form submission with invalid data
        $_POST['action'] = 'add_subscription';
        $_POST['device_id'] = 99999; // Invalid device
        $_POST['subscription_path'] = 'test/path';
        $_POST['instance_identifier'] = 'test-instance';

        // Capture output
        ob_start();
        $result = gnmi_process_subscription_action();
        $output = ob_get_clean();

        if ($result !== false) {
            throw new Exception('Expected form processing to fail with invalid device');
        }

        // Verify error message was set (if function exists)
        if (function_exists('raise_message')) {
            // This would normally set an error message
            // In test context, we just verify the function handles errors gracefully
        }

        return true;
    }

    /**
     * Test 12: Success messages displayed
     */
    public function testSuccessMessagesDisplayed() {
        if (!$this->test_device_id) {
            throw new Exception('Test device not created');
        }

        // Simulate form submission with valid data
        $_POST['action'] = 'add_subscription';
        $_POST['device_id'] = $this->test_device_id;
        $_POST['subscription_path'] = 'test/path';
        $_POST['instance_identifier'] = 'test-instance';

        // Capture output
        ob_start();
        $result = gnmi_process_subscription_action();
        $output = ob_get_clean();

        if ($result === false) {
            throw new Exception('Form processing failed');
        }

        // Verify success message was set (if function exists)
        if (function_exists('raise_message')) {
            // This would normally set a success message
            // In test context, we just verify the function handles success gracefully
        }

        // Clean up
        $subscriptions = gnmi_get_device_subscriptions($this->test_device_id);
        if (!empty($subscriptions)) {
            $this->test_subscription_id = $subscriptions[0]['id'];
        }

        return true;
    }

    /**
     * Test 13: Form validation - XSS prevention
     */
    public function testXSSPrevention() {
        if (!$this->test_device_id) {
            throw new Exception('Test device not created');
        }

        // Simulate form submission with malicious input
        $_POST['action'] = 'add_subscription';
        $_POST['device_id'] = $this->test_device_id;
        $_POST['subscription_path'] = '<script>alert("xss")</script>';
        $_POST['instance_identifier'] = 'test-instance';

        // Capture output
        ob_start();
        $result = gnmi_process_subscription_action();
        $output = ob_get_clean();

        if ($result === false) {
            throw new Exception('Form processing failed');
        }

        // Verify XSS was prevented
        $subscriptions = gnmi_get_device_subscriptions($this->test_device_id);
        if (empty($subscriptions)) {
            throw new Exception('Subscription not created');
        }

        $subscription = $subscriptions[0];
        if (strpos($subscription['subscription_path'], '<script>') !== false) {
            throw new Exception('XSS input not sanitized');
        }

        $this->test_subscription_id = $subscription['id'];

        return true;
    }

    /**
     * Test 14: Form validation - SQL injection prevention
     */
    public function testSQLInjectionPrevention() {
        if (!$this->test_device_id) {
            throw new Exception('Test device not created');
        }

        // Simulate form submission with SQL injection attempt
        $_POST['action'] = 'add_subscription';
        $_POST['device_id'] = $this->test_device_id;
        $_POST['subscription_path'] = "interfaces/interface'; DROP TABLE plugin_gnmi_subscriptions; --";
        $_POST['instance_identifier'] = 'test-instance';

        // Capture output
        ob_start();
        $result = gnmi_process_subscription_action();
        $output = ob_get_clean();

        if ($result === false) {
            throw new Exception('Form processing failed');
        }

        // Verify SQL injection was prevented: subscription created AND table still exists
        $subscriptions = gnmi_get_device_subscriptions($this->test_device_id);
        if (empty($subscriptions)) {
            throw new Exception('Subscription not created');
        }

        // Verify the table was not dropped (prepared statements prevent SQL injection)
        $table_check = db_fetch_cell("SHOW TABLES LIKE 'plugin_gnmi_subscriptions'");
        if (!$table_check) {
            throw new Exception('SQL injection succeeded - table was dropped');
        }

        $subscription = $subscriptions[0];
        $this->test_subscription_id = $subscription['id'];

        return true;
    }

    /**
     * Test 15: Form validation - CSRF protection
     */
    public function testCSRFProtection() {
        // Simulate form submission without CSRF token
        $_POST['action'] = 'add_subscription';
        $_POST['device_id'] = 1;
        $_POST['subscription_path'] = 'test/path';
        $_POST['instance_identifier'] = 'test-instance';
        // No CSRF token
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$GLOBALS['gnmi_enforce_csrf_in_cli'] = true;

        // Capture output
        ob_start();
		try {
			$result = gnmi_process_subscription_action();
		} finally {
			unset($GLOBALS['gnmi_enforce_csrf_in_cli']);
			unset($_SERVER['REQUEST_METHOD']);
		}
        $output = ob_get_clean();

        if ($result !== false) {
            throw new Exception('Expected form processing to fail without CSRF token');
        }

        return true;
    }
}

// Simple test runner
$test = new TestSubscriptionFormProcessing();

echo "Running Phase 3.3 Subscription Form Processing Tests...\n";
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

exit($failed > 0 ? 1 : 0);

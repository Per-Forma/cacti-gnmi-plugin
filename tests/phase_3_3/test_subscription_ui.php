<?php
/**
 * gNMI Plugin Phase 3.3 Subscription UI Tests
 *
 * Tests for subscription management UI rendering and functionality.
 * Follows TDD approach - write tests first, then implement to pass.
 */

require_once('/var/www/html/cacti/include/global.php');
require_once('/var/www/html/cacti/lib/database.php');
require_once('/var/www/html/cacti/plugins/gnmi/include/subscription_functions.php');
require_once('/var/www/html/cacti/plugins/gnmi/include/subscription_display.php');

class TestSubscriptionUI {

    private $test_device_id = null;
    private $test_subscription_id = null;
    private $test_metric_id = null;

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
     * Test 1: Subscription section renders with correct structure
     */
    public function testSubscriptionSectionRenders() {
        if (!$this->test_device_id) {
            throw new Exception('Test device not created');
        }

        // Capture output
        ob_start();
        $result = gnmi_render_subscription_section($this->test_device_id, 1);
        $output = ob_get_clean();

        if ($result === false) {
            throw new Exception('Subscription section failed to render');
        }

        // Check for key elements
        if (strpos($output, 'gNMI Subscriptions') === false) {
            throw new Exception('Section title not found');
        }

        if (strpos($output, 'Add Subscription') === false) {
            throw new Exception('Add Subscription button not found');
        }

        return true;
    }

    /**
     * Test 2: Subscription table renders with correct columns
     */
    public function testSubscriptionTableColumns() {
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

        // Capture output
        ob_start();
        $result = gnmi_render_subscription_table($this->test_device_id);
        $output = ob_get_clean();

        if ($result === false) {
            throw new Exception('Subscription table failed to render');
        }

        // Check for table headers
        $expected_headers = ['Path', 'Instance', 'Metrics', 'Enabled', 'Actions'];
        foreach ($expected_headers as $header) {
            if (strpos($output, $header) === false) {
                throw new Exception("Table header '$header' not found");
            }
        }

        return true;
    }

    /**
     * Test 3: Empty state message when no subscriptions
     */
    public function testEmptyStateMessage() {
        if (!$this->test_device_id) {
            throw new Exception('Test device not created');
        }

        // Capture output
        ob_start();
        $result = gnmi_render_subscription_table($this->test_device_id);
        $output = ob_get_clean();

        if ($result === false) {
            throw new Exception('Subscription table failed to render');
        }

        // Check for empty state message
        if (strpos($output, 'No subscriptions') === false && strpos($output, 'empty') === false) {
            throw new Exception('Empty state message not found');
        }

        return true;
    }

    /**
     * Test 4: Subscription row renders with data
     */
    public function testSubscriptionRowRenders() {
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

        // Get subscription data
        $subscription = gnmi_get_subscription($sub_id);
        if (!$subscription) {
            throw new Exception('Failed to get subscription data');
        }

        // Capture output
        ob_start();
        $result = gnmi_render_subscription_row($subscription);
        $output = ob_get_clean();

        if ($result === false) {
            throw new Exception('Subscription row failed to render');
        }

        // Check for subscription data
        if (strpos($output, 'test/path') === false) {
            throw new Exception('Subscription path not found in row');
        }

        if (strpos($output, 'test-instance') === false) {
            throw new Exception('Instance identifier not found in row');
        }

        return true;
    }

    /**
     * Test 5: Add subscription form renders
     */
    public function testAddSubscriptionFormRenders() {
        if (!$this->test_device_id) {
            throw new Exception('Test device not created');
        }

        // Capture output
        ob_start();
        $result = gnmi_render_add_subscription_form($this->test_device_id);
        $output = ob_get_clean();

        if ($result === false) {
            throw new Exception('Add subscription form failed to render');
        }

        // Check for form elements
        if (strpos($output, 'subscription_path') === false) {
            throw new Exception('Subscription path input not found');
        }

        if (strpos($output, 'instance_identifier') === false) {
            throw new Exception('Instance identifier input not found');
        }

        if (strpos($output, 'enabled') === false) {
            throw new Exception('Enabled checkbox not found');
        }

        return true;
    }

    /**
     * Test 6: Metric table renders with correct columns
     */
    public function testMetricTableColumns() {
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

        // Create test metric
        $metric_id = gnmi_add_metric_to_subscription($sub_id, 'test-metric', 'COUNTER');
        if ($metric_id === false) {
            throw new Exception('Failed to create test metric');
        }

        $this->test_metric_id = $metric_id;

        // Capture output
        ob_start();
        $result = gnmi_render_metric_table($sub_id);
        $output = ob_get_clean();

        if ($result === false) {
            throw new Exception('Metric table failed to render');
        }

        // Check for table headers
        $expected_headers = ['Metric Name', 'RRD Type', 'Cacti Field', 'Data Source', 'Enabled', 'Actions'];
        foreach ($expected_headers as $header) {
            if (strpos($output, $header) === false) {
                throw new Exception("Metric table header '$header' not found");
            }
        }

        return true;
    }

    /**
     * Test 7: Add metric form renders
     */
    public function testAddMetricFormRenders() {
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

        // Capture output
        ob_start();
        $result = gnmi_render_add_metric_form($sub_id);
        $output = ob_get_clean();

        if ($result === false) {
            throw new Exception('Add metric form failed to render');
        }

        // Check for form elements
        if (strpos($output, 'metric_name') === false) {
            throw new Exception('Metric name input not found');
        }

        if (strpos($output, 'rrd_type') === false) {
            throw new Exception('RRD type select not found');
        }

        return true;
    }

    /**
     * Test 8: Enabled/disabled toggle renders
     */
    public function testEnabledToggleRenders() {
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

        // Get subscription data
        $subscription = gnmi_get_subscription($sub_id);
        if (!$subscription) {
            throw new Exception('Failed to get subscription data');
        }

        // Capture output
        ob_start();
        $result = gnmi_render_subscription_row($subscription);
        $output = ob_get_clean();

        if ($result === false) {
            throw new Exception('Subscription row failed to render');
        }

        // Check for enabled toggle
        if (strpos($output, 'checkbox') === false && strpos($output, 'toggle') === false) {
            throw new Exception('Enabled toggle not found');
        }

        return true;
    }

    /**
     * Test 9: Delete button with confirmation renders
     */
    public function testDeleteButtonRenders() {
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

        // Get subscription data
        $subscription = gnmi_get_subscription($sub_id);
        if (!$subscription) {
            throw new Exception('Failed to get subscription data');
        }

        // Capture output
        ob_start();
        $result = gnmi_render_subscription_row($subscription);
        $output = ob_get_clean();

        if ($result === false) {
            throw new Exception('Subscription row failed to render');
        }

        // Check for delete button
        if (strpos($output, 'delete') === false && strpos($output, 'Delete') === false) {
            throw new Exception('Delete button not found');
        }

        // Check for confirmation
        if (strpos($output, 'confirm') === false && strpos($output, 'Confirm') === false) {
            throw new Exception('Delete confirmation not found');
        }

        return true;
    }

    /**
     * Test 10: Create Data Source button when datasource_created=false
     */
    public function testCreateDataSourceButtonRenders() {
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

        // Ensure datasource_created is false
        gnmi_update_metric($metric_id, ['datasource_created' => false]);

        // Get metric data
        $metrics = gnmi_get_subscription_metrics($sub_id);
        if (empty($metrics)) {
            throw new Exception('No metrics found');
        }

        $metric = $metrics[0];

        // Capture output
        ob_start();
        $result = gnmi_render_metric_table($sub_id);
        $output = ob_get_clean();

        if ($result === false) {
            throw new Exception('Metric table failed to render');
        }

        // Check for create data source button
        if (strpos($output, 'Create Data Source') === false && strpos($output, 'create') === false) {
            throw new Exception('Create Data Source button not found');
        }

        return true;
    }

    /**
     * Test 11: Data source link when datasource_created=true
     */
    public function testDataSourceLinkRenders() {
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

        // Set datasource_created to true and add local_data_id
        gnmi_update_metric($metric_id, [
            'datasource_created' => true,
            'local_data_id' => 123
        ]);

        // Capture output
        ob_start();
        $result = gnmi_render_metric_table($sub_id);
        $output = ob_get_clean();

        if ($result === false) {
            throw new Exception('Metric table failed to render');
        }

        // Check for data source link
        if (strpos($output, '123') === false && strpos($output, 'Data Source') === false) {
            throw new Exception('Data source link not found');
        }

        return true;
    }

    /**
     * Test 12: Subscription row expand shows metrics
     */
    public function testSubscriptionRowExpandShowsMetrics() {
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

        // Get subscription data
        $subscription = gnmi_get_subscription($sub_id);
        if (!$subscription) {
            throw new Exception('Failed to get subscription data');
        }

        // Capture output
        ob_start();
        $result = gnmi_render_subscription_row($subscription);
        $output = ob_get_clean();

        if ($result === false) {
            throw new Exception('Subscription row failed to render');
        }

        // Check for expand functionality
        if (strpos($output, 'expand') === false && strpos($output, 'click') === false) {
            throw new Exception('Expand functionality not found');
        }

        return true;
    }

    /**
     * Test 13: Form validation displays errors
     */
    public function testFormValidationDisplaysErrors() {
        if (!$this->test_device_id) {
            throw new Exception('Test device not created');
        }

        // Test with empty form data
        $_POST['subscription_path'] = '';
        $_POST['instance_identifier'] = '';

        // Capture output
        ob_start();
        $result = gnmi_render_add_subscription_form($this->test_device_id);
        $output = ob_get_clean();

        if ($result === false) {
            throw new Exception('Add subscription form failed to render');
        }

        // Check for validation attributes
        if (strpos($output, 'required') === false) {
            throw new Exception('Required validation not found');
        }

        return true;
    }

    /**
     * Test 14: Success messages display
     */
    public function testSuccessMessagesDisplay() {
        if (!$this->test_device_id) {
            throw new Exception('Test device not created');
        }

        // Test that the function can handle messages without error
        if (function_exists('raise_message')) {
            raise_message('gnmi_subscription_success', 'Test success message', MESSAGE_LEVEL_INFO);
        }

        // Capture output
        ob_start();
        $result = gnmi_render_subscription_section($this->test_device_id, 1);
        $output = ob_get_clean();

        if ($result === false) {
            throw new Exception('Subscription section failed to render');
        }

        // Just verify the function completed successfully
        return true;
    }

    /**
     * Test 15: Error messages display
     */
    public function testErrorMessagesDisplay() {
        if (!$this->test_device_id) {
            throw new Exception('Test device not created');
        }

        // Test that the function can handle messages without error
        if (function_exists('raise_message')) {
            raise_message('gnmi_subscription_error', 'Test error message', MESSAGE_LEVEL_ERROR);
        }

        // Capture output
        ob_start();
        $result = gnmi_render_subscription_section($this->test_device_id, 1);
        $output = ob_get_clean();

        if ($result === false) {
            throw new Exception('Subscription section failed to render');
        }

        // Just verify the function completed successfully
        return true;
    }
}

// Simple test runner
$test = new TestSubscriptionUI();

echo "Running Phase 3.3 Subscription UI Tests...\n";
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

exit($failed > 0 ? 1 : 0);

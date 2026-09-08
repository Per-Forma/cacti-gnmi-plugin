#!/usr/bin/env php
<?php
/**
 * gNMI Plugin Phase 3.5 - UI Stubs Implementation Tests
 *
 * Tests for the three previously-stubbed UI actions:
 *   1. Edit Subscription (backend was ready; frontend now wired)
 *   2. Edit Metric (backend added in this phase; frontend now wired)
 *   3. Restart Daemon (new AJAX route added in this phase)
 *
 * Run inside Docker: php plugins/gnmi/tests/phase_3_5/test_ui_stubs.php
 */

require_once('/var/www/html/cacti/include/global.php');
require_once('/var/www/html/cacti/lib/database.php');
require_once('/var/www/html/cacti/plugins/gnmi/include/subscription_functions.php');
require_once('/var/www/html/cacti/plugins/gnmi/include/subscription_actions.php');
require_once('/var/www/html/cacti/plugins/gnmi/include/subscription_display.php');
require_once('/var/www/html/cacti/plugins/gnmi/include/functions.php');

class TestUIStubs {

    private $test_device_id   = null;
    private $test_sub_id      = null;
    private $test_metric_id   = null;

    // -------------------------------------------------------------------------
    // Test lifecycle
    // -------------------------------------------------------------------------

    public function setUp() {
        // Clean up any stale test data from previous runs
        db_execute("DELETE FROM plugin_gnmi_devices WHERE hostname = 'ui-stub-test.example.com'");

        // Insert a test device (host_id=1 is fine — it just needs to exist in plugin table)
        $ok = db_execute("
            INSERT INTO plugin_gnmi_devices
                (host_id, enabled, hostname, port, username, password, use_tls, collection_interval, encoding)
            VALUES
                (1, 1, 'ui-stub-test.example.com', 9339, 'testuser', 'testpass', 0, 10, 'JSON_IETF')
        ");
        if (!$ok) {
            throw new Exception('setUp: could not insert test device');
        }
        $this->test_device_id = (int)db_fetch_cell('SELECT LAST_INSERT_ID()');

        // Insert a test subscription
        $ok = db_execute_prepared(
            "INSERT INTO plugin_gnmi_subscriptions
                 (device_id, subscription_path, instance_identifier, enabled, notes)
             VALUES (?, ?, ?, ?, ?)",
            [$this->test_device_id, 'original/test/path', 'original-instance', 1, 'original notes']
        );
        if (!$ok) {
            throw new Exception('setUp: could not insert test subscription');
        }
        $this->test_sub_id = (int)db_fetch_cell('SELECT LAST_INSERT_ID()');

        // Insert a test metric for that subscription
        $ok = db_execute_prepared(
            "INSERT INTO plugin_gnmi_metrics
                 (subscription_id, metric_name, cacti_field_name, rrd_type, rrd_heartbeat, rrd_min, rrd_max, enabled)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
            [$this->test_sub_id, 'in-octets', 'in_octets', 'COUNTER', 600, '0', 'U', 1]
        );
        if (!$ok) {
            throw new Exception('setUp: could not insert test metric');
        }
        $this->test_metric_id = (int)db_fetch_cell('SELECT LAST_INSERT_ID()');

        // Reset POST
        $_POST = [];
    }

    public function tearDown() {
        if ($this->test_metric_id) {
            db_execute_prepared('DELETE FROM plugin_gnmi_metrics WHERE id = ?', [$this->test_metric_id]);
            $this->test_metric_id = null;
        }
        if ($this->test_sub_id) {
            db_execute_prepared('DELETE FROM plugin_gnmi_subscriptions WHERE id = ?', [$this->test_sub_id]);
            $this->test_sub_id = null;
        }
        if ($this->test_device_id) {
            db_execute_prepared('DELETE FROM plugin_gnmi_devices WHERE id = ?', [$this->test_device_id]);
            $this->test_device_id = null;
        }
        $_POST = [];
    }

    // =========================================================================
    // GROUP 1: Update Subscription (backend was already complete)
    // =========================================================================

    /**
     * Test 1: update_subscription changes the subscription path
     */
    public function testUpdateSubscriptionPath() {
        $_POST['action']            = 'update_subscription';
        $_POST['subscription_id']   = $this->test_sub_id;
        $_POST['subscription_path'] = 'new/updated/path';
        $_POST['instance_identifier'] = 'original-instance';
        $_POST['enabled']           = '1';

        ob_start();
        $result = gnmi_process_subscription_action();
        ob_end_clean();

        if (!$result) {
            throw new Exception('gnmi_process_subscription_action() returned false for update_subscription');
        }

        $row = db_fetch_row_prepared(
            'SELECT * FROM plugin_gnmi_subscriptions WHERE id = ?',
            [$this->test_sub_id]
        );
        if ($row['subscription_path'] !== 'new/updated/path') {
            throw new Exception("Expected path 'new/updated/path', got '{$row['subscription_path']}'");
        }
        return true;
    }

    /**
     * Test 2: update_subscription toggles enabled 1→0
     */
    public function testUpdateSubscriptionEnabledToggle() {
        $_POST['action']              = 'update_subscription';
        $_POST['subscription_id']     = $this->test_sub_id;
        $_POST['subscription_path']   = 'original/test/path';
        $_POST['instance_identifier'] = 'original-instance';
        $_POST['enabled']             = '0';

        ob_start();
        $result = gnmi_process_subscription_action();
        ob_end_clean();

        if (!$result) {
            throw new Exception('update_subscription returned false');
        }

        $row = db_fetch_row_prepared(
            'SELECT enabled FROM plugin_gnmi_subscriptions WHERE id = ?',
            [$this->test_sub_id]
        );
        if ((int)$row['enabled'] !== 0) {
            throw new Exception("Expected enabled=0 after toggle, got {$row['enabled']}");
        }
        return true;
    }

    /**
     * Test 3: update_subscription updates notes
     */
    public function testUpdateSubscriptionNotes() {
        $_POST['action']              = 'update_subscription';
        $_POST['subscription_id']     = $this->test_sub_id;
        $_POST['subscription_path']   = 'original/test/path';
        $_POST['instance_identifier'] = 'original-instance';
        $_POST['enabled']             = '1';
        $_POST['notes']               = 'Updated notes from test';

        ob_start();
        $result = gnmi_process_subscription_action();
        ob_end_clean();

        if (!$result) {
            throw new Exception('update_subscription returned false');
        }

        $row = db_fetch_row_prepared(
            'SELECT notes FROM plugin_gnmi_subscriptions WHERE id = ?',
            [$this->test_sub_id]
        );
        if ($row['notes'] !== 'Updated notes from test') {
            throw new Exception("Expected notes 'Updated notes from test', got '{$row['notes']}'");
        }
        return true;
    }

    /**
     * Test 4: update_subscription with non-existent ID returns false
     */
    public function testUpdateSubscriptionInvalidId() {
        $_POST['action']            = 'update_subscription';
        $_POST['subscription_id']   = '999999';
        $_POST['subscription_path'] = 'some/path';
        $_POST['instance_identifier'] = 'some-instance';
        $_POST['enabled']           = '1';

        ob_start();
        $result = gnmi_process_subscription_action();
        ob_end_clean();

        if ($result !== false) {
            throw new Exception('Expected false for invalid subscription_id, got true');
        }
        return true;
    }

    /**
     * Test 5: update_subscription with missing subscription_id returns false
     */
    public function testUpdateSubscriptionMissingId() {
        $_POST['action']            = 'update_subscription';
        $_POST['subscription_path'] = 'some/path';

        ob_start();
        $result = gnmi_process_subscription_action();
        ob_end_clean();

        if ($result !== false) {
            throw new Exception('Expected false for missing subscription_id, got true');
        }
        return true;
    }

    // =========================================================================
    // GROUP 2: Update Metric (backend added in this phase — tests fail until wired)
    // =========================================================================

    /**
     * Test 6: update_metric changes the metric name
     */
    public function testUpdateMetricName() {
        $_POST['action']      = 'update_metric';
        $_POST['metric_id']   = $this->test_metric_id;
        $_POST['metric_name'] = 'out-octets';
        $_POST['rrd_type']    = 'COUNTER';
        $_POST['enabled']     = '1';

        ob_start();
        $result = gnmi_process_subscription_action();
        ob_end_clean();

        if (!$result) {
            throw new Exception('update_metric returned false for valid metric_name change');
        }

        $row = db_fetch_row_prepared(
            'SELECT metric_name FROM plugin_gnmi_metrics WHERE id = ?',
            [$this->test_metric_id]
        );
        if ($row['metric_name'] !== 'out-octets') {
            throw new Exception("Expected metric_name 'out-octets', got '{$row['metric_name']}'");
        }
        return true;
    }

    /**
     * Test 7: update_metric changes rrd_type COUNTER→GAUGE
     */
    public function testUpdateMetricRRDType() {
        $_POST['action']    = 'update_metric';
        $_POST['metric_id'] = $this->test_metric_id;
        $_POST['rrd_type']  = 'GAUGE';

        ob_start();
        $result = gnmi_process_subscription_action();
        ob_end_clean();

        if (!$result) {
            throw new Exception('update_metric returned false for rrd_type change');
        }

        $row = db_fetch_row_prepared(
            'SELECT rrd_type FROM plugin_gnmi_metrics WHERE id = ?',
            [$this->test_metric_id]
        );
        if ($row['rrd_type'] !== 'GAUGE') {
            throw new Exception("Expected rrd_type 'GAUGE', got '{$row['rrd_type']}'");
        }
        return true;
    }

    /**
     * Test 8: update_metric toggles enabled 1→0
     */
    public function testUpdateMetricEnabled() {
        $_POST['action']    = 'update_metric';
        $_POST['metric_id'] = $this->test_metric_id;
        $_POST['enabled']   = '0';

        ob_start();
        $result = gnmi_process_subscription_action();
        ob_end_clean();

        if (!$result) {
            throw new Exception('update_metric returned false for enabled toggle');
        }

        $row = db_fetch_row_prepared(
            'SELECT enabled FROM plugin_gnmi_metrics WHERE id = ?',
            [$this->test_metric_id]
        );
        if ((int)$row['enabled'] !== 0) {
            throw new Exception("Expected enabled=0 after toggle, got {$row['enabled']}");
        }
        return true;
    }

    /**
     * Test 9: update_metric with invalid rrd_type returns false
     */
    public function testUpdateMetricInvalidRRDType() {
        $_POST['action']    = 'update_metric';
        $_POST['metric_id'] = $this->test_metric_id;
        $_POST['rrd_type']  = 'INVALID_TYPE';

        ob_start();
        $result = gnmi_process_subscription_action();
        ob_end_clean();

        if ($result !== false) {
            throw new Exception('Expected false for invalid rrd_type, got true');
        }
        return true;
    }

    /**
     * Test 10: update_metric with non-existent metric_id returns false
     */
    public function testUpdateMetricInvalidId() {
        $_POST['action']    = 'update_metric';
        $_POST['metric_id'] = '999999';
        $_POST['rrd_type']  = 'GAUGE';

        ob_start();
        $result = gnmi_process_subscription_action();
        ob_end_clean();

        if ($result !== false) {
            throw new Exception('Expected false for invalid metric_id, got true');
        }
        return true;
    }

    // =========================================================================
    // GROUP 3: Restart Daemon (structural / whitelist checks)
    // =========================================================================

    /**
     * Test 11: 'restart_daemon' appears in the centralized action whitelist
     */
    public function testRestartDaemonWhitelisted() {
        $handler_path = '/var/www/html/cacti/plugins/gnmi/include/subscription_actions.php';
        if (!file_exists($handler_path)) {
            throw new Exception("subscription_actions.php not found at $handler_path");
        }

        $contents = file_get_contents($handler_path);
        if (strpos($contents, "'restart_daemon'") === false) {
            throw new Exception("'restart_daemon' not found in centralized action whitelist");
        }
        return true;
    }

    /**
     * Test 12: gnmi_restart_daemon_by_host_id() returns false for non-existent host
     */
    public function testRestartDaemonInvalidHost() {
        $result = gnmi_restart_daemon_by_host_id(999999);
        if ($result !== false) {
            throw new Exception('Expected false for non-existent host_id=999999, got true');
        }
        return true;
    }

    // =========================================================================
    // GROUP 4: UI Rendering — edit forms must appear in rendered HTML
    // =========================================================================

    /**
     * Test 13: edit-subscription-form div is rendered by gnmi_render_subscription_section()
     */
    public function testEditSubscriptionFormRendered() {
        ob_start();
        gnmi_render_subscription_section($this->test_device_id, 1);
        $html = ob_get_clean();

        if (strpos($html, 'id="edit-subscription-form"') === false) {
            throw new Exception('edit-subscription-form not found in rendered subscription section');
        }
        return true;
    }

    /**
     * Test 14: edit-metric-form div is rendered somewhere in the subscription section
     */
    public function testEditMetricFormRendered() {
        ob_start();
        gnmi_render_subscription_section($this->test_device_id, 1);
        $html = ob_get_clean();

        if (strpos($html, 'id="edit-metric-form"') === false) {
            throw new Exception('edit-metric-form not found in rendered subscription section');
        }
        return true;
    }

    /**
     * Test 15: Subscription row contains data-path, data-instance, data-enabled attributes
     */
    public function testSubscriptionRowHasDataAttributes() {
        ob_start();
        $sub = db_fetch_row_prepared(
            'SELECT * FROM plugin_gnmi_subscriptions WHERE id = ?',
            [$this->test_sub_id]
        );
        gnmi_render_subscription_row($sub, 'odd');
        $html = ob_get_clean();

        foreach (['data-path', 'data-instance', 'data-enabled', 'data-notes'] as $attr) {
            if (strpos($html, $attr . '=') === false) {
                throw new Exception("Subscription row missing attribute: $attr");
            }
        }
        return true;
    }

    /**
     * Test 16: Metric row contains data-metric-name, data-rrd-type, data-enabled attributes
     */
    public function testMetricRowHasDataAttributes() {
        ob_start();
        $metric = db_fetch_row_prepared(
            'SELECT * FROM plugin_gnmi_metrics WHERE id = ?',
            [$this->test_metric_id]
        );
        gnmi_render_metric_row($metric, 'odd');
        $html = ob_get_clean();

        foreach (['data-metric-name', 'data-rrd-type', 'data-enabled'] as $attr) {
            if (strpos($html, $attr . '=') === false) {
                throw new Exception("Metric row missing attribute: $attr");
            }
        }
        return true;
    }
}

// ---------------------------------------------------------------------------
// Test runner
// ---------------------------------------------------------------------------
if (basename(__FILE__) === basename($_SERVER['SCRIPT_NAME'])) {
    $test       = new TestUIStubs();
    $reflection = new ReflectionClass($test);

    echo "Running Phase 3.5 UI Stubs Tests...\n";
    echo "=====================================\n";

    $passed  = 0;
    $failed  = 0;
    $skipped = 0;

    foreach ($reflection->getMethods() as $method) {
        if (strpos($method->getName(), 'test') !== 0) {
            continue;
        }
        $name = $method->getName();
        echo "Running $name... ";
        try {
            $test->setUp();
            $result = $method->invoke($test);
            if ($result === true) {
                echo "PASS\n";
                $passed++;
            } elseif (is_string($result) && strpos($result, 'SKIP') === 0) {
                echo "SKIP: $result\n";
                $skipped++;
            } else {
                echo "UNKNOWN\n";
            }
        } catch (Exception $e) {
            echo "FAIL: " . $e->getMessage() . "\n";
            $failed++;
        }
        $test->tearDown();
    }

    echo "\nTest Results:\n";
    echo "=============\n";
    echo "Passed:  $passed\n";
    echo "Skipped: $skipped\n";
    echo "Failed:  $failed\n";
    echo "\n";
    if ($failed > 0) {
        exit(1);
    }
    echo "All tests passed.\n";
}

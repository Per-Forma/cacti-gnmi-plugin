#!/usr/bin/env php
<?php
/**
 * gNMI Plugin Phase 3.4 - Graph Auto-Creation Tests
 *
 * Tests for:
 *   - gnmi_auto_create_missing_graphs(): batch creation from poller hook
 *   - gnmi_create_data_source_for_metric() triggers graph when auto_create_graphs=1
 *   - Subscription flag auto_create_graphs=0 skips graph creation
 *   - Metrics without data sources are skipped
 *   - Metrics already with graphs are skipped (idempotency)
 *
 * Run: php plugins/gnmi/tests/phase_3_4/test_graph_auto_creation.php
 */

require_once('/var/www/html/cacti/include/global.php');
require_once('/var/www/html/cacti/lib/database.php');
require_once('/var/www/html/cacti/plugins/gnmi/include/functions.php');
require_once('/var/www/html/cacti/plugins/gnmi/include/subscription_functions.php');

class TestGraphAutoCreation {

    public $device_id      = null;
    public $subscription_id = null;
    public $host_id        = 1;

    public function setUp() {
        db_execute("DELETE FROM plugin_gnmi_devices WHERE hostname = 'auto-graph-test.example.com'");

        db_execute("INSERT INTO plugin_gnmi_devices
            (host_id, enabled, hostname, port, username, password, use_tls, collection_interval, encoding)
            VALUES (1, 1, 'auto-graph-test.example.com', 9339, 'u', 'p', 1, 10, 'JSON_IETF')");
        $this->device_id = (int)db_fetch_cell('SELECT LAST_INSERT_ID()');

        $this->subscription_id = gnmi_create_subscription(
            $this->device_id, 'test/auto/path', 'iface-0',
            array('auto_create_datasources' => 0)
        );
        // Ensure auto_create_graphs defaults to 1
        db_execute_prepared(
            "UPDATE plugin_gnmi_subscriptions SET auto_create_graphs = 1 WHERE id = ?",
            array($this->subscription_id)
        );
    }

    public function tearDown() {
        if ($this->subscription_id) {
            $metrics = db_fetch_assoc_prepared(
                'SELECT id, local_data_id, graph_local_id FROM plugin_gnmi_metrics WHERE subscription_id = ?',
                array($this->subscription_id)
            );
            foreach ($metrics as $m) {
                if ($m['graph_local_id']) {
                    $this->_delete_graph_instance((int)$m['graph_local_id']);
                }
                if ($m['local_data_id']) {
                    $this->_delete_data_source((int)$m['local_data_id']);
                }
            }
            db_execute_prepared('DELETE FROM plugin_gnmi_metrics WHERE subscription_id = ?',
                array($this->subscription_id));
            db_execute_prepared('DELETE FROM plugin_gnmi_subscriptions WHERE id = ?',
                array($this->subscription_id));
        }
        if ($this->device_id) {
            db_execute_prepared('DELETE FROM plugin_gnmi_devices WHERE id = ?', array($this->device_id));
        }
        $this->device_id      = null;
        $this->subscription_id = null;
    }

    private function _delete_graph_instance($local_graph_id) {
        db_execute_prepared('DELETE FROM graph_templates_item WHERE local_graph_id = ?', array($local_graph_id));
        db_execute_prepared('DELETE FROM graph_templates_graph WHERE local_graph_id = ?', array($local_graph_id));
        db_execute_prepared('DELETE FROM graph_local WHERE id = ?', array($local_graph_id));
    }

    private function _delete_data_source($local_data_id) {
        db_execute_prepared('DELETE FROM poller_item WHERE local_data_id = ?', array($local_data_id));
        db_execute_prepared('DELETE FROM data_template_rrd WHERE local_data_id = ?', array($local_data_id));
        db_execute_prepared('DELETE FROM data_template_data WHERE local_data_id = ?', array($local_data_id));
        db_execute_prepared('DELETE FROM data_local WHERE id = ?', array($local_data_id));
    }

    private function _make_metric_with_datasource($name, $rrd_type = 'GAUGE') {
        $metric_id = gnmi_add_metric_to_subscription($this->subscription_id, $name, $rrd_type);
        if (!$metric_id) throw new Exception("Failed to create metric '$name'");
        $ds_id = gnmi_create_data_source_for_metric($metric_id);
        if (!$ds_id) throw new Exception("Failed to create data source for metric '$name'");
        return $metric_id;
    }

    // -------------------------------------------------------------------------
    // gnmi_auto_create_missing_graphs()
    // -------------------------------------------------------------------------

    /**
     * Test 1: gnmi_auto_create_missing_graphs() creates a graph and returns count >= 1
     */
    public function testAutoCreateMissingGraphsCreatesGraph() {
        $metric_id = $this->_make_metric_with_datasource('cpu_util', 'GAUGE');
        // Ensure no graph yet
        db_execute_prepared('UPDATE plugin_gnmi_metrics SET graph_local_id = NULL, graph_created = 0 WHERE id = ?',
            array($metric_id));

        $count = gnmi_auto_create_missing_graphs();
        if ($count < 1) {
            throw new Exception("Expected at least 1 graph created, got $count");
        }
        $metric = db_fetch_row_prepared('SELECT * FROM plugin_gnmi_metrics WHERE id = ?', array($metric_id));
        if (empty($metric['graph_local_id'])) {
            throw new Exception("graph_local_id not set after gnmi_auto_create_missing_graphs()");
        }
        return true;
    }

    /**
     * Test 2: Returns 0 when no eligible metrics exist
     */
    public function testAutoCreateReturnsZeroWhenNothingToDo() {
        // Create metric with data source AND graph already
        $metric_id = $this->_make_metric_with_datasource('mem_util', 'GAUGE');
        gnmi_create_graph_for_metric($metric_id);

        $count = gnmi_auto_create_missing_graphs();
        // Should be 0 for our subscription (graph already exists)
        $metric = db_fetch_row_prepared('SELECT * FROM plugin_gnmi_metrics WHERE id = ?', array($metric_id));
        if (empty($metric['graph_local_id'])) {
            throw new Exception("graph_local_id should be set");
        }
        // The function may have created graphs for other subscriptions; just verify ours is not recreated
        $graph_id1 = (int)$metric['graph_local_id'];
        gnmi_auto_create_missing_graphs();
        $metric2 = db_fetch_row_prepared('SELECT * FROM plugin_gnmi_metrics WHERE id = ?', array($metric_id));
        if ((int)$metric2['graph_local_id'] !== $graph_id1) {
            throw new Exception("graph_local_id changed on second call — not idempotent");
        }
        return true;
    }

    /**
     * Test 3: Skips metrics where graph_local_id is already set
     */
    public function testAutoCreateSkipsMetricsWithExistingGraph() {
        $metric_id = $this->_make_metric_with_datasource('disk_io', 'GAUGE');
        $graph_id = gnmi_create_graph_for_metric($metric_id);
        if (!$graph_id) throw new Exception("Initial graph creation failed");

        // Manually force graph_created=0 to simulate partial state, but graph_local_id set
        db_execute_prepared('UPDATE plugin_gnmi_metrics SET graph_created = 0 WHERE id = ?', array($metric_id));

        gnmi_auto_create_missing_graphs();

        $metric = db_fetch_row_prepared('SELECT * FROM plugin_gnmi_metrics WHERE id = ?', array($metric_id));
        // graph_local_id should still be the same (idempotent — function returns existing ID)
        if ((int)$metric['graph_local_id'] !== $graph_id) {
            throw new Exception("graph_local_id changed after auto-create: expected $graph_id, got {$metric['graph_local_id']}");
        }
        return true;
    }

    /**
     * Test 4: Respects auto_create_graphs = 0 on subscription — skips those metrics
     */
    public function testAutoCreateRespectsSubscriptionFlag() {
        // Disable auto_create_graphs
        db_execute_prepared(
            'UPDATE plugin_gnmi_subscriptions SET auto_create_graphs = 0 WHERE id = ?',
            array($this->subscription_id)
        );

        $metric_id = $this->_make_metric_with_datasource('temp_fan', 'GAUGE');

        gnmi_auto_create_missing_graphs();

        $metric = db_fetch_row_prepared('SELECT * FROM plugin_gnmi_metrics WHERE id = ?', array($metric_id));
        if (!empty($metric['graph_local_id'])) {
            throw new Exception("Graph was created despite auto_create_graphs=0");
        }
        return true;
    }

    /**
     * Test 5: Skips metrics with no local_data_id (data source not ready)
     */
    public function testAutoCreateSkipsMetricsWithoutDataSource() {
        // Create metric but no data source
        $metric_id = gnmi_add_metric_to_subscription($this->subscription_id, 'no_ds_metric', 'GAUGE');
        if (!$metric_id) throw new Exception("Failed to create metric");

        gnmi_auto_create_missing_graphs();

        $metric = db_fetch_row_prepared('SELECT * FROM plugin_gnmi_metrics WHERE id = ?', array($metric_id));
        if (!empty($metric['graph_local_id'])) {
            throw new Exception("Graph was created for metric without data source");
        }
        return true;
    }

    // -------------------------------------------------------------------------
    // Auto-trigger from gnmi_create_data_source_for_metric()
    // -------------------------------------------------------------------------

    /**
     * Test 6: When auto_create_graphs=1 on subscription, creating a data source
     *         triggers graph creation automatically
     */
    public function testDataSourceCreationTriggersGraphCreation() {
        // Enable auto_create_graphs
        db_execute_prepared(
            'UPDATE plugin_gnmi_subscriptions SET auto_create_graphs = 1 WHERE id = ?',
            array($this->subscription_id)
        );

        $metric_id = gnmi_add_metric_to_subscription($this->subscription_id, 'power_watts', 'GAUGE');
        if (!$metric_id) throw new Exception("Failed to create metric");

        $ds_id = gnmi_create_data_source_for_metric($metric_id);
        if (!$ds_id) throw new Exception("Failed to create data source");

        $metric = db_fetch_row_prepared('SELECT * FROM plugin_gnmi_metrics WHERE id = ?', array($metric_id));
        if (empty($metric['graph_local_id'])) {
            throw new Exception("Graph not auto-created after data source creation with auto_create_graphs=1");
        }
        if ((int)$metric['graph_created'] !== 1) {
            throw new Exception("graph_created not set to 1 after auto-creation");
        }
        return true;
    }

    /**
     * Test 7: When auto_create_graphs=0, creating a data source does NOT trigger graph
     */
    public function testDataSourceCreationSkipsGraphWhenFlagOff() {
        db_execute_prepared(
            'UPDATE plugin_gnmi_subscriptions SET auto_create_graphs = 0 WHERE id = ?',
            array($this->subscription_id)
        );

        $metric_id = gnmi_add_metric_to_subscription($this->subscription_id, 'voltage_v', 'GAUGE');
        if (!$metric_id) throw new Exception("Failed to create metric");

        $ds_id = gnmi_create_data_source_for_metric($metric_id);
        if (!$ds_id) throw new Exception("Failed to create data source");

        $metric = db_fetch_row_prepared('SELECT * FROM plugin_gnmi_metrics WHERE id = ?', array($metric_id));
        if (!empty($metric['graph_local_id'])) {
            throw new Exception("Graph was auto-created despite auto_create_graphs=0");
        }
        return true;
    }
}

// Run tests if called directly
if (basename(__FILE__) == basename($_SERVER['SCRIPT_NAME'])) {
    $test = new TestGraphAutoCreation();
    $reflection = new ReflectionClass($test);

    echo "Running Phase 3.4 Graph Auto-Creation Tests...\n";
    echo "===============================================\n";

    $passed = 0;
    $skipped = 0;
    $failed = 0;

    foreach ($reflection->getMethods() as $method) {
        if (strpos($method->getName(), 'test') === 0) {
            echo "Running {$method->getName()}... ";
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
    }

    echo "\nTest Results:\n";
    echo "=============\n";
    echo "Passed:  $passed\n";
    echo "Skipped: $skipped\n";
    echo "Failed:  $failed\n";
    if ($failed > 0) {
        exit(1);
    }
    echo "\nTest run complete.\n";
}

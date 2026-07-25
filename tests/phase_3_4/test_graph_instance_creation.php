#!/usr/bin/env php
<?php
/**
 * gNMI Plugin Phase 3.4 - Graph Instance Creation Tests
 *
 * Tests for gnmi_create_graph_for_metric():
 *   - Generic metric → passthrough graph
 *   - Traffic pair → combined interface traffic graph
 *   - Idempotency
 *   - Deferred creation when partner not ready
 *   - Proper data_template_rrd linkage in items
 *   - Rollback on failure
 *
 * Run: php plugins/gnmi/tests/phase_3_4/test_graph_instance_creation.php
 */

require_once('/var/www/html/cacti/include/global.php');
require_once('/var/www/html/cacti/lib/database.php');
require_once('/var/www/html/cacti/plugins/gnmi/include/functions.php');
require_once('/var/www/html/cacti/plugins/gnmi/include/subscription_functions.php');

class TestGraphInstanceCreation {

    public $device_id      = null;
    public $subscription_id = null;
    public $host_id        = 1; // Use Cacti host.id=1 (localhost)

    public function setUp() {
        // Remove any stale test data
        db_execute("DELETE FROM plugin_gnmi_devices WHERE hostname = 'graph-test.example.com'");

        db_execute("INSERT INTO plugin_gnmi_devices
            (host_id, enabled, hostname, port, username, password, use_tls, collection_interval, encoding)
            VALUES (1, 1, 'graph-test.example.com', 9339, 'u', 'p', 1, 10, 'JSON_IETF')");
        $this->device_id = (int)db_fetch_cell('SELECT LAST_INSERT_ID()');

        $sub_id = gnmi_create_subscription($this->device_id, 'test/graph/path', 'iface-0',
            array('auto_create_datasources' => 0, 'auto_create_graphs' => 0));
        $this->subscription_id = $sub_id;
    }

    public function tearDown() {
        // Clean up graph instances created during tests
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
    // Generic / Passthrough graph
    // -------------------------------------------------------------------------

    /**
     * Test 1: Generic metric creates a graph_local row
     */
    public function testGenericMetricCreatesGraphLocal() {
        $metric_id = $this->_make_metric_with_datasource('cpu_util', 'GAUGE');
        $graph_id = gnmi_create_graph_for_metric($metric_id);
        if (!$graph_id || !is_int($graph_id) || $graph_id <= 0) {
            throw new Exception("Expected int > 0 from gnmi_create_graph_for_metric(), got: " . var_export($graph_id, true));
        }
        $row = db_fetch_row_prepared('SELECT * FROM graph_local WHERE id = ?', array($graph_id));
        if (!$row) throw new Exception("graph_local row not found for id=$graph_id");
        if ((int)$row['host_id'] !== $this->host_id) {
            throw new Exception("Expected host_id={$this->host_id}, got {$row['host_id']}");
        }
        return true;
    }

    /**
     * Test 2: After creation, metric.graph_local_id and graph_created are updated
     */
    public function testMetricUpdatedAfterGraphCreation() {
        $metric_id = $this->_make_metric_with_datasource('mem_util', 'GAUGE');
        $graph_id = gnmi_create_graph_for_metric($metric_id);
        $metric = db_fetch_row_prepared('SELECT * FROM plugin_gnmi_metrics WHERE id = ?', array($metric_id));
        if ((int)$metric['graph_local_id'] !== $graph_id) {
            throw new Exception("metric.graph_local_id not updated: expected $graph_id, got {$metric['graph_local_id']}");
        }
        if ((int)$metric['graph_created'] !== 1) {
            throw new Exception("metric.graph_created not set to 1");
        }
        return true;
    }

    /**
     * Test 3: graph_templates_graph instance row created
     */
    public function testGraphTemplatesGraphInstanceCreated() {
        $metric_id = $this->_make_metric_with_datasource('temp_sensor', 'GAUGE');
        $graph_id = gnmi_create_graph_for_metric($metric_id);
        $row = db_fetch_row_prepared(
            'SELECT * FROM graph_templates_graph WHERE local_graph_id = ?',
            array($graph_id)
        );
        if (!$row) throw new Exception("graph_templates_graph instance row not found for local_graph_id=$graph_id");
        return true;
    }

    /**
     * Test 4: graph_templates_item instance rows created, referencing correct data_template_rrd
     */
    public function testGraphItemsCreatedWithCorrectRrdRef() {
        $metric_id = $this->_make_metric_with_datasource('fan_speed', 'GAUGE');
        $metric = db_fetch_row_prepared('SELECT * FROM plugin_gnmi_metrics WHERE id = ?', array($metric_id));
        $local_data_id = (int)$metric['local_data_id'];

        $graph_id = gnmi_create_graph_for_metric($metric_id);

        $items = db_fetch_assoc_prepared(
            'SELECT * FROM graph_templates_item WHERE local_graph_id = ? ORDER BY sequence',
            array($graph_id)
        );
        if (count($items) < 2) {
            throw new Exception("Expected >= 2 instance graph items, got " . count($items));
        }
        // Verify task_item_id references the metric's own data_template_rrd
        $expected_rrd_id = (int)db_fetch_cell_prepared(
            'SELECT id FROM data_template_rrd WHERE local_data_id = ? LIMIT 1',
            array($local_data_id)
        );
        foreach ($items as $item) {
            if ((int)$item['task_item_id'] !== $expected_rrd_id) {
                throw new Exception("Item task_item_id={$item['task_item_id']} does not match expected data_template_rrd.id=$expected_rrd_id");
            }
        }
        return true;
    }

    /**
     * Test 5: Second call is idempotent — returns same graph_local_id, no duplicates
     */
    public function testCreateGraphIsIdempotent() {
        $metric_id = $this->_make_metric_with_datasource('voltage', 'GAUGE');
        $graph_id1 = gnmi_create_graph_for_metric($metric_id);
        $graph_id2 = gnmi_create_graph_for_metric($metric_id);
        if ($graph_id1 !== $graph_id2) {
            throw new Exception("Expected same graph_id on repeated calls, got $graph_id1 then $graph_id2");
        }
        $count = db_fetch_cell_prepared('SELECT COUNT(*) FROM graph_local WHERE id = ?', array($graph_id1));
        if ((int)$count !== 1) {
            throw new Exception("Expected exactly 1 graph_local row, got $count");
        }
        return true;
    }

    /**
     * Test 6: Returns false if local_data_id is NULL (data source not ready)
     */
    public function testReturnsFalseWithoutDataSource() {
        $metric_id = gnmi_add_metric_to_subscription($this->subscription_id, 'no_ds_metric', 'GAUGE');
        // Do NOT create data source
        $result = gnmi_create_graph_for_metric($metric_id);
        if ($result !== false) {
            throw new Exception("Expected false when metric has no data source, got: " . var_export($result, true));
        }
        return true;
    }

    /**
     * Test 7: Returns false for non-existent metric_id
     */
    public function testReturnsFalseForNonexistentMetric() {
        $result = gnmi_create_graph_for_metric(999999);
        if ($result !== false) {
            throw new Exception("Expected false for non-existent metric, got: " . var_export($result, true));
        }
        return true;
    }

    // -------------------------------------------------------------------------
    // Traffic pair graph
    // -------------------------------------------------------------------------

    /**
     * Test 8: Traffic pair (in_octets + out_octets) creates ONE combined graph_local
     */
    public function testTrafficPairCreatesCombinedGraph() {
        $in_id  = $this->_make_metric_with_datasource('in_octets', 'COUNTER');
        $out_id = $this->_make_metric_with_datasource('out_octets', 'COUNTER');

        $graph_id = gnmi_create_graph_for_metric($in_id);
        if (!$graph_id) throw new Exception("Expected graph_id for in_octets, got false");

        $in_metric  = db_fetch_row_prepared('SELECT graph_local_id FROM plugin_gnmi_metrics WHERE id = ?', array($in_id));
        $out_metric = db_fetch_row_prepared('SELECT graph_local_id FROM plugin_gnmi_metrics WHERE id = ?', array($out_id));

        if ((int)$in_metric['graph_local_id'] !== $graph_id) {
            throw new Exception("in_octets metric.graph_local_id not set to $graph_id");
        }
        if ((int)$out_metric['graph_local_id'] !== $graph_id) {
            throw new Exception("out_octets metric.graph_local_id not set to $graph_id (should share same graph)");
        }

        // Verify only ONE graph_local row for this host+template
        $gt_id = gnmi_get_traffic_graph_template_id();
        $count = db_fetch_cell_prepared(
            'SELECT COUNT(*) FROM graph_local WHERE id = ? AND graph_template_id = ?',
            array($graph_id, $gt_id)
        );
        if ((int)$count !== 1) {
            throw new Exception("Expected exactly 1 graph_local row for traffic pair, got $count");
        }
        return true;
    }

    /**
     * Test 9: Traffic metric deferred when partner has no data source yet
     */
    public function testTrafficMetricDeferredWithoutPartner() {
        // Only inbound metric, no outbound
        $in_id = $this->_make_metric_with_datasource('in_octets', 'COUNTER');
        // Create outbound metric without data source
        gnmi_add_metric_to_subscription($this->subscription_id, 'out_octets', 'COUNTER');

        $result = gnmi_create_graph_for_metric($in_id);
        if ($result !== false) {
            throw new Exception("Expected false (deferred) when outbound partner has no data source, got: " . var_export($result, true));
        }
        return true;
    }

    /**
     * Test 10: Calling create on the outbound metric after the combined graph was already
     *           created returns the existing graph_local_id (no duplicate)
     */
    public function testOutboundMetricLinksToExistingTrafficGraph() {
        $in_id  = $this->_make_metric_with_datasource('in_octets', 'COUNTER');
        $out_id = $this->_make_metric_with_datasource('out_octets', 'COUNTER');

        // Create graph starting from inbound
        $graph_id = gnmi_create_graph_for_metric($in_id);
        if (!$graph_id) throw new Exception("First call should create the graph");

        // Now explicitly call create on outbound — should return same graph_id
        $graph_id2 = gnmi_create_graph_for_metric($out_id);
        if ($graph_id2 !== $graph_id) {
            throw new Exception("Expected outbound to return existing graph_id=$graph_id, got $graph_id2");
        }
        return true;
    }

    /**
     * Test 11: A legacy/non-paired partner graph is not treated as an exact-key pair
     */
    public function testLegacyPartnerGraphDoesNotUnsafeLink() {
        $in_id  = $this->_make_metric_with_datasource('in-octets', 'COUNTER');
        $out_id = $this->_make_metric_with_datasource('out-octets', 'COUNTER');

        $out_metric = db_fetch_row_prepared('SELECT * FROM plugin_gnmi_metrics WHERE id = ?', array($out_id));
        $legacy_graph_id = gnmi_create_passthrough_graph_instance($out_metric, $this->host_id);
        if (!$legacy_graph_id) throw new Exception("Expected legacy partner graph to be created");

        $result = gnmi_create_graph_for_metric($in_id);
        if ($result !== false) {
            throw new Exception("Expected false when partner graph lacks exact pair items, got " . var_export($result, true));
        }

        $in_metric = db_fetch_row_prepared('SELECT graph_local_id FROM plugin_gnmi_metrics WHERE id = ?', array($in_id));
        if (!empty($in_metric['graph_local_id'])) {
            throw new Exception("Inbound metric was linked to an unsafe legacy partner graph");
        }
        return true;
    }

    /**
     * Test 12: Multiple traffic families in one subscription create separate exact-key graphs
     */
    public function testMultipleTrafficPairsUseSeparateGraphs() {
        $in_octets  = $this->_make_metric_with_datasource('in-octets', 'COUNTER');
        $out_octets = $this->_make_metric_with_datasource('out-octets', 'COUNTER');
        $in_unicast  = $this->_make_metric_with_datasource('in-unicast-octets', 'COUNTER');
        $out_unicast = $this->_make_metric_with_datasource('out-unicast-octets', 'COUNTER');

        $traffic_graph = gnmi_create_graph_for_metric($in_octets);
        $unicast_graph = gnmi_create_graph_for_metric($in_unicast);

        if (!$traffic_graph || !$unicast_graph) {
            throw new Exception("Expected both traffic graphs to be created");
        }
        if ($traffic_graph === $unicast_graph) {
            throw new Exception("Expected separate graph IDs for octets and unicast_octets");
        }

        $out_metric = db_fetch_row_prepared('SELECT graph_local_id FROM plugin_gnmi_metrics WHERE id = ?', array($out_octets));
        $out_unicast_metric = db_fetch_row_prepared('SELECT graph_local_id FROM plugin_gnmi_metrics WHERE id = ?', array($out_unicast));
        if ((int)$out_metric['graph_local_id'] !== $traffic_graph) {
            throw new Exception("out-octets was not linked to the octets graph");
        }
        if ((int)$out_unicast_metric['graph_local_id'] !== $unicast_graph) {
            throw new Exception("out-unicast-octets was not linked to the unicast_octets graph");
        }
        return true;
    }

    /**
     * Test 13: Normal packet pairs use the non-CDEF packet graph template
     */
    public function testPacketPairCreatesPacketGraph() {
        $in_id  = $this->_make_metric_with_datasource('in-broadcast-pkts', 'COUNTER');
        $out_id = $this->_make_metric_with_datasource('out-broadcast-pkts', 'COUNTER');

        $graph_id = gnmi_create_graph_for_metric($in_id);
        if (!$graph_id) throw new Exception("Expected graph for broadcast packet pair");

        $in_metric  = db_fetch_row_prepared('SELECT graph_local_id FROM plugin_gnmi_metrics WHERE id = ?', array($in_id));
        $out_metric = db_fetch_row_prepared('SELECT graph_local_id FROM plugin_gnmi_metrics WHERE id = ?', array($out_id));
        if ((int)$in_metric['graph_local_id'] !== $graph_id || (int)$out_metric['graph_local_id'] !== $graph_id) {
            throw new Exception("Broadcast packet pair did not share one graph");
        }

        $packet_template_id = gnmi_get_packets_graph_template_id();
        $row = db_fetch_row_prepared('SELECT graph_template_id FROM graph_local WHERE id = ?', array($graph_id));
        if ((int)$row['graph_template_id'] !== $packet_template_id) {
            throw new Exception("Expected packet graph template $packet_template_id, got {$row['graph_template_id']}");
        }
        return true;
    }

    /**
     * Test 14: Packet/event integrity metrics share one dynamic graph
     */
    public function testIntegrityPacketsShareGraph() {
        $undersize_id = $this->_make_metric_with_datasource('in-undersize-pkts', 'COUNTER');
        $dropped_id   = $this->_make_metric_with_datasource('in-dropped-pkts', 'COUNTER');
        $errors_id    = $this->_make_metric_with_datasource('out-errors', 'COUNTER');

        $graph_id = gnmi_create_graph_for_metric($undersize_id);
        if (!$graph_id) throw new Exception("Expected integrity packets graph");

        foreach (array($undersize_id, $dropped_id, $errors_id) as $metric_id) {
            $metric = db_fetch_row_prepared('SELECT graph_local_id FROM plugin_gnmi_metrics WHERE id = ?', array($metric_id));
            if ((int)$metric['graph_local_id'] !== $graph_id) {
                throw new Exception("Metric $metric_id was not linked to integrity packets graph $graph_id");
            }
        }

        $series_count = db_fetch_cell_prepared(
            'SELECT COUNT(DISTINCT task_item_id) FROM graph_templates_item WHERE local_graph_id = ? AND task_item_id > 0',
            array($graph_id)
        );
        if ((int)$series_count !== 3) {
            throw new Exception("Expected 3 integrity packet series, got $series_count");
        }
        return true;
    }

    /**
     * Test 15: Octet integrity metrics use a separate dynamic graph
     */
    public function testIntegrityOctetsUseSeparateGraph() {
        $packet_id = $this->_make_metric_with_datasource('in-jabber-pkts', 'COUNTER');
        $octet_id  = $this->_make_metric_with_datasource('in-jabber-octets', 'COUNTER');
        $drop_octets_id = $this->_make_metric_with_datasource('out-discards-octets', 'COUNTER');

        $packet_graph = gnmi_create_graph_for_metric($packet_id);
        $octet_graph = gnmi_create_graph_for_metric($octet_id);

        if (!$packet_graph || !$octet_graph) {
            throw new Exception("Expected both integrity packet and octet graphs");
        }
        if ($packet_graph === $octet_graph) {
            throw new Exception("Integrity packet and octet metrics should not share one graph");
        }

        $drop_metric = db_fetch_row_prepared('SELECT graph_local_id FROM plugin_gnmi_metrics WHERE id = ?', array($drop_octets_id));
        if ((int)$drop_metric['graph_local_id'] !== $octet_graph) {
            throw new Exception("out-discards-octets was not linked to the integrity octets graph");
        }
        return true;
    }

    /**
     * Test 16: Repeated integrity creation does not duplicate graph items
     */
    public function testIntegrityGraphIsIdempotent() {
        $metric_id = $this->_make_metric_with_datasource('in-undersize-pkts', 'COUNTER');
        $graph_id = gnmi_create_graph_for_metric($metric_id);
        if (!$graph_id) throw new Exception("Expected integrity graph");

        $before = db_fetch_cell_prepared(
            'SELECT COUNT(*) FROM graph_templates_item WHERE local_graph_id = ?',
            array($graph_id)
        );
        $graph_id2 = gnmi_create_graph_for_metric($metric_id);
        $after = db_fetch_cell_prepared(
            'SELECT COUNT(*) FROM graph_templates_item WHERE local_graph_id = ?',
            array($graph_id)
        );

        if ($graph_id2 !== $graph_id) {
            throw new Exception("Expected same graph on repeated integrity create");
        }
        if ((int)$before !== (int)$after) {
            throw new Exception("Integrity graph item count changed on repeated create: $before -> $after");
        }
        return true;
    }

    /**
     * Test 17: Existing integrity graph attaches newly added matching metrics
     */
    public function testExistingIntegrityGraphAttachesNewMetric() {
        $first_id = $this->_make_metric_with_datasource('in-undersize-pkts', 'COUNTER');
        $graph_id = gnmi_create_graph_for_metric($first_id);
        if (!$graph_id) throw new Exception("Expected initial integrity graph");

        $new_id = $this->_make_metric_with_datasource('out-errors', 'COUNTER');
        $graph_id2 = gnmi_create_graph_for_metric($first_id);
        if ($graph_id2 !== $graph_id) {
            throw new Exception("Expected existing integrity graph id $graph_id, got $graph_id2");
        }

        $new_metric = db_fetch_row_prepared('SELECT graph_local_id FROM plugin_gnmi_metrics WHERE id = ?', array($new_id));
        if ((int)$new_metric['graph_local_id'] !== $graph_id) {
            throw new Exception("New integrity metric was not attached to existing graph");
        }

        $series_count = db_fetch_cell_prepared(
            'SELECT COUNT(DISTINCT task_item_id) FROM graph_templates_item WHERE local_graph_id = ? AND task_item_id > 0',
            array($graph_id)
        );
        if ((int)$series_count !== 2) {
            throw new Exception("Expected 2 integrity packet series after attach, got $series_count");
        }
        return true;
    }
}

// Run tests if called directly
if (basename(__FILE__) == basename($_SERVER['SCRIPT_NAME'])) {
    $test = new TestGraphInstanceCreation();
    $reflection = new ReflectionClass($test);

    echo "Running Phase 3.4 Graph Instance Creation Tests...\n";
    echo "===================================================\n";

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

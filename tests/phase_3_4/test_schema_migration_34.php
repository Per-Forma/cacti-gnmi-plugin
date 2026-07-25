#!/usr/bin/env php
<?php
/**
 * gNMI Plugin Phase 3.4 - Schema Migration Tests
 *
 * Validates that the Phase 3.4 schema additions are present:
 *   - plugin_gnmi_subscriptions.auto_create_graphs  (BOOLEAN, DEFAULT TRUE)
 *   - plugin_gnmi_metrics.metric_group              (ENUM)
 *   - plugin_gnmi_metrics.metric_direction          (ENUM)
 *   - plugin_gnmi_metrics.metric_graph_key          (VARCHAR, nullable)
 *   - plugin_gnmi_metrics.graph_created             (BOOLEAN)
 *   - plugin_gnmi_metrics.graph_local_id            (already exists from Phase 3.3 - regression guard)
 *
 * Run: php plugins/gnmi/tests/phase_3_4/test_schema_migration_34.php
 */

require_once('/var/www/html/cacti/include/global.php');
require_once('/var/www/html/cacti/lib/database.php');
require_once('/var/www/html/cacti/plugins/gnmi/include/functions.php');

class TestSchemaMigration34 {

    public function setUp() {
        // Read-only schema validation — no setup needed
    }

    public function tearDown() {
        // Nothing to clean up
    }

    // -------------------------------------------------------------------------
    // plugin_gnmi_subscriptions new column
    // -------------------------------------------------------------------------

    /**
     * Test 1: auto_create_graphs column exists in plugin_gnmi_subscriptions
     */
    public function testAutoCreateGraphsColumnExists() {
        $columns = db_fetch_assoc('DESCRIBE plugin_gnmi_subscriptions');
        if (!is_array($columns)) {
            throw new Exception('Could not describe plugin_gnmi_subscriptions');
        }
        $col_names = array_column($columns, 'Field');
        if (!in_array('auto_create_graphs', $col_names)) {
            throw new Exception("Column 'auto_create_graphs' missing from plugin_gnmi_subscriptions");
        }
        return true;
    }

    /**
     * Test 2: auto_create_graphs has DEFAULT 1 (TRUE)
     */
    public function testAutoCreateGraphsDefault() {
        $columns = db_fetch_assoc('DESCRIBE plugin_gnmi_subscriptions');
        if (!is_array($columns)) {
            throw new Exception('Could not describe plugin_gnmi_subscriptions');
        }
        foreach ($columns as $col) {
            if ($col['Field'] === 'auto_create_graphs') {
                if ($col['Default'] !== '1') {
                    throw new Exception("Expected DEFAULT 1 for auto_create_graphs, got '{$col['Default']}'");
                }
                return true;
            }
        }
        throw new Exception("Column 'auto_create_graphs' not found");
    }

    // -------------------------------------------------------------------------
    // plugin_gnmi_metrics new columns
    // -------------------------------------------------------------------------

    /**
     * Test 3: metric_group column exists in plugin_gnmi_metrics
     */
    public function testMetricGroupColumnExists() {
        $columns = db_fetch_assoc('DESCRIBE plugin_gnmi_metrics');
        if (!is_array($columns)) {
            throw new Exception('Could not describe plugin_gnmi_metrics');
        }
        $col_names = array_column($columns, 'Field');
        if (!in_array('metric_group', $col_names)) {
            throw new Exception("Column 'metric_group' missing from plugin_gnmi_metrics");
        }
        return true;
    }

    /**
     * Test 4: metric_direction column exists in plugin_gnmi_metrics
     */
    public function testMetricDirectionColumnExists() {
        $columns = db_fetch_assoc('DESCRIBE plugin_gnmi_metrics');
        if (!is_array($columns)) {
            throw new Exception('Could not describe plugin_gnmi_metrics');
        }
        $col_names = array_column($columns, 'Field');
        if (!in_array('metric_direction', $col_names)) {
            throw new Exception("Column 'metric_direction' missing from plugin_gnmi_metrics");
        }
        return true;
    }

    /**
     * Test 5: graph_created column exists in plugin_gnmi_metrics
     */
    public function testGraphCreatedColumnExists() {
        $columns = db_fetch_assoc('DESCRIBE plugin_gnmi_metrics');
        if (!is_array($columns)) {
            throw new Exception('Could not describe plugin_gnmi_metrics');
        }
        $col_names = array_column($columns, 'Field');
        if (!in_array('graph_created', $col_names)) {
            throw new Exception("Column 'graph_created' missing from plugin_gnmi_metrics");
        }
        return true;
    }

    /**
     * Test 6: graph_local_id column still exists (Phase 3.3 regression guard)
     */
    public function testGraphLocalIdColumnStillExists() {
        $columns = db_fetch_assoc('DESCRIBE plugin_gnmi_metrics');
        if (!is_array($columns)) {
            throw new Exception('Could not describe plugin_gnmi_metrics');
        }
        $col_names = array_column($columns, 'Field');
        if (!in_array('graph_local_id', $col_names)) {
            throw new Exception("Regression: 'graph_local_id' missing from plugin_gnmi_metrics");
        }
        return true;
    }

    /**
     * Test 7: metric_group ENUM includes all expected values
     */
    public function testMetricGroupEnumValues() {
        $columns = db_fetch_assoc('DESCRIBE plugin_gnmi_metrics');
        if (!is_array($columns)) {
            throw new Exception('Could not describe plugin_gnmi_metrics');
        }
        foreach ($columns as $col) {
            if ($col['Field'] === 'metric_group') {
                $type = $col['Type'];
                $required = array('traffic', 'packets', 'errors', 'discards', 'generic');
                foreach ($required as $val) {
                    if (strpos($type, "'$val'") === false) {
                        throw new Exception("ENUM value '$val' missing from metric_group type: $type");
                    }
                }
                return true;
            }
        }
        throw new Exception("Column 'metric_group' not found");
    }

    /**
     * Test 8: metric_direction ENUM includes inbound, outbound, none
     */
    public function testMetricDirectionEnumValues() {
        $columns = db_fetch_assoc('DESCRIBE plugin_gnmi_metrics');
        if (!is_array($columns)) {
            throw new Exception('Could not describe plugin_gnmi_metrics');
        }
        foreach ($columns as $col) {
            if ($col['Field'] === 'metric_direction') {
                $type = $col['Type'];
                $required = array('inbound', 'outbound', 'none');
                foreach ($required as $val) {
                    if (strpos($type, "'$val'") === false) {
                        throw new Exception("ENUM value '$val' missing from metric_direction type: $type");
                    }
                }
                return true;
            }
        }
        throw new Exception("Column 'metric_direction' not found");
    }

    /**
     * Test 9: metric_graph_key column exists and is nullable
     */
    public function testMetricGraphKeyColumnExists() {
        $columns = db_fetch_assoc('DESCRIBE plugin_gnmi_metrics');
        if (!is_array($columns)) {
            throw new Exception('Could not describe plugin_gnmi_metrics');
        }
        foreach ($columns as $col) {
            if ($col['Field'] === 'metric_graph_key') {
                if (stripos($col['Type'], 'varchar(128)') === false) {
                    throw new Exception("Expected metric_graph_key varchar(128), got {$col['Type']}");
                }
                if (strtoupper($col['Null']) !== 'YES') {
                    throw new Exception("Expected metric_graph_key to be nullable");
                }
                return true;
            }
        }
        throw new Exception("Column 'metric_graph_key' not found");
    }

    /**
     * Test 10: metric_graph_key index exists
     */
    public function testMetricGraphKeyIndexExists() {
        $indexes = db_fetch_assoc("SHOW INDEX FROM plugin_gnmi_metrics WHERE Key_name = 'idx_metric_graph_key'");
        if (empty($indexes)) {
            throw new Exception("Index 'idx_metric_graph_key' missing from plugin_gnmi_metrics");
        }
        $columns = array_column($indexes, 'Column_name');
        foreach (array('subscription_id', 'metric_graph_key', 'metric_direction') as $required) {
            if (!in_array($required, $columns)) {
                throw new Exception("Index 'idx_metric_graph_key' missing column '$required'");
            }
        }
        return true;
    }
}

// Run tests if called directly
if (basename(__FILE__) == basename($_SERVER['SCRIPT_NAME'])) {
    $test = new TestSchemaMigration34();
    $reflection = new ReflectionClass($test);

    echo "Running Phase 3.4 Schema Migration Tests...\n";
    echo "============================================\n";

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

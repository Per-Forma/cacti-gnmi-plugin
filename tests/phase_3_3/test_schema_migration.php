<?php
/**
 * Phase 3.3 Schema Migration Tests
 *
 * Tests for plugin_gnmi_subscriptions and refactored plugin_gnmi_metrics tables.
 * Follows TDD approach - write tests first, then implement to pass.
 */

require_once('/var/www/html/cacti/include/global.php');
require_once('/var/www/html/cacti/lib/database.php');

class TestSchemaMigration {

    private $fixture_token = null;
    private $fixture_device_ids = array();
    private $fixture_subscription_ids = array();
    private $fixture_metric_ids = array();

    public function setUp() {
        // Every test gets an ownership marker. Cleanup always combines this marker
        // with recorded primary keys so an unrelated row can never be removed.
        $this->fixture_token = 'gnmi_schema_' . substr(sha1(uniqid('', true) . mt_rand()), 0, 16);
        $this->fixture_device_ids = array();
        $this->fixture_subscription_ids = array();
        $this->fixture_metric_ids = array();
    }

    public function tearDown() {
        $this->cleanupTestTables();
    }

    private function cleanupTestTables() {
        // Delete children first. Some behavior tests deliberately exercise
        // cascades, so already-deleted fixture IDs are expected and harmless.
        foreach (array_reverse($this->fixture_metric_ids, true) as $metric_id => $metric_name) {
            db_execute_prepared(
                'DELETE FROM plugin_gnmi_metrics WHERE id = ? AND metric_name = ?',
                array($metric_id, $metric_name)
            );
        }

        foreach (array_reverse($this->fixture_subscription_ids, true) as $subscription_id => $instance_identifier) {
            db_execute_prepared(
                'DELETE FROM plugin_gnmi_subscriptions WHERE id = ? AND instance_identifier = ?',
                array($subscription_id, $instance_identifier)
            );
        }

        foreach (array_reverse($this->fixture_device_ids, true) as $device_id => $hostname) {
            db_execute_prepared(
                'DELETE FROM plugin_gnmi_devices WHERE id = ? AND hostname = ?',
                array($device_id, $hostname)
            );
        }

        $remaining = $this->countTrackedFixtureRows();
        $this->fixture_device_ids = array();
        $this->fixture_subscription_ids = array();
        $this->fixture_metric_ids = array();

        if ($remaining !== 0) {
            throw new Exception("Fixture cleanup left {$remaining} owned row(s) behind");
        }
    }

    /**
     * Create an isolated plugin device while leaving the referenced Cacti host
     * untouched. The host must not already own a gNMI device because host_id is
     * unique in plugin_gnmi_devices.
     */
    protected function createFixtureDevice() {
        $host_id = db_fetch_cell(
            'SELECT h.id FROM host AS h ' .
            'LEFT JOIN plugin_gnmi_devices AS gd ON gd.host_id = h.id ' .
            'WHERE gd.id IS NULL ORDER BY h.id LIMIT 1'
        );

        if (empty($host_id)) {
            throw new Exception('Schema fixture requires an existing Cacti host without a gNMI device');
        }

        $ordinal = count($this->fixture_device_ids) + 1;
        $hostname = $this->fixture_token . '-' . $ordinal . '.invalid';
        $created = db_execute_prepared(
            'INSERT INTO plugin_gnmi_devices ' .
            '(host_id, enabled, hostname, port, username, password, use_tls, skip_verify, collection_interval, encoding) ' .
            'VALUES (?, 1, ?, 9339, ?, ?, 1, 0, 10, ?)',
            array($host_id, $hostname, $this->fixture_token, $this->fixture_token, 'JSON_IETF')
        );

        if (!$created) {
            throw new Exception('Failed to create fixture device');
        }

        $device_id = (int) db_fetch_cell('SELECT LAST_INSERT_ID()');
        if ($device_id < 1) {
            throw new Exception('Fixture device insert did not return an ID');
        }

        $this->fixture_device_ids[$device_id] = $hostname;
        return $device_id;
    }

    /**
     * Create a subscription using only required columns, allowing default-value
     * tests to inspect values supplied by the database itself.
     */
    protected function createFixtureSubscription($device_id) {
        $ordinal = count($this->fixture_subscription_ids) + 1;
        $marker = $this->fixture_token . '_' . $ordinal;
        $created = db_execute_prepared(
            'INSERT INTO plugin_gnmi_subscriptions ' .
            '(device_id, subscription_path, instance_identifier) VALUES (?, ?, ?)',
            array($device_id, '/test/' . $marker, $marker)
        );

        if (!$created) {
            throw new Exception('Failed to create fixture subscription');
        }

        $subscription_id = (int) db_fetch_cell('SELECT LAST_INSERT_ID()');
        if ($subscription_id < 1) {
            throw new Exception('Fixture subscription insert did not return an ID');
        }

        $this->fixture_subscription_ids[$subscription_id] = $marker;
        return $subscription_id;
    }

    /**
     * Create a metric using only required columns. Names remain unique within a
     * subscription and carry the fixture ownership marker used during cleanup.
     */
    protected function createFixtureMetric($subscription_id) {
        $ordinal = count($this->fixture_metric_ids) + 1;
        $metric_name = $this->fixture_token . '_' . $ordinal;
        $cacti_field_name = 't' . substr(sha1($metric_name), 0, 18);
        $created = db_execute_prepared(
            'INSERT INTO plugin_gnmi_metrics ' .
            '(subscription_id, metric_name, cacti_field_name) VALUES (?, ?, ?)',
            array($subscription_id, $metric_name, $cacti_field_name)
        );

        if (!$created) {
            throw new Exception('Failed to create fixture metric');
        }

        $metric_id = (int) db_fetch_cell('SELECT LAST_INSERT_ID()');
        if ($metric_id < 1) {
            throw new Exception('Fixture metric insert did not return an ID');
        }

        $this->fixture_metric_ids[$metric_id] = $metric_name;
        return $metric_id;
    }

    protected function getFixtureToken() {
        return $this->fixture_token;
    }

    private function getColumnMetadata($table_name) {
        $rows = db_fetch_assoc_prepared(
            'SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, ' .
            'CHARACTER_MAXIMUM_LENGTH, EXTRA ' .
            'FROM information_schema.COLUMNS ' .
            'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            array($table_name)
        );

        if (!is_array($rows) || empty($rows)) {
            throw new Exception("Failed to read column metadata for {$table_name}");
        }

        $columns = array();
        foreach ($rows as $row) {
            $columns[$row['COLUMN_NAME']] = $row;
        }

        return $columns;
    }

    private function requireColumn($columns, $table_name, $column_name) {
        if (!isset($columns[$column_name])) {
            throw new Exception("Missing {$table_name}.{$column_name}");
        }

        return $columns[$column_name];
    }

    private function enableStrictSqlMode() {
        $original_sql_mode = (string) db_fetch_cell('SELECT @@SESSION.sql_mode');
        $strict_sql_mode = $original_sql_mode;
        if (!preg_match('/(?:^|,)STRICT_(?:ALL|TRANS)_TABLES(?:,|$)/', $strict_sql_mode)) {
            $strict_sql_mode = trim($strict_sql_mode . ',STRICT_TRANS_TABLES', ',');
        }

        if (!db_execute_prepared('SET SESSION sql_mode = ?', array($strict_sql_mode))) {
            throw new Exception('Could not enable strict SQL mode for constraint validation');
        }

        $active_sql_mode = (string) db_fetch_cell('SELECT @@SESSION.sql_mode');
        if (!preg_match('/(?:^|,)STRICT_(?:ALL|TRANS)_TABLES(?:,|$)/', $active_sql_mode)) {
            db_execute_prepared('SET SESSION sql_mode = ?', array($original_sql_mode));
            throw new Exception('Strict SQL mode is required for constraint validation');
        }

        return $original_sql_mode;
    }

    private function restoreSqlMode($sql_mode) {
        if (!db_execute_prepared('SET SESSION sql_mode = ?', array($sql_mode))) {
            throw new Exception('Could not restore the original SQL mode after constraint validation');
        }
    }

    private function countTrackedFixtureRows() {
        $remaining = 0;

        foreach ($this->fixture_metric_ids as $metric_id => $metric_name) {
            $remaining += (int) db_fetch_cell_prepared(
                'SELECT COUNT(*) FROM plugin_gnmi_metrics WHERE id = ? AND metric_name = ?',
                array($metric_id, $metric_name)
            );
        }
        foreach ($this->fixture_subscription_ids as $subscription_id => $instance_identifier) {
            $remaining += (int) db_fetch_cell_prepared(
                'SELECT COUNT(*) FROM plugin_gnmi_subscriptions WHERE id = ? AND instance_identifier = ?',
                array($subscription_id, $instance_identifier)
            );
        }
        foreach ($this->fixture_device_ids as $device_id => $hostname) {
            $remaining += (int) db_fetch_cell_prepared(
                'SELECT COUNT(*) FROM plugin_gnmi_devices WHERE id = ? AND hostname = ?',
                array($device_id, $hostname)
            );
        }

        return $remaining;
    }

    /**
     * Capture the schema surfaces touched by the supported upgrade helpers.
     * Ordered information_schema rows make the snapshot safe to compare before
     * and after repeated helper execution without depending on SHOW CREATE
     * TABLE formatting differences between MySQL and MariaDB.
     */
    private function getSupportedSchemaSnapshot() {
        $tables = array(
            'plugin_gnmi_devices',
            'plugin_gnmi_subscriptions',
            'plugin_gnmi_metrics',
        );
        $placeholders = implode(',', array_fill(0, count($tables), '?'));

        $columns = db_fetch_assoc_prepared(
            'SELECT TABLE_NAME, COLUMN_NAME, ORDINAL_POSITION, COLUMN_TYPE, ' .
            'IS_NULLABLE, COLUMN_DEFAULT, EXTRA ' .
            'FROM information_schema.COLUMNS ' .
            "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ({$placeholders}) " .
            'ORDER BY TABLE_NAME, ORDINAL_POSITION',
            $tables
        );
        $indexes = db_fetch_assoc_prepared(
            'SELECT TABLE_NAME, INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, ' .
            'COLUMN_NAME, SUB_PART, INDEX_TYPE ' .
            'FROM information_schema.STATISTICS ' .
            "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ({$placeholders}) " .
            'ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX',
            $tables
        );
        $foreign_keys = db_fetch_assoc_prepared(
            'SELECT k.TABLE_NAME, k.CONSTRAINT_NAME, k.COLUMN_NAME, ' .
            'k.REFERENCED_TABLE_NAME, k.REFERENCED_COLUMN_NAME, ' .
            'r.UPDATE_RULE, r.DELETE_RULE ' .
            'FROM information_schema.KEY_COLUMN_USAGE AS k ' .
            'INNER JOIN information_schema.REFERENTIAL_CONSTRAINTS AS r ' .
            'ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA ' .
            'AND r.TABLE_NAME = k.TABLE_NAME ' .
            'AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME ' .
            "WHERE k.TABLE_SCHEMA = DATABASE() AND k.TABLE_NAME IN ({$placeholders}) " .
            'AND k.REFERENCED_TABLE_NAME IS NOT NULL ' .
            'ORDER BY k.TABLE_NAME, k.CONSTRAINT_NAME, k.ORDINAL_POSITION',
            $tables
        );

        if (!is_array($columns) || !is_array($indexes) || !is_array($foreign_keys)) {
            throw new Exception('Failed to capture supported schema snapshot');
        }

        return array(
            'columns' => $columns,
            'indexes' => $indexes,
            'foreign_keys' => $foreign_keys,
        );
    }

    /**
     * Ensure helper-managed definitions occur exactly once. This detects a
     * helper accidentally adding duplicate columns/indexes/foreign keys even if
     * the resulting schema remains queryable.
     */
    private function assertSupportedSchemaDefinitionsUnique() {
        $columns = array(
            array('plugin_gnmi_devices', 'hostname_source'),
            array('plugin_gnmi_devices', 'compatibility_mode'),
            array('plugin_gnmi_subscriptions', 'auto_create_graphs'),
            array('plugin_gnmi_metrics', 'metric_group'),
            array('plugin_gnmi_metrics', 'metric_direction'),
            array('plugin_gnmi_metrics', 'metric_graph_key'),
            array('plugin_gnmi_metrics', 'graph_created'),
        );
        foreach ($columns as $column) {
            $count = (int) db_fetch_cell_prepared(
                'SELECT COUNT(*) FROM information_schema.COLUMNS ' .
                'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                $column
            );
            if ($count !== 1) {
                throw new Exception("Expected exactly one {$column[0]}.{$column[1]} column, found {$count}");
            }
        }

        $graph_index_columns = db_fetch_assoc(
            "SELECT COLUMN_NAME FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = 'plugin_gnmi_metrics'
            AND INDEX_NAME = 'idx_metric_graph_key'
            ORDER BY SEQ_IN_INDEX"
        );
        $graph_index_signature = is_array($graph_index_columns)
            ? array_column($graph_index_columns, 'COLUMN_NAME')
            : array();
        if ($graph_index_signature !== array('subscription_id', 'metric_graph_key', 'metric_direction')) {
            throw new Exception('idx_metric_graph_key must exist exactly once with the expected columns');
        }

        $foreign_keys = array(
            array('plugin_gnmi_subscriptions', 'device_id', 'plugin_gnmi_devices', 'id'),
            array('plugin_gnmi_metrics', 'subscription_id', 'plugin_gnmi_subscriptions', 'id'),
        );
        foreach ($foreign_keys as $foreign_key) {
            $count = (int) db_fetch_cell_prepared(
                'SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE ' .
                'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? ' .
                'AND REFERENCED_TABLE_NAME = ? AND REFERENCED_COLUMN_NAME = ?',
                $foreign_key
            );
            if ($count !== 1) {
                throw new Exception("Expected exactly one foreign key for {$foreign_key[0]}.{$foreign_key[1]}, found {$count}");
            }
        }
    }

    private function getOwnedFixtureState($device_id, $subscription_id, $metric_id) {
        return array(
            'device' => db_fetch_row_prepared(
                'SELECT * FROM plugin_gnmi_devices WHERE id = ? AND hostname = ?',
                array($device_id, $this->fixture_device_ids[$device_id])
            ),
            'subscription' => db_fetch_row_prepared(
                'SELECT * FROM plugin_gnmi_subscriptions WHERE id = ? AND instance_identifier = ?',
                array($subscription_id, $this->fixture_subscription_ids[$subscription_id])
            ),
            'metric' => db_fetch_row_prepared(
                'SELECT * FROM plugin_gnmi_metrics WHERE id = ? AND metric_name = ?',
                array($metric_id, $this->fixture_metric_ids[$metric_id])
            ),
        );
    }

    /**
     * Test 1: plugin_gnmi_subscriptions table creation
     */
    public function testSubscriptionsTableCreation() {
        // Verify table exists (validation mode)
        $result = db_fetch_assoc('SELECT * FROM plugin_gnmi_subscriptions LIMIT 1');
        if ($result === false) {
            throw new Exception('plugin_gnmi_subscriptions table should exist');
        }
        return true;
    }

    /**
     * Test 2: Verify all columns exist with correct types
     */
    public function testSubscriptionsTableColumns() {
        $columns = db_fetch_assoc('DESCRIBE plugin_gnmi_subscriptions');
        if (!is_array($columns)) {
            throw new Exception('Failed to describe plugin_gnmi_subscriptions table');
        }
        $column_names = array_column($columns, 'Field');

        $expected_columns = [
            'id', 'device_id', 'subscription_path', 'instance_identifier',
            'enabled', 'discovery_mode', 'auto_create_datasources',
            'last_discovery_time', 'discovery_status', 'notes',
            'created_on', 'modified_on'
        ];

        foreach ($expected_columns as $expected) {
            if (!in_array($expected, $column_names)) {
                throw new Exception("Column $expected should exist");
            }
        }
        return true;
    }

    /**
     * Test 3: Verify indexes are created
     */
    public function testSubscriptionsTableIndexes() {
        $indexes = db_fetch_assoc('SHOW INDEX FROM plugin_gnmi_subscriptions');
        if (!is_array($indexes)) {
            throw new Exception('Failed to get indexes from plugin_gnmi_subscriptions table');
        }
        $index_names = array_unique(array_column($indexes, 'Key_name'));

        $expected_indexes = ['PRIMARY', 'idx_device_id', 'idx_enabled', 'idx_discovery_mode'];

        foreach ($expected_indexes as $expected) {
            if (!in_array($expected, $index_names)) {
                throw new Exception("Index $expected should exist");
            }
        }
        return true;
    }

    /**
     * Test 4: Verify foreign key constraint
     */
    public function testSubscriptionsForeignKeyConstraint() {
        // Check foreign key exists
        $fks = db_fetch_assoc("
            SELECT
                CONSTRAINT_NAME,
                COLUMN_NAME,
                REFERENCED_TABLE_NAME,
                REFERENCED_COLUMN_NAME
            FROM information_schema.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = 'plugin_gnmi_subscriptions'
            AND REFERENCED_TABLE_NAME IS NOT NULL
        ");

        if (!is_array($fks)) {
            throw new Exception('Failed to get foreign key information');
        }

        if (count($fks) !== 1) {
            throw new Exception('Should have exactly one foreign key');
        }
        if ($fks[0]['COLUMN_NAME'] !== 'device_id') {
            throw new Exception('Foreign key should be on device_id column');
        }
        if ($fks[0]['REFERENCED_TABLE_NAME'] !== 'plugin_gnmi_devices') {
            throw new Exception('Foreign key should reference plugin_gnmi_devices table');
        }
        if ($fks[0]['REFERENCED_COLUMN_NAME'] !== 'id') {
            throw new Exception('Foreign key should reference id column');
        }
        return true;
    }

    /**
     * Test 5: Verify CASCADE delete works
     */
    public function testSubscriptionsCascadeDelete() {
        $device_id = $this->createFixtureDevice();
        $subscription_id = $this->createFixtureSubscription($device_id);

        if ((int) db_fetch_cell_prepared(
            'SELECT COUNT(*) FROM plugin_gnmi_subscriptions WHERE id = ? AND device_id = ?',
            array($subscription_id, $device_id)
        ) !== 1) {
            throw new Exception('Subscription cascade fixture was not created');
        }

        $deleted = db_execute_prepared(
            'DELETE FROM plugin_gnmi_devices WHERE id = ? AND hostname = ?',
            array($device_id, $this->fixture_device_ids[$device_id])
        );
        if (!$deleted) {
            throw new Exception('Failed to delete owned fixture device');
        }

        if ((int) db_fetch_cell_prepared(
            'SELECT COUNT(*) FROM plugin_gnmi_subscriptions WHERE id = ?',
            array($subscription_id)
        ) !== 0) {
            throw new Exception('Deleting a device should cascade to its subscriptions');
        }

        return true;
    }

    /**
     * Test 6: Verify default values
     */
    public function testSubscriptionsDefaultValues() {
        $device_id = $this->createFixtureDevice();
        $subscription_id = $this->createFixtureSubscription($device_id);
        $row = db_fetch_row_prepared(
            'SELECT enabled, discovery_mode, auto_create_datasources, auto_create_graphs, ' .
            'last_discovery_time, discovery_status, notes, created_on, modified_on ' .
            'FROM plugin_gnmi_subscriptions WHERE id = ?',
            array($subscription_id)
        );

        if (!is_array($row) || empty($row)) {
            throw new Exception('Failed to fetch subscription defaults');
        }

        $expected = array(
            'enabled' => '1',
            'discovery_mode' => 'manual',
            'auto_create_datasources' => '1',
            'auto_create_graphs' => '1',
        );
        foreach ($expected as $column => $value) {
            if ((string) $row[$column] !== $value) {
                throw new Exception("Unexpected default for {$column}: " . var_export($row[$column], true));
            }
        }

        foreach (array('last_discovery_time', 'discovery_status', 'notes') as $column) {
            if ($row[$column] !== null) {
                throw new Exception("Expected {$column} to default to NULL");
            }
        }
        if (empty($row['created_on']) || empty($row['modified_on'])) {
            throw new Exception('Subscription timestamps should be populated by default');
        }

        return true;
    }

    /**
     * Test 7: plugin_gnmi_metrics table recreation
     */
    public function testMetricsTableRecreation() {
        // Verify old table is dropped (if it existed) and new one created
        $old_table_exists = db_table_exists('plugin_gnmi_device_metrics');
        if ($old_table_exists) {
            throw new Exception('Expected plugin_gnmi_device_metrics to be dropped');
        }

        // Verify new table exists
        $new_table_exists = db_table_exists('plugin_gnmi_metrics');
        if (!$new_table_exists) {
            throw new Exception('New plugin_gnmi_metrics table should exist');
        }

        // Verify new table has data (can query it)
        $result = db_fetch_assoc('SELECT * FROM plugin_gnmi_metrics LIMIT 1');
        if ($result === false) {
            throw new Exception('New plugin_gnmi_metrics table should be queryable');
        }

        return true;
    }

    /**
     * Test 8: Verify metrics table has correct structure
     */
    public function testMetricsTableStructure() {
        $columns = db_fetch_assoc('DESCRIBE plugin_gnmi_metrics');
        if (!is_array($columns)) {
            throw new Exception('Failed to describe plugin_gnmi_metrics table');
        }
        $column_names = array_column($columns, 'Field');

        $expected_columns = [
            'id', 'subscription_id', 'metric_name', 'cacti_field_name',
            'rrd_type', 'rrd_heartbeat', 'rrd_min', 'rrd_max',
            'enabled', 'discovered', 'datasource_created',
            'local_data_id', 'graph_local_id', 'created_on', 'modified_on'
        ];

        foreach ($expected_columns as $expected) {
            if (!in_array($expected, $column_names)) {
                throw new Exception("Column $expected should exist");
            }
        }
        return true;
    }

    /**
     * Test 9: Verify unique constraint on subscription_id + metric_name
     */
    public function testMetricsUniqueConstraint() {
        $device_id = $this->createFixtureDevice();
        $first_subscription_id = $this->createFixtureSubscription($device_id);
        $second_subscription_id = $this->createFixtureSubscription($device_id);
        $first_metric_id = $this->createFixtureMetric($first_subscription_id);
        $metric_name = $this->fixture_metric_ids[$first_metric_id];

        $duplicate_created = db_execute_prepared(
            'INSERT INTO plugin_gnmi_metrics ' .
            '(subscription_id, metric_name, cacti_field_name) VALUES (?, ?, ?)',
            array($first_subscription_id, $metric_name, 'duplicate_test')
        );
        if ($duplicate_created) {
            $duplicate_id = (int) db_fetch_cell('SELECT LAST_INSERT_ID()');
            if ($duplicate_id > 0) {
                $this->fixture_metric_ids[$duplicate_id] = $metric_name;
            }
            throw new Exception('Duplicate metric name within a subscription should be rejected');
        }

        $same_name_created = db_execute_prepared(
            'INSERT INTO plugin_gnmi_metrics ' .
            '(subscription_id, metric_name, cacti_field_name) VALUES (?, ?, ?)',
            array($second_subscription_id, $metric_name, 'same_name_allowed')
        );
        if (!$same_name_created) {
            throw new Exception('The same metric name should be allowed under another subscription');
        }
        $second_metric_id = (int) db_fetch_cell('SELECT LAST_INSERT_ID()');
        $this->fixture_metric_ids[$second_metric_id] = $metric_name;

        if ((int) db_fetch_cell_prepared(
            'SELECT COUNT(*) FROM plugin_gnmi_metrics WHERE metric_name = ? ' .
            'AND subscription_id IN (?, ?)',
            array($metric_name, $first_subscription_id, $second_subscription_id)
        ) !== 2) {
            throw new Exception('Expected one same-named metric in each subscription');
        }

        return true;
    }

    /**
     * Test 10: Verify foreign key to subscriptions with CASCADE
     */
    public function testMetricsForeignKeyCascade() {
        $device_id = $this->createFixtureDevice();
        $deleted_subscription_id = $this->createFixtureSubscription($device_id);
        $preserved_subscription_id = $this->createFixtureSubscription($device_id);
        $deleted_metric_id = $this->createFixtureMetric($deleted_subscription_id);
        $preserved_metric_id = $this->createFixtureMetric($preserved_subscription_id);

        $deleted = db_execute_prepared(
            'DELETE FROM plugin_gnmi_subscriptions WHERE id = ? AND instance_identifier = ?',
            array($deleted_subscription_id, $this->fixture_subscription_ids[$deleted_subscription_id])
        );
        if (!$deleted) {
            throw new Exception('Failed to delete owned fixture subscription');
        }

        if ((int) db_fetch_cell_prepared(
            'SELECT COUNT(*) FROM plugin_gnmi_metrics WHERE id = ?',
            array($deleted_metric_id)
        ) !== 0) {
            throw new Exception('Deleting a subscription should cascade to its metrics');
        }
        if ((int) db_fetch_cell_prepared(
            'SELECT COUNT(*) FROM plugin_gnmi_metrics WHERE id = ?',
            array($preserved_metric_id)
        ) !== 1) {
            throw new Exception('Cascade delete removed a metric owned by another subscription');
        }

        return true;
    }

    /**
     * Test 11: Verify supported schema helpers are idempotent.
     *
     * This intentionally does not rerun plugin_gnmi_install() against the
     * populated test database. Full clean-install/reinstall coverage belongs in
     * the isolated lifecycle suite; this test covers the schema routines that
     * plugin_gnmi_upgrade() currently invokes.
     */
    public function testSupportedSchemaHelperIdempotence() {
        global $config;

        if (!function_exists('gnmi_apply_hostname_source_schema') ||
            !function_exists('gnmi_apply_compatibility_mode_schema') ||
            !function_exists('gnmi_apply_schema_34')) {
            require_once($config['base_path'] . '/plugins/gnmi/setup.php');
        }

        $device_id = $this->createFixtureDevice();
        $subscription_id = $this->createFixtureSubscription($device_id);
        $metric_id = $this->createFixtureMetric($subscription_id);

        $fixture_before = $this->getOwnedFixtureState($device_id, $subscription_id, $metric_id);
        $schema_before = $this->getSupportedSchemaSnapshot();
        $this->assertSupportedSchemaDefinitionsUnique();

        for ($run = 1; $run <= 2; $run++) {
            if (gnmi_apply_hostname_source_schema() !== true) {
                throw new Exception("Hostname-source schema helper failed on run {$run}");
            }
            if (gnmi_apply_compatibility_mode_schema() !== true) {
                throw new Exception("Compatibility-mode schema helper failed on run {$run}");
            }

            // gnmi_apply_schema_34() has no return value; its success is checked
            // through the complete schema snapshot and definition assertions.
            gnmi_apply_schema_34();
            $this->assertSupportedSchemaDefinitionsUnique();

            $schema_after = $this->getSupportedSchemaSnapshot();
            if ($schema_after !== $schema_before) {
                throw new Exception("Supported schema changed on idempotence run {$run}");
            }

            $fixture_after = $this->getOwnedFixtureState($device_id, $subscription_id, $metric_id);
            if ($fixture_after !== $fixture_before) {
                throw new Exception("Owned fixture data changed on idempotence run {$run}");
            }
        }

        return true;
    }

    /**
     * Test 12: Verify the supported removal lifecycle can drop the live schema
     * in dependency-safe order.
     *
     * This is deliberately not called a downgrade test: the plugin does not
     * provide an automated schema downgrade. Executing plugin_gnmi_uninstall()
     * here would destroy the shared test installation, so this assertion
     * compares the live foreign-key graph with the production uninstall code.
     * Destructive behavior and abort safeguards are exercised by the isolated
     * test_plugin_uninstall_* scripts.
     */
    public function testSupportedUninstallDependencyOrder() {
        global $config;

        $setup_path = $config['base_path'] . '/plugins/gnmi/setup.php';
        $setup = file_get_contents($setup_path);
        if ($setup === false) {
            throw new Exception("Could not read production setup file: {$setup_path}");
        }

        $start = strpos($setup, 'function plugin_gnmi_uninstall()');
        $end = strpos($setup, 'function plugin_gnmi_upgrade()', $start);
        if ($start === false || $end === false || $end <= $start) {
            throw new Exception('Could not isolate plugin_gnmi_uninstall()');
        }
        $uninstall = substr($setup, $start, $end - $start);

        $foreign_keys = db_fetch_assoc(
            "SELECT TABLE_NAME, REFERENCED_TABLE_NAME " .
            "FROM information_schema.KEY_COLUMN_USAGE " .
            "WHERE TABLE_SCHEMA = DATABASE() " .
            "AND REFERENCED_TABLE_NAME IS NOT NULL " .
            "AND TABLE_NAME LIKE 'plugin\\_gnmi\\_%'"
        );
        if (!is_array($foreign_keys) || empty($foreign_keys)) {
            throw new Exception('Expected the installed plugin schema to contain foreign keys');
        }

        $plugin_dependency_count = 0;
        foreach ($foreign_keys as $foreign_key) {
            $child = $foreign_key['TABLE_NAME'];
            $parent = $foreign_key['REFERENCED_TABLE_NAME'];
            if (strpos($parent, 'plugin_gnmi_') !== 0) {
                continue;
            }

            $plugin_dependency_count++;
            $child_position = strpos($uninstall, 'DROP TABLE IF EXISTS `' . $child . '`');
            $parent_position = strpos($uninstall, 'DROP TABLE IF EXISTS `' . $parent . '`');
            if ($child_position === false || $parent_position === false) {
                throw new Exception("Uninstall is missing a DROP for dependency {$child} -> {$parent}");
            }
            if ($child_position >= $parent_position) {
                throw new Exception("Uninstall must drop child {$child} before parent {$parent}");
            }
        }

        if ($plugin_dependency_count === 0) {
            throw new Exception('Expected at least one foreign key between plugin tables');
        }

        return true;
    }

    /**
     * Test 13: Verify data type constraints
     */
    public function testDataTypes() {
        $devices = $this->getColumnMetadata('plugin_gnmi_devices');
        $subscriptions = $this->getColumnMetadata('plugin_gnmi_subscriptions');
        $metrics = $this->getColumnMetadata('plugin_gnmi_metrics');

        foreach (array('id', 'device_id') as $column_name) {
            $column = $this->requireColumn($subscriptions, 'plugin_gnmi_subscriptions', $column_name);
            if (!preg_match('/^int(?:\(\d+\))? unsigned$/i', $column['COLUMN_TYPE'])) {
                throw new Exception("Expected unsigned INT for subscriptions.{$column_name}, got {$column['COLUMN_TYPE']}");
            }
        }
        foreach (array('id', 'subscription_id', 'rrd_heartbeat', 'local_data_id', 'graph_local_id') as $column_name) {
            $column = $this->requireColumn($metrics, 'plugin_gnmi_metrics', $column_name);
            if (!preg_match('/^int(?:\(\d+\))? unsigned$/i', $column['COLUMN_TYPE'])) {
                throw new Exception("Expected unsigned INT for metrics.{$column_name}, got {$column['COLUMN_TYPE']}");
            }
        }

        $enum_types = array(
            array($devices, 'plugin_gnmi_devices', 'compatibility_mode', "enum('standard','ciena_saos10')"),
            array($subscriptions, 'plugin_gnmi_subscriptions', 'discovery_mode', "enum('manual','discovered','template')"),
            array($subscriptions, 'plugin_gnmi_subscriptions', 'discovery_status', "enum('pending','success','failed')"),
            array($metrics, 'plugin_gnmi_metrics', 'rrd_type', "enum('counter','gauge','derive','absolute')"),
            array($metrics, 'plugin_gnmi_metrics', 'metric_group', "enum('traffic','packets','errors','discards','generic')"),
            array($metrics, 'plugin_gnmi_metrics', 'metric_direction', "enum('inbound','outbound','none')"),
        );
        foreach ($enum_types as $expectation) {
            $column = $this->requireColumn($expectation[0], $expectation[1], $expectation[2]);
            if (strtolower($column['COLUMN_TYPE']) !== $expectation[3]) {
                throw new Exception("Unexpected enum domain for {$expectation[1]}.{$expectation[2]}: {$column['COLUMN_TYPE']}");
            }
        }

		$compatibility_mode = $this->requireColumn($devices, 'plugin_gnmi_devices', 'compatibility_mode');
		if ($compatibility_mode['IS_NULLABLE'] !== 'NO' ||
			trim((string) $compatibility_mode['COLUMN_DEFAULT'], "'\"") !== 'standard') {
			throw new Exception('Compatibility mode must be non-null with standard as its default');
		}

        $boolean_columns = array(
            array($subscriptions, 'plugin_gnmi_subscriptions', 'enabled', '1'),
            array($subscriptions, 'plugin_gnmi_subscriptions', 'auto_create_datasources', '1'),
            array($subscriptions, 'plugin_gnmi_subscriptions', 'auto_create_graphs', '1'),
            array($metrics, 'plugin_gnmi_metrics', 'enabled', '1'),
            array($metrics, 'plugin_gnmi_metrics', 'discovered', '0'),
            array($metrics, 'plugin_gnmi_metrics', 'datasource_created', '0'),
            array($metrics, 'plugin_gnmi_metrics', 'graph_created', '0'),
        );
        foreach ($boolean_columns as $expectation) {
            $column = $this->requireColumn($expectation[0], $expectation[1], $expectation[2]);
            if (!preg_match('/^tinyint(?:\(1\))?$/i', $column['COLUMN_TYPE']) ||
                $column['IS_NULLABLE'] !== 'NO' ||
                (string) $column['COLUMN_DEFAULT'] !== $expectation[3]) {
                throw new Exception("Unexpected boolean definition for {$expectation[1]}.{$expectation[2]}");
            }
        }

        foreach (array('local_data_id', 'graph_local_id') as $column_name) {
            $column = $this->requireColumn($metrics, 'plugin_gnmi_metrics', $column_name);
            $has_null_default = $column['COLUMN_DEFAULT'] === null ||
                strtoupper((string) $column['COLUMN_DEFAULT']) === 'NULL';
            if ($column['IS_NULLABLE'] !== 'YES' || !$has_null_default) {
                throw new Exception("Expected metrics.{$column_name} to be nullable with a NULL default");
            }
        }

        $device_id = $this->createFixtureDevice();
        $subscription_id = $this->createFixtureSubscription($device_id);
        $invalid_enum_inserts = array(
            array(
                'INSERT INTO plugin_gnmi_subscriptions ' .
                '(device_id, subscription_path, instance_identifier, discovery_mode) VALUES (?, ?, ?, ?)',
                array($device_id, '/test/invalid-discovery-mode', $this->fixture_token . '_bad_mode', 'invalid'),
                'subscription',
                'subscriptions.discovery_mode',
            ),
            array(
                'INSERT INTO plugin_gnmi_subscriptions ' .
                '(device_id, subscription_path, instance_identifier, discovery_status) VALUES (?, ?, ?, ?)',
                array($device_id, '/test/invalid-discovery-status', $this->fixture_token . '_bad_status', 'invalid'),
                'subscription',
                'subscriptions.discovery_status',
            ),
            array(
                'INSERT INTO plugin_gnmi_metrics ' .
                '(subscription_id, metric_name, cacti_field_name, rrd_type) VALUES (?, ?, ?, ?)',
                array($subscription_id, $this->fixture_token . '_bad_rrd', 'bad_rrd', 'invalid'),
                'metric',
                'metrics.rrd_type',
            ),
            array(
                'INSERT INTO plugin_gnmi_metrics ' .
                '(subscription_id, metric_name, cacti_field_name, metric_group) VALUES (?, ?, ?, ?)',
                array($subscription_id, $this->fixture_token . '_bad_group', 'bad_group', 'invalid'),
                'metric',
                'metrics.metric_group',
            ),
            array(
                'INSERT INTO plugin_gnmi_metrics ' .
                '(subscription_id, metric_name, cacti_field_name, metric_direction) VALUES (?, ?, ?, ?)',
                array($subscription_id, $this->fixture_token . '_bad_direction', 'bad_direction', 'invalid'),
                'metric',
                'metrics.metric_direction',
            ),
        );

        $original_sql_mode = $this->enableStrictSqlMode();
        try {
            foreach ($invalid_enum_inserts as $invalid_insert) {
                db_execute_prepared($invalid_insert[0], $invalid_insert[1]);
                if ($invalid_insert[2] === 'subscription') {
                    $unexpected_id = (int) db_fetch_cell_prepared(
                        'SELECT id FROM plugin_gnmi_subscriptions WHERE instance_identifier = ?',
                        array($invalid_insert[1][2])
                    );
                    if ($unexpected_id > 0) {
                        $this->fixture_subscription_ids[$unexpected_id] = $invalid_insert[1][2];
                    }
                } else {
                    $unexpected_id = (int) db_fetch_cell_prepared(
                        'SELECT id FROM plugin_gnmi_metrics WHERE subscription_id = ? AND metric_name = ?',
                        array($subscription_id, $invalid_insert[1][1])
                    );
                    if ($unexpected_id > 0) {
                        $this->fixture_metric_ids[$unexpected_id] = $invalid_insert[1][1];
                    }
                }

                if ($unexpected_id > 0) {
                    throw new Exception(
                        "Strict SQL mode should reject values outside the {$invalid_insert[3]} ENUM domain"
                    );
                }
            }
        } finally {
            $this->restoreSqlMode($original_sql_mode);
        }

        return true;
    }

    /**
     * Test 14: Verify field length constraints
     */
    public function testFieldLengthConstraints() {
        $subscriptions = $this->getColumnMetadata('plugin_gnmi_subscriptions');
        $metrics = $this->getColumnMetadata('plugin_gnmi_metrics');
        $lengths = array(
            array($subscriptions, 'plugin_gnmi_subscriptions', 'instance_identifier', 100),
            array($metrics, 'plugin_gnmi_metrics', 'metric_name', 255),
            array($metrics, 'plugin_gnmi_metrics', 'cacti_field_name', 19),
            array($metrics, 'plugin_gnmi_metrics', 'rrd_min', 20),
            array($metrics, 'plugin_gnmi_metrics', 'rrd_max', 20),
            array($metrics, 'plugin_gnmi_metrics', 'metric_graph_key', 128),
        );
        foreach ($lengths as $expectation) {
            $column = $this->requireColumn($expectation[0], $expectation[1], $expectation[2]);
            if ((int) $column['CHARACTER_MAXIMUM_LENGTH'] !== $expectation[3]) {
                throw new Exception("Expected {$expectation[1]}.{$expectation[2]} length {$expectation[3]}, got {$column['CHARACTER_MAXIMUM_LENGTH']}");
            }
        }

        $device_id = $this->createFixtureDevice();
        $instance_identifier = str_pad($this->fixture_token, 100, 's');
        $created = db_execute_prepared(
            'INSERT INTO plugin_gnmi_subscriptions ' .
            '(device_id, subscription_path, instance_identifier) VALUES (?, ?, ?)',
            array($device_id, '/test/field-boundary', $instance_identifier)
        );
        if (!$created) {
            throw new Exception('Maximum-length subscription field should be accepted');
        }
        $subscription_id = (int) db_fetch_cell('SELECT LAST_INSERT_ID()');
        $this->fixture_subscription_ids[$subscription_id] = $instance_identifier;

        $metric_name = str_pad($this->fixture_token, 255, 'm');
        $cacti_field_name = str_pad('f', 19, 'f');
        $rrd_min = str_pad('0', 20, '0');
        $rrd_max = str_pad('U', 20, 'U');
        $metric_graph_key = str_pad($this->fixture_token, 128, 'g');
        $created = db_execute_prepared(
            'INSERT INTO plugin_gnmi_metrics ' .
            '(subscription_id, metric_name, cacti_field_name, rrd_min, rrd_max, metric_graph_key) ' .
            'VALUES (?, ?, ?, ?, ?, ?)',
            array($subscription_id, $metric_name, $cacti_field_name, $rrd_min, $rrd_max, $metric_graph_key)
        );
        if (!$created) {
            throw new Exception('Maximum-length metric fields should be accepted');
        }
        $metric_id = (int) db_fetch_cell('SELECT LAST_INSERT_ID()');
        $this->fixture_metric_ids[$metric_id] = $metric_name;

        $original_sql_mode = $this->enableStrictSqlMode();
        try {
            $oversized_identifier = str_pad($this->fixture_token, 101, 'x');
            db_execute_prepared(
                'INSERT INTO plugin_gnmi_subscriptions ' .
                '(device_id, subscription_path, instance_identifier) VALUES (?, ?, ?)',
                array($device_id, '/test/field-oversized', $oversized_identifier)
            );
            $oversized_id = (int) db_fetch_cell_prepared(
                'SELECT id FROM plugin_gnmi_subscriptions WHERE device_id = ? AND subscription_path = ?',
                array($device_id, '/test/field-oversized')
            );
            if ($oversized_id > 0) {
                $stored_identifier = (string) db_fetch_cell_prepared(
                    'SELECT instance_identifier FROM plugin_gnmi_subscriptions WHERE id = ?',
                    array($oversized_id)
                );
                $this->fixture_subscription_ids[$oversized_id] = $stored_identifier;
                throw new Exception('Strict SQL mode should reject an oversized VARCHAR value');
            }
        } finally {
            $this->restoreSqlMode($original_sql_mode);
        }

        return true;
    }

    /**
     * Test 15: Verify auto-increment behavior
     */
    public function testAutoIncrementBehavior() {
        $device_id = $this->createFixtureDevice();
        $first_subscription_id = $this->createFixtureSubscription($device_id);
        $second_subscription_id = $this->createFixtureSubscription($device_id);
        $first_metric_id = $this->createFixtureMetric($first_subscription_id);
        $second_metric_id = $this->createFixtureMetric($second_subscription_id);

        foreach (array($device_id, $first_subscription_id, $second_subscription_id, $first_metric_id, $second_metric_id) as $id) {
            if ($id < 1) {
                throw new Exception('Auto-increment IDs must be positive');
            }
        }
        if ($first_subscription_id === $second_subscription_id || $first_metric_id === $second_metric_id) {
            throw new Exception('Auto-increment IDs must be distinct within each table');
        }

        db_execute_prepared(
            'DELETE FROM plugin_gnmi_subscriptions WHERE id = ? AND instance_identifier = ?',
            array($second_subscription_id, $this->fixture_subscription_ids[$second_subscription_id])
        );
        $third_subscription_id = $this->createFixtureSubscription($device_id);
        $third_metric_id = $this->createFixtureMetric($third_subscription_id);

        if ($third_subscription_id === $second_subscription_id) {
            throw new Exception('Subscription auto-increment reused a just-deleted ID');
        }
        if ($third_metric_id === $second_metric_id) {
            throw new Exception('Metric auto-increment reused a cascade-deleted ID');
        }

        return true;
    }
}

// Run tests if called directly
if (basename(__FILE__) == basename($_SERVER['SCRIPT_NAME'])) {
    // Simple test runner for development
    $test = new TestSchemaMigration();
    $reflection = new ReflectionClass($test);

    echo "Running Phase 3.3 Schema Migration Tests...\n";
    echo "==========================================\n";

    $passed = 0;
    $skipped = 0;
    $failed = 0;

    foreach ($reflection->getMethods() as $method) {
        if (strpos($method->getName(), 'test') === 0) {
            echo "Running {$method->getName()}... ";

            $result = null;
            $test_failure = null;
            $teardown_failure = null;

            try {
                $test->setUp();
                $result = $method->invoke($test);
            } catch (Throwable $e) {
                $test_failure = $e;
            }

            // Teardown is attempted even when setup or the test itself throws.
            // Capture its failure separately so it cannot hide the first error.
            try {
                $test->tearDown();
            } catch (Throwable $e) {
                $teardown_failure = $e;
            }

            if ($test_failure !== null || $teardown_failure !== null) {
                $messages = array();
                if ($test_failure !== null) {
                    $messages[] = $test_failure->getMessage();
                }
                if ($teardown_failure !== null) {
                    $messages[] = 'teardown failed: ' . $teardown_failure->getMessage();
                }
                echo 'FAIL: ' . implode('; ', $messages) . "\n";
                $failed++;
            } elseif ($result === true) {
                echo "PASS\n";
                $passed++;
            } elseif (is_string($result) && strpos($result, 'SKIP') === 0) {
                $reason = trim(substr($result, 4));
                $reason = ltrim($reason, ": -\t");
                if ($reason === '') {
                    $reason = 'no reason provided';
                }
                echo "SKIP (unexpected): {$reason}\n";
                $skipped++;
            } else {
                echo 'FAIL: unexpected return value (' . gettype($result) . ")\n";
                $failed++;
            }
        }
    }

    echo "\nTest Results:\n";
    echo "=============\n";
    echo "Passed: $passed\n";
    echo "Skipped: $skipped\n";
    echo "Failed: $failed\n";
    echo "\nTest run complete.\n";

    exit(($failed > 0 || $skipped > 0) ? 1 : 0);
}

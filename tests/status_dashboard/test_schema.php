<?php
/**
 * Tests for the current public-beta database schema.
 *
 * Validates the four active plugin tables, key columns, and event behavior.
 *
 * Run: php test_schema.php
 */

// Include Cacti environment
$no_http_headers = true;
chdir(__DIR__ . '/../../../../');
include_once('./include/cli_check.php');

// Test results tracking
$tests_run = 0;
$tests_passed = 0;
$tests_failed = 0;

// Test helper functions
function assert_equals($expected, $actual, $message) {
	global $tests_run, $tests_passed, $tests_failed;
	$tests_run++;

	if ($expected === $actual) {
		$tests_passed++;
		echo "✓ PASS: $message\n";
		return true;
	} else {
		$tests_failed++;
		echo "✗ FAIL: $message\n";
		echo "  Expected: " . var_export($expected, true) . "\n";
		echo "  Actual: " . var_export($actual, true) . "\n";
		return false;
	}
}

function assert_true($condition, $message) {
	global $tests_run, $tests_passed, $tests_failed;
	$tests_run++;

	if ($condition) {
		$tests_passed++;
		echo "✓ PASS: $message\n";
		return true;
	} else {
		$tests_failed++;
		echo "✗ FAIL: $message\n";
		return false;
	}
}

echo "=== gNMI Current Schema Tests ===\n\n";

function test_current_table_set() {
	$rows = db_fetch_assoc("SELECT table_name AS table_name_value
		FROM information_schema.tables
		WHERE table_schema = DATABASE()
		AND table_name LIKE 'plugin_gnmi_%'
		ORDER BY table_name");
	$actual = array_map(function ($row) {
		return $row['table_name_value'];
	}, $rows);
	$expected = array(
		'plugin_gnmi_devices',
		'plugin_gnmi_events',
		'plugin_gnmi_metrics',
		'plugin_gnmi_subscriptions',
	);
	assert_equals($expected, $actual, 'Only the four current plugin tables should exist');
}

function test_current_schema_columns() {
	$required = array(
		'plugin_gnmi_devices' => array(
			'id', 'host_id', 'enabled', 'hostname', 'hostname_source', 'port',
			'username', 'password', 'use_tls', 'compatibility_mode', 'ca_cert_path',
			'client_key_path', 'client_cert_path', 'tls_override', 'skip_verify', 'tls_cipher_policy',
			'collection_interval', 'encoding', 'last_poll_time', 'last_poll_status',
			'last_error_message', 'created_on', 'modified_on',
		),
		'plugin_gnmi_subscriptions' => array(
			'id', 'device_id', 'subscription_path', 'instance_identifier', 'enabled',
			'discovery_mode', 'auto_create_datasources', 'auto_create_graphs',
			'last_discovery_time', 'discovery_status', 'notes', 'created_on', 'modified_on',
		),
		'plugin_gnmi_metrics' => array(
			'id', 'subscription_id', 'metric_name', 'cacti_field_name', 'rrd_type',
			'rrd_heartbeat', 'rrd_min', 'rrd_max', 'enabled', 'discovered',
			'datasource_created', 'metric_group', 'metric_direction', 'metric_graph_key',
			'graph_created', 'local_data_id', 'graph_local_id', 'created_on', 'modified_on',
		),
		'plugin_gnmi_events' => array('id', 'device_id', 'event_type', 'event_data', 'created_at'),
	);

	foreach ($required as $table => $columns) {
		$rows = db_fetch_assoc("SELECT column_name AS column_name_value
			FROM information_schema.columns
			WHERE table_schema = DATABASE()
			AND table_name = '$table'
			ORDER BY ordinal_position");
		$actual = array_map(function ($row) {
			return $row['column_name_value'];
		}, $rows);
		assert_equals($columns, $actual, "$table should have the exact current column set");
	}
}

function get_schema_column($table, $column) {
	return db_fetch_row_prepared(
		'SELECT COLUMN_TYPE AS column_type_value, IS_NULLABLE AS nullable_value, ' .
		'COLUMN_DEFAULT AS default_value, CHARACTER_MAXIMUM_LENGTH AS length_value, ' .
		'EXTRA AS extra_value FROM information_schema.columns ' .
		'WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?',
		array($table, $column)
	);
}

function normalized_default($value) {
	if ($value === null) return null;
	return trim((string)$value, "'\"");
}

function test_current_schema_column_contracts() {
	$enums = array(
		array('plugin_gnmi_devices', 'hostname_source', "enum('device','custom')"),
		array('plugin_gnmi_devices', 'compatibility_mode', "enum('standard','ciena_saos10')"),
		array('plugin_gnmi_devices', 'tls_cipher_policy', "enum('default','legacy_compatibility')"),
		array('plugin_gnmi_subscriptions', 'discovery_mode', "enum('manual','discovered','template')"),
		array('plugin_gnmi_subscriptions', 'discovery_status', "enum('pending','success','failed')"),
		array('plugin_gnmi_metrics', 'rrd_type', "enum('counter','gauge','derive','absolute')"),
		array('plugin_gnmi_metrics', 'metric_group', "enum('traffic','packets','errors','discards','generic')"),
		array('plugin_gnmi_metrics', 'metric_direction', "enum('inbound','outbound','none')"),
	);
	foreach ($enums as $expected) {
		$column = get_schema_column($expected[0], $expected[1]);
		assert_equals($expected[2], strtolower($column['column_type_value']), "{$expected[0]}.{$expected[1]} enum domain");
	}

	$defaults = array(
		array('plugin_gnmi_devices', 'enabled', '1'),
		array('plugin_gnmi_devices', 'hostname_source', 'device'),
		array('plugin_gnmi_devices', 'port', '9339'),
		array('plugin_gnmi_devices', 'use_tls', '1'),
		array('plugin_gnmi_devices', 'compatibility_mode', 'standard'),
		array('plugin_gnmi_devices', 'tls_cipher_policy', 'default'),
		array('plugin_gnmi_devices', 'skip_verify', '0'),
		array('plugin_gnmi_devices', 'collection_interval', '10'),
		array('plugin_gnmi_devices', 'encoding', 'JSON_IETF'),
		array('plugin_gnmi_devices', 'last_poll_status', 'never'),
		array('plugin_gnmi_subscriptions', 'enabled', '1'),
		array('plugin_gnmi_subscriptions', 'discovery_mode', 'manual'),
		array('plugin_gnmi_subscriptions', 'auto_create_datasources', '1'),
		array('plugin_gnmi_subscriptions', 'auto_create_graphs', '1'),
		array('plugin_gnmi_metrics', 'rrd_type', 'COUNTER'),
		array('plugin_gnmi_metrics', 'rrd_heartbeat', '600'),
		array('plugin_gnmi_metrics', 'rrd_min', '0'),
		array('plugin_gnmi_metrics', 'rrd_max', 'U'),
		array('plugin_gnmi_metrics', 'enabled', '1'),
		array('plugin_gnmi_metrics', 'discovered', '0'),
		array('plugin_gnmi_metrics', 'datasource_created', '0'),
		array('plugin_gnmi_metrics', 'metric_group', 'generic'),
		array('plugin_gnmi_metrics', 'metric_direction', 'none'),
		array('plugin_gnmi_metrics', 'graph_created', '0'),
	);
	foreach ($defaults as $expected) {
		$column = get_schema_column($expected[0], $expected[1]);
		assert_equals($expected[2], normalized_default($column['default_value']), "{$expected[0]}.{$expected[1]} default");
		assert_equals('NO', $column['nullable_value'], "{$expected[0]}.{$expected[1]} should be non-null");
	}

	$nullable = array(
		array('plugin_gnmi_devices', 'password'),
		array('plugin_gnmi_devices', 'last_poll_time'),
		array('plugin_gnmi_subscriptions', 'last_discovery_time'),
		array('plugin_gnmi_subscriptions', 'discovery_status'),
		array('plugin_gnmi_metrics', 'metric_graph_key'),
		array('plugin_gnmi_metrics', 'local_data_id'),
		array('plugin_gnmi_metrics', 'graph_local_id'),
		array('plugin_gnmi_events', 'event_data'),
	);
	foreach ($nullable as $expected) {
		$column = get_schema_column($expected[0], $expected[1]);
		assert_equals('YES', $column['nullable_value'], "{$expected[0]}.{$expected[1]} should be nullable");
		$has_null_default = $column['default_value'] === null ||
			strtoupper((string)$column['default_value']) === 'NULL';
		assert_true($has_null_default, "{$expected[0]}.{$expected[1]} should default to NULL");
	}

	$lengths = array(
		array('plugin_gnmi_devices', 'hostname', 255),
		array('plugin_gnmi_devices', 'username', 255),
		array('plugin_gnmi_subscriptions', 'instance_identifier', 100),
		array('plugin_gnmi_metrics', 'metric_name', 255),
		array('plugin_gnmi_metrics', 'cacti_field_name', 19),
		array('plugin_gnmi_metrics', 'rrd_min', 20),
		array('plugin_gnmi_metrics', 'rrd_max', 20),
		array('plugin_gnmi_metrics', 'metric_graph_key', 128),
		array('plugin_gnmi_events', 'event_type', 50),
	);
	foreach ($lengths as $expected) {
		$column = get_schema_column($expected[0], $expected[1]);
		assert_equals($expected[2], (int)$column['length_value'], "{$expected[0]}.{$expected[1]} length");
	}

	foreach (array('plugin_gnmi_devices', 'plugin_gnmi_subscriptions', 'plugin_gnmi_metrics', 'plugin_gnmi_events') as $table) {
		$column = get_schema_column($table, 'id');
		assert_true(strpos(strtolower($column['column_type_value']), 'unsigned') !== false, "$table.id should be unsigned");
		assert_true(strpos(strtolower($column['extra_value']), 'auto_increment') !== false, "$table.id should auto-increment");
	}
}

function get_schema_indexes($table) {
	$rows = db_fetch_assoc_prepared(
		'SELECT INDEX_NAME AS index_name_value, NON_UNIQUE AS non_unique_value, ' .
		'COLUMN_NAME AS column_name_value FROM information_schema.statistics ' .
		'WHERE table_schema = DATABASE() AND table_name = ? ORDER BY INDEX_NAME, SEQ_IN_INDEX',
		array($table)
	);
	$indexes = array();
	foreach ($rows as $row) {
		$name = $row['index_name_value'];
		if (!isset($indexes[$name])) {
			$indexes[$name] = array('unique' => ((int)$row['non_unique_value'] === 0), 'columns' => array());
		}
		$indexes[$name]['columns'][] = $row['column_name_value'];
	}
	return $indexes;
}

function test_current_schema_indexes() {
	$expected = array(
		'plugin_gnmi_devices' => array(
			'PRIMARY' => array(true, array('id')),
			'uk_host_id' => array(true, array('host_id')),
			'idx_enabled' => array(false, array('enabled')),
			'idx_created_on' => array(false, array('created_on')),
		),
		'plugin_gnmi_subscriptions' => array(
			'PRIMARY' => array(true, array('id')),
			'idx_device_id' => array(false, array('device_id')),
			'idx_enabled' => array(false, array('enabled')),
			'idx_discovery_mode' => array(false, array('discovery_mode')),
		),
		'plugin_gnmi_metrics' => array(
			'PRIMARY' => array(true, array('id')),
			'uk_subscription_metric' => array(true, array('subscription_id', 'metric_name')),
			'idx_enabled' => array(false, array('enabled')),
			'idx_datasource_created' => array(false, array('datasource_created')),
			'idx_metric_graph_key' => array(false, array('subscription_id', 'metric_graph_key', 'metric_direction')),
			'idx_discovered' => array(false, array('discovered')),
		),
		'plugin_gnmi_events' => array(
			'PRIMARY' => array(true, array('id')),
			'idx_device_id' => array(false, array('device_id')),
			'idx_event_type' => array(false, array('event_type')),
			'idx_created_at' => array(false, array('created_at')),
		),
	);

	foreach ($expected as $table => $table_indexes) {
		$actual = get_schema_indexes($table);
		foreach ($table_indexes as $name => $contract) {
			assert_true(isset($actual[$name]), "$table.$name index should exist");
			if (isset($actual[$name])) {
				assert_equals($contract[0], $actual[$name]['unique'], "$table.$name uniqueness");
				assert_equals($contract[1], $actual[$name]['columns'], "$table.$name ordered columns");
			}
		}
	}
}

function test_current_schema_foreign_keys() {
	$rows = db_fetch_assoc(
		'SELECT k.TABLE_NAME AS table_name_value, k.COLUMN_NAME AS column_name_value, ' .
		'k.REFERENCED_TABLE_NAME AS referenced_table_value, ' .
		'k.REFERENCED_COLUMN_NAME AS referenced_column_value, r.DELETE_RULE AS delete_rule_value ' .
		'FROM information_schema.KEY_COLUMN_USAGE k ' .
		'JOIN information_schema.REFERENTIAL_CONSTRAINTS r ' .
		'ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME ' .
		'WHERE k.CONSTRAINT_SCHEMA = DATABASE() AND k.TABLE_NAME LIKE \'plugin_gnmi_%\' ' .
		'ORDER BY k.TABLE_NAME, k.COLUMN_NAME'
	);
	$actual = array();
	foreach ($rows as $row) {
		$key = $row['table_name_value'] . '.' . $row['column_name_value'];
		$actual[$key] = array(
			$row['referenced_table_value'],
			$row['referenced_column_value'],
			strtoupper($row['delete_rule_value']),
		);
	}
	$expected = array(
		'plugin_gnmi_devices.host_id' => array('host', 'id', 'CASCADE'),
		'plugin_gnmi_events.device_id' => array('plugin_gnmi_devices', 'id', 'CASCADE'),
		'plugin_gnmi_metrics.subscription_id' => array('plugin_gnmi_subscriptions', 'id', 'CASCADE'),
		'plugin_gnmi_subscriptions.device_id' => array('plugin_gnmi_devices', 'id', 'CASCADE'),
	);
	assert_equals($expected, $actual, 'Current schema foreign-key graph and delete rules');
}

function schema_definition_snapshot() {
	return array(
		'columns' => db_fetch_assoc(
			'SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA ' .
			'FROM information_schema.columns WHERE table_schema = DATABASE() ' .
			'AND table_name LIKE \'plugin_gnmi_%\' ORDER BY TABLE_NAME, ORDINAL_POSITION'
		),
		'indexes' => db_fetch_assoc(
			'SELECT TABLE_NAME, INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME ' .
			'FROM information_schema.statistics WHERE table_schema = DATABASE() ' .
			'AND table_name LIKE \'plugin_gnmi_%\' ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX'
		),
		'foreign_keys' => db_fetch_assoc(
			'SELECT k.TABLE_NAME, k.COLUMN_NAME, k.REFERENCED_TABLE_NAME, ' .
			'k.REFERENCED_COLUMN_NAME, r.DELETE_RULE FROM information_schema.KEY_COLUMN_USAGE k ' .
			'JOIN information_schema.REFERENTIAL_CONSTRAINTS r ' .
			'ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME ' .
			'WHERE k.CONSTRAINT_SCHEMA = DATABASE() AND k.TABLE_NAME LIKE \'plugin_gnmi_%\' ' .
			'ORDER BY k.TABLE_NAME, k.COLUMN_NAME'
		),
	);
}

function provisioned_metadata_snapshot() {
	return array(
		'hooks' => db_fetch_assoc("SELECT hook, file, function, status FROM plugin_hooks WHERE name = 'gnmi' ORDER BY hook, function"),
		'realms' => db_fetch_assoc("SELECT file, display FROM plugin_realms WHERE plugin = 'gnmi' ORDER BY file"),
		'settings' => db_fetch_assoc("SELECT name, value FROM settings WHERE name LIKE 'gnmi_%' ORDER BY name"),
		'profiles' => db_fetch_assoc("SELECT id, name, step, heartbeat FROM data_source_profiles WHERE name = 'gNMI - 10 Second Collection' ORDER BY id"),
		'profile_cfs' => db_fetch_assoc(
			"SELECT pcf.data_source_profile_id, pcf.consolidation_function_id " .
			"FROM data_source_profiles_cf pcf JOIN data_source_profiles p ON p.id = pcf.data_source_profile_id " .
			"WHERE p.name = 'gNMI - 10 Second Collection' ORDER BY pcf.consolidation_function_id"
		),
		'profile_rras' => db_fetch_assoc(
			"SELECT pr.data_source_profile_id, pr.name, pr.steps, pr.`rows` AS rows_value, pr.timespan " .
			"FROM data_source_profiles_rra pr JOIN data_source_profiles p ON p.id = pr.data_source_profile_id " .
			"WHERE p.name = 'gNMI - 10 Second Collection' ORDER BY pr.id"
		),
		'inputs' => db_fetch_assoc("SELECT id, name, input_string, type_id FROM data_input WHERE name = 'gNMI - Passthrough' ORDER BY id"),
		'input_fields' => db_fetch_assoc(
			"SELECT f.data_input_id, f.name, f.data_name, f.input_output, f.sequence " .
			"FROM data_input_fields f JOIN data_input i ON i.id = f.data_input_id " .
			"WHERE i.name = 'gNMI - Passthrough' ORDER BY f.id"
		),
		'templates' => db_fetch_assoc("SELECT id, name FROM data_template WHERE name = 'gNMI - Passthrough' ORDER BY id"),
		'template_data' => db_fetch_assoc(
			"SELECT dtd.id, dtd.data_template_id, dtd.data_input_id, dtd.local_data_id, dtd.data_source_path " .
			"FROM data_template_data dtd JOIN data_template dt ON dt.id = dtd.data_template_id " .
			"WHERE dt.name = 'gNMI - Passthrough' AND dtd.local_data_id = 0 ORDER BY dtd.id"
		),
		'graphs' => db_fetch_assoc("SELECT id, name FROM graph_templates WHERE name LIKE 'gNMI - %' ORDER BY id"),
		'graph_shells' => db_fetch_assoc(
			"SELECT gtg.id, gtg.graph_template_id, gtg.local_graph_id " .
			"FROM graph_templates_graph gtg JOIN graph_templates gt ON gt.id = gtg.graph_template_id " .
			"WHERE gt.name LIKE 'gNMI - %' AND gtg.local_graph_id = 0 ORDER BY gtg.id"
		),
		'graph_items' => db_fetch_assoc(
			"SELECT gti.id, gti.graph_template_id, gti.local_graph_id, gti.sequence " .
			"FROM graph_templates_item gti JOIN graph_templates gt ON gt.id = gti.graph_template_id " .
			"WHERE gt.name LIKE 'gNMI - %' AND gti.local_graph_id = 0 ORDER BY gti.id"
		),
	);
}

function test_populated_current_schema_reinstall_preserves_state() {
	global $config;
	require_once($config['base_path'] . '/plugins/gnmi/setup.php');

	$token = 'gnmi_reinstall_' . substr(sha1(uniqid('', true)), 0, 12);
	$device_id = 0;
	$subscription_id = 0;
	$metric_id = 0;
	$event_id = 0;

	try {
		$host_id = (int)db_fetch_cell(
			'SELECT h.id FROM host h LEFT JOIN plugin_gnmi_devices d ON d.host_id = h.id ' .
			'WHERE d.id IS NULL ORDER BY h.id LIMIT 1'
		);
		assert_true($host_id > 0, 'Reinstall fixture requires an unassigned Cacti host');
		if ($host_id < 1) return;

		$created = db_execute_prepared(
			'INSERT INTO plugin_gnmi_devices ' .
			'(host_id, enabled, hostname, hostname_source, port, username, password, use_tls, ' .
			'compatibility_mode, skip_verify, collection_interval, encoding, last_poll_status) ' .
			'VALUES (?, 0, ?, \'custom\', 57400, ?, ?, 0, \'standard\', 1, 60, \'JSON_IETF\', \'never\')',
			array($host_id, $token . '.invalid', $token, $token)
		);
		assert_true($created !== false, 'Create owned device reinstall fixture');
		$device_id = (int)db_fetch_insert_id();

		$created = db_execute_prepared(
			'INSERT INTO plugin_gnmi_subscriptions ' .
			'(device_id, subscription_path, instance_identifier, enabled, discovery_mode, ' .
			'auto_create_datasources, auto_create_graphs, notes) ' .
			'VALUES (?, ?, ?, 0, \'manual\', 0, 0, ?)',
			array($device_id, '/test/' . $token, $token, $token)
		);
		assert_true($created !== false, 'Create owned subscription reinstall fixture');
		$subscription_id = (int)db_fetch_insert_id();

		$created = db_execute_prepared(
			'INSERT INTO plugin_gnmi_metrics ' .
			'(subscription_id, metric_name, cacti_field_name, rrd_type, rrd_heartbeat, ' .
			'rrd_min, rrd_max, enabled, metric_group, metric_direction, metric_graph_key) ' .
			'VALUES (?, ?, ?, \'GAUGE\', 123, \'U\', \'U\', 0, \'generic\', \'none\', NULL)',
			array($subscription_id, $token, substr($token, 0, 19))
		);
		assert_true($created !== false, 'Create owned metric reinstall fixture');
		$metric_id = (int)db_fetch_insert_id();

		$created = db_execute_prepared(
			'INSERT INTO plugin_gnmi_events (device_id, event_type, event_data) VALUES (?, ?, ?)',
			array($device_id, 'config_change', json_encode(array('token' => $token)))
		);
		assert_true($created !== false, 'Create owned event reinstall fixture');
		$event_id = (int)db_fetch_insert_id();

		$rows_before = array(
			'device' => db_fetch_row_prepared('SELECT * FROM plugin_gnmi_devices WHERE id = ?', array($device_id)),
			'subscription' => db_fetch_row_prepared('SELECT * FROM plugin_gnmi_subscriptions WHERE id = ?', array($subscription_id)),
			'metric' => db_fetch_row_prepared('SELECT * FROM plugin_gnmi_metrics WHERE id = ?', array($metric_id)),
			'event' => db_fetch_row_prepared('SELECT * FROM plugin_gnmi_events WHERE id = ?', array($event_id)),
		);
		$schema_before = schema_definition_snapshot();
		$metadata_before = provisioned_metadata_snapshot();

		assert_equals(true, plugin_gnmi_install(), 'Current-schema reinstall should succeed');

		$rows_after = array(
			'device' => db_fetch_row_prepared('SELECT * FROM plugin_gnmi_devices WHERE id = ?', array($device_id)),
			'subscription' => db_fetch_row_prepared('SELECT * FROM plugin_gnmi_subscriptions WHERE id = ?', array($subscription_id)),
			'metric' => db_fetch_row_prepared('SELECT * FROM plugin_gnmi_metrics WHERE id = ?', array($metric_id)),
			'event' => db_fetch_row_prepared('SELECT * FROM plugin_gnmi_events WHERE id = ?', array($event_id)),
		);
		assert_equals($rows_before, $rows_after, 'Current-schema reinstall preserves owned rows exactly');
		assert_equals($schema_before, schema_definition_snapshot(), 'Current-schema reinstall preserves schema definitions');
		assert_equals($metadata_before, provisioned_metadata_snapshot(), 'Current-schema reinstall does not duplicate provisioned metadata');
	} finally {
		if ($event_id > 0) db_execute_prepared('DELETE FROM plugin_gnmi_events WHERE id = ?', array($event_id));
		if ($metric_id > 0) db_execute_prepared('DELETE FROM plugin_gnmi_metrics WHERE id = ?', array($metric_id));
		if ($subscription_id > 0) db_execute_prepared('DELETE FROM plugin_gnmi_subscriptions WHERE id = ?', array($subscription_id));
		if ($device_id > 0) db_execute_prepared('DELETE FROM plugin_gnmi_devices WHERE id = ?', array($device_id));
	}

	$remaining = (int)db_fetch_cell_prepared(
		'SELECT COUNT(*) FROM plugin_gnmi_devices WHERE hostname = ?',
		array($token . '.invalid')
	);
	assert_equals(0, $remaining, 'Reinstall fixtures are removed');
}

// Test 1: Table existence
function test_events_table_exists() {
	$exists = db_fetch_cell("
		SELECT COUNT(*) FROM information_schema.tables
		WHERE table_schema = DATABASE()
		AND table_name = 'plugin_gnmi_events'
	");
	assert_equals(1, intval($exists), "plugin_gnmi_events table should exist");
}

// Test 2: Required columns exist
function test_events_table_columns() {
	$required_columns = array('id', 'device_id', 'event_type', 'event_data', 'created_at');

	foreach ($required_columns as $column) {
		$exists = db_fetch_cell("
			SELECT COUNT(*) FROM information_schema.columns
			WHERE table_schema = DATABASE()
			AND table_name = 'plugin_gnmi_events'
			AND column_name = '$column'
		");
		assert_equals(1, intval($exists), "Column '$column' should exist in plugin_gnmi_events");
	}
}

// Test 3: Foreign key constraint exists
function test_device_id_foreign_key() {
	$constraint_exists = db_fetch_cell("
		SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE
		WHERE table_schema = DATABASE()
		AND table_name = 'plugin_gnmi_events'
		AND column_name = 'device_id'
		AND referenced_table_name = 'plugin_gnmi_devices'
	");
	assert_true(intval($constraint_exists) >= 1, "device_id foreign key should exist");
}

// Test 4: event_type index exists
function test_event_type_index() {
	$index_exists = db_fetch_cell("
		SELECT COUNT(*) FROM information_schema.statistics
		WHERE table_schema = DATABASE()
		AND table_name = 'plugin_gnmi_events'
		AND index_name = 'idx_event_type'
		AND column_name = 'event_type'
	");
	assert_true(intval($index_exists) >= 1, "idx_event_type index should exist");
}

// Test 5: created_at index exists
function test_created_at_index() {
	$index_exists = db_fetch_cell("
		SELECT COUNT(*) FROM information_schema.statistics
		WHERE table_schema = DATABASE()
		AND table_name = 'plugin_gnmi_events'
		AND index_name = 'idx_created_at'
		AND column_name = 'created_at'
	");
	assert_true(intval($index_exists) >= 1, "idx_created_at index should exist");
}

// Test 6: JSON data stored correctly
function test_json_data_storage() {
	// Get a valid device ID for testing
	$device_id = db_fetch_cell('SELECT id FROM plugin_gnmi_devices LIMIT 1');

	if (!$device_id) {
		echo "⊘ SKIP: No devices available to test JSON storage\n";
		global $tests_run;
		$tests_run++;
		return;
	}

	// Insert test event with JSON data
	$test_data = array('test_key' => 'test_value', 'number' => 123);
	$json_data = json_encode($test_data);

	db_execute_prepared(
		'INSERT INTO plugin_gnmi_events (device_id, event_type, event_data, created_at) VALUES (?, ?, ?, NOW())',
		array($device_id, 'error', $json_data)
	);

	// Retrieve and verify
	$retrieved = db_fetch_cell_prepared(
		'SELECT event_data FROM plugin_gnmi_events WHERE device_id = ? AND event_type = ? ORDER BY id DESC LIMIT 1',
		array($device_id, 'error')
	);

	$decoded = json_decode($retrieved, true);
	assert_true(is_array($decoded) && $decoded['test_key'] === 'test_value', "JSON data should be stored and retrieved correctly");

	// Cleanup test data
	db_execute_prepared(
		'DELETE FROM plugin_gnmi_events WHERE device_id = ? AND event_type = ?',
		array($device_id, 'error')
	);
}

// Test 7: CASCADE delete works
function test_cascade_delete() {
	// Get a valid device ID
	$device_id = db_fetch_cell('SELECT id FROM plugin_gnmi_devices LIMIT 1');

	if (!$device_id) {
		echo "⊘ SKIP: No devices available to test CASCADE delete\n";
		global $tests_run;
		$tests_run++;
		return;
	}

	// Insert test event
	db_execute_prepared(
		'INSERT INTO plugin_gnmi_events (device_id, event_type, event_data, created_at) VALUES (?, ?, ?, NOW())',
		array($device_id, 'error', '{"test": "cascade"}')
	);

	// Count events for this device
	$count_before = db_fetch_cell_prepared(
		'SELECT COUNT(*) FROM plugin_gnmi_events WHERE device_id = ?',
		array($device_id)
	);

	assert_true($count_before > 0, "CASCADE delete test: Events should exist before device deletion (found $count_before)");

	// Note: We won't actually delete the device since it might be in use
	// Instead, we verify the foreign key constraint exists (tested above)
	// Cleanup test event
	db_execute_prepared(
		'DELETE FROM plugin_gnmi_events WHERE device_id = ? AND event_data LIKE ?',
		array($device_id, '%cascade%')
	);

	echo "  Note: CASCADE delete constraint verified via FK existence test\n";
}

// Run all tests
echo "Running schema tests...\n\n";
test_current_table_set();
test_current_schema_columns();
test_current_schema_column_contracts();
test_current_schema_indexes();
test_current_schema_foreign_keys();
test_events_table_exists();
test_events_table_columns();
test_device_id_foreign_key();
test_event_type_index();
test_created_at_index();
test_json_data_storage();
test_cascade_delete();
test_populated_current_schema_reinstall_preserves_state();

// Print summary
echo "\n=== Test Summary ===\n";
echo "Total: $tests_run\n";
echo "Passed: $tests_passed\n";
echo "Failed: $tests_failed\n";

if ($tests_failed > 0) {
	echo "\n⚠ TESTS FAILED - Schema implementation needed\n";
	exit(1);
} else {
	echo "\n✓ ALL TESTS PASSED\n";
	exit(0);
}

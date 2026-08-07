<?php
/**
 * Verify that public-beta installation refuses unsupported legacy schemas
 * before making any changes.
 */

define('POLLER_VERBOSITY_LOW', 1);

$legacy_tables = array();
$metric_columns = array();
$schema_writes = array();
$captured_logs = array();

function db_table_exists($table) {
	global $legacy_tables;
	return in_array($table, $legacy_tables, true);
}

function db_fetch_assoc($sql) {
	global $metric_columns;
	if ($sql !== 'DESCRIBE plugin_gnmi_metrics') {
		throw new RuntimeException('Unexpected schema query: ' . $sql);
	}
	return $metric_columns;
}

function db_execute($sql) {
	global $schema_writes;
	$schema_writes[] = $sql;
	return true;
}

function cacti_log($message, $output = false, $category = '', $verbosity = null) {
	global $captured_logs;
	$captured_logs[] = $message;
}

require_once(__DIR__ . '/../../setup.php');

function guard_assert($condition, $message) {
	if (!$condition) {
		fwrite(STDERR, "FAIL: {$message}\n");
		exit(1);
	}
	echo "PASS: {$message}\n";
}

function run_legacy_install_case($tables, $columns, $expected_marker) {
	global $legacy_tables, $metric_columns, $schema_writes, $captured_logs, $config;

	$legacy_tables = $tables;
	$metric_columns = $columns;
	$schema_writes = array();
	$captured_logs = array();
	$config = array('base_path' => '/path/that/must/not/be/reached');

	$result = plugin_gnmi_install();
	guard_assert($result === false, "installation rejects {$expected_marker}");
	guard_assert(empty($schema_writes), "installation leaves {$expected_marker} unchanged");
	guard_assert(
		count($captured_logs) === 1 && strpos($captured_logs[0], $expected_marker) !== false,
		"installation logs the {$expected_marker} reason"
	);
	guard_assert(
		strpos($captured_logs[0], 'fresh-install only') !== false &&
		strpos($captured_logs[0], 'left unchanged') !== false,
		"installation provides safe remediation guidance"
	);
}

run_legacy_install_case(
	array('plugin_gnmi_device_metrics'),
	array(),
	'plugin_gnmi_device_metrics'
);
run_legacy_install_case(
	array('plugin_gnmi_device_settings'),
	array(),
	'plugin_gnmi_device_settings'
);
run_legacy_install_case(
	array('plugin_gnmi_metrics'),
	array(array('Field' => 'name'), array('Field' => 'gnmi_path')),
	'plugin_gnmi_metrics (missing subscription_id)'
);
run_legacy_install_case(
	array('plugin_gnmi_metrics'),
	array(),
	'plugin_gnmi_metrics (schema could not be inspected)'
);

$legacy_tables = array('plugin_gnmi_metrics');
$metric_columns = array(
	array('Field' => 'id'),
	array('Field' => 'subscription_id'),
	array('Field' => 'metric_name'),
);
guard_assert(
	gnmi_find_unsupported_legacy_schema() === array(),
	'current subscription-based metric schema passes preflight'
);

echo "All legacy schema guard tests passed.\n";

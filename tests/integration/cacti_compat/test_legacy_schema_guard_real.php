<?php
/**
 * Exercise legacy-schema install guards against the disposable Cacti database.
 *
 * This harness must run after plugin files are deployed but before the normal
 * Cacti plugin installation.
 */

$no_http_headers = true;
chdir(__DIR__ . '/../../../../../');
include_once('./include/cli_check.php');
require_once('./plugins/gnmi/setup.php');

$tests_run = 0;
$tests_failed = 0;

function guard_real_assert($condition, $message) {
	global $tests_run, $tests_failed;
	$tests_run++;
	if ($condition) {
		echo "PASS: $message\n";
		return;
	}
	$tests_failed++;
	echo "FAIL: $message\n";
}

function guard_real_plugin_tables() {
	return db_fetch_assoc(
		"SELECT TABLE_NAME AS table_name_value FROM information_schema.tables " .
		"WHERE table_schema = DATABASE() AND table_name LIKE 'plugin_gnmi_%' " .
		"ORDER BY table_name"
	);
}

function guard_real_metadata_snapshot() {
	return array(
		'hooks' => db_fetch_assoc("SELECT * FROM plugin_hooks WHERE name = 'gnmi' ORDER BY id"),
		'realms' => db_fetch_assoc("SELECT * FROM plugin_realms WHERE plugin = 'gnmi' ORDER BY id"),
		'settings' => db_fetch_assoc("SELECT * FROM settings WHERE name LIKE 'gnmi_%' ORDER BY name"),
		'profiles' => db_fetch_assoc("SELECT * FROM data_source_profiles WHERE name = 'gNMI - 10 Second Collection' ORDER BY id"),
		'inputs' => db_fetch_assoc("SELECT * FROM data_input WHERE name = 'gNMI - Passthrough' ORDER BY id"),
		'templates' => db_fetch_assoc("SELECT * FROM data_template WHERE name = 'gNMI - Passthrough' ORDER BY id"),
		'graphs' => db_fetch_assoc("SELECT * FROM graph_templates WHERE name LIKE 'gNMI - %' ORDER BY id"),
	);
}

function guard_real_runtime_snapshot() {
	global $config;
	$override = getenv('GNMI_RUNTIME_DIR');
	$root = (is_string($override) && trim($override) !== '')
		? rtrim(trim($override), '/')
		: $config['base_path'] . '/plugins/gnmi/runtime';
	$paths = array($root, $root . '/storage', $root . '/certs', $root . '/logs');
	$result = array();
	foreach ($paths as $path) {
		$result[$path] = file_exists($path);
	}
	return $result;
}

function guard_real_run_case($table, $create_sql, $insert_sql, $marker) {
	$baseline_metadata = guard_real_metadata_snapshot();
	$baseline_runtime = guard_real_runtime_snapshot();
	guard_real_assert(guard_real_plugin_tables() === array(), "$marker begins with no plugin schema");

	try {
		guard_real_assert(db_execute($create_sql) !== false, "$marker fixture table created");
		guard_real_assert(db_execute($insert_sql) !== false, "$marker fixture row created");
		$definition_before = db_fetch_row("SHOW CREATE TABLE `$table`");
		$rows_before = db_fetch_assoc("SELECT * FROM `$table` ORDER BY id");
		$tables_before = guard_real_plugin_tables();

		guard_real_assert(plugin_gnmi_install() === false, "$marker blocks installation");
		guard_real_assert($tables_before === guard_real_plugin_tables(), "$marker creates no other plugin tables");
		guard_real_assert($definition_before === db_fetch_row("SHOW CREATE TABLE `$table`"), "$marker table definition is unchanged");
		guard_real_assert($rows_before === db_fetch_assoc("SELECT * FROM `$table` ORDER BY id"), "$marker table data is unchanged");
		guard_real_assert($baseline_metadata === guard_real_metadata_snapshot(), "$marker creates no Cacti metadata");
		guard_real_assert($baseline_runtime === guard_real_runtime_snapshot(), "$marker creates no runtime directories");
	} finally {
		db_execute("DROP TABLE IF EXISTS `$table`");
	}

	guard_real_assert(guard_real_plugin_tables() === array(), "$marker fixture cleanup is complete");
}

guard_real_run_case(
	'plugin_gnmi_device_metrics',
	'CREATE TABLE plugin_gnmi_device_metrics (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, sentinel VARCHAR(32) NOT NULL) ENGINE=InnoDB',
	"INSERT INTO plugin_gnmi_device_metrics (sentinel) VALUES ('device-metrics-fixture')",
	'legacy device-metric assignment table'
);

guard_real_run_case(
	'plugin_gnmi_device_settings',
	'CREATE TABLE plugin_gnmi_device_settings (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, sentinel VARCHAR(32) NOT NULL) ENGINE=InnoDB',
	"INSERT INTO plugin_gnmi_device_settings (sentinel) VALUES ('device-settings-fixture')",
	'legacy device-settings table'
);

guard_real_run_case(
	'plugin_gnmi_metrics',
	'CREATE TABLE plugin_gnmi_metrics (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, name VARCHAR(255) NOT NULL, gnmi_path TEXT NOT NULL) ENGINE=InnoDB',
	"INSERT INTO plugin_gnmi_metrics (name, gnmi_path) VALUES ('legacy-metric', '/legacy/path')",
	'legacy metric layout without subscription_id'
);

echo "Legacy schema real-database assertions: $tests_run; failures: $tests_failed\n";
exit($tests_failed === 0 ? 0 : 1);

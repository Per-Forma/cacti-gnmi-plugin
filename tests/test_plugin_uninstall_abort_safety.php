<?php
/**
 * Isolated regression test: uninstall must leave schema and Cacti metadata
 * untouched when its poller lock or daemon-stop preconditions fail.
 *
 * This test invokes the production plugin_gnmi_uninstall() function with
 * in-memory Cacti/database stubs. It never connects to a database.
 *
 * Run from repo root:
 *   php plugins/gnmi/tests/test_plugin_uninstall_abort_safety.php
 */

define('POLLER_VERBOSITY_LOW', 1);
define('POLLER_VERBOSITY_MEDIUM', 2);

$config = array('base_path' => dirname(__DIR__, 3));
$mode = 'lock_failure';
$calls = array();

function db_table_exists($table) {
	return $table === 'plugin_gnmi_devices' || $table === 'plugin_gnmi_metrics';
}

function db_fetch_assoc($sql) {
	if (strpos($sql, 'SELECT id FROM plugin_gnmi_devices') !== false) {
		return array(array('id' => 41));
	}
	if (strpos($sql, 'DESCRIBE plugin_gnmi_metrics') !== false) {
		return array(array('Field' => 'local_data_id'), array('Field' => 'graph_local_id'));
	}

	return array();
}

function db_fetch_cell($sql) {
	return 0;
}

function db_execute($sql) {
	global $calls;
	$calls[] = array('db_execute', $sql);
	return true;
}

function cacti_log($message, $unused = false, $facility = '', $verbosity = 0) {
	global $calls;
	$calls[] = array('log', $message);
}

function gnmi_get_storage_dir() {
	return sys_get_temp_dir() . '/gnmi-uninstall-abort-storage-not-created';
}

function gnmi_get_logs_dir() {
	return sys_get_temp_dir() . '/gnmi-uninstall-abort-logs-not-created';
}

function gnmi_stop_daemon($device_id) {
	global $calls;
	$calls[] = array('stop_daemon', (int)$device_id);
	return false;
}

function gnmi_with_poller_exclusive_lock(callable $fn, $lock_path = null, $blocking = false) {
	global $mode, $calls;
	$calls[] = array('poller_lock', (bool)$blocking);
	if ($mode === 'lock_failure') {
		return false;
	}

	$fn();
	return true;
}

require_once(dirname(__DIR__) . '/setup.php');

function test_assert($condition, $message) {
	if (!$condition) {
		fwrite(STDERR, "FAIL: $message\n");
		exit(1);
	}
}

function test_has_call($type) {
	global $calls;
	foreach ($calls as $call) {
		if ($call[0] === $type) {
			return true;
		}
	}
	return false;
}

function test_has_sql($needle) {
	global $calls;
	foreach ($calls as $call) {
		if ($call[0] === 'db_execute' && strpos($call[1], $needle) !== false) {
			return true;
		}
	}
	return false;
}

// Failure to acquire the exclusive poller lock must abort before inspecting or
// mutating plugin-owned resources.
$result = plugin_gnmi_uninstall();
test_assert($result === false, 'uninstall must fail when the poller lock is unavailable');
test_assert(!test_has_call('stop_daemon'), 'daemon cleanup must not run without the poller lock');
test_assert(!test_has_sql('DROP TABLE'), 'tables must not be dropped without the poller lock');

// Once the lock is held, failure to stop any configured daemon must abort before
// Cacti metadata cleanup or table removal.
$mode = 'daemon_failure';
$calls = array();
$result = plugin_gnmi_uninstall();
test_assert($result === false, 'uninstall must fail when a configured daemon cannot stop');
test_assert(test_has_call('stop_daemon'), 'uninstall must attempt daemon cleanup while holding the lock');
test_assert(!test_has_sql('START TRANSACTION'), 'metadata cleanup must not begin after daemon failure');
test_assert(!test_has_sql('DROP TABLE'), 'tables must not be dropped after daemon failure');

echo "OK: uninstall aborts safely on poller-lock and daemon-stop failures.\n";

<?php
/**
 * Focused uninstall regression test with Cacti API/database stubs.
 *
 * Run from repo root:
 *   php plugins/gnmi/tests/test_plugin_uninstall_cleanup.php
 */

define('POLLER_VERBOSITY_LOW', 1);
define('POLLER_VERBOSITY_MEDIUM', 2);

$test_root = sys_get_temp_dir() . '/gnmi-uninstall-' . getmypid();
$storage_dir = $test_root . '/runtime/storage';
$logs_dir = $test_root . '/runtime/logs';
$rra_dir = $test_root . '/rra';
mkdir($storage_dir, 0770, true);
mkdir($logs_dir, 0770, true);
mkdir($rra_dir, 0770, true);

$config = array(
	'base_path' => dirname(__DIR__, 3),
	'rra_path' => $rra_dir,
);
$calls = array();
$rrd_files = array($rra_dir . '/7/201.rrd', $rra_dir . '/7/202.rrd');
mkdir(dirname($rrd_files[0]), 0770, true);
foreach ($rrd_files as $rrd_file) {
	file_put_contents($rrd_file, 'preserve-me');
}
foreach (array(7, 8) as $device_id) {
	file_put_contents($storage_dir . "/device_{$device_id}.pid", '99999');
	file_put_contents($storage_dir . "/device_{$device_id}_config.json", '{}');
	file_put_contents($storage_dir . "/device_{$device_id}.json", '{}');
	file_put_contents($logs_dir . "/device_{$device_id}.log", 'test');
}

function db_table_exists($table) {
	return in_array($table, array(
		'plugin_gnmi_devices',
		'plugin_gnmi_metrics',
		'data_source_purge_action',
	), true);
}

function db_fetch_assoc($sql) {
	global $rra_dir;

	if (strpos($sql, 'SELECT id FROM plugin_gnmi_devices') !== false) {
		return array(array('id' => 7), array('id' => 8));
	}
	if (strpos($sql, 'DESCRIBE plugin_gnmi_metrics') !== false) {
		return array(array('Field' => 'local_data_id'), array('Field' => 'graph_local_id'));
	}
	if (strpos($sql, 'SELECT DISTINCT graph_local_id') !== false) {
		return array(array('graph_local_id' => 101), array('graph_local_id' => 102));
	}
	if (strpos($sql, 'SELECT DISTINCT local_data_id') !== false) {
		return array(array('local_data_id' => 201), array('local_data_id' => 202));
	}
	if (strpos($sql, 'SELECT local_data_id, data_source_path') !== false) {
		return array(
			array('local_data_id' => 201, 'data_source_path' => '<path_rra>/7/201.rrd'),
			array('local_data_id' => 202, 'data_source_path' => '<path_rra>/7/202.rrd'),
		);
	}

	return array();
}

function db_fetch_cell($sql) {
	return 0;
}

function db_fetch_cell_prepared($sql, $args = array()) {
	if (strpos($sql, 'SELECT id FROM data_template WHERE') !== false) {
		return 301;
	}
	if (strpos($sql, 'SELECT id FROM data_input WHERE') !== false) {
		return 302;
	}
	if (strpos($sql, 'SELECT id FROM data_source_profiles WHERE') !== false) {
		return 303;
	}
	return 0;
}

function db_execute($sql) {
	global $calls;
	$calls[] = array('db_execute', $sql);
	return true;
}

function db_execute_prepared($sql, $args = array()) {
	global $calls;
	$calls[] = array('db_execute_prepared', $sql, $args);
	return true;
}

function cacti_log($message, $unused = false, $facility = '', $verbosity = 0) {
	global $calls;
	$calls[] = array('log', $message);
}

function gnmi_get_storage_dir() {
	global $storage_dir;
	return $storage_dir;
}

function gnmi_get_logs_dir() {
	global $logs_dir;
	return $logs_dir;
}

function gnmi_stop_daemon($device_id) {
	global $calls;
	$calls[] = array('stop_daemon', (int)$device_id);
	return true;
}

function gnmi_with_poller_exclusive_lock(callable $fn, $lock_path = null, $blocking = false) {
	global $calls;
	$calls[] = array('poller_lock', (bool)$blocking);
	$fn();
	return true;
}

function poller_push_to_remote_db_connect($device_or_poller, $is_poller = false) {
	return false;
}

function get_remote_poller_ids_from_data_sources(&$data_source_ids) {
	return array();
}

function api_aggregate_disassociate($local_graph_id, $graphs) {
	return true;
}

function api_graph_remove_multi($graph_ids) {
	global $calls;
	$calls[] = array('remove_graphs', $graph_ids);
}

function api_data_source_remove_multi($data_source_ids) {
	global $calls;
	$calls[] = array('remove_data_sources', $data_source_ids);
}

function api_data_input_remove($input_id) {
	global $calls;
	$calls[] = array('remove_data_input', (int)$input_id);
}

require_once(dirname(__DIR__) . '/setup.php');

function test_call_position($type, $value = null) {
	global $calls;
	foreach ($calls as $position => $call) {
		if ($call[0] === $type && ($value === null || $call[1] === $value)) {
			return $position;
		}
	}
	return false;
}

function test_assert($condition, $message) {
	if (!$condition) {
		fwrite(STDERR, "FAIL: $message\n");
		exit(1);
	}
}

$result = plugin_gnmi_uninstall();
test_assert($result === true, 'uninstall should succeed');

$stop_7 = test_call_position('stop_daemon', 7);
$stop_8 = test_call_position('stop_daemon', 8);
$poller_lock = test_call_position('poller_lock', true);
$remove_graphs = test_call_position('remove_graphs');
$remove_data_sources = test_call_position('remove_data_sources');
$remove_data_input = test_call_position('remove_data_input');
$first_drop = false;
$purge_delete = false;
$commit = false;
foreach ($calls as $position => $call) {
	if ($call[0] !== 'db_execute') {
		continue;
	}
	if ($first_drop === false && strpos($call[1], 'DROP TABLE') !== false) {
		$first_drop = $position;
	}
	if (strpos($call[1], 'DELETE FROM data_source_purge_action') !== false) {
		$purge_delete = $position;
	}
	if ($call[1] === 'COMMIT') {
		$commit = $position;
	}
}

test_assert($stop_7 !== false && $stop_8 !== false, 'every daemon must be stopped');
test_assert($poller_lock !== false, 'uninstall must acquire the poller lock in blocking mode');
test_assert($remove_graphs !== false, 'plugin-owned graphs must be removed');
test_assert($remove_data_sources !== false, 'plugin-owned data sources must be removed');
test_assert($remove_data_input !== false, 'plugin-owned data input must be removed');
test_assert($first_drop !== false, 'plugin tables must be dropped');
test_assert($poller_lock < $stop_7 && $poller_lock < $stop_8, 'poller lock must be held before daemon cleanup begins');
test_assert($stop_7 < $remove_graphs && $stop_8 < $remove_graphs, 'daemons must stop before Cacti metadata removal');
test_assert($remove_graphs < $remove_data_sources, 'graphs must be removed before data sources');
test_assert($remove_data_sources < $first_drop, 'Cacti metadata must be removed before plugin tables');
test_assert($remove_data_input < $first_drop, 'shared plugin definitions must be removed before plugin tables');
test_assert($purge_delete !== false && $commit !== false && $purge_delete < $commit, 'RRD purge actions must be removed before commit');

test_assert($calls[$remove_graphs][1] === array(101, 102), 'graph IDs must come from plugin metric ownership');
test_assert($calls[$remove_data_sources][1] === array(201, 202), 'data-source IDs must come from plugin metric ownership');
test_assert($calls[$remove_data_input][1] === 302, 'the exact gNMI data input must be removed');

$template_removed = false;
$profile_removed = false;
$settings_removed = false;
foreach ($calls as $call) {
	if ($call[0] === 'db_execute_prepared' && strpos($call[1], 'DELETE FROM data_template WHERE') !== false) {
		$template_removed = true;
	}
	if ($call[0] === 'db_execute_prepared' && strpos($call[1], 'DELETE FROM data_source_profiles WHERE') !== false) {
		$profile_removed = true;
	}
	if ($call[0] === 'db_execute' && strpos($call[1], "DELETE FROM settings WHERE name LIKE 'gnmi_%'") !== false) {
		$settings_removed = true;
	}
}
test_assert($template_removed, 'plugin-owned data template must be removed');
test_assert($profile_removed, 'plugin-owned data-source profile must be removed');
test_assert($settings_removed, 'plugin-owned settings must be removed');
foreach ($rrd_files as $rrd_file) {
	test_assert(file_exists($rrd_file), "RRD must be preserved: $rrd_file");
}
foreach (array(7, 8) as $device_id) {
	test_assert(!file_exists($storage_dir . "/device_{$device_id}.pid"), 'PID file must be removed');
	test_assert(!file_exists($storage_dir . "/device_{$device_id}_config.json"), 'config file must be removed');
	test_assert(!file_exists($storage_dir . "/device_{$device_id}.json"), 'storage JSON must be removed');
	test_assert(!file_exists($logs_dir . "/device_{$device_id}.log"), 'daemon log must be removed');
}

foreach (array_reverse($rrd_files) as $rrd_file) {
	@unlink($rrd_file);
}
@rmdir(dirname($rrd_files[0]));
@rmdir($rra_dir);
@rmdir($storage_dir);
@rmdir($logs_dir);
@rmdir(dirname($storage_dir));
@rmdir($test_root);

echo "OK: uninstall stops daemons, removes Cacti metadata, and preserves RRD files.\n";

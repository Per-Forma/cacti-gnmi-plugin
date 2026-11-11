#!/usr/bin/env php
<?php
/**
 * Standalone tests: disabled device rows must not spawn collectors from stale snapshots.
 *
 * Run: php plugins/gnmi/tests/daemon_lifecycle/test_paused_collector_not_restarted.php
 */

define('CACTI_VERSION', 'test');
define('POLLER_VERBOSITY_LOW', 1);

$GLOBALS['logged_messages'] = array();
$GLOBALS['subscription_reads'] = 0;
$GLOBALS['device_row'] = array(
	'id' => 7,
	'enabled' => 0,
	'hostname' => 'router.example.com',
	'port' => 9339,
	'username' => 'u',
	'password' => 'p',
	'use_tls' => 1,
	'encoding' => 'JSON_IETF',
);

if (!function_exists('cacti_log')) {
	function cacti_log($message, $output = false, $facility = 'POLLER', $level = 1) {
		$GLOBALS['logged_messages'][] = (string) $message;
	}
}
if (!function_exists('db_fetch_row_prepared')) {
	function db_fetch_row_prepared($sql, $params = array()) {
		if (stripos($sql, 'plugin_gnmi_devices') !== false && isset($params[0]) && (int) $params[0] === 7) {
			return $GLOBALS['device_row'];
		}
		return false;
	}
}
if (!function_exists('db_fetch_assoc_prepared')) {
	function db_fetch_assoc_prepared($sql, $params = array()) {
		$GLOBALS['subscription_reads']++;
		return array(array('id' => 1, 'subscription_path' => '/x', 'instance_identifier' => 'i', 'enabled' => 1));
	}
}

require_once __DIR__ . '/../../include/functions.php';

$tests_run = 0;
$tests_failed = 0;

function assert_true($cond, $message) {
	global $tests_run, $tests_failed;
	$tests_run++;
	if (!$cond) {
		$tests_failed++;
		echo "FAIL: $message\n";
	}
	else {
		echo "PASS: $message\n";
	}
}

$snapshot = array(
	'id' => 7,
	'enabled' => 1,
	'hostname' => 'router.example.com',
);

$GLOBALS['subscription_reads'] = 0;
$config = gnmi_build_daemon_config(7);
assert_true($config === false, 'build_daemon_config refuses disabled device');
assert_true($GLOBALS['subscription_reads'] === 0, 'build_daemon_config does not load subscriptions when disabled');

$GLOBALS['logged_messages'] = array();
$result = gnmi_start_daemon($snapshot);
assert_true($result === false, 'start_daemon refuses stale enabled snapshot');
$log_blob = implode("\n", $GLOBALS['logged_messages']);
assert_true(strpos($log_blob, 'disabled device 7') !== false, 'start_daemon logs disabled device');
assert_true(strpos($log_blob, 'Virtual environment not found') === false, 'start_daemon fails for disabled reason not venv');

// Dashboard and subscription restarts call gnmi_restart_daemon() with the
// in-memory device row. That row can still say enabled = 1 after the database
// row is paused. The restart function has its own disabled-device return,
// before it builds a config or execs daemon_ctl.
//
// gnmi_build_daemon_config() logs the same "disabled device" sentence and
// then gnmi_restart_daemon() logs "Cannot restart daemon". Seeing that second
// sentence means the restart guard was skipped. A temp base_path keeps a
// skipped guard from including the real status helpers or finding a venv;
// the assertions then fail on the log, not on a missing Cacti tree.
$GLOBALS['logged_messages'] = array();
$GLOBALS['subscription_reads'] = 0;
$config['base_path'] = sys_get_temp_dir() . '/gnmi-restart-paused-' . getmypid();
$result = gnmi_restart_daemon($snapshot);
assert_true($result === false, 'restart_daemon refuses stale enabled snapshot');
$log_blob = implode("\n", $GLOBALS['logged_messages']);
assert_true(
	strpos($log_blob, 'Not starting collector for disabled device 7') !== false,
	'restart_daemon logs disabled device'
);
assert_true(
	strpos($log_blob, 'Cannot restart daemon') === false,
	'restart_daemon returns before the collector start command'
);
assert_true($GLOBALS['subscription_reads'] === 0, 'restart_daemon does not load subscriptions when disabled');
assert_true(strpos($log_blob, 'Daemon started') === false, 'restart_daemon does not start the collector');

echo "\nTests Run: $tests_run\n";
echo "Tests Failed: $tests_failed\n";
exit($tests_failed > 0 ? 1 : 0);

<?php
/**
 * Regression test: a registered poller hook may run briefly while Cacti is
 * removing the plugin. Missing plugin tables must make the hook exit cleanly.
 *
 * Run from repo root:
 *   php plugins/gnmi/tests/test_uninstall_poller_guard.php
 */

define('POLLER_VERBOSITY_LOW', 1);

$config = array('base_path' => dirname(__DIR__, 3));
$logged_messages = array();
$manage_calls = 0;
$table_probe_mode = 'missing';
$table_probe_count = 0;

function cacti_log($message, $unused = false, $facility = '', $verbosity = 0) {
	global $logged_messages;
	$logged_messages[] = $message;
}

function db_fetch_assoc($sql) {
	global $table_probe_mode, $table_probe_count;

	if (strpos($sql, 'SHOW TABLES LIKE') === false) {
		return array();
	}

	$table_probe_count++;
	if ($table_probe_mode === 'present_then_missing' && $table_probe_count <= 2) {
		return array(array('table' => 'present'));
	}

	return array();
}

function gnmi_manage_daemons() {
	global $manage_calls;
	$manage_calls++;
}

function gnmi_ensure_storage_directory() {
	return true;
}

function gnmi_with_poller_exclusive_lock(callable $fn, $lock_path = null, $blocking = false) {
	$fn();
	return true;
}

require_once(dirname(__DIR__) . '/setup.php');

gnmi_poller_bottom();

if ($manage_calls !== 0) {
	fwrite(STDERR, "FAIL: daemon management ran without plugin tables\n");
	exit(1);
}

$guard_logged = false;
foreach ($logged_messages as $message) {
	if (strpos($message, 'plugin tables are unavailable') !== false) {
		$guard_logged = true;
		break;
	}
}

if (!$guard_logged) {
	fwrite(STDERR, "FAIL: missing-table guard was not logged\n");
	exit(1);
}

// Simulate a long-running poller that observed tables before uninstall, then
// acquired the lock only after uninstall removed them.
$logged_messages = array();
$table_probe_mode = 'present_then_missing';
$table_probe_count = 0;
gnmi_poller_bottom();

if ($manage_calls !== 0) {
	fwrite(STDERR, "FAIL: daemon management ran after tables disappeared while waiting for the lock\n");
	exit(1);
}

$post_lock_guard_logged = false;
foreach ($logged_messages as $message) {
	if (strpos($message, 'tables became unavailable') !== false) {
		$post_lock_guard_logged = true;
		break;
	}
}

if (!$post_lock_guard_logged) {
	fwrite(STDERR, "FAIL: post-lock missing-table guard was not logged\n");
	exit(1);
}

echo "OK: teardown-window poller hook exits cleanly before and after lock acquisition.\n";

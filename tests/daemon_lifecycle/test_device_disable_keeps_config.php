#!/usr/bin/env php
<?php
/**
 * Standalone tests for pausing gNMI on device save without deleting configuration.
 *
 * Run: php plugins/gnmi/tests/daemon_lifecycle/test_device_disable_keeps_config.php
 */

if (!defined('POLLER_VERBOSITY_LOW')) {
	define('POLLER_VERBOSITY_LOW', 1);
}
if (!defined('POLLER_VERBOSITY_MEDIUM')) {
	define('POLLER_VERBOSITY_MEDIUM', 2);
}
if (!defined('POLLER_VERBOSITY_DEBUG')) {
	define('POLLER_VERBOSITY_DEBUG', 5);
}
if (!defined('MESSAGE_LEVEL_ERROR')) {
	define('MESSAGE_LEVEL_ERROR', 1);
}

$GLOBALS['config'] = array(
	'base_path' => sys_get_temp_dir() . '/gnmi_disable_keeps_config',
);
$GLOBALS['fixture'] = null;
$GLOBALS['events'] = array();
$GLOBALS['messages'] = array();
$GLOBALS['restarted_hosts'] = array();
$GLOBALS['stop_result'] = true;
$GLOBALS['update_result'] = true;
$GLOBALS['lock_result'] = true;

function default_fixture($enabled = 1) {
	return array(
		'id' => 7,
		'host_id' => 42,
		'enabled' => $enabled,
		'hostname' => 'router.example.com',
		'hostname_source' => 'device',
		'port' => 9339,
		'username' => 'telemetry',
		'password' => 'secret',
		'use_tls' => 1,
		'ca_cert_path' => '',
		'client_key_path' => '',
		'client_cert_path' => '',
		'tls_override' => '',
		'skip_verify' => 0,
		'tls_cipher_policy' => 'default',
		'compatibility_mode' => 'standard',
		'collection_interval' => 10,
		'encoding' => 'JSON_IETF',
	);
}

function reset_case_state($enabled = 1) {
	$_POST = array();
	$GLOBALS['events'] = array();
	$GLOBALS['messages'] = array();
	$GLOBALS['restarted_hosts'] = array();
	$GLOBALS['stop_result'] = true;
	$GLOBALS['update_result'] = true;
	$GLOBALS['lock_result'] = true;
	$GLOBALS['fixture'] = default_fixture($enabled);
}

if (!function_exists('html_escape')) {
	function html_escape($value) {
		return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
	}
}
if (!function_exists('cacti_log')) {
	function cacti_log($message, $output = false, $facility = 'POLLER', $level = 1) {
	}
}
if (!function_exists('__')) {
	function __($text) {
		return $text;
	}
}
if (!function_exists('raise_message')) {
	function raise_message($id, $message, $level) {
		$GLOBALS['messages'][] = $message;
	}
}
if (!function_exists('sanitize_search_string')) {
	function sanitize_search_string($value) {
		return (string) $value;
	}
}
if (!function_exists('gnmi_current_user_has_plugin_realm')) {
	function gnmi_current_user_has_plugin_realm($realm_file) {
		return false;
	}
}
if (!function_exists('gnmi_stop_daemon')) {
	function gnmi_stop_daemon($device_id) {
		$GLOBALS['events'][] = array('stop', (int) $device_id);
		return $GLOBALS['stop_result'];
	}
}
if (!function_exists('gnmi_with_poller_exclusive_lock')) {
	function gnmi_with_poller_exclusive_lock(callable $fn, $lock_path = null, $blocking = false) {
		$GLOBALS['events'][] = array('lock', (bool) $blocking);
		if ($GLOBALS['lock_result'] === false) {
			return false;
		}
		$fn();
		return true;
	}
}
if (!function_exists('gnmi_certificate_path_is_allowed')) {
	function gnmi_certificate_path_is_allowed($path) {
		return $path === '';
	}
}
if (!function_exists('gnmi_restart_daemon_by_host_id')) {
	function gnmi_restart_daemon_by_host_id($host_id) {
		$GLOBALS['restarted_hosts'][] = (int) $host_id;
		return true;
	}
}
if (!function_exists('gnmi_get_certs_dir')) {
	function gnmi_get_certs_dir() {
		return $GLOBALS['config']['base_path'] . '/runtime/certs';
	}
}
if (!function_exists('db_execute_prepared')) {
	function db_execute_prepared($sql, $params = array()) {
		$GLOBALS['events'][] = array('sql', $sql, $params);
		if (stripos($sql, 'DELETE FROM plugin_gnmi_devices') !== false) {
			$GLOBALS['fixture'] = null;
			return true;
		}
		if (stripos($sql, 'SET enabled = 0') !== false) {
			if ($GLOBALS['update_result'] === false) {
				return false;
			}
			if (is_array($GLOBALS['fixture'])) {
				$GLOBALS['fixture']['enabled'] = 0;
			}
			return true;
		}
		if (stripos($sql, 'UPDATE plugin_gnmi_devices SET') !== false && isset($params[0])) {
			if (is_array($GLOBALS['fixture'])) {
				$GLOBALS['fixture']['enabled'] = (int) $params[0];
				if (isset($params[5])) {
					$GLOBALS['fixture']['password'] = $params[5];
				}
			}
			return true;
		}
		return true;
	}
}
if (!function_exists('db_fetch_row_prepared')) {
	function db_fetch_row_prepared($sql, $params = array()) {
		if ($GLOBALS['fixture'] === null) {
			return array();
		}
		if (stripos($sql, 'plugin_gnmi_devices') === false) {
			return array();
		}
		if (stripos($sql, 'SELECT id FROM') !== false) {
			return array('id' => $GLOBALS['fixture']['id']);
		}
		return $GLOBALS['fixture'];
	}
}
if (!function_exists('db_fetch_cell_prepared')) {
	function db_fetch_cell_prepared($sql, $params = array()) {
		if (stripos($sql, 'SELECT id FROM plugin_gnmi_devices') !== false) {
			return null;
		}
		if (stripos($sql, 'SELECT hostname FROM host') !== false) {
			return 'router.example.com';
		}
		return null;
	}
}

require_once __DIR__ . '/../../include/form_functions.php';

$tests_run = 0;
$tests_failed = 0;

function assert_same($expected, $actual, $message) {
	global $tests_run, $tests_failed;
	$tests_run++;
	if ($expected !== $actual) {
		$tests_failed++;
		echo "FAIL: $message\n";
		echo '  Expected: ' . var_export($expected, true) . "\n";
		echo '  Actual:   ' . var_export($actual, true) . "\n";
	}
	else {
		echo "PASS: $message\n";
	}
}

function assert_true($cond, $message) {
	assert_same(true, (bool) $cond, $message);
}

function assert_has_text($needle, $haystack, $message) {
	global $tests_run, $tests_failed;
	$tests_run++;
	if (strpos($haystack, $needle) === false) {
		$tests_failed++;
		echo "FAIL: $message\n";
	}
	else {
		echo "PASS: $message\n";
	}
}

function event_has_delete() {
	foreach ($GLOBALS['events'] as $event) {
		if ($event[0] === 'sql' && stripos($event[1], 'DELETE FROM plugin_gnmi_devices') !== false) {
			return true;
		}
	}
	return false;
}

function checked_enable_post() {
	return array(
		'gnmi_form_present' => '1',
		'gnmi_enabled' => '1',
		'gnmi_hostname' => 'router.example.com',
		'gnmi_hostname_source' => 'device',
		'gnmi_port' => '9339',
		'gnmi_username' => 'telemetry',
		'gnmi_password' => 'secret',
		'use_tls' => '1',
		'compatibility_mode' => 'standard',
		'tls_cipher_policy' => 'default',
		'collection_interval' => '10',
	);
}

function assert_pause_event_order() {
	assert_same('lock', $GLOBALS['events'][0][0], 'pause begins with poller lock');
	assert_same(true, $GLOBALS['events'][0][1], 'pause lock is blocking');
	assert_same('sql', $GLOBALS['events'][1][0], 'pause updates enabled before stop');
	assert_has_text('enabled = 0', $GLOBALS['events'][1][1], 'pause SQL sets enabled to 0');
	assert_same('stop', $GLOBALS['events'][2][0], 'pause stops collector after update');
	assert_same(7, $GLOBALS['events'][2][1], 'pause stops the correct device id');
}

// Case 1
reset_case_state(1);
$_POST = array('gnmi_form_present' => '1');
$result = gnmi_process_device_save(42);
assert_same(true, $result, 'case 1 returns true');
assert_pause_event_order();
assert_true(!event_has_delete(), 'case 1 does not delete the row');
assert_same('telemetry', $GLOBALS['fixture']['username'], 'case 1 keeps username');
assert_same('secret', $GLOBALS['fixture']['password'], 'case 1 keeps password');
assert_same(array(), $GLOBALS['messages'], 'case 1 raises no messages');

// Case 2
reset_case_state(0);
$_POST = array('gnmi_form_present' => '1');
$result = gnmi_process_device_save(42);
assert_same(true, $result, 'case 2 returns true');
assert_pause_event_order();
assert_true(!event_has_delete(), 'case 2 does not delete the row');

// Case 3
reset_case_state(1);
$_POST = array();
$result = gnmi_process_device_save(42);
assert_same(true, $result, 'case 3 returns true');
assert_same(array(), $GLOBALS['events'], 'case 3 performs no gNMI work');
assert_same(1, $GLOBALS['fixture']['enabled'], 'case 3 leaves enabled unchanged');

// Case 4
reset_case_state(1);
$_POST = array(
	'gnmi_enabled' => '1',
	'gnmi_hostname' => 'router.example.com',
);
$result = gnmi_process_device_save(42);
assert_same(true, $result, 'case 4 returns true');
assert_same(array(), $GLOBALS['events'], 'case 4 ignores POST without gnmi_form_present');
assert_same(1, $GLOBALS['fixture']['enabled'], 'case 4 leaves enabled unchanged');

// Case 5
reset_case_state(1);
$GLOBALS['fixture'] = null;
$_POST = array('gnmi_form_present' => '1');
$result = gnmi_process_device_save(42);
assert_same(true, $result, 'case 5 returns true');
assert_same(array(), $GLOBALS['events'], 'case 5 with no row performs no work');

// Case 6
reset_case_state(0);
$_POST = checked_enable_post();
$result = gnmi_process_device_save(42);
assert_same(true, $result, 'case 6 returns true');
assert_same(1, $GLOBALS['fixture']['enabled'], 'case 6 re-enables the device');
assert_same(array(), $GLOBALS['restarted_hosts'], 'case 6 does not restart when config unchanged');

// Case 7
reset_case_state(1);
$_POST = checked_enable_post();
$_POST['gnmi_password'] = '';
$result = gnmi_process_device_save(42);
assert_same(false, $result, 'case 7 rejects empty password');
assert_same('secret', $GLOBALS['fixture']['password'], 'case 7 leaves stored password');

// Case 8
reset_case_state(1);
$GLOBALS['update_result'] = false;
$_POST = array('gnmi_form_present' => '1');
$result = gnmi_process_device_save(42);
assert_same(false, $result, 'case 8 returns false when update fails');
assert_same('lock', $GLOBALS['events'][0][0], 'case 8 attempted lock');
assert_true(!in_array(array('stop', 7), $GLOBALS['events'], true), 'case 8 does not stop when update fails');
assert_same(1, $GLOBALS['fixture']['enabled'], 'case 8 keeps row enabled');
assert_same(
	'gNMI could not be disabled. Existing collection is unchanged.',
	$GLOBALS['messages'][0],
	'case 8 reports unchanged collection'
);

// Case 9
reset_case_state(1);
$GLOBALS['lock_result'] = false;
$_POST = array('gnmi_form_present' => '1');
$result = gnmi_process_device_save(42);
assert_same(false, $result, 'case 9 returns false when lock fails');
assert_true(!event_has_delete(), 'case 9 does not delete');
assert_same(1, $GLOBALS['fixture']['enabled'], 'case 9 keeps row enabled');
assert_same(
	'gNMI could not be disabled. Existing collection is unchanged.',
	$GLOBALS['messages'][0],
	'case 9 reports unchanged collection'
);

// Case 10
reset_case_state(1);
$GLOBALS['stop_result'] = false;
$_POST = array('gnmi_form_present' => '1');
$result = gnmi_process_device_save(42);
assert_same(false, $result, 'case 10 returns false when stop fails');
assert_same(0, $GLOBALS['fixture']['enabled'], 'case 10 persists disabled state');
assert_same(
	'gNMI is disabled, but the collector could not be stopped. The next poller cycle will stop a leftover collector.',
	$GLOBALS['messages'][0],
	'case 10 reports stop failure'
);

// Case 11 — paused device render
reset_case_state(0);
ob_start();
gnmi_render_device_form_section(
	42,
	array(
		'id' => 7,
		'enabled' => 0,
		'hostname' => 'router.example.com',
		'hostname_source' => 'device',
	),
	array(),
	'router.example.com'
);
$paused_html = ob_get_clean();
assert_has_text('name="gnmi_form_present"', $paused_html, 'case 11 renders form marker');
assert_has_text('name="gnmi_enabled"', $paused_html, 'case 11 renders enable checkbox');
assert_true(
	preg_match('/name="gnmi_enabled"[^>]*checked/i', $paused_html) !== 1,
	'case 11 checkbox is not checked when paused'
);
$heading = '>Subscriptions &amp; Metrics</td>';
assert_has_text($heading, $paused_html, 'case 11 renders subscription heading');
$pos = strpos($paused_html, $heading);
$tr_pos = strrpos(substr($paused_html, 0, $pos), '<tr');
$segment = substr($paused_html, $tr_pos, $pos - $tr_pos);
assert_has_text('display: none;', $segment, 'case 11 hides subscription row when paused');

// Case 12 — enabled device render
reset_case_state(1);
ob_start();
gnmi_render_device_form_section(
	42,
	array(
		'id' => 7,
		'enabled' => 1,
		'hostname' => 'router.example.com',
		'hostname_source' => 'device',
	),
	array(),
	'router.example.com'
);
$enabled_html = ob_get_clean();
assert_has_text($heading, $enabled_html, 'case 12 renders subscription heading');
$pos = strpos($enabled_html, $heading);
$tr_pos = strrpos(substr($enabled_html, 0, $pos), '<tr');
$segment = substr($enabled_html, $tr_pos, $pos - $tr_pos);
assert_true(strpos($segment, 'display: none;') === false, 'case 12 shows subscription row when enabled');
assert_true(
	preg_match('/name="gnmi_enabled"[^>]*checked/i', $enabled_html) === 1,
	'case 12 checkbox is checked when enabled'
);

echo "\nTests Run: $tests_run\n";
echo "Tests Failed: $tests_failed\n";
exit($tests_failed > 0 ? 1 : 0);

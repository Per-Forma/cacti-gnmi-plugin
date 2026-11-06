<?php
/**
 * Paused collection rows on the status dashboard (issue #41).
 *
 * Run: php plugins/gnmi/tests/status_dashboard/test_paused_dashboard.php
 */
define('CACTI_VERSION', 'test');
define('POLLER_VERBOSITY_LOW', 1);
define('POLLER_VERBOSITY_DEBUG', 5);
define('MESSAGE_LEVEL_ERROR', 1);

$config = array(
	'base_path' => sys_get_temp_dir() . '/gnmi-paused-dash-' . getmypid(),
	'url_path' => '/cacti/',
);
$_SESSION = array('sess_user_id' => 42);

$device_rows = array(
	array(
		'device_id' => 7,
		'id' => 7,
		'host_id' => 101,
		'hostname' => 'collecting',
		'enabled' => 1,
		'tls_cipher_policy' => 'default',
		'last_poll_time' => null,
		'last_poll_status' => 'error',
		'last_error_message' => null,
		'device_description' => 'C',
	),
	array(
		'device_id' => 8,
		'id' => 8,
		'host_id' => 101,
		'hostname' => 'paused-visible',
		'enabled' => 0,
		'tls_cipher_policy' => 'default',
		'last_poll_time' => null,
		'last_poll_status' => 'error',
		'last_error_message' => null,
		'device_description' => 'P',
	),
	array(
		'device_id' => 9,
		'id' => 9,
		'host_id' => 102,
		'hostname' => 'paused-hidden',
		'enabled' => 0,
		'tls_cipher_policy' => 'default',
		'last_poll_time' => null,
		'last_poll_status' => 'success',
		'last_error_message' => null,
		'device_description' => 'H',
	),
);

function read_config_option($key) {
	return $key === 'auth_method' ? 1 : ($key === 'poller_interval' ? 300 : '');
}

function get_guest_account() {
	return 99;
}

// Default: only host 101 visible (for summary + Case B).
$GLOBALS['gnmi_paused_test_allow_hosts'] = array(101);

function is_device_allowed($host) {
	return in_array((int) $host, $GLOBALS['gnmi_paused_test_allow_hosts'], true);
}

function api_user_realm_auth($file) {
	return false;
}

/** Case B: operator may restart when this returns true for dashboard_actions. */
function api_plugin_user_realm_auth($file) {
	return $file === 'dashboard_actions.php';
}

function is_realm_allowed($id) {
	return false;
}

function cacti_log(...$args) {
}

function html_escape($text) {
	return htmlspecialchars((string) $text, ENT_QUOTES);
}

function db_fetch_assoc($sql) {
	global $device_rows;
	return $device_rows;
}

require_once __DIR__ . '/../../include/functions.php';
require_once __DIR__ . '/../../include/status_functions.php';
require_once __DIR__ . '/../../include/status_display.php';

$failed = 0;

function check($ok, $message) {
	global $failed;
	if (!$ok) {
		$failed++;
		echo "FAIL: $message\n";
	}
}

putenv('GNMI_RUNTIME_DIR=' . $config['base_path'] . '/plugins/gnmi/runtime');
$storage = gnmi_get_storage_dir();
mkdir($storage, 0700, true);

$GLOBALS['gnmi_enforce_web_auth_in_cli'] = true;

try {
	// --- Task 1: summary contract ---
	$summary = gnmi_get_dashboard_summary();
	$by_host = array();
	foreach ($summary as $row) {
		$by_host[$row['hostname']] = $row;
	}
	check(isset($by_host['collecting'], $by_host['paused-visible']), 'summary includes enabled and paused on same host');
	check(!isset($by_host['paused-hidden']), 'hidden-host paused row omitted');
	check($by_host['collecting']['enabled'] === 1 && $by_host['collecting']['health'] === 'critical', 'enabled row keeps critical when stopped');
	check($by_host['collecting']['daemon_status'] === 'stopped', 'enabled row daemon stopped without PID');
	check($by_host['paused-visible']['enabled'] === 0, 'paused row reports enabled 0');
	check($by_host['paused-visible']['health'] === 'paused', 'paused row health is paused');
	check($by_host['paused-visible']['daemon_status'] === 'stopped', 'paused row daemon stopped without PID');

	// Diagnostic: no probes for denied device 9 paths.
	class PausedProbeStream {
		public $context;
		public static $paths = array();

		public function url_stat($path, $flags) {
			self::$paths[] = $path;
			return false;
		}
	}
	stream_wrapper_register('gnmipause', 'PausedProbeStream');
	putenv('GNMI_RUNTIME_DIR=gnmipause://runtime');
	gnmi_get_dashboard_summary();
	check(
		!array_filter(PausedProbeStream::$paths, function ($p) {
			return strpos($p, 'device_9.') !== false;
		}),
		'denied device 9 causes zero diagnostic filesystem probes'
	);
	stream_wrapper_unregister('gnmipause');
	putenv('GNMI_RUNTIME_DIR=' . $config['base_path'] . '/plugins/gnmi/runtime');

	// --- Task 3: display ---
	// Case A: web authorization off — Restart prints for every row today unless we withhold for paused.
	$GLOBALS['gnmi_enforce_web_auth_in_cli'] = false;
	$paused_row = array(
		'device_id' => 8,
		'enabled' => 0,
		'health' => 'paused',
		'daemon_status' => 'stopped',
		'daemon_pid' => null,
		'uptime_seconds' => 0,
		'data_age_seconds' => 99999,
		'host_id' => 101,
		'hostname' => 'paused.example',
		'device_description' => 'Paused host',
		'last_poll_time' => null,
		'last_poll_status' => 'success',
		'last_error_message' => null,
		'tls_cipher_policy' => 'default',
	);
	$html_a = gnmi_render_summary_table(array($paused_row));
	check(strpos($html_a, '#5b6abf') !== false, 'Case A: paused badge color');
	check(strpos($html_a, 'Paused') !== false, 'Case A: Paused label');
	check(strpos($html_a, '99999s ago') !== false, 'Case A: data age visible');
	check(strpos($html_a, 'color:red;') === false, 'Case A: paused row data age not red');
	check(strpos($html_a, 'value="restart"') === false, 'Case A: no restart on paused row');
	check(strpos($html_a, 'toggleDeviceDetails(8)') !== false, 'Case A: Details for paused device');

	// Case B: web auth on, daemon realm granted, host 101 allowed.
	$GLOBALS['gnmi_enforce_web_auth_in_cli'] = true;
	$enabled_row = array(
		'device_id' => 7,
		'enabled' => 1,
		'health' => 'healthy',
		'daemon_status' => 'running',
		'daemon_pid' => 12345,
		'uptime_seconds' => 60,
		'data_age_seconds' => 10,
		'host_id' => 101,
		'hostname' => 'a.example',
		'device_description' => 'A',
		'last_poll_time' => '2025-01-01',
		'last_poll_status' => 'success',
		'last_error_message' => null,
		'tls_cipher_policy' => 'default',
	);
	$html_b = gnmi_render_summary_table(array($enabled_row, $paused_row));
	check(substr_count($html_b, 'value="restart"') === 1, 'Case B: exactly one restart form');
	check(strpos($html_b, 'name="device_id" value="7"') !== false, 'Case B: restart targets device 7');
	check(strpos($html_b, 'name="device_id" value="8"') === false, 'Case B: no restart for device 8');
} finally {
	@rmdir($storage);
	@rmdir(dirname($storage));
	@rmdir(dirname(dirname($storage)));
	@rmdir($config['base_path'] . '/plugins');
	@rmdir($config['base_path']);
}

echo "Paused dashboard failures: $failed\n";
exit($failed ? 1 : 0);

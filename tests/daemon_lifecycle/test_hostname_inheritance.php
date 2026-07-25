#!/usr/bin/env php
<?php
/**
 * Standalone tests for gNMI hostname inheritance and form state.
 *
 * Run: php plugins/gnmi/tests/daemon_lifecycle/test_hostname_inheritance.php
 */

if (!defined('POLLER_VERBOSITY_LOW')) define('POLLER_VERBOSITY_LOW', 1);
if (!defined('POLLER_VERBOSITY_MEDIUM')) define('POLLER_VERBOSITY_MEDIUM', 2);
if (!defined('POLLER_VERBOSITY_DEBUG')) define('POLLER_VERBOSITY_DEBUG', 5);

if (!function_exists('html_escape')) {
	function html_escape($value) {
		return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
	}
}
if (!function_exists('cacti_log')) {
	function cacti_log($message, $output = false, $facility = 'POLLER', $level = 1) {}
}
if (!function_exists('db_fetch_row_prepared')) {
	function db_fetch_row_prepared($sql, $params = []) {
		return [];
	}
}
if (!function_exists('db_fetch_cell_prepared')) {
	function db_fetch_cell_prepared($sql, $params = []) {
		return null;
	}
}
if (!function_exists('db_fetch_assoc')) {
	function db_fetch_assoc($sql) {
		return $GLOBALS['test_schema_columns'] ?? [];
	}
}
if (!function_exists('db_execute')) {
	function db_execute($sql) {
		$GLOBALS['test_schema_queries'][] = $sql;
		return true;
	}
}
if (!function_exists('db_fetch_error')) {
	function db_fetch_error() {
		return '';
	}
}

$GLOBALS['config'] = [
	'base_path' => sys_get_temp_dir() . '/gnmi_hostname_inheritance',
];

require_once(__DIR__ . '/../../include/form_functions.php');
require_once(__DIR__ . '/../../setup.php');

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
		return;
	}

	echo "PASS: $message\n";
}

function assert_has_text($needle, $haystack, $message) {
	global $tests_run, $tests_failed;
	$tests_run++;

	if (strpos($haystack, $needle) === false) {
		$tests_failed++;
		echo "FAIL: $message\n";
		return;
	}

	echo "PASS: $message\n";
}

$resolved = gnmi_resolve_hostname('device', '', 'router-01.example.com');
assert_same('router-01.example.com', $resolved['hostname'], 'New inherited config uses the device hostname');
assert_same('device', $resolved['hostname_source'], 'New inherited config persists device source');

$resolved = gnmi_resolve_hostname('device', 'router-old.example.com', 'router-new.example.com');
assert_same('router-new.example.com', $resolved['hostname'], 'Inherited hostname follows a device hostname change');
$changed = gnmi_detect_config_changes(
	['hostname' => 'router-old.example.com'],
	['gnmi_hostname' => $resolved['hostname']]
);
assert_same(true, in_array('hostname', $changed, true), 'Inherited hostname change triggers config change detection');

$resolved = gnmi_resolve_hostname('custom', 'telemetry.example.net', 'router-new.example.com');
assert_same('telemetry.example.net', $resolved['hostname'], 'Custom hostname ignores a device hostname change');
assert_same('custom', $resolved['hostname_source'], 'Direct edit persists custom source');
$changed = gnmi_detect_config_changes(
	['hostname' => 'telemetry.example.net'],
	['gnmi_hostname' => $resolved['hostname']]
);
assert_same(false, in_array('hostname', $changed, true), 'Custom hostname avoids an unrelated config restart');

$resolved = gnmi_resolve_hostname('device', 'telemetry.example.net', 'router.example.com');
assert_same('router.example.com', $resolved['hostname'], 'Reset restores the device hostname');
assert_same('device', $resolved['hostname_source'], 'Reset restores device source');

ob_start();
gnmi_render_device_form_section(0, [], [], 'router-01.example.com');
$inherited_form = ob_get_clean();

assert_has_text('value="router-01.example.com"', $inherited_form, 'Inherited form renders the device hostname');
assert_has_text('name="gnmi_hostname_source"', $inherited_form, 'Form renders persistent hostname source state');
assert_has_text('value="device"', $inherited_form, 'Inherited form renders device source');
assert_has_text('gnmiUseDeviceHostname()', $inherited_form, 'Form renders the reset action');
assert_has_text('input.gnmiHostnameSync', $inherited_form, 'Form binds device hostname mirroring');

ob_start();
gnmi_render_device_form_section(0, [
	'hostname' => 'telemetry.example.net',
	'hostname_source' => 'custom',
], [], 'router-01.example.com');
$custom_form = ob_get_clean();

assert_has_text('value="telemetry.example.net"', $custom_form, 'Custom form renders the explicit hostname');
assert_has_text('value="custom"', $custom_form, 'Custom form renders custom source');
assert_has_text('display:inline;', $custom_form, 'Custom form shows the reset action');

$GLOBALS['test_schema_columns'] = [
	['Field' => 'hostname', 'Null' => 'NO', 'Default' => null],
];
$GLOBALS['test_schema_queries'] = [];
assert_same(true, gnmi_apply_hostname_source_schema(), 'Migration succeeds when hostname_source is missing');
assert_same(3, count($GLOBALS['test_schema_queries']), 'Migration adds, classifies, and finalizes the column');
assert_has_text('NULL DEFAULT NULL', $GLOBALS['test_schema_queries'][0], 'Migration starts with a resumable nullable column');
assert_has_text('WHERE gd.hostname_source IS NULL', $GLOBALS['test_schema_queries'][1], 'Migration classifies only unclassified rows');
assert_has_text("NOT NULL DEFAULT 'device'", $GLOBALS['test_schema_queries'][2], 'Migration finalizes the required default');

$GLOBALS['test_schema_columns'] = [
	['Field' => 'hostname_source', 'Null' => 'NO', 'Default' => 'device'],
];
$GLOBALS['test_schema_queries'] = [];
assert_same(true, gnmi_apply_hostname_source_schema(), 'Finalized migration remains idempotent');
assert_same(1, count($GLOBALS['test_schema_queries']), 'Finalized migration does not alter the column again');
assert_has_text('WHERE gd.hostname_source IS NULL', $GLOBALS['test_schema_queries'][0], 'Idempotent rerun preserves explicit source values');

echo "\nTests Run: $tests_run\n";
echo "Tests Failed: $tests_failed\n";

exit($tests_failed > 0 ? 1 : 0);

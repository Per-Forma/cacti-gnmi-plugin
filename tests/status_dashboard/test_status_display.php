<?php
/**
 * Unit Tests for Status Display Functions (Phase 3.2)
 *
 * Tests HTML rendering functions.
 *
 * Run: php test_status_display.php
 */

// Include Cacti environment
$no_http_headers = true;
chdir(__DIR__ . '/../../../../');
include_once('./include/cli_check.php');
include_once($config['base_path'] . '/plugins/gnmi/include/functions.php');
include_once($config['base_path'] . '/plugins/gnmi/include/status_functions.php');
include_once($config['base_path'] . '/plugins/gnmi/include/status_display.php');

// Test results tracking
$tests_run = 0;
$tests_passed = 0;
$tests_failed = 0;

// Test helper functions
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
		return false;
	}
}

echo "=== gNMI Status Display Tests (Phase 3.2) ===\n\n";

// Test 1: render_summary_table returns HTML string
function test_summary_table_returns_html() {
	$devices = array(
		array(
			'device_id' => 1,
			'hostname' => '192.168.1.1',
			'device_description' => 'Test Device',
			'daemon_status' => 'running',
			'daemon_pid' => 12345,
			'health' => 'healthy',
			'uptime_seconds' => 3600,
			'data_age_seconds' => 10,
			'last_poll_time' => '2025-10-21 14:00:00',
			'last_poll_status' => 'success',
			'last_error_message' => null,
			'host_id' => 1
		)
	);

	$html = gnmi_render_summary_table($devices);
	assert_true(is_string($html), "render_summary_table() should return HTML string");
}

// Test 2: Summary table contains table tags
function test_summary_table_structure() {
	$devices = array(
		array(
			'device_id' => 1,
			'hostname' => '192.168.1.1',
			'device_description' => 'Test Device',
			'daemon_status' => 'running',
			'daemon_pid' => 12345,
			'health' => 'healthy',
			'uptime_seconds' => 3600,
			'data_age_seconds' => 10,
			'last_poll_time' => null,
			'last_poll_status' => 'success',
			'last_error_message' => null,
			'host_id' => 1
		)
	);

	$html = gnmi_render_summary_table($devices);
	assert_true(strpos($html, '<table') !== false, "Should contain table tag");
	assert_true(strpos($html, 'Test Device') !== false, "Should contain device description");
	assert_true(strpos($html, '192.168.1.1') !== false, "Should contain hostname");
	assert_true(strpos($html, 'method="post"') !== false, "Restart action should use a POST form");
	assert_true(strpos($html, '?action=restart') === false, "Restart action should not use a GET link");
}

// Test 3: Health badge returns colored HTML
function test_health_badge_colors() {
	$badge_healthy = gnmi_render_health_badge('healthy');
	$badge_warning = gnmi_render_health_badge('warning');
	$badge_critical = gnmi_render_health_badge('critical');

	assert_true(strpos($badge_healthy, '#28a745') !== false, "Healthy badge should be green");
	assert_true(strpos($badge_warning, '#ffc107') !== false, "Warning badge should be yellow");
	assert_true(strpos($badge_critical, '#dc3545') !== false, "Critical badge should be red");
}

// Test 4: format_uptime returns readable string
function test_format_uptime() {
	assert_equals('Not running', gnmi_format_uptime(0), "Zero uptime should return 'Not running'");
	assert_equals('1m', gnmi_format_uptime(60), "60 seconds should return '1m'");

	$uptime_1h = gnmi_format_uptime(3600);
	assert_true(strpos($uptime_1h, '1h') !== false, "3600 seconds should include '1h'");

	$uptime_1d = gnmi_format_uptime(86400);
	assert_true(strpos($uptime_1d, '1d') !== false, "86400 seconds should include '1d'");
}

// Test 5: orphan_panel shows correct message
function test_orphan_panel_zero() {
	$orphan_summary = array(
		'total_orphans' => 0,
		'orphan_pids' => 0,
		'orphan_processes' => 0,
		'pid_details' => array(),
		'process_details' => array()
	);

	$html = gnmi_render_orphan_panel($orphan_summary);
	assert_true(strpos($html, 'No orphans detected') !== false, "Should show 'No orphans detected' when total is 0");
}

// Test 6: orphan_panel shows warning when orphans exist
function test_orphan_panel_nonzero() {
	$orphan_summary = array(
		'total_orphans' => 3,
		'orphan_pids' => 2,
		'orphan_processes' => 1,
		'pid_details' => array(),
		'process_details' => array()
	);

	$html = gnmi_render_orphan_panel($orphan_summary);
	assert_true(strpos($html, '3 orphan(s) detected') !== false, "Should show orphan count");
	assert_true(strpos($html, 'Clean Up Orphans Now') !== false, "Should show cleanup button");
	assert_true(strpos($html, 'method="post"') !== false, "Orphan cleanup should use a POST form");
	assert_true(strpos($html, '?action=cleanup_orphans') === false, "Orphan cleanup should not use a GET link");
}

// Test 7: device_detail includes metrics
function test_device_detail_content() {
	$device = array(
		'device_id' => 1,
		'hostname' => '192.168.1.1',
		'device_description' => 'Test Device',
		'daemon_status' => 'running',
		'daemon_pid' => 12345,
		'health' => 'healthy',
		'uptime_seconds' => 3600,
		'data_age_seconds' => 10,
		'last_poll_time' => null,
		'last_poll_status' => 'success',
		'last_error_message' => null,
		'host_id' => 1
	);

	$html = gnmi_render_device_detail($device);
	assert_true(strpos($html, 'Daemon Metrics') !== false, "Should include 'Daemon Metrics' section");
	assert_true(strpos($html, 'PID') !== false, "Should include PID label");
	assert_true(strpos($html, 'Uptime') !== false, "Should include Uptime label");
}

// Test 8: events_table renders correctly
function test_events_table() {
	$events = array(
		array(
			'id' => 1,
			'device_id' => 1,
			'event_type' => 'daemon_start',
			'event_data' => array('method' => 'auto'),
			'created_at' => '2025-10-21 14:00:00',
			'device_hostname' => '192.168.1.1',
			'device_description' => 'Test Device'
		)
	);

	$html = gnmi_render_events_table($events);
	assert_true(strpos($html, '<table') !== false, "Should contain table tag");
	assert_true(strpos($html, 'daemon_start') !== false, "Should contain event type");
	assert_true(strpos($html, '2025-10-21') !== false, "Should contain timestamp");
}

// Test 9: format_event_data handles different types
function test_format_event_data() {
	$config_data = gnmi_format_event_data('config_change', array('changed_fields' => array('hostname', 'port')));
	assert_true(strpos($config_data, 'Changed:') !== false, "Config change should show 'Changed:'");

	$restart_data = gnmi_format_event_data('daemon_restart', array('reason' => 'user_action'));
	assert_true(strpos($restart_data, 'restarted') !== false, "Restart should show 'restarted'");

	$error_data = gnmi_format_event_data('error', array('message' => 'Connection failed'));
	assert_true(strpos($error_data, 'Connection failed') !== false, "Error should show message");
}

// Test 10: Empty devices handled gracefully
function test_empty_devices() {
	$html = gnmi_render_summary_table(array());
	assert_true(strpos($html, 'No gNMI devices') !== false, "Empty array should show 'No gNMI devices' message");
}

// Run all tests
echo "Running status display tests...\n\n";
test_summary_table_returns_html();
test_summary_table_structure();
test_health_badge_colors();
test_format_uptime();
test_orphan_panel_zero();
test_orphan_panel_nonzero();
test_device_detail_content();
test_events_table();
test_format_event_data();
test_empty_devices();

// Print summary
echo "\n=== Test Summary ===\n";
echo "Total: $tests_run\n";
echo "Passed: $tests_passed\n";
echo "Failed: $tests_failed\n";

if ($tests_failed > 0) {
	echo "\n⚠ TESTS FAILED\n";
	exit(1);
} else {
	echo "\n✓ ALL TESTS PASSED\n";
	exit(0);
}

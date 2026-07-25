<?php
/**
 * Unit Tests for Status Data Retrieval Functions (Phase 3.2)
 *
 * Tests gnmi_get_dashboard_summary(), gnmi_get_daemon_stats(), etc.
 *
 * Run: php test_status_functions.php
 */

// Include Cacti environment
$no_http_headers = true;
chdir(__DIR__ . '/../../../../');
include_once('./include/cli_check.php');
include_once($config['base_path'] . '/plugins/gnmi/include/functions.php');
include_once($config['base_path'] . '/plugins/gnmi/include/status_functions.php');

// Test results tracking
$tests_run = 0;
$tests_passed = 0;
$tests_failed = 0;

// Test helper functions
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
		echo "  Expected: " . var_export($expected, true) . "\n";
		echo "  Actual: " . var_export($actual, true) . "\n";
		return false;
	}
}

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

function assert_not_null($value, $message) {
	global $tests_run, $tests_passed, $tests_failed;
	$tests_run++;

	if ($value !== null) {
		$tests_passed++;
		echo "✓ PASS: $message\n";
		return true;
	} else {
		$tests_failed++;
		echo "✗ FAIL: $message\n";
		return false;
	}
}

echo "=== gNMI Status Functions Tests (Phase 3.2) ===\n\n";

// Test 1: Dashboard summary returns array
function test_dashboard_summary_returns_array() {
	$summary = gnmi_get_dashboard_summary();
	assert_true(is_array($summary), "Dashboard summary should return array");
}

// Test 2: Summary includes all enabled devices
function test_dashboard_summary_enabled_devices() {
	$summary = gnmi_get_dashboard_summary();

	// Count enabled devices in database
	$enabled_count = db_fetch_cell('SELECT COUNT(*) FROM plugin_gnmi_devices WHERE enabled = 1');
	assert_equals(intval($enabled_count), count($summary), "Summary should include all enabled devices (found " . count($summary) . ", expected $enabled_count)");
}

// Test 3: Summary includes required fields
function test_dashboard_summary_fields() {
	$summary = gnmi_get_dashboard_summary();

	if (empty($summary)) {
		echo "⊘ SKIP: No devices to test fields\n";
		global $tests_run;
		$tests_run++;
		return;
	}

	$first_device = $summary[0];
	$required_fields = array('device_id', 'hostname', 'host_id', 'daemon_status', 'health', 'last_poll_status');

	$all_present = true;
	foreach ($required_fields as $field) {
		if (!isset($first_device[$field])) {
			$all_present = false;
			break;
		}
	}

	assert_true($all_present, "Summary should include required fields: " . implode(', ', $required_fields));
}

// Test 4: get_daemon_stats returns array or null
function test_daemon_stats_returns_data() {
	$device_id = db_fetch_cell('SELECT id FROM plugin_gnmi_devices LIMIT 1');

	if (!$device_id) {
		echo "⊘ SKIP: No devices available for testing\n";
		global $tests_run;
		$tests_run++;
		return;
	}

	$stats = gnmi_get_daemon_stats($device_id);
	assert_true(is_array($stats), "get_daemon_stats() should return array for valid device");
}

// Test 5: Daemon stats include required metrics
function test_daemon_stats_metrics() {
	$device_id = db_fetch_cell('SELECT id FROM plugin_gnmi_devices LIMIT 1');

	if (!$device_id) {
		echo "⊘ SKIP: No devices available for testing\n";
		global $tests_run;
		$tests_run++;
		return;
	}

	$stats = gnmi_get_daemon_stats($device_id);
	$required_fields = array('uptime_seconds', 'memory_mb', 'data_age_seconds', 'pid', 'daemon_status');

	$all_present = true;
	foreach ($required_fields as $field) {
		if (!array_key_exists($field, $stats)) {
			$all_present = false;
			echo "  Missing field: $field\n";
		}
	}

	assert_true($all_present, "Daemon stats should include: uptime_seconds, memory_mb, data_age_seconds, pid, daemon_status");
}

// Test 6: get_orphan_summary returns correct structure
function test_orphan_summary_structure() {
	$summary = gnmi_get_orphan_summary();

	assert_true(is_array($summary), "Orphan summary should return array");
	assert_true(isset($summary['total_orphans']), "Orphan summary should include total_orphans");
	assert_true(isset($summary['orphan_pids']), "Orphan summary should include orphan_pids count");
	assert_true(isset($summary['orphan_processes']), "Orphan summary should include orphan_processes count");
}

// Test 7: calculate_uptime returns non-negative integer
function test_calculate_uptime() {
	$device_id = db_fetch_cell('SELECT id FROM plugin_gnmi_devices LIMIT 1');

	if (!$device_id) {
		echo "⊘ SKIP: No devices available for testing\n";
		global $tests_run;
		$tests_run++;
		return;
	}

	$uptime = gnmi_calculate_uptime($device_id);
	assert_true(is_int($uptime) && $uptime >= 0, "calculate_uptime() should return non-negative integer (got $uptime)");
}

// Test 8: get_data_freshness returns null or non-negative integer
function test_data_freshness() {
	$device_id = db_fetch_cell('SELECT id FROM plugin_gnmi_devices LIMIT 1');

	if (!$device_id) {
		echo "⊘ SKIP: No devices available for testing\n";
		global $tests_run;
		$tests_run++;
		return;
	}

	$freshness = gnmi_get_data_freshness($device_id);
	$valid = ($freshness === null || (is_int($freshness) && $freshness >= 0));
	assert_true($valid, "get_data_freshness() should return null or non-negative integer");
}

// Test 9: calculate_health_status returns valid status
function test_health_status_values() {
	$valid_statuses = array('healthy', 'warning', 'critical', 'unknown');

	// Test various scenarios
	$health1 = gnmi_calculate_health_status('valid', 10, 'success');
	assert_true(in_array($health1, $valid_statuses), "Health status should be one of: healthy, warning, critical, unknown (got: $health1)");

	$health2 = gnmi_calculate_health_status('stopped', 100, 'error');
	assert_equals('critical', $health2, "Stopped daemon should be critical");

	$poller_interval = gnmi_get_poller_interval();
	$critical_age = ($poller_interval * 2) + 1;
	$health3 = gnmi_calculate_health_status('valid', $critical_age, 'success');
	assert_equals('critical', $health3, "Data older than 2x the poller interval should be critical");
}

// Test 10: Invalid device_id handled gracefully
function test_invalid_device_id_handling() {
	$stats = gnmi_get_daemon_stats(999999);
	assert_equals(null, $stats, "Invalid device_id should return null");
}

// Test 11: Health status warning range
function test_health_status_warning() {
	$poller_interval = gnmi_get_poller_interval();
	$warning_age = $poller_interval + 1;
	$health = gnmi_calculate_health_status('valid', $warning_age, 'success');
	assert_equals('warning', $health, "Data age between 1x and 2x the poller interval should be warning");
}

// Test 12: Health status unknown for never polled
function test_health_status_unknown() {
	$health = gnmi_calculate_health_status('valid', 10, 'never');
	assert_equals('unknown', $health, "Never polled should be unknown status");
}

// Run all tests
echo "Running status functions tests...\n\n";
test_dashboard_summary_returns_array();
test_dashboard_summary_enabled_devices();
test_dashboard_summary_fields();
test_daemon_stats_returns_data();
test_daemon_stats_metrics();
test_orphan_summary_structure();
test_calculate_uptime();
test_data_freshness();
test_health_status_values();
test_invalid_device_id_handling();
test_health_status_warning();
test_health_status_unknown();

// Print summary
echo "\n=== Test Summary ===\n";
echo "Total: $tests_run\n";
echo "Passed: $tests_passed\n";
echo "Failed: $tests_failed\n";

if ($tests_failed > 0) {
	echo "\n⚠ TESTS FAILED - Implementation needed\n";
	exit(1);
} else {
	echo "\n✓ ALL TESTS PASSED\n";
	exit(0);
}

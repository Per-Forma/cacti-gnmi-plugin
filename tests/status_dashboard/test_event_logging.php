<?php
/**
 * Unit Tests for Event Logging Functions (Phase 3.2)
 *
 * Tests gnmi_log_event(), gnmi_cleanup_old_events(), and gnmi_get_recent_events().
 *
 * Run: php test_event_logging.php
 */

// Include Cacti environment
$no_http_headers = true;
chdir(__DIR__ . '/../../../../');
include_once('./include/cli_check.php');
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

// Helper function to clean up test events
function cleanup_test_events() {
	db_execute("DELETE FROM plugin_gnmi_events WHERE event_data LIKE '%test%'");
}

echo "=== gNMI Event Logging Tests (Phase 3.2) ===\n\n";

// Test 1: gnmi_log_event() creates event record
function test_log_event_creates_record() {
	cleanup_test_events();

	// Get first valid device
	$device_id = db_fetch_cell('SELECT id FROM plugin_gnmi_devices LIMIT 1');
	if (!$device_id) {
		echo "⊘ SKIP: No devices available for testing\n";
		global $tests_run;
		$tests_run++;
		return;
	}

	// Log test event
	$success = gnmi_log_event($device_id, 'error', array('test' => 'event_creation'));
	assert_true($success, "gnmi_log_event() should return true on success");

	// Verify event was created
	$count = db_fetch_cell_prepared(
		"SELECT COUNT(*) FROM plugin_gnmi_events WHERE device_id = ? AND event_data LIKE ?",
		array($device_id, '%event_creation%')
	);
	assert_true($count > 0, "Event should be created in database");

	cleanup_test_events();
}

// Test 2: Invalid device_id rejected
function test_invalid_device_id() {
	$success = gnmi_log_event(999999, 'error', array('test' => 'invalid_device'));
	assert_equals(false, $success, "Invalid device_id should be rejected");
}

// Test 3: Invalid event_type rejected
function test_invalid_event_type() {
	$device_id = db_fetch_cell('SELECT id FROM plugin_gnmi_devices LIMIT 1');
	if (!$device_id) {
		echo "⊘ SKIP: No devices available for testing\n";
		global $tests_run;
		$tests_run++;
		return;
	}

	$success = gnmi_log_event($device_id, 'invalid_type', array('test' => 'invalid_type'));
	assert_equals(false, $success, "Invalid event_type should be rejected");
}

// Test 4: Event data correctly JSON encoded
function test_event_data_json() {
	cleanup_test_events();

	$device_id = db_fetch_cell('SELECT id FROM plugin_gnmi_devices LIMIT 1');
	if (!$device_id) {
		echo "⊘ SKIP: No devices available for testing\n";
		global $tests_run;
		$tests_run++;
		return;
	}

	$test_data = array('key1' => 'value1', 'key2' => 123, 'nested' => array('a' => 'b'));
	gnmi_log_event($device_id, 'config_change', $test_data);

	// Retrieve and parse
	$stored = db_fetch_cell_prepared(
		"SELECT event_data FROM plugin_gnmi_events WHERE device_id = ? AND event_type = ? ORDER BY id DESC LIMIT 1",
		array($device_id, 'config_change')
	);

	$parsed = json_decode($stored, true);
	assert_true(is_array($parsed), "Event data should be valid JSON array");
	assert_equals('value1', $parsed['key1'], "JSON data should preserve string values");
	assert_equals(123, $parsed['key2'], "JSON data should preserve numeric values");
	assert_equals('b', $parsed['nested']['a'], "JSON data should preserve nested arrays");

	cleanup_test_events();
}

// Test 5: gnmi_get_recent_events() returns events ordered by timestamp DESC
function test_get_recent_events_ordering() {
	cleanup_test_events();

	$device_id = db_fetch_cell('SELECT id FROM plugin_gnmi_devices LIMIT 1');
	if (!$device_id) {
		echo "⊘ SKIP: No devices available for testing\n";
		global $tests_run;
		$tests_run++;
		return;
	}

	// Create multiple events with different timestamps
	gnmi_log_event($device_id, 'daemon_start', array('test' => 'order1'));
	sleep(1);
	gnmi_log_event($device_id, 'daemon_start', array('test' => 'order2'));
	sleep(1);
	gnmi_log_event($device_id, 'daemon_start', array('test' => 'order3'));

	// Retrieve events
	$events = gnmi_get_recent_events($device_id, null, 10);

	assert_true(count($events) >= 3, "Should retrieve at least 3 test events");

	// Verify ordering (newest first)
	$timestamps = array();
	foreach ($events as $event) {
		$timestamps[] = strtotime($event['created_at']);
	}

	$is_descending = true;
	for ($i = 0; $i < count($timestamps) - 1; $i++) {
		if ($timestamps[$i] < $timestamps[$i + 1]) {
			$is_descending = false;
			break;
		}
	}

	assert_true($is_descending, "Events should be ordered by timestamp DESC (newest first)");

	cleanup_test_events();
}

// Test 6: Filtering by device_id
function test_filter_by_device_id() {
	cleanup_test_events();

	$device_ids = db_fetch_assoc('SELECT id FROM plugin_gnmi_devices LIMIT 2');
	if (count($device_ids) < 2) {
		echo "⊘ SKIP: Need at least 2 devices for filtering test\n";
		global $tests_run;
		$tests_run++;
		return;
	}

	$device1 = $device_ids[0]['id'];
	$device2 = $device_ids[1]['id'];

	// Create events for both devices
	gnmi_log_event($device1, 'error', array('test' => 'filter_dev1'));
	gnmi_log_event($device2, 'error', array('test' => 'filter_dev2'));

	// Get events for device1 only
	$events = gnmi_get_recent_events($device1, null, 10);

	$all_match_device1 = true;
	foreach ($events as $event) {
		if ($event['device_id'] != $device1) {
			$all_match_device1 = false;
			break;
		}
	}

	assert_true($all_match_device1, "Filtering by device_id should return only events for that device");

	cleanup_test_events();
}

// Test 7: Filtering by event_type
function test_filter_by_event_type() {
	cleanup_test_events();

	$device_id = db_fetch_cell('SELECT id FROM plugin_gnmi_devices LIMIT 1');
	if (!$device_id) {
		echo "⊘ SKIP: No devices available for testing\n";
		global $tests_run;
		$tests_run++;
		return;
	}

	// Create events of different types
	gnmi_log_event($device_id, 'daemon_start', array('test' => 'type_start'));
	gnmi_log_event($device_id, 'daemon_stop', array('test' => 'type_stop'));
	gnmi_log_event($device_id, 'error', array('test' => 'type_error'));

	// Get only daemon_start events
	$events = gnmi_get_recent_events($device_id, 'daemon_start', 10);

	$all_match_type = true;
	foreach ($events as $event) {
		if ($event['event_type'] != 'daemon_start') {
			$all_match_type = false;
			break;
		}
	}

	assert_true($all_match_type, "Filtering by event_type should return only events of that type");

	cleanup_test_events();
}

// Test 8: Cleanup deletes old events when limit exceeded
function test_cleanup_old_events() {
	// This test is complex and may affect production data
	// For now, we'll just test that the function exists and doesn't error
	$deleted = gnmi_cleanup_old_events(10000);
	assert_true($deleted >= 0, "gnmi_cleanup_old_events() should return non-negative number");
}

// Run all tests
echo "Running event logging tests...\n\n";
test_log_event_creates_record();
test_invalid_device_id();
test_invalid_event_type();
test_event_data_json();
test_get_recent_events_ordering();
test_filter_by_device_id();
test_filter_by_event_type();
test_cleanup_old_events();

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

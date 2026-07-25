<?php
/**
 * Unit Tests for Orphan Detection Functions
 *
 * Tests gnmi_find_orphan_pids() function to ensure it correctly identifies
 * orphaned daemon processes based on various criteria.
 *
 * Run: php test_orphan_detection.php
 */

// Include Cacti environment
$no_http_headers = true;
chdir(__DIR__ . '/../../../../');
include_once('./include/cli_check.php');
include_once($config['base_path'] . '/plugins/gnmi/include/functions.php');

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

function assert_count($expected_count, $array, $message) {
	global $tests_run, $tests_passed, $tests_failed;
	$tests_run++;
	$actual_count = count($array);

	if ($expected_count === $actual_count) {
		$tests_passed++;
		echo "✓ PASS: $message (count=$actual_count)\n";
		return true;
	} else {
		$tests_failed++;
		echo "✗ FAIL: $message\n";
		echo "  Expected count: $expected_count\n";
		echo "  Actual count: $actual_count\n";
		return false;
	}
}

function assert_contains($needle, $haystack, $message) {
	global $tests_run, $tests_passed, $tests_failed;
	$tests_run++;

	if (in_array($needle, $haystack)) {
		$tests_passed++;
		echo "✓ PASS: $message\n";
		return true;
	} else {
		$tests_failed++;
		echo "✗ FAIL: $message\n";
		echo "  Looking for: " . var_export($needle, true) . "\n";
		echo "  In array: " . var_export($haystack, true) . "\n";
		return false;
	}
}

function cleanup_test_files() {
	global $test_pid_files;
	foreach ($test_pid_files as $file) {
		@unlink($file);
	}
	$test_pid_files = array();
}

echo "\n=== Test Suite 1.1: Orphan Detection (Unit Tests) ===\n\n";

$test_pid_files = array();

// Clean up only files registered by this test process.
cleanup_test_files();

/**
 * Test 1.1.1: Detect PID File Without Database Record
 */
echo "Test 1.1.1: Detect PID File Without Database Record\n";
echo "---------------------------------------------------\n";

// Setup: Create a PID file for an ID verified to be absent from the database.
$storage_dir = gnmi_get_storage_dir();
$missing_device_id = (int)db_fetch_cell(
	'SELECT COALESCE(MAX(id), 0) + 100000 FROM plugin_gnmi_devices'
);
$test_pid_file = "$storage_dir/device_{$missing_device_id}.pid";
$test_pid_files[] = $test_pid_file;
file_put_contents($test_pid_file, "99999");

// Verify the selected ID is not in the database.
$device_exists = db_fetch_cell_prepared(
	'SELECT COUNT(*) FROM plugin_gnmi_devices WHERE id = ?',
	array($missing_device_id)
);
assert_equals(0, $device_exists, "Selected orphan-test device should not exist in database");

// Run function
$orphans = gnmi_find_orphan_pids();

// Extract device_ids from orphans
$orphan_device_ids = array_column($orphans, 'device_id');

// Validation
assert_contains($missing_device_id, $orphan_device_ids, "Should detect the selected missing device as orphan");

// Verify orphan has correct structure
$missing_orphan = null;
foreach ($orphans as $orphan) {
	if ($orphan['device_id'] == $missing_device_id) {
		$missing_orphan = $orphan;
		break;
	}
}

if ($missing_orphan) {
	assert_equals(99999, $missing_orphan['pid'], "Should have correct PID for orphan");
	assert_equals('not_in_db', $missing_orphan['reason'], "Reason should be 'not_in_db'");
	assert_equals($test_pid_file, $missing_orphan['pid_file'], "Should have correct PID file path");
}

// Cleanup
@unlink($test_pid_file);

echo "\n";

/**
 * Test 1.1.2: Detect Daemon for Disabled Device
 */
echo "Test 1.1.2: Detect Daemon for Disabled Device\n";
echo "----------------------------------------------\n";

// Setup: Create device with enabled=0 and PID file
// Use an existing host_id to satisfy FK constraint
$existing_host = db_fetch_row('SELECT id FROM host LIMIT 1');
if (empty($existing_host)) {
	echo "⊘ SKIP: No hosts in database to test with\n\n";
} else {
	$test_host_id = $existing_host['id'];
	// Insert disabled device into database
	db_execute_prepared(
		'INSERT INTO plugin_gnmi_devices (host_id, enabled, hostname, port, username, password, use_tls, skip_verify, collection_interval, encoding) ' .
		'VALUES (?, 0, ?, 9339, ?, ?, 1, 0, 10, ?)',
		array($test_host_id, 'test-disabled.example.com', 'testuser', 'testpass', 'JSON_IETF')
	);
	$test_device_id = (int)db_fetch_insert_id();

	// Create PID file
	$test_pid_file_disabled = "$storage_dir/device_{$test_device_id}.pid";
	$test_pid_files[] = $test_pid_file_disabled;
	file_put_contents($test_pid_file_disabled, "88888");

	// Run function
	$orphans = gnmi_find_orphan_pids();
	$orphan_device_ids = array_column($orphans, 'device_id');

	// Validation
	assert_contains($test_device_id, $orphan_device_ids, "Should detect the disabled test device as orphan");

	// Verify reason is 'disabled'
	$disabled_orphan = null;
	foreach ($orphans as $orphan) {
		if ($orphan['device_id'] == $test_device_id) {
			$disabled_orphan = $orphan;
			break;
		}
	}

	if ($disabled_orphan) {
		assert_equals('disabled', $disabled_orphan['reason'], "Reason should be 'disabled' for device with enabled=0");
	}

	// Cleanup
	db_execute_prepared('DELETE FROM plugin_gnmi_devices WHERE id = ?', array($test_device_id));
	@unlink($test_pid_file_disabled);
}

echo "\n";

/**
 * Test 1.1.3: Do NOT Detect Valid Enabled Devices
 */
echo "Test 1.1.3: Do NOT Detect Valid Enabled Devices\n";
echo "------------------------------------------------\n";

// Use a dedicated enabled test device rather than touching a live daemon PID.
$existing_host = db_fetch_row('SELECT id FROM host LIMIT 1');
if (!empty($existing_host)) {
	db_execute_prepared(
		'INSERT INTO plugin_gnmi_devices (host_id, enabled, hostname, port, username, password, use_tls, skip_verify, collection_interval, encoding) ' .
		'VALUES (?, 1, ?, 9339, ?, ?, 1, 0, 10, ?)',
		array($existing_host['id'], 'test-enabled.example.com', 'testuser', 'testpass', 'JSON_IETF')
	);
	$valid_device_id = (int)db_fetch_insert_id();
	$valid_pid_file = "$storage_dir/device_{$valid_device_id}.pid";
	$test_pid_files[] = $valid_pid_file;
	file_put_contents($valid_pid_file, "77777");

	// Run function
	$orphans = gnmi_find_orphan_pids();
	$orphan_device_ids = array_column($orphans, 'device_id');

	// Validation: Valid device should NOT be in orphans list
	if (!in_array($valid_device_id, $orphan_device_ids)) {
		echo "✓ PASS: Valid enabled device ($valid_device_id) not detected as orphan\n";
		$tests_passed++;
	} else {
		echo "✗ FAIL: Valid enabled device ($valid_device_id) incorrectly detected as orphan\n";
		$tests_failed++;
	}
	$tests_run++;

	db_execute_prepared('DELETE FROM plugin_gnmi_devices WHERE id = ?', array($valid_device_id));
	@unlink($valid_pid_file);
} else {
	echo "⊘ SKIP: No hosts in database to test with\n";
}

echo "\n";

/**
 * Test 1.1.4: Empty Result When No Orphans
 */
echo "Test 1.1.4: Empty Result When No Orphans\n";
echo "-----------------------------------------\n";

// Setup: Ensure no orphan PID files exist
cleanup_test_files();

// Run function
$orphans = gnmi_find_orphan_pids();

// Validation: Should return empty array or only valid devices
// Since we can't control what's in production DB, just verify structure
if (is_array($orphans)) {
	echo "✓ PASS: Function returns array (count=" . count($orphans) . ")\n";
	$tests_passed++;

	// Verify structure if any orphans found
	foreach ($orphans as $orphan) {
		if (!isset($orphan['device_id']) || !isset($orphan['pid']) || !isset($orphan['reason'])) {
			echo "✗ FAIL: Orphan array structure incorrect\n";
			echo "  Got: " . var_export($orphan, true) . "\n";
			$tests_failed++;
			$tests_run++;
			break;
		}
	}
} else {
	echo "✗ FAIL: Function should return array, got: " . gettype($orphans) . "\n";
	$tests_failed++;
}
$tests_run++;

echo "\n";

/**
 * Test 1.1.5: Process orphans without PID files are safe to clean up
 */
echo "Test 1.1.5: Null PID File Guard\n";
echo "--------------------------------\n";

if (gnmi_remove_orphan_pid_file(null) === false &&
	gnmi_remove_orphan_pid_file('') === false) {
	echo "✓ PASS: Null/empty PID file paths are ignored safely\n";
	$tests_passed++;
} else {
	echo "✗ FAIL: Null/empty PID file paths should not be removed\n";
	$tests_failed++;
}
$tests_run++;

echo "\n";

// Final cleanup
cleanup_test_files();

// Summary
echo "========================================\n";
echo "Test Summary\n";
echo "========================================\n";
echo "Tests Run:    $tests_run\n";
echo "Tests Passed: $tests_passed\n";
echo "Tests Failed: $tests_failed\n";
echo "Success Rate: " . round(($tests_passed / max($tests_run, 1)) * 100, 1) . "%\n";
echo "\n";

if ($tests_failed > 0) {
	echo "❌ SOME TESTS FAILED\n";
	exit(1);
} else {
	echo "✅ ALL TESTS PASSED\n";
	exit(0);
}
?>

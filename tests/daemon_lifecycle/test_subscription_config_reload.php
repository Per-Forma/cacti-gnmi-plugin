<?php
/**
 * Unit Tests for Subscription Config Change Detection
 *
 * Tests gnmi_check_subscription_config_changed() function to ensure it correctly
 * detects when subscription/metric configuration in the database differs from
 * the on-disk daemon config file.
 *
 * Run: php test_subscription_config_reload.php
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

function assert_equals_test($expected, $actual, $message) {
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

// Use a temporary directory for test config files
$test_storage_dir = sys_get_temp_dir() . '/gnmi_test_config_reload_' . getmypid();
@mkdir($test_storage_dir, 0755, true);

function unused_test_device_id($start) {
	for ($candidate = $start; $candidate > $start - 1000; $candidate--) {
		$count = (int)db_fetch_cell_prepared(
			'SELECT COUNT(*) FROM plugin_gnmi_devices WHERE id = ?',
			array($candidate)
		);
		if ($count === 0) {
			return $candidate;
		}
	}
	throw new RuntimeException('Unable to find an unused device ID for config reload tests');
}

$test_device_id = unused_test_device_id(1900000000);
$missing_device_id = unused_test_device_id($test_device_id - 1);
$corrupt_device_id = unused_test_device_id($missing_device_id - 1);

echo "\n=== Test Suite: Subscription Config Change Detection ===\n\n";

/**
 * Helper: Write a config file to the test storage directory
 */
function write_test_config($device_id, $config_data) {
	global $test_storage_dir;
	$path = "$test_storage_dir/device_{$device_id}_config.json";
	file_put_contents($path, json_encode($config_data, JSON_PRETTY_PRINT));
	return $path;
}

/**
 * Helper: Remove a config file from the test storage directory
 */
function remove_test_config($device_id) {
	global $test_storage_dir;
	$path = "$test_storage_dir/device_{$device_id}_config.json";
	if (file_exists($path)) {
		unlink($path);
	}
}

/**
 * Test 1: Config match returns false (no restart needed)
 */
echo "Test 1: Config match - no restart needed\n";
echo "-----------------------------------------\n";

$disk_config = [
	'device_id' => $test_device_id,
	'hostname' => '192.168.1.1',
	'subscriptions' => [
		[
			'path' => '/interfaces/interface/state/counters',
			'instance' => 'ettp-40',
			'metrics' => ['in-octets'],
			'field_mapping' => ['in-octets' => 'in_octets']
		]
	]
];

// Write config to disk and compare with identical DB config
write_test_config($test_device_id, $disk_config);

$db_subscriptions = [
	[
		'path' => '/interfaces/interface/state/counters',
		'instance' => 'ettp-40',
		'metrics' => ['in-octets'],
		'field_mapping' => ['in-octets' => 'in_octets']
	]
];

$result = gnmi_check_subscription_config_changed($test_device_id, $db_subscriptions, $test_storage_dir);
assert_equals_test(false, $result, "Identical config should return false (no restart needed)");

echo "\n";

/**
 * Test 2: New metric added returns true
 */
echo "Test 2: New metric added - restart needed\n";
echo "------------------------------------------\n";

// Disk has 1 metric, DB has 2
$disk_config_2 = [
	'device_id' => $test_device_id,
	'subscriptions' => [
		[
			'path' => '/interfaces/interface/state/counters',
			'instance' => 'ettp-40',
			'metrics' => ['in-octets'],
			'field_mapping' => ['in-octets' => 'in_octets']
		]
	]
];
write_test_config($test_device_id, $disk_config_2);

$db_subscriptions_2 = [
	[
		'path' => '/interfaces/interface/state/counters',
		'instance' => 'ettp-40',
		'metrics' => ['in-octets', 'out-octets'],
		'field_mapping' => ['in-octets' => 'in_octets', 'out-octets' => 'out_octets']
	]
];

$result = gnmi_check_subscription_config_changed($test_device_id, $db_subscriptions_2, $test_storage_dir);
assert_equals_test(true, $result, "New metric added should return true (restart needed)");

echo "\n";

/**
 * Test 3: Metric removed returns true
 */
echo "Test 3: Metric removed - restart needed\n";
echo "----------------------------------------\n";

// Disk has 3 metrics, DB has 2
$disk_config_3 = [
	'device_id' => $test_device_id,
	'subscriptions' => [
		[
			'path' => '/interfaces/interface/state/counters',
			'instance' => 'ettp-40',
			'metrics' => ['in-octets', 'out-octets', 'in-errors'],
			'field_mapping' => ['in-octets' => 'in_octets', 'out-octets' => 'out_octets', 'in-errors' => 'in_errors']
		]
	]
];
write_test_config($test_device_id, $disk_config_3);

$db_subscriptions_3 = [
	[
		'path' => '/interfaces/interface/state/counters',
		'instance' => 'ettp-40',
		'metrics' => ['in-octets', 'out-octets'],
		'field_mapping' => ['in-octets' => 'in_octets', 'out-octets' => 'out_octets']
	]
];

$result = gnmi_check_subscription_config_changed($test_device_id, $db_subscriptions_3, $test_storage_dir);
assert_equals_test(true, $result, "Metric removed should return true (restart needed)");

echo "\n";

/**
 * Test 4: Subscription path changed returns true
 */
echo "Test 4: Subscription path changed - restart needed\n";
echo "---------------------------------------------------\n";

$disk_config_4 = [
	'device_id' => $test_device_id,
	'subscriptions' => [
		[
			'path' => '/interfaces/interface/state/counters',
			'instance' => 'ettp-40',
			'metrics' => ['in-octets'],
			'field_mapping' => ['in-octets' => 'in_octets']
		]
	]
];
write_test_config($test_device_id, $disk_config_4);

$db_subscriptions_4 = [
	[
		'path' => '/interfaces/interface/state/counters/updated',
		'instance' => 'ettp-40',
		'metrics' => ['in-octets'],
		'field_mapping' => ['in-octets' => 'in_octets']
	]
];

$result = gnmi_check_subscription_config_changed($test_device_id, $db_subscriptions_4, $test_storage_dir);
assert_equals_test(true, $result, "Changed subscription path should return true (restart needed)");

echo "\n";

/**
 * Test 5: Missing config file returns true (needs fresh config)
 */
echo "Test 5: Missing config file - restart needed\n";
echo "---------------------------------------------\n";

remove_test_config($missing_device_id);

$db_subscriptions_5 = [
	[
		'path' => '/interfaces/interface/state/counters',
		'instance' => 'ettp-40',
		'metrics' => ['in-octets'],
		'field_mapping' => ['in-octets' => 'in_octets']
	]
];

$result = gnmi_check_subscription_config_changed($missing_device_id, $db_subscriptions_5, $test_storage_dir);
assert_equals_test(true, $result, "Missing config file should return true (restart needed)");

echo "\n";

/**
 * Test 6: Corrupt/invalid JSON on disk returns true
 */
echo "Test 6: Corrupt config file - restart needed\n";
echo "---------------------------------------------\n";

$corrupt_path = "$test_storage_dir/device_{$corrupt_device_id}_config.json";
file_put_contents($corrupt_path, "this is not valid json {{{");

$db_subscriptions_6 = [
	[
		'path' => '/interfaces/interface/state/counters',
		'instance' => 'ettp-40',
		'metrics' => ['in-octets'],
		'field_mapping' => ['in-octets' => 'in_octets']
	]
];

$result = gnmi_check_subscription_config_changed($corrupt_device_id, $db_subscriptions_6, $test_storage_dir);
assert_equals_test(true, $result, "Corrupt config file should return true (restart needed)");

echo "\n";

// Cleanup test files
$test_files = glob("$test_storage_dir/device_*.json");
foreach ($test_files as $f) {
	@unlink($f);
}
@rmdir($test_storage_dir);

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

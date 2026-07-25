<?php
/**
 * Unit Tests for gnmi_get_poller_interval() Helper
 *
 * Tests that the poller interval helper correctly reads from Cacti settings,
 * returns an integer, and falls back to 300 when the value is missing or invalid.
 *
 * Run: php test_poller_interval.php
 */

// Include Cacti environment
$no_http_headers = true;
chdir(__DIR__ . '/../../../');
include_once('./include/cli_check.php');
include_once($config['base_path'] . '/plugins/gnmi/include/functions.php');

// Test results tracking
$tests_run     = 0;
$tests_passed  = 0;
$tests_failed  = 0;

// ---------------------------------------------------------------------------
// Assertion helpers
// ---------------------------------------------------------------------------

function assert_equals_pi($expected, $actual, $message) {
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
		echo "  Actual:   " . var_export($actual, true) . "\n";
		return false;
	}
}

function assert_true_pi($condition, $message) {
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

// ---------------------------------------------------------------------------
// Helper: set / clear the Cacti settings.poller_interval row
// ---------------------------------------------------------------------------

function pi_set_setting($value) {
	// Direct DB manipulation is allowed for test scaffolding (SELECT + write helpers
	// required to set up controlled test state). This is test infrastructure only.
	db_execute_prepared(
		"REPLACE INTO settings (name, value) VALUES ('poller_interval', ?)",
		array($value)
	);
}

function pi_remove_setting() {
	db_execute("DELETE FROM settings WHERE name = 'poller_interval'");
}

// ---------------------------------------------------------------------------
// Test functions
// ---------------------------------------------------------------------------

echo "=== gnmi_get_poller_interval() Tests ===\n\n";

// Test 1: Return type is always an integer
function test_returns_integer() {
	pi_set_setting('60');
	$result = gnmi_get_poller_interval(true);
	assert_true_pi(is_int($result), 'gnmi_get_poller_interval() must return an integer');
}

// Test 2: Reads the correct value from the settings table (10s)
function test_reads_10_second_interval() {
	pi_set_setting('10');
	$result = gnmi_get_poller_interval(true);
	assert_equals_pi(10, $result, 'Returns 10 when poller_interval=10');
}

// Test 3: Reads the correct value from the settings table (60s)
function test_reads_60_second_interval() {
	pi_set_setting('60');
	$result = gnmi_get_poller_interval(true);
	assert_equals_pi(60, $result, 'Returns 60 when poller_interval=60');
}

// Test 4: Reads the correct value from the settings table (300s)
function test_reads_300_second_interval() {
	pi_set_setting('300');
	$result = gnmi_get_poller_interval(true);
	assert_equals_pi(300, $result, 'Returns 300 when poller_interval=300');
}

// Test 5: Falls back to 300 when the row is absent
function test_fallback_when_missing() {
	pi_remove_setting();
	$result = gnmi_get_poller_interval(true);
	assert_equals_pi(300, $result, 'Falls back to 300 when poller_interval row is absent');
}

// Test 6: Falls back to 300 when the value is empty string
function test_fallback_on_empty_string() {
	pi_set_setting('');
	$result = gnmi_get_poller_interval(true);
	assert_equals_pi(300, $result, 'Falls back to 300 when poller_interval is empty string');
}

// Test 7: Falls back to 300 when the value is non-numeric
function test_fallback_on_non_numeric() {
	pi_set_setting('invalid');
	$result = gnmi_get_poller_interval(true);
	assert_equals_pi(300, $result, 'Falls back to 300 when poller_interval is non-numeric');
}

// Test 8: Falls back to 300 when the value is zero
function test_fallback_on_zero() {
	pi_set_setting('0');
	$result = gnmi_get_poller_interval(true);
	assert_equals_pi(300, $result, 'Falls back to 300 when poller_interval is zero');
}

// ---------------------------------------------------------------------------
// Run tests, then restore whatever was in settings before we started
// ---------------------------------------------------------------------------

// Preserve pre-existing value so we don't pollute the dev environment
$original_value = db_fetch_cell("SELECT value FROM settings WHERE name = 'poller_interval'");

echo "Running tests...\n\n";

test_returns_integer();
test_reads_10_second_interval();
test_reads_60_second_interval();
test_reads_300_second_interval();
test_fallback_when_missing();
test_fallback_on_empty_string();
test_fallback_on_non_numeric();
test_fallback_on_zero();

// Restore original state
if ($original_value !== false && $original_value !== null) {
	pi_set_setting($original_value);
} else {
	pi_remove_setting();
}

// ---------------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------------

echo "\n=== Test Summary ===\n";
echo "Total:  $tests_run\n";
echo "Passed: $tests_passed\n";
echo "Failed: $tests_failed\n";

if ($tests_failed > 0) {
	echo "\n⚠ TESTS FAILED\n";
	exit(1);
} else {
	echo "\n✓ ALL TESTS PASSED\n";
	exit(0);
}

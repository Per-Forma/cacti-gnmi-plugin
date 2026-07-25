<?php
/**
 * Unit Tests for Config Change Detection
 *
 * Tests gnmi_detect_config_changes() function to ensure it correctly identifies
 * which configuration fields changed between database and form POST.
 *
 * Run: php test_config_detection.php
 */

// Include Cacti environment
$no_http_headers = true;
chdir(__DIR__ . '/../../../../');
include_once('./include/cli_check.php');
include_once($config['base_path'] . '/plugins/gnmi/include/form_functions.php');

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

function assert_contains_test($needle, $haystack, $message) {
	global $tests_run, $tests_passed, $tests_failed;
	$tests_run++;

	if (in_array($needle, $haystack)) {
		$tests_passed++;
		echo "✓ PASS: $message\n";
		return true;
	} else {
		$tests_failed++;
		echo "✗ FAIL: $message\n";
		return false;
	}
}

function assert_not_contains_test($needle, $haystack, $message) {
	global $tests_run, $tests_passed, $tests_failed;
	$tests_run++;

	if (!in_array($needle, $haystack)) {
		$tests_passed++;
		echo "✓ PASS: $message\n";
		return true;
	} else {
		$tests_failed++;
		echo "✗ FAIL: $message\n";
		return false;
	}
}

echo "\n=== Test Suite 2.1: Config Change Detection (Unit Tests) ===\n\n";

/**
 * Test 2.1.1: Detect Hostname Change
 */
echo "Test 2.1.1: Detect Hostname Change\n";
echo "------------------------------------\n";

$existing_device = [
	'hostname' => '192.168.1.1',
	'port' => 9339,
	'username' => 'admin',
	'password' => 'pass123'
];

$post_data = [
	'gnmi_hostname' => '192.168.1.2',  // Changed
	'gnmi_port' => '9339',              // Unchanged
	'gnmi_username' => 'admin',        // Unchanged
	'gnmi_password' => 'pass123'       // Unchanged
];

$changed = gnmi_detect_config_changes($existing_device, $post_data);

assert_contains_test('hostname', $changed, "Should detect hostname change");
assert_not_contains_test('port', $changed, "Should NOT detect unchanged port");
assert_not_contains_test('username', $changed, "Should NOT detect unchanged username");

echo "\n";

/**
 * Test 2.1.2: Detect Multiple Changes
 */
echo "Test 2.1.2: Detect Multiple Changes\n";
echo "------------------------------------\n";

$existing_device = [
	'hostname' => 'A',
	'port' => 9339,
	'username' => 'user1',
	'password' => 'pass1'
];

$post_data = [
	'gnmi_hostname' => 'B',      // Changed
	'gnmi_port' => '9340',       // Changed
	'gnmi_username' => 'user2',  // Changed
	'gnmi_password' => 'pass1'   // Unchanged
];

$changed = gnmi_detect_config_changes($existing_device, $post_data);

assert_contains_test('hostname', $changed, "Should detect hostname change");
assert_contains_test('port', $changed, "Should detect port change");
assert_contains_test('username', $changed, "Should detect username change");
assert_not_contains_test('password', $changed, "Should NOT detect unchanged password");
assert_equals_test(3, count($changed), "Should have exactly 3 changes");

echo "\n";

/**
 * Test 2.1.3: Detect TLS Certificate Changes
 */
echo "Test 2.1.3: Detect TLS Certificate Changes\n";
echo "-------------------------------------------\n";

$existing_device = [
	'ca_cert_path' => '/old/ca.pem',
	'client_key_path' => '/old/key.pem',
	'client_cert_path' => '/old/cert.pem',
	'hostname' => '192.168.1.1',
	'port' => 9339
];

$post_data = [
	'ca_cert_path' => '/new/ca.pem',      // Changed
	'client_key_path' => '/old/key.pem',  // Unchanged
	'client_cert_path' => '/old/cert.pem', // Unchanged
	'gnmi_hostname' => '192.168.1.1',     // Unchanged
	'gnmi_port' => '9339'                  // Unchanged
];

$changed = gnmi_detect_config_changes($existing_device, $post_data);

assert_contains_test('ca_cert_path', $changed, "Should detect CA cert path change");
assert_not_contains_test('client_key_path', $changed, "Should NOT detect unchanged client key");
assert_not_contains_test('hostname', $changed, "Should NOT detect unchanged hostname");

echo "\n";

/**
 * Test 2.1.4: No Changes Detected
 */
echo "Test 2.1.4: No Changes Detected\n";
echo "--------------------------------\n";

$existing_device = [
	'hostname' => '192.168.1.1',
	'port' => 9339,
	'username' => 'admin',
	'use_tls' => 1,
	'skip_verify' => 0
];

$post_data = [
	'gnmi_hostname' => '192.168.1.1',  // Same
	'gnmi_port' => '9339',              // Same
	'gnmi_username' => 'admin',         // Same
	'use_tls' => '1',                   // Same (checkbox checked)
	// skip_verify not in POST = unchecked = 0 = same
];

$changed = gnmi_detect_config_changes($existing_device, $post_data);

assert_equals_test(0, count($changed), "Should have 0 changes");
assert_equals_test([], $changed, "Should return empty array");

echo "\n";

/**
 * Test 2.1.5: Detect Boolean Field Changes
 */
echo "Test 2.1.5: Detect Boolean Field Changes\n";
echo "-----------------------------------------\n";

$existing_device = [
	'use_tls' => 1,       // TLS enabled
	'skip_verify' => 0,   // Verify enabled
	'hostname' => '192.168.1.1'
];

$post_data = [
	'use_tls' => '0',            // TLS disabled (changed)
	'skip_verify' => '1',        // Verify disabled (changed)
	'gnmi_hostname' => '192.168.1.1'
];

$changed = gnmi_detect_config_changes($existing_device, $post_data);

assert_contains_test('use_tls', $changed, "Should detect use_tls change");
assert_contains_test('skip_verify', $changed, "Should detect skip_verify change");

echo "\n";

/**
 * Test 2.1.6: New Config Defaults to Device Hostname
 */
echo "Test 2.1.6: New Config Defaults to Device Hostname\n";
echo "---------------------------------------------------\n";

$resolved = gnmi_resolve_hostname('device', '', 'router-01.example.com');

assert_equals_test('router-01.example.com', $resolved['hostname'], "Should use device hostname for inherited config");
assert_equals_test('device', $resolved['hostname_source'], "Should persist device source");

echo "\n";

/**
 * Test 2.1.7: Inherited Hostname Follows Device Change
 */
echo "Test 2.1.7: Inherited Hostname Follows Device Change\n";
echo "-----------------------------------------------------\n";

$existing_device = [
	'hostname' => 'router-old.example.com',
	'hostname_source' => 'device'
];
$resolved = gnmi_resolve_hostname('device', 'router-old.example.com', 'router-new.example.com');
$changed = gnmi_detect_config_changes($existing_device, [
	'gnmi_hostname' => $resolved['hostname']
]);

assert_equals_test('router-new.example.com', $resolved['hostname'], "Should resolve the new device hostname");
assert_contains_test('hostname', $changed, "Should detect inherited hostname change for daemon restart");

echo "\n";

/**
 * Test 2.1.8: Custom Hostname Ignores Device Change
 */
echo "Test 2.1.8: Custom Hostname Ignores Device Change\n";
echo "--------------------------------------------------\n";

$existing_device = [
	'hostname' => 'gnmi-router.example.net',
	'hostname_source' => 'custom'
];
$resolved = gnmi_resolve_hostname('custom', 'gnmi-router.example.net', 'router-new.example.com');
$changed = gnmi_detect_config_changes($existing_device, [
	'gnmi_hostname' => $resolved['hostname']
]);

assert_equals_test('gnmi-router.example.net', $resolved['hostname'], "Should preserve custom gNMI hostname");
assert_not_contains_test('hostname', $changed, "Should not restart for an unrelated device hostname change");

echo "\n";

/**
 * Test 2.1.9: Direct Edit Selects Custom Source
 */
echo "Test 2.1.9: Direct Edit Selects Custom Source\n";
echo "----------------------------------------------\n";

$resolved = gnmi_resolve_hostname('custom', 'telemetry.example.net', 'router.example.com');

assert_equals_test('telemetry.example.net', $resolved['hostname'], "Should use directly edited gNMI hostname");
assert_equals_test('custom', $resolved['hostname_source'], "Should persist custom source after direct edit");

echo "\n";

/**
 * Test 2.1.10: Reset Restores Device Inheritance
 */
echo "Test 2.1.10: Reset Restores Device Inheritance\n";
echo "-----------------------------------------------\n";

$resolved = gnmi_resolve_hostname('device', 'telemetry.example.net', 'router.example.com');

assert_equals_test('router.example.com', $resolved['hostname'], "Should replace custom hostname after reset");
assert_equals_test('device', $resolved['hostname_source'], "Should restore device source after reset");

echo "\n";

/**
 * Test 2.1.11: Compatibility Mode Is Explicit And Restart-Sensitive
 */
echo "Test 2.1.11: Detect Compatibility Mode Change\n";
echo "------------------------------------------------\n";

$changed = gnmi_detect_config_changes(
	['compatibility_mode' => 'standard'],
	['compatibility_mode' => 'ciena_saos10']
);

assert_contains_test('compatibility_mode', $changed, "Should restart when Ciena compatibility mode is selected");
assert_not_contains_test(
	'compatibility_mode',
	gnmi_detect_config_changes(['compatibility_mode' => 'standard'], []),
	"Missing legacy POST field should retain standard compatibility mode"
);
assert_equals_test('standard', gnmi_normalize_compatibility_mode('unexpected'), "Unknown compatibility modes should normalize to standard");
assert_equals_test('ciena_saos10', gnmi_normalize_compatibility_mode('ciena_saos10'), "Ciena compatibility mode should be preserved");
assert_equals_test('JSON_IETF', gnmi_encoding_for_compatibility_mode('standard'), "Standard mode should use JSON_IETF");
assert_equals_test('JSON', gnmi_encoding_for_compatibility_mode('ciena_saos10'), "Ciena mode should use legacy JSON");

$changed = gnmi_detect_config_changes(
	['compatibility_mode' => 'ciena_saos10', 'encoding' => 'JSON_IETF'],
	['compatibility_mode' => 'ciena_saos10', 'encoding' => 'JSON']
);
assert_contains_test('encoding', $changed, "Should restart when Ciena wire encoding is corrected");

$errors = gnmi_validate_form_input([
	'gnmi_hostname' => 'router',
	'gnmi_port' => '9339',
	'gnmi_username' => 'user',
	'gnmi_password' => 'pass',
	'compatibility_mode' => 'unexpected',
]);
assert_contains_test('Compatibility Mode is invalid', $errors, "Should reject an invalid compatibility mode");

echo "\n";

/**
 * Test 2.1.12: Credentials Preserve Protocol-Significant Punctuation
 */
echo "Test 2.1.12: Preserve Credential Punctuation\n";
echo "------------------------------------------------\n";

assert_equals_test(
	'NokiaSrl1!',
	gnmi_preserve_credential('NokiaSrl1!'),
	"Should preserve the SR Linux Containerlab password exactly"
);
assert_equals_test(
	'user+telemetry@example.net',
	gnmi_preserve_credential('user+telemetry@example.net'),
	"Should preserve punctuation in gNMI usernames"
);

echo "\n";

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

#!/usr/bin/env php
<?php
/**
 * Tests for gNMI data source profile heartbeat derivation.
 *
 * Run from a full Cacti install:
 *   php plugins/gnmi/tests/test_profile_heartbeat.php
 */

$no_http_headers = true;
chdir(__DIR__ . '/../../../');
include_once('./include/cli_check.php');
include_once($config['base_path'] . '/plugins/gnmi/setup.php');
include_once($config['base_path'] . '/plugins/gnmi/include/functions.php');

$tests_run = 0;
$tests_passed = 0;
$tests_failed = 0;
$test_profile_prefix = 'gNMI Test Interval Profile ' . getmypid() . ' ';

function profile_heartbeat_assert_equals($expected, $actual, $message) {
	global $tests_run, $tests_passed, $tests_failed;
	$tests_run++;

	if ($expected === $actual) {
		$tests_passed++;
		echo "PASS: $message\n";
		return;
	}

	$tests_failed++;
	echo "FAIL: $message\n";
	echo "  Expected: " . var_export($expected, true) . "\n";
	echo "  Actual:   " . var_export($actual, true) . "\n";
}

function profile_heartbeat_assert_true($condition, $message) {
	global $tests_run, $tests_passed, $tests_failed;
	$tests_run++;

	if ($condition) {
		$tests_passed++;
		echo "PASS: $message\n";
		return;
	}

	$tests_failed++;
	echo "FAIL: $message\n";
}

function profile_heartbeat_set_poller_interval($value) {
	db_execute_prepared(
		"REPLACE INTO settings (name, value) VALUES ('poller_interval', ?)",
		array($value)
	);
	gnmi_get_poller_interval(true);
}

function profile_heartbeat_remove_poller_interval() {
	db_execute("DELETE FROM settings WHERE name = 'poller_interval'");
	gnmi_get_poller_interval(true);
}

function profile_heartbeat_cleanup_profile($profile_name) {
	$profile_ids = db_fetch_assoc_prepared(
		'SELECT id FROM data_source_profiles WHERE name = ?',
		array($profile_name)
	);

	if (!is_array($profile_ids)) {
		return;
	}

	foreach ($profile_ids as $row) {
		$profile_id = (int)$row['id'];
		db_execute_prepared('DELETE FROM data_source_profiles_cf WHERE data_source_profile_id = ?', array($profile_id));
		db_execute_prepared('DELETE FROM data_source_profiles_rra WHERE data_source_profile_id = ?', array($profile_id));
		db_execute_prepared('DELETE FROM data_source_profiles WHERE id = ?', array($profile_id));
	}
}

function profile_heartbeat_verify_profile($profile_name, $expected_heartbeat) {
	$profile_id = gnmi_ensure_data_source_profile($profile_name);
	profile_heartbeat_assert_true($profile_id !== false && (int)$profile_id > 0, "$profile_name: helper returns profile id");

	if ($profile_id === false) {
		return false;
	}

	$profile = db_fetch_row_prepared(
		'SELECT step, heartbeat FROM data_source_profiles WHERE id = ?',
		array($profile_id)
	);

	profile_heartbeat_assert_true(is_array($profile), "$profile_name: profile row exists");
	if (!is_array($profile)) {
		return false;
	}

	profile_heartbeat_assert_equals(10, (int)$profile['step'], "$profile_name: profile step remains 10 seconds");
	profile_heartbeat_assert_equals($expected_heartbeat, (int)$profile['heartbeat'], "$profile_name: heartbeat derives from poller interval");

	$cf_count = (int)db_fetch_cell_prepared(
		'SELECT COUNT(*) FROM data_source_profiles_cf WHERE data_source_profile_id = ?',
		array($profile_id)
	);
	profile_heartbeat_assert_equals(4, $cf_count, "$profile_name: four consolidation functions linked");

	$rra_count = (int)db_fetch_cell_prepared(
		'SELECT COUNT(*) FROM data_source_profiles_rra WHERE data_source_profile_id = ?',
		array($profile_id)
	);
	profile_heartbeat_assert_equals(4, $rra_count, "$profile_name: four RRA definitions exist");

	return (int)$profile_id;
}

function profile_heartbeat_test_10_second_profile() {
	global $test_profile_prefix;
	$profile_name = $test_profile_prefix . '10s';
	profile_heartbeat_cleanup_profile($profile_name);
	profile_heartbeat_set_poller_interval('10');
	profile_heartbeat_verify_profile($profile_name, 20);
	profile_heartbeat_cleanup_profile($profile_name);
}

function profile_heartbeat_test_existing_profile_is_updated() {
	global $test_profile_prefix;
	$profile_name = $test_profile_prefix . '300s update';
	profile_heartbeat_cleanup_profile($profile_name);
	profile_heartbeat_set_poller_interval('60');

	db_execute_prepared(
		'INSERT INTO data_source_profiles (hash, name, step, heartbeat) VALUES (?, ?, 10, 120)',
		array(generate_hash(), $profile_name)
	);
	$existing_id = (int)db_fetch_insert_id();

	profile_heartbeat_set_poller_interval('300');
	$profile_id = profile_heartbeat_verify_profile($profile_name, 600);
	profile_heartbeat_assert_equals($existing_id, $profile_id, "$profile_name: existing profile is updated in place");
	profile_heartbeat_cleanup_profile($profile_name);
}

function profile_heartbeat_test_missing_setting_falls_back_to_600() {
	global $test_profile_prefix;
	$profile_name = $test_profile_prefix . 'fallback';
	profile_heartbeat_cleanup_profile($profile_name);
	profile_heartbeat_remove_poller_interval();
	profile_heartbeat_verify_profile($profile_name, 600);
	profile_heartbeat_cleanup_profile($profile_name);
}

$original_exists = (int)db_fetch_cell("SELECT COUNT(*) FROM settings WHERE name = 'poller_interval'");
$original_value = db_fetch_cell("SELECT value FROM settings WHERE name = 'poller_interval'");

echo "Running gNMI profile heartbeat tests...\n";
echo "=======================================\n";

try {
	profile_heartbeat_assert_true(
		function_exists('gnmi_ensure_data_source_profile'),
		'gnmi_ensure_data_source_profile() exists'
	);

	if (function_exists('gnmi_ensure_data_source_profile')) {
		profile_heartbeat_test_10_second_profile();
		profile_heartbeat_test_existing_profile_is_updated();
		profile_heartbeat_test_missing_setting_falls_back_to_600();
	}
} finally {
	if ($original_exists > 0) {
		profile_heartbeat_set_poller_interval($original_value);
	} else {
		profile_heartbeat_remove_poller_interval();
	}
}

echo "\nResults: $tests_passed / $tests_run passed";
if ($tests_failed > 0) {
	echo ", $tests_failed FAILED\n";
	exit(1);
}

echo "\n";
exit(0);

<?php
/**
 * Standalone regression tests for dashboard mutation authorization.
 */

define('CACTI_VERSION', 'test');
define('MESSAGE_LEVEL_ERROR', 1);
define('MESSAGE_LEVEL_INFO', 2);

$authorized = false;
$csrf_valid = false;
$restart_calls = 0;
$cleanup_calls = 0;
$event_calls = 0;
$tests_run = 0;
$tests_failed = 0;

function __($message) {
	$args = func_get_args();
	array_shift($args);
	if (!empty($args) && end($args) === 'gnmi') {
		array_pop($args);
	}
	return empty($args) ? $message : vsprintf($message, $args);
}

function gnmi_current_user_can_manage() {
	global $authorized;
	return $authorized;
}

function gnmi_validate_csrf_request() {
	global $csrf_valid;
	return $csrf_valid;
}

function db_fetch_row_prepared() {
	return array('id' => 7);
}

function db_fetch_cell() {
	return 7;
}

function gnmi_restart_daemon() {
	global $restart_calls;
	$restart_calls++;
	return true;
}

function gnmi_cleanup_orphans() {
	global $cleanup_calls;
	$cleanup_calls++;
}

function gnmi_log_event() {
	global $event_calls;
	$event_calls++;
	return true;
}

function test_assert($condition, $message) {
	global $tests_run, $tests_failed;
	$tests_run++;
	if (!$condition) {
		$tests_failed++;
		echo "FAIL: $message\n";
	}
}

function reset_action_state() {
	global $authorized, $csrf_valid, $restart_calls, $cleanup_calls, $event_calls;
	$authorized = false;
	$csrf_valid = false;
	$restart_calls = 0;
	$cleanup_calls = 0;
	$event_calls = 0;
	$_SESSION = array();
}

require_once(__DIR__ . '/../../include/dashboard_actions.php');

reset_action_state();
$_SERVER['REQUEST_METHOD'] = 'GET';
$authorized = true;
$csrf_valid = true;
gnmi_process_dashboard_action('restart', 7);
gnmi_process_dashboard_action('cleanup_orphans');
test_assert($restart_calls === 0 && $cleanup_calls === 0, 'GET requests must not mutate daemon state');

reset_action_state();
$_SERVER['REQUEST_METHOD'] = 'POST';
gnmi_process_dashboard_action('restart', 7);
gnmi_process_dashboard_action('cleanup_orphans');
test_assert($restart_calls === 0 && $cleanup_calls === 0, 'anonymous requests must not mutate daemon state');

reset_action_state();
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SESSION['sess_user_id'] = 42;
gnmi_process_dashboard_action('restart', 7);
gnmi_process_dashboard_action('cleanup_orphans');
test_assert($restart_calls === 0 && $cleanup_calls === 0, 'unauthorized users must not mutate daemon state');

reset_action_state();
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SESSION['sess_user_id'] = 42;
$authorized = true;
gnmi_process_dashboard_action('restart', 7);
gnmi_process_dashboard_action('cleanup_orphans');
test_assert($restart_calls === 0 && $cleanup_calls === 0, 'invalid CSRF tokens must not mutate daemon state');

reset_action_state();
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SESSION['sess_user_id'] = 42;
$authorized = true;
$csrf_valid = true;
gnmi_process_dashboard_action('restart', 7);
gnmi_process_dashboard_action('cleanup_orphans');
test_assert($restart_calls === 1 && $cleanup_calls === 1, 'authorized POST requests with a valid token should execute');
test_assert($event_calls === 2, 'successful authorized actions should be logged');

echo "Dashboard action tests: $tests_run run, $tests_failed failed\n";
exit($tests_failed === 0 ? 0 : 1);

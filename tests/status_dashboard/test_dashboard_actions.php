<?php
/** Real plugin permission helpers with isolated Cacti APIs/lifecycle counters. */
define('CACTI_VERSION', 'test');
define('MESSAGE_LEVEL_ERROR', 1);
define('MESSAGE_LEVEL_INFO', 2);
$GLOBALS['gnmi_enforce_web_auth_in_cli'] = true;
$realms = array();
$allowed_hosts = array(101);
$csrf_valid = true;
$restart_calls = $cleanup_calls = $event_calls = 0;
$audit = array();
$devices = array(7=>array('id'=>7,'host_id'=>101,'enabled'=>1), 8=>array('id'=>8,'host_id'=>102,'enabled'=>1),
    9=>array('id'=>9,'host_id'=>101,'enabled'=>0), 10=>array('id'=>10,'host_id'=>0,'enabled'=>1));
$tests_run = $tests_failed = 0;
function __($message, ...$args) { if (end($args)==='gnmi') { array_pop($args); } return $args ? vsprintf($message,$args) : $message; }
function api_plugin_user_realm_auth($file) { return in_array($file, $GLOBALS['realms'], true); }
function api_user_realm_auth($file) { return $file === 'host.php'; }
function is_realm_allowed($realm) { return in_array($realm, $GLOBALS['realms'], true); }
function is_device_allowed($host) { return in_array((int)$host,$GLOBALS['allowed_hosts'],true); }
function get_guest_account() { return 99; }
function read_config_option($name) { return $name === 'auth_method' ? 1 : ''; }
function gnmi_validate_csrf_request() { return $GLOBALS['csrf_valid']; }
function db_fetch_row_prepared($sql,$params) { return $GLOBALS['devices'][$params[0]] ?? false; }
function gnmi_restart_daemon($device) { $GLOBALS['restart_calls']++; return true; }
function gnmi_cleanup_orphans($manual = false, &$result = null) {
    $GLOBALS['cleanup_calls']++;
    $result = array('orphans_found'=>3,'processes_stopped'=>2);
    return 2;
}
function gnmi_log_event() { $GLOBALS['event_calls']++; }
function cacti_log($message, ...$unused) { $GLOBALS['audit'][] = $message; }
function test_assert($condition,$message) {
    $GLOBALS['tests_run']++;
    if (!$condition) { $GLOBALS['tests_failed']++; echo "FAIL: $message\n"; }
}
require_once __DIR__ . '/../../include/access.php';
require_once __DIR__ . '/../../include/dashboard_actions.php';
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SESSION = array('sess_user_id'=>42);
// General device management never substitutes for the dedicated realm.
gnmi_process_dashboard_action('restart',7);
test_assert($restart_calls===0, 'general manager cannot restart');
$realms = array('dashboard_actions.php');
foreach (array(8,9,10,999,'bad',array(7),0,'7bad',true) as $id) {
    $r = gnmi_process_dashboard_action('restart',$id);
    test_assert(!$r['success'] && $restart_calls===0, 'unavailable/malformed target never dispatches');
    test_assert($r['message']==='Target not found or permission denied.', 'generic unavailable response');
}
foreach (array(array('restart'),false,'unknown','') as $action) {
    test_assert(!gnmi_process_dashboard_action($action,7)['success'], 'unknown/malformed action denied');
}
gnmi_process_dashboard_action('restart',7);
test_assert($restart_calls===1 && $event_calls===1,'operator can restart A');
$allowed_hosts = array();
gnmi_process_dashboard_action('restart',7);
test_assert($restart_calls===1,'device authorization rechecked at execution');
$allowed_hosts = array(101);
$realms = array();
gnmi_process_dashboard_action('restart',7);
test_assert($restart_calls===1,'revoked daemon realm rechecked');
$realms = array('dashboard_actions.php');
foreach (array(array(),array('sess_user_id'=>0),array('sess_user_id'=>99),array('sess_user_id'=>42,'sess_change_password'=>true)) as $identity) {
    $_SESSION = $identity;
    gnmi_process_dashboard_action('restart',7);
    test_assert($restart_calls===1,'missing/guest/unfinished-password identity denied');
}
$_SESSION = array('sess_user_id'=>42);
$_SERVER['REQUEST_METHOD'] = 'GET';
gnmi_process_dashboard_action('restart',7);
test_assert($restart_calls===1,'GET denied');
$_SERVER['REQUEST_METHOD'] = 'POST';
$csrf_valid = false;
gnmi_process_dashboard_action('restart',7);
test_assert($restart_calls===1,'invalid CSRF denied');
$csrf_valid = true;
gnmi_process_dashboard_action('cleanup_orphans');
test_assert($cleanup_calls===0,'operator cannot perform global cleanup');
$realms = array(1);
gnmi_process_dashboard_action('cleanup_orphans');
test_assert($cleanup_calls===0,'installation authority alone cannot clean up');
$realms = array(1,'dashboard_actions.php');
gnmi_process_dashboard_action('cleanup_orphans');
test_assert($cleanup_calls===1 && $event_calls===1,'manual cleanup is installation-level, not a device event');
test_assert(count($audit)===1 && strpos($audit[0],'actor=42')!==false && strpos($audit[0],'orphans_found=3')!==false,
    'manual audit attributes actor and actual outcome counts');
echo "Dashboard action tests: $tests_run run, $tests_failed failed\n";
exit($tests_failed ? 1 : 0);

<?php
/** Fail-closed reads must filter before any diagnostic filesystem inspection. */
define('CACTI_VERSION', 'test');
define('POLLER_VERBOSITY_LOW', 1);
define('POLLER_VERBOSITY_DEBUG', 5);
$GLOBALS['gnmi_enforce_web_auth_in_cli'] = true;
$config = array('base_path'=>sys_get_temp_dir() . '/gnmi-access-' . getmypid(), 'url_path'=>'/cacti/');
$_SESSION = array('sess_user_id'=>42);
$allow = array(101);
$device_rows = array(
    array('device_id'=>7,'id'=>7,'host_id'=>101,'hostname'=>'allowed','enabled'=>1,'device_description'=>'A','last_poll_time'=>null,'last_poll_status'=>'error','last_error_message'=>'allowed-error'),
    array('device_id'=>8,'id'=>8,'host_id'=>102,'hostname'=>'hidden','enabled'=>1,'device_description'=>'B','last_poll_time'=>null,'last_poll_status'=>'error','last_error_message'=>'secret-error'));
$events = array(
    array('id'=>2,'device_id'=>8,'event_type'=>'error','event_data'=>'{"message":"hidden-event"}','created_at'=>'now','device_hostname'=>'hidden','device_description'=>'B'),
    array('id'=>1,'device_id'=>7,'event_type'=>'error','event_data'=>'{"message":"allowed-event"}','created_at'=>'now','device_hostname'=>'allowed','device_description'=>'A'));
$event_queries = 0;
$host_checks = 0;
function read_config_option($key) { return $key==='auth_method' ? 1 : ($key==='poller_interval' ? 300 : ''); }
function get_guest_account() { return 99; }
function api_plugin_user_realm_auth($file) { return false; }
function is_realm_allowed($id) { return false; }
function is_device_allowed($host) { $GLOBALS['host_checks']++; return in_array((int)$host,$GLOBALS['allow'],true); }
function api_user_realm_auth($file) { return false; }
function cacti_log(...$args) {}
function html_escape($text) { return htmlspecialchars((string)$text, ENT_QUOTES); }
function db_fetch_assoc($sql) { return $GLOBALS['device_rows']; }
function db_fetch_row_prepared($sql,$params) {
    foreach ($GLOBALS['device_rows'] as $d) { if ($d['id']==$params[0]) { return $d; } }
    return false;
}
function db_fetch_assoc_prepared($sql,$params) {
    $GLOBALS['event_queries']++;
    // A real SQL WHERE must select authorized devices before LIMIT.
    $result = $GLOBALS['events'];
    if (strpos($sql,'e.device_id IN')!==false || strpos($sql,'e.device_id = ?')!==false) {
        $result = array_values(array_filter($result, fn($e)=>in_array($e['device_id'],$params)));
    }
    preg_match('/LIMIT\s+(\d+)/', $sql,$m);
    return array_slice($result,0,(int)($m[1] ?? 50));
}
require_once __DIR__ . '/../../include/functions.php';
require_once __DIR__ . '/../../include/status_functions.php';
require_once __DIR__ . '/../../include/status_display.php';
$failed = 0;
function check($ok,$message) { if (!$ok) { $GLOBALS['failed']++; echo "FAIL: $message\n"; } }
putenv('GNMI_RUNTIME_DIR=' . $config['base_path'] . '/plugins/gnmi/runtime');
$storage = gnmi_get_storage_dir();
mkdir($storage,0700,true);
foreach (array(7,8) as $id) { file_put_contents($storage . '/device_' . $id . '.pid','999999'); }
try {
    $summary = gnmi_get_dashboard_summary();
    check(count($summary)===1 && $summary[0]['device_id']===7,'summary contains A only');
    check(is_file($storage . '/device_7.pid') && is_file($storage . '/device_8.pid'),'dashboard GET retains both stale PID files');
    check(gnmi_get_daemon_stats(8)===null,'direct hidden stats denied');
    check(gnmi_get_recent_events(8)===array(),'direct hidden events denied');
    $recent = gnmi_get_recent_events(null,null,1);
    check(count($recent)===1 && $recent[0]['device_id']===7,'authorization precedes all-device event limit');
    $html = gnmi_render_summary_table($summary);
    check(strpos($html,'secret-error')===false && strpos($html,'hidden-event')===false,'hidden data absent from HTML');
    check(strpos($html,'value="restart"')===false,'viewer restart control absent');
    check(gnmi_get_orphan_summary()===null,'viewer never scans global processes');
    check(gnmi_render_orphan_panel(array('total_orphans'=>1))==='','viewer receives no global orphan HTML');
    check($host_checks===2,'reuse effective host decisions within the request');
    // A diagnostic stream records every filesystem probe, including stat.
    class AccessProbeStream {
        public $context;
        public static $paths = array();
        public function url_stat($path, $flags) { self::$paths[] = $path; return false; }
    }
    stream_wrapper_register('gnmiaccess', 'AccessProbeStream');
    putenv('GNMI_RUNTIME_DIR=gnmiaccess://runtime');
    gnmi_get_dashboard_summary();
    gnmi_get_daemon_stats(8);
    check(!array_filter(AccessProbeStream::$paths, fn($p)=>strpos($p,'device_8.')!==false),
        'denied device causes zero diagnostic filesystem probes');
    check((bool)array_filter(AccessProbeStream::$paths, fn($p)=>strpos($p,'device_7.')!==false),
        'allowed device still receives diagnostic inspection');
    stream_wrapper_unregister('gnmiaccess');
    putenv('GNMI_RUNTIME_DIR=' . $config['base_path'] . '/plugins/gnmi/runtime');
    // Trusted lifecycle inspection still removes stale files.
    gnmi_validate_pid_file(7,$storage . '/device_7.pid');
    check(!is_file($storage . '/device_7.pid'),'trusted PID validation keeps cleanup default');
    file_put_contents($storage . '/device_7.pid',(string)getmypid());
    $wrong = gnmi_validate_pid_file(7,$storage . '/device_7.pid',false);
    check($wrong['status']==='wrong_process' && is_file($storage . '/device_7.pid'),'read-only wrong-process inspection retains PID');
    gnmi_validate_pid_file(7,$storage . '/device_7.pid');
    check(!is_file($storage . '/device_7.pid'),'trusted wrong-process inspection cleans PID');
} finally {
    @unlink($storage . '/device_7.pid'); @unlink($storage . '/device_8.pid');
    rmdir($storage); rmdir(dirname($storage)); rmdir(dirname(dirname($storage))); rmdir($config['base_path'] . '/plugins'); rmdir($config['base_path']);
}
echo "Access data failures: $failed\n";
exit($failed ? 1 : 0);

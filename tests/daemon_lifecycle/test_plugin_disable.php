<?php
/** Standalone regression for the disabled-plugin shutdown guard. */
$status = 1;
$tables = true;
$stopped = array();
$inside_lock_status = null;
$locked = false;
function db_fetch_cell($sql) { return $GLOBALS['status']; }
function db_fetch_assoc($sql) {
    if (strpos($sql, 'SHOW TABLES') === 0) {
        return $GLOBALS['tables'] ? array(array('table'=>'present')) : array();
    }
    return array(array('id'=>12), array('id'=>34));
}
function gnmi_stop_daemon($id) { $GLOBALS['stopped'][] = $id; return true; }
function gnmi_with_poller_exclusive_lock($callback, $path=null, $blocking=false) {
    if (!$blocking) { throw new RuntimeException('Disable must serialize with the poller'); }
    $GLOBALS['locked'] = true;
    if ($GLOBALS['inside_lock_status'] !== null) { $GLOBALS['status'] = $GLOBALS['inside_lock_status']; }
    $callback();
    return true;
}
require __DIR__ . '/../../setup.php';
function check_disable($condition, $message) {
    if (!$condition) { throw new RuntimeException($message); }
    echo "PASS: $message\n";
}
gnmi_stop_disabled_plugin_daemons();
check_disable(!$locked && !$stopped, 'Enabled plugin collectors are untouched');
$status = 4;
gnmi_stop_disabled_plugin_daemons();
check_disable($locked && $stopped === array(12,34), 'Disabled plugin stops all collectors under the poller lock');
$stopped = array();
$inside_lock_status = 1;
gnmi_stop_disabled_plugin_daemons();
check_disable(!$stopped, 'Concurrent re-enable is respected after acquiring the lock');
$status = 4;
$tables = false;
$locked = false;
gnmi_stop_disabled_plugin_daemons();
check_disable(!$locked && !$stopped, 'Uninstalled tables are never queried for devices');
$status = false;
$tables = true;
gnmi_stop_disabled_plugin_daemons();
check_disable(!$stopped, 'Missing plugin registration is left to uninstall cleanup');

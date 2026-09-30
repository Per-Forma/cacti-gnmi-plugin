<?php
// Isolated assertions against the same exporter used by the actual collector.
require __DIR__ . '/../../include/database_config.php';
function check($ok, $why) { if (!$ok) { throw new RuntimeException($why); } }
function rejects($fn) { try {$fn();} catch(GnmiDatabaseConfigException $e) {return;} throw new RuntimeException('Expected safe rejection'); }
$values=['database_type'=>'mysql','database_hostname'=>'localhost','database_port'=>'4406','database_default'=>'cacti','database_username'=>'synthetic','database_password'=>" '\"\\ \u{2603} ",'database_ssl'=>false,'database_ssl_ca'=>'unused','database_ssl_cert'=>'unused','database_ssl_key'=>'unused'];
$p=gnmi_database_payload($values);
check($p['host']==='127.0.0.1' && $p['port']===4406, 'localhost nondefault normalization');
check(base64_decode($p['password_b64'],true)===$values['database_password'], 'exact bytes');
check(!isset($p['ca']), 'disabled TLS ignores paths');
$v=$values;$v['database_password']='';check(gnmi_database_payload($v)['password_b64']==='', 'explicit empty password');
$v=$values;$v['database_port']='invalid'; rejects(fn()=>gnmi_database_payload($v));
$v=$values;$v['database_type']='sqlite'; rejects(fn()=>gnmi_database_payload($v));
$v=$values;$v['database_ssl']=true; rejects(fn()=>gnmi_database_payload($v));
$v=$values;$v['database_ssl']='false'; rejects(fn()=>gnmi_database_payload($v));
$v=$values;$v['database_hostname']='localhost';$v['database_port']=3306;
$p=gnmi_database_payload($v, '/tmp/mysql.sock');check($p['unix_socket']==='/tmp/mysql.sock', 'explicit PHP socket resolution');
rejects(fn()=>gnmi_database_payload($v, ''));
class SelectedConnection extends PDO {function __construct() {}}
$selected=new SelectedConnection();$other=new SelectedConnection();
$database_hostname='localhost';$database_port='4406';$database_default='cacti';
$database_sessions=['localhost:4406:cacti'=>$selected];
$database_details=[spl_object_hash($other)=>['database_conn'=>$other]+array_merge($values,['database_password'=>'wrong']),spl_object_hash($selected)=>['database_conn'=>$selected]+array_merge($values,['database_hostname'=>'127.0.0.1'])];
check(gnmi_export_database_config()['password_b64']===base64_encode($values['database_password']), 'select exact connection object');
$database_details[spl_object_hash($selected)]['database_default']='wrong';rejects(fn()=>gnmi_export_database_config());
unset($database_sessions['localhost:4406:cacti']);rejects(fn()=>gnmi_export_database_config());
echo "PASS: private database normalization and selected connection exporter\n";

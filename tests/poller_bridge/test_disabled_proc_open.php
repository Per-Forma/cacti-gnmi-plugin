<?php
// A separate PHP process simulates a poller SAPI with proc_open disabled.
if(function_exists('proc_open')){
 $p=proc_open([PHP_BINARY,'-d','disable_functions=proc_open',__FILE__],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
 fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
 if(proc_close($p)!==0 || $err!=='' || $out!=="PASS: disabled proc_open fails once before enumeration or cursor updates\n")throw new RuntimeException('Disabled proc_open preflight failed');
 echo $out;exit(0);
}
define('CACTI_VERSION','test');define('POLLER_VERBOSITY_LOW',1);
$messages=[];
function cacti_log($message,...$args){global $messages;$messages[]=$message;}
function read_config_option(...$args){return 10;}
require __DIR__.'/../../include/functions.php';
$gnmi_poller_lock_held=true;
if(gnmi_collect_telemetry()!==false || count($messages)!==1 || strpos($messages[0],'proc_open')===false)throw new RuntimeException('Configuration preflight failed');
echo "PASS: disabled proc_open fails once before enumeration or cursor updates\n";

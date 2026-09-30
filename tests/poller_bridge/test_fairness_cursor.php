<?php
require __DIR__.'/../../include/poller_bridge.php';
function check($ok,$why){if(!$ok)throw new RuntimeException($why);}
function rejected($fn){try{$fn();}catch(GnmiBridgeMaintenanceException $e){return;}throw new RuntimeException('Unsafe cursor accepted');}
$dir=sys_get_temp_dir().'/gnmi-cursor-'.bin2hex(random_bytes(6));mkdir($dir,0750);
$path=$dir.'/.gnmi_bridge_cursor.json';
$gnmi_poller_lock_held=true;
try{
 check(gnmi_bridge_cursor_read($dir)===[0,0],'missing resets');
 $pairs=[['device_id'=>1,'local_data_id'=>2],['device_id'=>2,'local_data_id'=>1],['device_id'=>1,'local_data_id'=>1]];
 $order=gnmi_bridge_order_pairs($pairs,[1,1]);check(array_column($order,'local_data_id')===[2,1,1], 'deterministic sorted wrap');
 gnmi_bridge_cursor_write($dir,[1,2]);check(gnmi_bridge_cursor_read($dir)===[1,2],'persist progress');
 check((fileperms($path)&0777)===0640,'protected mode');
 file_put_contents($path,'invalid');check(gnmi_bridge_cursor_read($dir)===[0,0],'malformed reset');
 file_put_contents($path,str_repeat('x',1000));check(gnmi_bridge_cursor_read($dir)===[0,0],'oversize reset');
 gnmi_bridge_cursor_write($dir,[2,1]);check(gnmi_bridge_cursor_read($dir)===[2,1],'safe replacement');
 $gnmi_poller_lock_held=false;rejected(fn()=>gnmi_bridge_cursor_write($dir,[1,1]));$gnmi_poller_lock_held=true;
 unlink($path);file_put_contents($dir.'/device_1.json','telemetry');symlink($dir.'/device_1.json',$path);
 rejected(fn()=>gnmi_bridge_cursor_read($dir));rejected(fn()=>gnmi_bridge_cursor_write($dir,[1,1]));
 check(file_get_contents($dir.'/device_1.json')==='telemetry','never modify telemetry through a symlink');
 unlink($path);mkdir($path);rejected(fn()=>gnmi_bridge_cursor_read($dir));rmdir($path);
 check(gnmi_bridge_order_pairs([], [2,1])===[], 'deleted and disabled sources vanish');
 if(function_exists('posix_geteuid') && posix_geteuid()!==0){
  chmod($dir,0550);rejected(fn()=>gnmi_bridge_cursor_write($dir,[3,1]));chmod($dir,0750);
 }

 echo "PASS: deterministic fair cursor, atomic protected state and unsafe-path refusal\n";
}finally{chmod($dir,0750);foreach(glob($dir.'/*') as $f)unlink($f);if(is_file($path)||is_link($path))unlink($path);rmdir($dir);}

<?php
/** Disposable integration: actual Cacti selection, private child, RRD, outage and deadlines. */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit(1); }
chdir(__DIR__.'/../../../../..');
require './include/cli_check.php';
require './plugins/gnmi/include/functions.php';
require './plugins/gnmi/include/subscription_functions.php';
function require_bridge($ok,$why){if(!$ok)throw new RuntimeException($why);}
// The runner uses a labeled fresh package installation. Refuse populated plugin databases.
require_bridge((int)db_fetch_cell('SELECT COUNT(*) FROM plugin_gnmi_devices')===0,'Requires a fresh disposable plugin database');
db_execute("REPLACE INTO settings(name,value) VALUES('poller_interval','10')");
$devices=[];$sources=[];$hosts=[];$epoch=(int)(floor(time()/10)*10)-20;
try{
 for($n=1;$n<=3;$n++){
  $host=db_fetch_row('SELECT * FROM host ORDER BY id LIMIT 1');$host['id']=0;$host['hostname']='127.0.0.'.$n;$host['description']='bridge-fixture-'.$n;$host['disabled']='on';
  $host_id=sql_save($host,'host');$hosts[]=$host_id;require_bridge($host_id>0,'Host fixture');
  db_execute_prepared("INSERT INTO plugin_gnmi_devices(host_id,enabled,hostname,port,username,password,use_tls,collection_interval,encoding) VALUES(?,1,?,9,'synthetic','synthetic',0,10,'JSON_IETF')",[$host_id,'127.0.0.'.$n]);
  $id=(int)db_fetch_insert_id();$devices[]=$id;
  $sub=gnmi_create_subscription($id,'/synthetic','fixture'.$n,['auto_create_datasources'=>0]);
  db_execute_prepared('UPDATE plugin_gnmi_subscriptions SET auto_create_datasources=0,auto_create_graphs=0 WHERE id=?',[$sub]);
  $metrics=[];
  for($j=1;$j<=4;$j++){
   $name='bridge-value-'.$j;$value=100*$n+$j;$metric=gnmi_add_metric_to_subscription($sub,$name,'GAUGE');
   $ds=(int)gnmi_create_data_source_for_metric($metric);require_bridge($ds>0,'Data source fixture');
   require_bridge(gnmi_create_graph_for_metric($metric)>0,'First graph metadata');
   $sources[]=['device'=>$id,'ds'=>$ds,'value'=>$value];$metrics[$name]=$value;
  }
  $data=['device_id'=>$id,'daemon_status'=>'connected','last_update'=>date('c'),'metric_groups'=>['fixture'.$n=>$metrics],
   'samples_history'=>['fixture'.$n=>[['epoch'=>$epoch]+$metrics,['epoch'=>$epoch+10]+$metrics]]];
  file_put_contents(gnmi_get_storage_dir().'/device_'.$id.'.json',json_encode($data));
 }
 $before=db_fetch_assoc('SELECT id,last_poll_time,last_poll_status FROM plugin_gnmi_devices ORDER BY id');
 $t=gnmi_bridge_now();gnmi_collect_telemetry($t-1);
 require_bridge(db_fetch_assoc('SELECT id,last_poll_time,last_poll_status FROM plugin_gnmi_devices ORDER BY id')===$before,'Expired inherited hook target preserves unattempted status');
 require_bridge(!file_exists(gnmi_get_storage_dir().'/.gnmi_bridge_cursor.json'),'Expired invocation starts no child');
 $key="$database_hostname:$database_port:$database_default";$hash=spl_object_hash($database_sessions[$key]);
 $password=$database_details[$hash]['database_password'];
 // The resolved object's credentials must win over a subsequently changed global.
 $database_password='UNUSED_GLOBAL_CANARY';
 $t=gnmi_bridge_now();require_bridge(gnmi_collect_telemetry(),'Actual collection');$elapsed=gnmi_bridge_now()-$t;
 require_bridge($elapsed<8,'Twelve-source collection within 10-second polling target');
 foreach($sources as $source){
  $path=get_data_source_path($source['ds'],true);$out=shell_exec('rrdtool lastupdate '.escapeshellarg($path));
  require_bridge(strpos($out,($epoch+10).': '.$source['value'])!==false,'Exact timestamp and RRD value');
  $image=sys_get_temp_dir().'/gnmi-bridge-graph-'.$source['ds'].'.png';
  exec('rrdtool graph '.escapeshellarg($image).' --start '.($epoch-10).' --end '.($epoch+20).' '.escapeshellarg('DEF:v='.$path.':'.db_fetch_cell_prepared('SELECT data_source_name FROM data_template_rrd WHERE local_data_id=?',[$source['ds']]).':AVERAGE').' LINE1:v#00FF00 >/dev/null 2>&1',$unused,$exit);
  require_bridge($exit===0 && filesize($image)>0,'Physical first graph renders');unlink($image);
 }
 $storage_before=[];foreach($devices as $id)$storage_before[$id]=file_get_contents(gnmi_get_storage_dir().'/device_'.$id.'.json');
 $before=db_fetch_assoc('SELECT id,last_poll_time,last_poll_status FROM plugin_gnmi_devices ORDER BY id');
 $cursor_before=gnmi_bridge_cursor_read(gnmi_get_storage_dir());
 $database_details[$hash]['database_password']='INVALID_PASSWORD_CANARY';
 $log=$config['base_path'].'/log/cacti.log';$log_size=filesize($log);
 $t=gnmi_bridge_now();gnmi_collect_telemetry();$failure_time=gnmi_bridge_now()-$t;
 $cursor=gnmi_bridge_cursor_read(gnmi_get_storage_dir());
 require_bridge($cursor!==$cursor_before,'Shared failure attempted one source and advances progress');
 $after=db_fetch_assoc('SELECT id,last_poll_time,last_poll_status FROM plugin_gnmi_devices ORDER BY id');
 $changed=0;foreach($after as $i=>$row)if($row!==$before[$i])$changed++;
 require_bridge($changed===1,'Shared authentication failure stops remaining children and preserves other devices');
 $new_log=file_get_contents($log,false,null,$log_size);
 require_bridge(substr_count($new_log,'Database authentication failed')===1,'One installation failure diagnostic');
 require_bridge(strpos($new_log,'CANARY')===false,'No raw or encoded credentials in poller log');
 foreach($devices as $id)require_bridge(file_get_contents(gnmi_get_storage_dir().'/device_'.$id.'.json')===$storage_before[$id],'Collection failures leave healthy daemon state and buffers intact');
 $database_details[$hash]['database_password']=$password;
 require_bridge(gnmi_collect_telemetry(),'Recovery on the next invocation');
 foreach(db_fetch_assoc('SELECT last_poll_status FROM plugin_gnmi_devices') as $row)require_bridge($row['last_poll_status']==='success','Recovered device');
 // Missing selected metadata must not trigger a file-parser fallback or advance the cursor.
 $cursor_before=gnmi_bridge_cursor_read(gnmi_get_storage_dir());$detail=$database_details[$hash];unset($database_details[$hash]);
 require_bridge(gnmi_collect_telemetry()===false,'Missing selected metadata fails closed');
 require_bridge(gnmi_bridge_cursor_read(gnmi_get_storage_dir())===$cursor_before,'Shared config failure preserves cursor');
 $database_details[$hash]=$detail;
 // Controlled hung source exercises the actual collector's budget/cursor admission.
 $runtime_env=getenv('GNMI_RUNTIME_DIR');$runtime=gnmi_get_runtime_dir();
 $base=$config['base_path'];$fake=sys_get_temp_dir().'/gnmi-bridge-parent-'.bin2hex(random_bytes(6));
 mkdir($fake.'/plugins/gnmi/scripts',0750,true);mkdir($fake.'/plugins/gnmi/venv/bin',0750,true);
 symlink($base.'/plugins/gnmi/venv/bin/python3',$fake.'/plugins/gnmi/venv/bin/python3');
 $marker=$fake.'/attempts';
 $first_ds=$sources[0]['ds'];
 $program="import os,sys,time\nargs=sys.argv\nds=int(args[args.index('--local-data-id')+1])\nsys.stdin.buffer.read(65537)\nwith open(".json_encode($marker,JSON_UNESCAPED_SLASHES).",'a') as f:f.write(str(ds)+'\\n')\nif ds==".$first_ds.":time.sleep(5)\nsys.exit(2)\n";
 file_put_contents($fake.'/plugins/gnmi/scripts/gnmi_poller_bridge.py',$program);
 try{
  gnmi_with_poller_exclusive_lock(function () { gnmi_bridge_cursor_write(gnmi_get_storage_dir(),[0,0]); });
  putenv('GNMI_RUNTIME_DIR='.$runtime);
  $config['base_path']=$fake;
  $before=db_fetch_assoc('SELECT id,last_poll_time,last_poll_status FROM plugin_gnmi_devices ORDER BY id');
  $t=gnmi_bridge_now();gnmi_collect_telemetry($t+.3);$timeout_elapsed=gnmi_bridge_now()-$t;
  $attempts=file($marker,FILE_IGNORE_NEW_LINES);
  require_bridge($attempts===[(string)$first_ds] && $timeout_elapsed<.6,'Hung source bounded with no fresh per-source interval');
  require_bridge(gnmi_bridge_cursor_read(gnmi_get_storage_dir())===[$devices[0],$first_ds],'Timeout advances fairness cursor');
  $after=db_fetch_assoc('SELECT id,last_poll_time,last_poll_status FROM plugin_gnmi_devices ORDER BY id');
  require_bridge($before[1]===$after[1] && $before[2]===$after[2],'Budget deferral preserves unattempted devices');
  $t=gnmi_bridge_now();gnmi_collect_telemetry($t+.4);$attempts=file($marker,FILE_IGNORE_NEW_LINES);
  require_bridge(count($attempts)>1 && (int)$attempts[1]===$sources[1]['ds'],'Next cycle resumes beyond slow first source');
 }finally{
  putenv($runtime_env===false ? 'GNMI_RUNTIME_DIR' : 'GNMI_RUNTIME_DIR='.$runtime_env);
  $config['base_path']=$base;@unlink($marker);unlink($fake.'/plugins/gnmi/scripts/gnmi_poller_bridge.py');
  unlink($fake.'/plugins/gnmi/venv/bin/python3');rmdir($fake.'/plugins/gnmi/venv/bin');rmdir($fake.'/plugins/gnmi/venv');
  rmdir($fake.'/plugins/gnmi/scripts');rmdir($fake.'/plugins/gnmi');rmdir($fake.'/plugins');rmdir($fake);
 }
 foreach(['UNUSED_GLOBAL_CANARY','INVALID_PASSWORD_CANARY'] as $canary){
  $variants=[$canary,base64_encode($canary),bin2hex($canary)];
  $files=[$log];
  $iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator(gnmi_get_runtime_dir(),FilesystemIterator::SKIP_DOTS));
  foreach($iterator as $file)if($file->isFile())$files[]=$file->getPathname();
  foreach($files as $file){$bytes=file_get_contents($file);foreach($variants as $variant)require_bridge(strpos($bytes,$variant)===false,'Credential canary absent from runtime and poller logs');}
 }
 require_once './plugins/gnmi/setup.php';
 $t=gnmi_bridge_now();gnmi_poller_bottom();$hook_elapsed=gnmi_bridge_now()-$t;
 // Deadline expiry after preceding lifecycle work cannot reset at collector entry.
 $cursor_before=gnmi_bridge_cursor_read(gnmi_get_storage_dir());
 $before=db_fetch_assoc('SELECT id,last_poll_time,last_poll_status FROM plugin_gnmi_devices ORDER BY id');
 gnmi_manage_daemons(gnmi_bridge_now()-1);
 require_bridge(gnmi_bridge_cursor_read(gnmi_get_storage_dir())===$cursor_before,'Lifecycle handoff preserves an expired hook target');
 require_bridge(db_fetch_assoc('SELECT id,last_poll_time,last_poll_status FROM plugin_gnmi_devices ORDER BY id')===$before,'Lifecycle-consumed target defers without failing unattempted devices');
 echo json_encode(['cacti'=>CACTI_VERSION,'php'=>PHP_VERSION,'poller_uid'=>posix_geteuid(),'poller_interval'=>10,'sources'=>count($sources),'collector_seconds'=>$elapsed,'startup_hook_seconds'=>$hook_elapsed,'whole_hook_hard_bound'=>false,'shared_failure_seconds'=>$failure_time,'exact_rrd_samples'=>count($sources),'rendered_graphs'=>count($sources),'result'=>'passed'])."\n";
}finally{
 // Remove Cacti fixture ownership before the FK cascade removes its resource mapping.
 require_once './plugins/gnmi/setup.php';
 require_bridge(gnmi_uninstall_stop_daemons($devices),'Stop fixture daemons');
 require_bridge(gnmi_uninstall_remove_cacti_objects(gnmi_uninstall_collect_resources()),'Remove fixture graph/data-source metadata');
 foreach($devices as $id){db_execute_prepared('DELETE FROM plugin_gnmi_devices WHERE id=?',[$id]); @unlink(gnmi_get_storage_dir().'/device_'.$id.'.json');}
 foreach($hosts as $id)db_execute_prepared('DELETE FROM host WHERE id=?',[$id]);
 @unlink(gnmi_get_storage_dir().'/.gnmi_bridge_cursor.json');
}

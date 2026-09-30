<?php
function assert_ok($ok,$why){if(!$ok)throw new RuntimeException($why);}
$dir=sys_get_temp_dir().'/gnmi-resolver-'.bin2hex(random_bytes(6));mkdir($dir,0700);
$helper=__DIR__.'/../../scripts/gnmi_database_config.php';
function resolve($source){global $dir,$helper;file_put_contents($dir.'/config.php','<?php '.$source);$p=proc_open([PHP_BINARY,$helper,$dir.'/config.php'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$code=proc_close($p);return [$code,$out,$err];}
try{
 $base="\$database_hostname='127.0.0.1';\$database_port='4406';\$database_password='';";
 [$code,$out,$err]=resolve($base);$cfg=json_decode($out,true);
 assert_ok($code===0 && $cfg['password_b64']==='' && $cfg['port']===4406 && $err==='', 'explicit empty password and Cacti defaults');
 file_put_contents($dir.'/nested.php',"<?php \$database_password=getenv('GNMI_TEST_PASSWORD');");
 $password=" \"'\\ \u{2603} \$() `command` ";putenv('GNMI_TEST_PASSWORD='.$password);
 [$code,$out,$err]=resolve($base."// \$database_password='wrong';\ninclude './nested.php';");
 assert_ok($code===0 && base64_decode(json_decode($out,true)['password_b64'],true)===$password,'nested includes, environment, exact effective bytes');
 foreach(["echo 'SECRET_CANARY';", "trigger_error('SECRET_CANARY');", "throw new Exception('SECRET_CANARY');", "exit('SECRET_CANARY');", "\$poller_id=2;", "\$rdatabase_hostname='other';", "\$database_ssl=true;\$database_ssl_cert='/missing';", "\$database_port=[];", "this is invalid php"] as $bad){
  [$code,$out,$err]=resolve($base.$bad);
  assert_ok($code!==0 || $out==='', 'reject invalid or output-producing config');
  assert_ok($out==='' && strpos($err,'SECRET_CANARY')===false, 'contain config output and exceptions');
 }
 [$code,$out,$err]=resolve($base."\$database_password='first';\$database_password='last';");
 assert_ok($code===0 && base64_decode(json_decode($out,true)['password_b64'],true)==='last','last assignment wins');
 echo "PASS: trusted standalone PHP evaluation without secret diagnostics\n";
}finally{foreach(glob($dir.'/*') as $f)unlink($f);rmdir($dir);}

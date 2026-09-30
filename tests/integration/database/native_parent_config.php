<?php
/** Only disposable test configuration; one canonical Cacti config per run. */
if(PHP_SAPI!=='cli')exit(1);
$socket=($argv[1]??'')==='socket';
$values=['database_type'=>'mysql','database_default'=>'cacti','database_hostname'=>$socket?'/sock/only.sock':'bridge-db',
 'database_port'=>$socket?'3306':'4406','database_username'=>'gnmi_native','database_password'=>" '\"\\ native \u{2603} \$() `command` ",
 'database_retries'=>0,'database_persist'=>false,'database_ssl'=>!$socket,'database_ssl_ca'=>$socket?'':'/tls/ca.crt',
 'database_ssl_cert'=>$socket?'':'/tls/client.crt','database_ssl_key'=>$socket?'':'/tls/client.key','poller_id'=>1,'url_path'=>'/cacti/'];
$text="<?php\n";foreach($values as $name=>$value)$text.='$'.$name.'='.var_export($value,true).";\n";
file_put_contents('/var/www/html/cacti/include/config.php',$text);
chmod('/var/www/html/cacti/include/config.php',0640);

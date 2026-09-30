<?php
require __DIR__.'/../../include/poller_bridge.php';
function check($ok,$why){if(!$ok)throw new RuntimeException($why);}
function child($code,$seconds=1,$input='private-canary') {return gnmi_run_bridge([PHP_BINARY,'-r',$code],$input,gnmi_bridge_now()+$seconds);}
$r=child('fwrite(STDERR,"SECRET_CANARY field:999\n");echo "1700000000 field:12\n";exit(1);');
check($r['stdout']==='' && $r['exit_code']===1, 'discard failed partial metrics and stderr');
check(strpos(json_encode($r),'SECRET_CANARY')===false,'raw diagnostics never returned');
$r=child('fwrite(STDERR,str_repeat("SECRET_CANARY",100000));echo "1700000000 field:12\n";');
check($r['exit_code']===0 && $r['stdout']==="1700000000 field:12\n",'drain huge stderr independently');
$r=child('echo stream_get_contents(STDIN)==="private-canary" ? "1700000000 field:9\n" : "bad";');
check($r['stdout']==="1700000000 field:9\n", 'close stdin after complete private write');
$r=child('fwrite(STDERR,"GNMI_BRIDGE_ERROR authentication connect\n");exit(1);');
check($r['category']==='authentication' && $r['phase']==='connect','allowlisted category');
$t=gnmi_bridge_now();$r=child('echo "1700000000 field:1\n";sleep(5);',.3);
check($r['category']==='timeout' && $r['stdout']==='' && gnmi_bridge_now()-$t<.6, 'bound timeout and reap');
$r=child('exit(2);');check($r['exit_code']===2 && $r['stdout']==='', 'stale exit preserved');
$r=child('posix_kill(getmypid(),9);');check($r['stdout']==='' && $r['exit_code']!==0, 'signal is failure');
$r=child('echo str_repeat("x",1200000);');check($r['stdout']==='', 'bound stdout');
$r=child('echo "unexpected";',-1);check($r['category']==='deferred','expired allowance starts no child');
echo "PASS: bounded private subprocess, independent pipes, safe failure output\n";
$r=child('$private=stream_get_contents(STDIN);$visible=json_encode([getenv(),$_SERVER["argv"]]);echo strpos($visible,$private)===false ? "1700000000 field:1\n" : "leaked";');
check($r['stdout']==="1700000000 field:1\n",'private config is absent from child argv and environment');

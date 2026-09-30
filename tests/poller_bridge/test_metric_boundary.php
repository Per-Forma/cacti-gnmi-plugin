<?php
define('CACTI_VERSION','test');define('POLLER_VERBOSITY_LOW',1);
function cacti_log(...$args) {}
function db_fetch_assoc(...$args){throw new RuntimeException('Malformed output reached database/RRD work');}
require __DIR__.'/../../include/functions.php';
foreach(['',"1700000000 field:1\nSECRET_CANARY",'1700000000 field:NaN','1700000000 field:INF',
 '1700000000 field:-INF','1700000000 field:1e999','1700000000 field:1;command','1700000000 bad-field:1',
 'bad field:1','999999999 field:1','1700000000 field:1 extra'] as $output){
 if(gnmi_apply_bridge_output($output,1,1,'fixture',gnmi_bridge_now()+1)!==false)throw new RuntimeException('Malformed output accepted');
}
if(gnmi_apply_bridge_output('1700000000 field:1',1,1,'fixture',gnmi_bridge_now()-1)!==false)throw new RuntimeException('Expired RRD work admitted');
echo "PASS: malformed or expired stdout never reaches database/RRD helpers\n";

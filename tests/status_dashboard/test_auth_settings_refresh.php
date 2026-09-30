<?php
/** An old session configuration cannot bypass an effective no-auth setting. */
define('CACTI_VERSION', 'test');
$_SESSION = array('sess_user_id'=>42);
function read_config_option($name, $force = false) { return $name === 'auth_method' ? ($force ? 0 : 1) : ''; }
function get_guest_account() { return 99; }
function api_plugin_user_realm_auth($file) { return true; }
require_once __DIR__ . '/../../include/access.php';
if (gnmi_current_user_can_manage_daemons()) { fwrite(STDERR,"Stale session settings authorized mutation\n"); exit(1); }
echo "PASS: effective no-auth setting denies an existing administrative session\n";

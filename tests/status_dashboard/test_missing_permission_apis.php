<?php
/** Required Cacti authorization APIs absent: deny without diagnostic work. */
define('CACTI_VERSION', 'test');
$GLOBALS['gnmi_enforce_web_auth_in_cli'] = true;
$_SESSION = array('sess_user_id'=>42);
require_once __DIR__ . '/../../include/access.php';
if (gnmi_current_user_can_view_host(101) || gnmi_current_user_can_manage_daemons()
    || gnmi_current_user_is_installation_admin() || gnmi_current_user_can_cleanup_orphans()) {
    fwrite(STDERR, "Missing authorization APIs must deny\n"); exit(1);
}
echo "PASS: missing authorization APIs deny reads and mutations\n";

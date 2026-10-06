<?php
/** A supplied token is insufficient when no Cacti validator is available. */
define('CACTI_VERSION', 'test');
$GLOBALS['gnmi_enforce_csrf_in_cli'] = true;
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST['__csrf_magic'] = 'unvalidated-token';
require_once __DIR__ . '/../../include/functions.php';
if (gnmi_validate_csrf_request()) { fwrite(STDERR, "Missing CSRF validator must deny POST\n"); exit(1); }
echo "PASS: absent CSRF validator denies POST\n";

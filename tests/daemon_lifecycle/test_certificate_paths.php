<?php
/**
 * Standalone regressions for the runtime certificate directory boundary.
 */

define('CACTI_VERSION', 'test');
require_once(__DIR__ . '/../../include/functions.php');

$tests_run = 0;
$tests_failed = 0;

function test_assert($condition, $message) {
	global $tests_run, $tests_failed;
	$tests_run++;
	if (!$condition) {
		$tests_failed++;
		echo "FAIL: $message\n";
	}
}

$runtime = sys_get_temp_dir() . '/gnmi-cert-boundary-' . getmypid();
$certs = $runtime . '/certs';
$sibling = $runtime . '/certs-other';
mkdir($certs, 0700, true);
mkdir($sibling, 0700, true);
file_put_contents($certs . '/allowed.pem', 'test');
file_put_contents($sibling . '/rejected.pem', 'test');
putenv('GNMI_RUNTIME_DIR=' . $runtime);

test_assert(gnmi_certificate_path_is_allowed(''), 'empty optional certificate path should pass');
test_assert(gnmi_certificate_path_is_allowed($certs . '/allowed.pem'), 'file inside runtime/certs should pass');
test_assert(!gnmi_certificate_path_is_allowed($sibling . '/rejected.pem'), 'certs-other sibling must not pass the certs boundary');
test_assert(!gnmi_certificate_path_is_allowed($certs . '/missing.pem'), 'missing certificate files must fail closed');

putenv('GNMI_RUNTIME_DIR');
unlink($certs . '/allowed.pem');
unlink($sibling . '/rejected.pem');
rmdir($certs);
rmdir($sibling);
rmdir($runtime);

echo "Certificate path tests: $tests_run run, $tests_failed failed\n";
exit($tests_failed === 0 ? 0 : 1);

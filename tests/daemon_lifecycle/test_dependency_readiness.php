<?php
/**
 * Standalone regression tests for fail-closed runtime dependency readiness.
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

$ready = array(
	'venv_module' => true,
	'rrdtool_dev' => true,
	'rrdtool' => true,
	'pygnmi' => true,
	'pymysql' => true,
);

test_assert(gnmi_dependency_state_is_ready($ready), 'complete runtime dependency state should pass');

$missing_bridge = $ready;
$missing_bridge['pymysql'] = false;
test_assert(!gnmi_dependency_state_is_ready($missing_bridge), 'missing pymysql must fail closed');

$legacy_state = $ready;
unset($legacy_state['pymysql']);
test_assert(!gnmi_dependency_state_is_ready($legacy_state), 'state without the bridge dependency flag must fail closed');

echo "Dependency readiness tests: $tests_run run, $tests_failed failed\n";
exit($tests_failed === 0 ? 0 : 1);

<?php
/**
 * Standalone regression tests for request/config debug-log redaction.
 */

define('CACTI_VERSION', 'test');
require_once(__DIR__ . '/../../include/functions.php');

$input = array(
	'hostname' => 'router.example',
	'password' => 'not-for-logs',
	'csrf_token' => 'csrf-secret',
	'client_private_key' => 'private-key-material',
	'nested' => array(
		'api_secret' => 'nested-secret',
		'metric' => 'interfaces',
	),
);
$masked = gnmi_mask_sensitive_data($input);

$failures = 0;
if ($masked['hostname'] !== 'router.example' || $masked['nested']['metric'] !== 'interfaces') {
	$failures++;
	echo "FAIL: non-sensitive values should remain available for diagnostics\n";
}
foreach (array($masked['password'], $masked['csrf_token'], $masked['client_private_key'], $masked['nested']['api_secret']) as $value) {
	if ($value !== '[redacted]') {
		$failures++;
		echo "FAIL: sensitive value was not redacted\n";
	}
}

$rendered = print_r($masked, true);
foreach (array('not-for-logs', 'csrf-secret', 'private-key-material', 'nested-secret') as $secret) {
	if (strpos($rendered, $secret) !== false) {
		$failures++;
		echo "FAIL: rendered debug data contains a secret\n";
	}
}

echo "Secret masking tests: 7 checks, $failures failed\n";
exit($failures === 0 ? 0 : 1);

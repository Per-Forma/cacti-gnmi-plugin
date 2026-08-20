#!/usr/bin/env php
<?php
/**
 * Focused Cacti integration tests for the beta.3 management dispatcher.
 */

$no_http_headers = true;
chdir(__DIR__ . '/../../../../');
include_once('./include/cli_check.php');
require_once('./plugins/gnmi/include/functions.php');
require_once('./plugins/gnmi/include/subscription_functions.php');
require_once('./plugins/gnmi/include/subscription_actions.php');
require_once('./plugins/gnmi/include/subscription_display.php');

$passed = 0;
$failed = 0;

function ajax_assert($condition, $message) {
	global $passed, $failed;
	if ($condition) {
		$passed++;
		echo "PASS: $message\n";
		return;
	}

	$failed++;
	echo "FAIL: $message\n";
}

function ajax_dispatch($host_id, $action, $values = array()) {
	return gnmi_dispatch_management_action(array_merge(array(
		'action' => $action,
		'host_id' => $host_id,
	), $values));
}

$host_id = (int)db_fetch_cell(
	'SELECT h.id FROM host h LEFT JOIN plugin_gnmi_devices d ON d.host_id = h.id '
	. 'WHERE d.id IS NULL ORDER BY h.id LIMIT 1'
);
if ($host_id <= 0) {
	echo "FAIL: disposable Cacti fixture has no unassigned host\n";
	exit(1);
}

$device_id = 0;
$subscription_id = 0;
$metric_id = 0;

try {
	db_execute_prepared(
		'INSERT INTO plugin_gnmi_devices '
		. '(host_id, enabled, hostname, hostname_source, port, username, password, use_tls, '
		. 'compatibility_mode, skip_verify, tls_cipher_policy, collection_interval, encoding) '
		. 'VALUES (?, 0, ?, ?, 9339, ?, ?, 0, ?, 0, ?, 10, ?)',
		array($host_id, 'ajax-action-test.invalid', 'custom', 'test', 'test', 'standard', 'default', 'JSON_IETF')
	);
	$device_id = (int)db_fetch_cell('SELECT LAST_INSERT_ID()');

	$result = ajax_dispatch($host_id, 'not_an_action');
	ajax_assert(!$result['success'] && $result['status'] === 400 && $result['code'] === 'invalid_action', 'unknown actions are rejected');

	$result = ajax_dispatch($host_id, 'add_subscription', array(
		'device_id' => $device_id,
		'subscription_path' => '/interfaces/interface/state',
		'instance_identifier' => 'ajax-test',
		'enabled' => '1',
	));
	ajax_assert($result['success'] && $result['status'] === 201 && $result['code'] === 'subscription_created', 'subscription is created with structured response');
	$subscription_id = isset($result['data']['subscription_id']) ? (int)$result['data']['subscription_id'] : 0;
	$auto_create = (int)db_fetch_cell_prepared(
		'SELECT auto_create_datasources FROM plugin_gnmi_subscriptions WHERE id = ?',
		array($subscription_id)
	);
	ajax_assert($auto_create === 1, 'missing auto-create input defaults on');

	$result = ajax_dispatch($host_id + 999999, 'update_subscription', array(
		'subscription_id' => $subscription_id,
		'notes' => 'must not be saved',
	));
	ajax_assert(!$result['success'] && $result['status'] === 404 && $result['code'] === 'target_unavailable', 'subscription host mismatch is rejected');

	$result = ajax_dispatch($host_id, 'update_subscription', array(
		'subscription_id' => $subscription_id,
		'auto_create_datasources' => '0',
		'notes' => 'updated',
	));
	ajax_assert($result['success'] && $result['code'] === 'subscription_updated', 'subscription settings update');
	$auto_create = (int)db_fetch_cell_prepared(
		'SELECT auto_create_datasources FROM plugin_gnmi_subscriptions WHERE id = ?',
		array($subscription_id)
	);
	ajax_assert($auto_create === 0, 'auto-create can be disabled explicitly');

	$result = ajax_dispatch($host_id, 'add_metric', array(
		'subscription_id' => $subscription_id,
		'metric_name' => 'in-octets',
		'rrd_type' => 'COUNTER',
		'enabled' => '1',
	));
	ajax_assert($result['success'] && $result['status'] === 201 && $result['code'] === 'metric_created', 'metric is created with structured response');
	$metric_id = isset($result['data']['metric_id']) ? (int)$result['data']['metric_id'] : 0;

	$result = ajax_dispatch($host_id, 'update_metric', array(
		'metric_id' => $metric_id,
		'rrd_type' => 'INVALID',
	));
	ajax_assert(!$result['success'] && $result['status'] === 400, 'invalid RRD type is rejected');

	$result = ajax_dispatch($host_id, 'update_metric', array(
		'metric_id' => $metric_id,
		'metric_name' => 'out-octets',
		'rrd_type' => 'GAUGE',
		'rrd_heartbeat' => '30',
		'rrd_min' => '0',
		'rrd_max' => 'U',
		'enabled' => '0',
	));
	ajax_assert($result['success'] && $result['code'] === 'metric_updated', 'metric fields update');

	foreach (array('create_datasource', 'create_graph', 'delete_metric') as $metric_action) {
		$result = ajax_dispatch($host_id + 999999, $metric_action, array(
			'metric_id' => $metric_id,
			'confirm' => '1',
		));
		ajax_assert(!$result['success'] && $result['code'] === 'target_unavailable', "$metric_action enforces metric ownership");
	}

	$result = ajax_dispatch($host_id + 999999, 'restart_daemon');
	ajax_assert(!$result['success'] && $result['code'] === 'target_unavailable', 'restart enforces plugin-backed target ownership');

	$result = ajax_dispatch($host_id, 'delete_metric', array('metric_id' => $metric_id));
	ajax_assert(!$result['success'] && $result['code'] === 'confirmation_required', 'metric deletion requires confirmation');

	$result = ajax_dispatch($host_id, 'delete_metric', array(
		'metric_id' => $metric_id,
		'confirm' => '1',
	));
	ajax_assert($result['success'] && $result['code'] === 'metric_deleted', 'metric deletion succeeds');
	$metric_id = 0;

	$result = ajax_dispatch($host_id, 'delete_subscription', array(
		'subscription_id' => $subscription_id,
		'confirm' => '0',
	));
	ajax_assert(!$result['success'] && $result['code'] === 'confirmation_required', 'subscription deletion requires confirmation');

	$result = ajax_dispatch($host_id, 'delete_subscription', array(
		'subscription_id' => $subscription_id,
		'confirm' => '1',
	));
	ajax_assert($result['success'] && $result['code'] === 'subscription_deleted', 'subscription deletion succeeds');
	$subscription_id = 0;

	ob_start();
	gnmi_render_add_subscription_form($device_id);
	gnmi_render_edit_subscription_form();
	gnmi_render_subscription_javascript($host_id);
	$html = ob_get_clean();
	ajax_assert(strpos($html, 'data-gnmi-name="auto_create_datasources"') !== false, 'auto-create controls render');
	ajax_assert(strpos($html, 'gnmi_ajax_request') !== false && strpos($html, 'gnmiHostId') !== false, 'shared AJAX request helper renders');
} finally {
	if ($metric_id > 0) {
		db_execute_prepared('DELETE FROM plugin_gnmi_metrics WHERE id = ?', array($metric_id));
	}
	if ($subscription_id > 0) {
		db_execute_prepared('DELETE FROM plugin_gnmi_subscriptions WHERE id = ?', array($subscription_id));
	}
	if ($device_id > 0) {
		db_execute_prepared('DELETE FROM plugin_gnmi_devices WHERE id = ?', array($device_id));
	}
}

echo "Management action tests: $passed passed, $failed failed\n";
exit($failed === 0 ? 0 : 1);

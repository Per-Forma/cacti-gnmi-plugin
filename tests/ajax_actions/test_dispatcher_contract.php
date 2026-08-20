#!/usr/bin/env php
<?php
/**
 * Standalone contract tests for the management dispatcher.
 *
 * The Cacti-backed suite verifies real storage behavior; this isolated suite
 * exercises every dispatch branch and fail-closed authorization without
 * provisioning Cacti data sources, graphs, or daemons in the fixture.
 */

define('CACTI_VERSION', 'test');

$test_device_allowed = true;
$test_last_subscription_fields = null;
$passed = 0;
$failed = 0;

function db_fetch_cell_prepared($sql, $params) {
	$id = isset($params[0]) ? (int)$params[0] : 0;
	if (strpos($sql, 'FROM plugin_gnmi_devices WHERE host_id') !== false) {
		return $id === 101 ? 101 : false;
	}
	if (strpos($sql, 'FROM plugin_gnmi_devices WHERE id') !== false) {
		return $id === 11 ? 101 : false;
	}
	if (strpos($sql, 'FROM plugin_gnmi_subscriptions') !== false) {
		return $id === 21 ? 101 : false;
	}
	if (strpos($sql, 'FROM plugin_gnmi_metrics') !== false) {
		return $id === 31 ? 101 : false;
	}
	return false;
}

function db_fetch_row_prepared($sql, $params) {
	if (strpos($sql, 'FROM plugin_gnmi_metrics') !== false && (int)$params[0] === 31) {
		return array('local_data_id' => null, 'datasource_created' => 0);
	}
	return false;
}

function is_device_allowed($host_id) {
	global $test_device_allowed;
	return $test_device_allowed && (int)$host_id === 101;
}

function gnmi_validate_subscription_path($path) {
	return is_string($path) && substr($path, 0, 1) === '/';
}

function gnmi_create_subscription($device_id, $path, $instance, $options) {
	return 201;
}

function gnmi_update_subscription($subscription_id, $fields) {
	global $test_last_subscription_fields;
	$test_last_subscription_fields = $fields;
	return true;
}

function gnmi_delete_subscription($subscription_id) {
	return true;
}

function gnmi_add_metric_to_subscription($subscription_id, $name, $rrd_type, $options) {
	return 301;
}

function gnmi_update_metric($metric_id, $fields) {
	return true;
}

function gnmi_delete_metric($metric_id) {
	return true;
}

function gnmi_create_data_source_for_metric($metric_id) {
	return 401;
}

function gnmi_create_graph_for_metric($metric_id) {
	return 501;
}

function gnmi_restart_daemon_by_host_id($host_id) {
	return true;
}

require_once(__DIR__ . '/../../include/subscription_actions.php');

function contract_assert($condition, $message) {
	global $passed, $failed;
	if ($condition) {
		$passed++;
		echo "PASS: $message\n";
		return;
	}

	$failed++;
	echo "FAIL: $message\n";
}

function contract_dispatch($action, $values = array()) {
	return gnmi_dispatch_management_action(array_merge(array(
		'action' => $action,
		'host_id' => 101,
	), $values));
}

$success_cases = array(
	array('add_subscription', array(
		'device_id' => 11,
		'subscription_path' => '/interfaces/state',
		'instance_identifier' => 'port-1',
	), 'subscription_created', 'subscription_id', 201),
	array('update_subscription', array('subscription_id' => 21, 'notes' => 'updated'), 'subscription_updated', null, null),
	array('delete_subscription', array('subscription_id' => 21, 'confirm' => '1'), 'subscription_deleted', null, null),
	array('add_metric', array('subscription_id' => 21, 'metric_name' => 'in-octets', 'rrd_type' => 'COUNTER'), 'metric_created', 'metric_id', 301),
	array('update_metric', array('metric_id' => 31, 'rrd_type' => 'GAUGE'), 'metric_updated', null, null),
	array('delete_metric', array('metric_id' => 31, 'confirm' => '1'), 'metric_deleted', null, null),
	array('create_datasource', array('metric_id' => 31), 'datasource_created', 'local_data_id', 401),
	array('create_graph', array('metric_id' => 31), 'graph_created', 'graph_local_id', 501),
	array('restart_daemon', array(), 'daemon_restarted', null, null),
);

foreach ($success_cases as $case) {
	$result = contract_dispatch($case[0], $case[1]);
	contract_assert(
		$result['success'] === true && $result['code'] === $case[2]
			&& isset($result['message']) && is_string($result['message']),
		$case[0] . ' returns the success contract'
	);
	if ($case[3] !== null) {
		contract_assert(
			isset($result['data'][$case[3]]) && (int)$result['data'][$case[3]] === $case[4],
			$case[0] . ' returns its created resource ID'
		);
	}
}

$result = gnmi_dispatch_management_action(array('action' => 'invalid', 'host_id' => 101));
contract_assert(!$result['success'] && $result['status'] === 400 && $result['code'] === 'invalid_action', 'unsupported action is rejected');

$result = gnmi_dispatch_management_action(array('action' => 'restart_daemon'));
contract_assert(!$result['success'] && $result['status'] === 400, 'missing host_id is rejected');

$result = contract_dispatch('add_subscription', array('device_id' => 11, 'subscription_path' => '', 'instance_identifier' => ''));
contract_assert(!$result['success'] && $result['status'] === 400, 'required subscription strings are enforced');

$result = contract_dispatch('add_subscription', array(
	'device_id' => 11,
	'subscription_path' => array('/interfaces/state'),
	'instance_identifier' => 'port-1',
));
contract_assert(!$result['success'] && $result['status'] === 400, 'malformed array input is rejected without dispatch');

$result = contract_dispatch('add_subscription', array(
	'device_id' => 11,
	'subscription_path' => '/interfaces/state',
	'instance_identifier' => 'port-1',
	'enabled' => 'yes',
));
contract_assert(!$result['success'] && $result['status'] === 400, 'subscription booleans are strict');

$result = contract_dispatch('update_subscription', array('subscription_id' => 21));
contract_assert(!$result['success'] && $result['code'] === 'invalid_request', 'empty subscription update is rejected');

$result = contract_dispatch('update_subscription', array('subscription_id' => 21, 'notes' => 'preserve auto setting'));
contract_assert(
	$result['success'] && !array_key_exists('auto_create_datasources', $GLOBALS['test_last_subscription_fields']),
	'update omits auto-create when the browser does not submit it'
);

$result = contract_dispatch('add_metric', array('subscription_id' => 21, 'metric_name' => 'm', 'rrd_type' => 'INVALID'));
contract_assert(!$result['success'] && $result['status'] === 400, 'invalid add-metric RRD type is rejected');

$result = contract_dispatch('add_metric', array(
	'subscription_id' => 21,
	'metric_name' => 'm',
	'rrd_type' => 'COUNTER',
	'rrd_heartbeat' => '0',
));
contract_assert(!$result['success'] && $result['status'] === 400, 'invalid add-metric heartbeat is rejected');

$result = contract_dispatch('update_metric', array('metric_id' => 31));
contract_assert(!$result['success'] && $result['code'] === 'invalid_request', 'empty metric update is rejected');

$result = contract_dispatch('update_metric', array('metric_id' => 31, 'rrd_min' => 'not-a-limit'));
contract_assert(!$result['success'] && $result['status'] === 400, 'invalid RRD range is rejected');

foreach (array(
	array('delete_subscription', array('subscription_id' => 21)),
	array('delete_metric', array('metric_id' => 31)),
) as $delete_case) {
	$result = contract_dispatch($delete_case[0], $delete_case[1]);
	contract_assert(!$result['success'] && $result['code'] === 'confirmation_required', $delete_case[0] . ' requires confirmation');
}

$result = contract_dispatch('update_subscription', array('subscription_id' => 999, 'notes' => 'x'));
contract_assert(!$result['success'] && $result['status'] === 404, 'nonexistent subscription fails closed');

$result = contract_dispatch('update_metric', array('metric_id' => 999, 'metric_name' => 'x'));
contract_assert(!$result['success'] && $result['status'] === 404, 'nonexistent metric fails closed');

$result = contract_dispatch('update_subscription', array('host_id' => 102, 'subscription_id' => 21, 'notes' => 'x'));
contract_assert(!$result['success'] && $result['status'] === 404, 'subscription cannot cross host scope');

$result = contract_dispatch('update_metric', array('host_id' => 102, 'metric_id' => 31, 'metric_name' => 'x'));
contract_assert(!$result['success'] && $result['status'] === 404, 'metric cannot cross host scope');

$GLOBALS['gnmi_enforce_device_auth_in_cli'] = true;
$test_device_allowed = false;
$result = contract_dispatch('update_subscription', array('subscription_id' => 21, 'notes' => 'denied'));
contract_assert(!$result['success'] && $result['status'] === 404, 'device-level denial fails closed');

echo "Dispatcher contract tests: $passed passed, $failed failed\n";
exit($failed === 0 ? 0 : 1);

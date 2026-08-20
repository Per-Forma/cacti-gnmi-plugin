<?php
/**
 * gNMI subscription and metric management action service.
 *
 * This file contains no HTTP response or redirect handling. Callers pass an
 * explicit request array and receive a structured result suitable for JSON.
 */

if (!defined('CACTI_VERSION')) {
	die('Direct access not allowed');
}

/**
 * Return the supported management action names.
 *
 * @return array
 */
function gnmi_management_action_names() {
	return array(
		'add_subscription',
		'update_subscription',
		'delete_subscription',
		'add_metric',
		'update_metric',
		'delete_metric',
		'create_datasource',
		'create_graph',
		'restart_daemon',
	);
}

/**
 * Build a management action result.
 *
 * @param bool $success
 * @param int $status HTTP status for the adapter
 * @param string $code Stable machine-readable code
 * @param string $message User-safe message
 * @param array $data Optional response data
 * @return array
 */
function gnmi_management_result($success, $status, $code, $message, $data = array()) {
	$result = array(
		'success' => (bool)$success,
		'status' => (int)$status,
		'code' => (string)$code,
		'message' => (string)$message,
	);

	if (!empty($data)) {
		$result['data'] = $data;
	}

	return $result;
}

/**
 * Read a strictly positive integer request value.
 *
 * @param array $request
 * @param string $key
 * @return int|false
 */
function gnmi_management_positive_int($request, $key) {
	if (!array_key_exists($key, $request)
		|| (!is_string($request[$key]) && !is_int($request[$key]))) {
		return false;
	}

	$value = filter_var($request[$key], FILTER_VALIDATE_INT, array(
		'options' => array('min_range' => 1),
	));

	return $value === false ? false : (int)$value;
}

/**
 * Read a scalar request value as trimmed text without triggering PHP warnings.
 *
 * @param array $request
 * @param string $key
 * @param string|null $value
 * @return bool
 */
function gnmi_management_text($request, $key, &$value) {
	if (!array_key_exists($key, $request)
		|| !is_scalar($request[$key])
		|| is_bool($request[$key])) {
		$value = null;
		return false;
	}

	$value = trim((string)$request[$key]);
	return true;
}

/**
 * Read a strict HTML-form boolean (0/1 only).
 *
 * @param mixed $value
 * @param bool|null $parsed
 * @return bool
 */
function gnmi_management_parse_boolean($value, &$parsed) {
	if ($value === 1 || $value === '1' || $value === true) {
		$parsed = true;
		return true;
	}

	if ($value === 0 || $value === '0' || $value === false) {
		$parsed = false;
		return true;
	}

	$parsed = null;
	return false;
}

/**
 * Resolve the Cacti host targeted by an action.
 *
 * @param string $action
 * @param array $request
 * @return array Structured resolution result
 */
function gnmi_resolve_management_target($action, $request) {
	if ($action === 'restart_daemon') {
		$host_id = gnmi_management_positive_int($request, 'host_id');
		if ($host_id === false) {
			return gnmi_management_result(false, 400, 'invalid_request', 'A valid host_id is required.');
		}

		$resolved = db_fetch_cell_prepared(
			'SELECT host_id FROM plugin_gnmi_devices WHERE host_id = ?',
			array($host_id)
		);
	} elseif ($action === 'add_subscription') {
		$device_id = gnmi_management_positive_int($request, 'device_id');
		if ($device_id === false) {
			return gnmi_management_result(false, 400, 'invalid_request', 'A valid device_id is required.');
		}

		$resolved = db_fetch_cell_prepared(
			'SELECT host_id FROM plugin_gnmi_devices WHERE id = ?',
			array($device_id)
		);
	} elseif (in_array($action, array('update_subscription', 'delete_subscription', 'add_metric'), true)) {
		$subscription_id = gnmi_management_positive_int($request, 'subscription_id');
		if ($subscription_id === false) {
			return gnmi_management_result(false, 400, 'invalid_request', 'A valid subscription_id is required.');
		}

		$resolved = db_fetch_cell_prepared(
			'SELECT d.host_id FROM plugin_gnmi_subscriptions s '
			. 'INNER JOIN plugin_gnmi_devices d ON d.id = s.device_id '
			. 'WHERE s.id = ?',
			array($subscription_id)
		);
	} else {
		$metric_id = gnmi_management_positive_int($request, 'metric_id');
		if ($metric_id === false) {
			return gnmi_management_result(false, 400, 'invalid_request', 'A valid metric_id is required.');
		}

		$resolved = db_fetch_cell_prepared(
			'SELECT d.host_id FROM plugin_gnmi_metrics m '
			. 'INNER JOIN plugin_gnmi_subscriptions s ON s.id = m.subscription_id '
			. 'INNER JOIN plugin_gnmi_devices d ON d.id = s.device_id '
			. 'WHERE m.id = ?',
			array($metric_id)
		);
	}

	$submitted_host_id = gnmi_management_positive_int($request, 'host_id');
	if ($submitted_host_id === false) {
		return gnmi_management_result(false, 400, 'invalid_request', 'A valid host_id is required.');
	}

	if (empty($resolved) || (int)$resolved !== $submitted_host_id) {
		return gnmi_management_result(false, 404, 'target_unavailable', 'Target not found or permission denied.');
	}

	// Cacti exposes device-level view restrictions through is_device_allowed().
	// CLI harnesses may bypass this unless they explicitly enable authorization.
	if (PHP_SAPI !== 'cli' || !empty($GLOBALS['gnmi_enforce_device_auth_in_cli'])) {
		if (!function_exists('is_device_allowed') || !is_device_allowed($submitted_host_id)) {
			return gnmi_management_result(false, 404, 'target_unavailable', 'Target not found or permission denied.');
		}
	}

	return gnmi_management_result(true, 200, 'target_authorized', 'Target authorized.', array(
		'host_id' => $submitted_host_id,
	));
}

/**
 * Dispatch a validated management request.
 *
 * Authentication and CSRF are transport concerns handled by ajax_handler.php.
 * Resource-to-device authorization is enforced here before every mutation.
 *
 * @param array $request
 * @return array
 */
function gnmi_dispatch_management_action($request) {
	$action = isset($request['action']) && is_string($request['action'])
		? trim($request['action'])
		: '';

	if (!in_array($action, gnmi_management_action_names(), true)) {
		return gnmi_management_result(false, 400, 'invalid_action', 'Invalid or missing action.');
	}

	$target = gnmi_resolve_management_target($action, $request);
	if (!$target['success']) {
		return $target;
	}

	switch ($action) {
		case 'add_subscription':
			return gnmi_dispatch_add_subscription($request);
		case 'update_subscription':
			return gnmi_dispatch_update_subscription($request);
		case 'delete_subscription':
			return gnmi_dispatch_delete_subscription($request);
		case 'add_metric':
			return gnmi_dispatch_add_metric($request);
		case 'update_metric':
			return gnmi_dispatch_update_metric($request);
		case 'delete_metric':
			return gnmi_dispatch_delete_metric($request);
		case 'create_datasource':
			return gnmi_dispatch_create_datasource($request);
		case 'create_graph':
			return gnmi_dispatch_create_graph($request);
		case 'restart_daemon':
			return gnmi_dispatch_restart_daemon($request);
	}

	return gnmi_management_result(false, 400, 'invalid_action', 'Invalid or missing action.');
}

function gnmi_dispatch_add_subscription($request) {
	$device_id = gnmi_management_positive_int($request, 'device_id');
	$path = '';
	$instance = '';
	$notes = '';
	$path_valid = gnmi_management_text($request, 'subscription_path', $path);
	$instance_valid = gnmi_management_text($request, 'instance_identifier', $instance);
	if (array_key_exists('notes', $request) && !gnmi_management_text($request, 'notes', $notes)) {
		return gnmi_management_result(false, 400, 'invalid_request', 'Notes must be text.');
	}

	if (!$path_valid || !$instance_valid || !gnmi_validate_subscription_path($path) || $instance === '') {
		return gnmi_management_result(false, 400, 'invalid_request', 'Subscription path and instance identifier are required.');
	}

	$enabled = true;
	if (array_key_exists('enabled', $request) && !gnmi_management_parse_boolean($request['enabled'], $enabled)) {
		return gnmi_management_result(false, 400, 'invalid_request', 'Enabled must be 0 or 1.');
	}

	$auto_create = true;
	if (array_key_exists('auto_create_datasources', $request)
		&& !gnmi_management_parse_boolean($request['auto_create_datasources'], $auto_create)) {
		return gnmi_management_result(false, 400, 'invalid_request', 'Automatic data-source creation must be 0 or 1.');
	}

	$subscription_id = gnmi_create_subscription($device_id, $path, $instance, array(
		'enabled' => $enabled,
		'notes' => $notes,
		'auto_create_datasources' => $auto_create,
	));

	if (!$subscription_id) {
		return gnmi_management_result(false, 500, 'action_failed', 'Failed to create subscription.');
	}

	return gnmi_management_result(true, 201, 'subscription_created', 'Subscription created successfully.', array(
		'subscription_id' => (int)$subscription_id,
	));
}

function gnmi_dispatch_update_subscription($request) {
	$subscription_id = gnmi_management_positive_int($request, 'subscription_id');
	$fields = array();

	if (array_key_exists('subscription_path', $request)) {
		if (!gnmi_management_text($request, 'subscription_path', $path)
			|| !gnmi_validate_subscription_path($path)) {
			return gnmi_management_result(false, 400, 'invalid_request', 'A valid subscription path is required.');
		}
		$fields['subscription_path'] = $path;
	}

	if (array_key_exists('instance_identifier', $request)) {
		if (!gnmi_management_text($request, 'instance_identifier', $instance) || $instance === '') {
			return gnmi_management_result(false, 400, 'invalid_request', 'Instance identifier cannot be empty.');
		}
		$fields['instance_identifier'] = $instance;
	}

	if (array_key_exists('enabled', $request)) {
		if (!gnmi_management_parse_boolean($request['enabled'], $enabled)) {
			return gnmi_management_result(false, 400, 'invalid_request', 'Enabled must be 0 or 1.');
		}
		$fields['enabled'] = $enabled;
	}

	if (array_key_exists('auto_create_datasources', $request)) {
		if (!gnmi_management_parse_boolean($request['auto_create_datasources'], $auto_create)) {
			return gnmi_management_result(false, 400, 'invalid_request', 'Automatic data-source creation must be 0 or 1.');
		}
		$fields['auto_create_datasources'] = $auto_create;
	}

	if (array_key_exists('notes', $request)) {
		if (!gnmi_management_text($request, 'notes', $notes)) {
			return gnmi_management_result(false, 400, 'invalid_request', 'Notes must be text.');
		}
		$fields['notes'] = $notes;
	}

	if (empty($fields)) {
		return gnmi_management_result(false, 400, 'invalid_request', 'No subscription fields were provided.');
	}

	if (!gnmi_update_subscription($subscription_id, $fields)) {
		return gnmi_management_result(false, 500, 'action_failed', 'Failed to update subscription.');
	}

	return gnmi_management_result(true, 200, 'subscription_updated', 'Subscription updated successfully.');
}

function gnmi_dispatch_delete_subscription($request) {
	$subscription_id = gnmi_management_positive_int($request, 'subscription_id');
	$confirmed = false;
	if (!array_key_exists('confirm', $request)
		|| !gnmi_management_parse_boolean($request['confirm'], $confirmed)
		|| !$confirmed) {
		return gnmi_management_result(false, 400, 'confirmation_required', 'Subscription deletion must be confirmed.');
	}

	if (!gnmi_delete_subscription($subscription_id)) {
		return gnmi_management_result(false, 500, 'action_failed', 'Failed to delete subscription.');
	}

	return gnmi_management_result(true, 200, 'subscription_deleted', 'Subscription deleted successfully.');
}

function gnmi_dispatch_add_metric($request) {
	$subscription_id = gnmi_management_positive_int($request, 'subscription_id');
	$metric_name = '';
	$rrd_type = '';
	$metric_name_valid = gnmi_management_text($request, 'metric_name', $metric_name);
	$rrd_type_valid = gnmi_management_text($request, 'rrd_type', $rrd_type);
	$rrd_type = strtoupper($rrd_type);
	$valid_rrd_types = array('COUNTER', 'GAUGE', 'DERIVE', 'ABSOLUTE');

	if (!$metric_name_valid || !$rrd_type_valid || $metric_name === '' || !in_array($rrd_type, $valid_rrd_types, true)) {
		return gnmi_management_result(false, 400, 'invalid_request', 'Metric name and a valid RRD type are required.');
	}

	$enabled = true;
	if (array_key_exists('enabled', $request) && !gnmi_management_parse_boolean($request['enabled'], $enabled)) {
		return gnmi_management_result(false, 400, 'invalid_request', 'Enabled must be 0 or 1.');
	}

	$options = array('enabled' => $enabled);
	if (array_key_exists('rrd_heartbeat', $request)) {
		$heartbeat = gnmi_management_positive_int($request, 'rrd_heartbeat');
		if ($heartbeat === false) {
			return gnmi_management_result(false, 400, 'invalid_request', 'RRD heartbeat must be a positive integer.');
		}
		$options['rrd_heartbeat'] = (int)$heartbeat;
	}

	foreach (array('rrd_min', 'rrd_max') as $range_field) {
		if (array_key_exists($range_field, $request)) {
			if (!gnmi_management_text($request, $range_field, $value)
				|| $value === ''
				|| (strtoupper($value) !== 'U' && !is_numeric($value))) {
				return gnmi_management_result(false, 400, 'invalid_request', 'RRD limits must be numeric or U.');
			}
			$options[$range_field] = strtoupper($value) === 'U' ? 'U' : $value;
		}
	}

	$metric_id = gnmi_add_metric_to_subscription($subscription_id, $metric_name, $rrd_type, $options);
	if (!$metric_id) {
		return gnmi_management_result(false, 500, 'action_failed', 'Failed to add metric.');
	}

	return gnmi_management_result(true, 201, 'metric_created', 'Metric added successfully.', array(
		'metric_id' => (int)$metric_id,
	));
}

function gnmi_dispatch_update_metric($request) {
	$metric_id = gnmi_management_positive_int($request, 'metric_id');
	$fields = array();

	if (array_key_exists('metric_name', $request)) {
		if (!gnmi_management_text($request, 'metric_name', $metric_name) || $metric_name === '') {
			return gnmi_management_result(false, 400, 'invalid_request', 'Metric name cannot be empty.');
		}
		$fields['metric_name'] = $metric_name;
	}

	if (array_key_exists('rrd_type', $request)) {
		if (!gnmi_management_text($request, 'rrd_type', $rrd_type)) {
			return gnmi_management_result(false, 400, 'invalid_request', 'Invalid RRD type.');
		}
		$rrd_type = strtoupper($rrd_type);
		if (!in_array($rrd_type, array('COUNTER', 'GAUGE', 'DERIVE', 'ABSOLUTE'), true)) {
			return gnmi_management_result(false, 400, 'invalid_request', 'Invalid RRD type.');
		}
		$fields['rrd_type'] = $rrd_type;
	}

	if (array_key_exists('enabled', $request)) {
		if (!gnmi_management_parse_boolean($request['enabled'], $enabled)) {
			return gnmi_management_result(false, 400, 'invalid_request', 'Enabled must be 0 or 1.');
		}
		$fields['enabled'] = $enabled;
	}

	if (array_key_exists('rrd_heartbeat', $request)) {
		$heartbeat = gnmi_management_positive_int($request, 'rrd_heartbeat');
		if ($heartbeat === false) {
			return gnmi_management_result(false, 400, 'invalid_request', 'RRD heartbeat must be a positive integer.');
		}
		$fields['rrd_heartbeat'] = (int)$heartbeat;
	}

	foreach (array('rrd_min', 'rrd_max') as $range_field) {
		if (array_key_exists($range_field, $request)) {
			if (!gnmi_management_text($request, $range_field, $value)
				|| $value === ''
				|| (strtoupper($value) !== 'U' && !is_numeric($value))) {
				return gnmi_management_result(false, 400, 'invalid_request', 'RRD limits must be numeric or U.');
			}
			$fields[$range_field] = strtoupper($value) === 'U' ? 'U' : $value;
		}
	}

	if (empty($fields)) {
		return gnmi_management_result(false, 400, 'invalid_request', 'No metric fields were provided.');
	}

	if (!gnmi_update_metric($metric_id, $fields)) {
		return gnmi_management_result(false, 500, 'action_failed', 'Failed to update metric.');
	}

	return gnmi_management_result(true, 200, 'metric_updated', 'Metric updated successfully.');
}

function gnmi_dispatch_delete_metric($request) {
	$metric_id = gnmi_management_positive_int($request, 'metric_id');
	$confirmed = false;
	if (!array_key_exists('confirm', $request)
		|| !gnmi_management_parse_boolean($request['confirm'], $confirmed)
		|| !$confirmed) {
		return gnmi_management_result(false, 400, 'confirmation_required', 'Metric deletion must be confirmed.');
	}

	if (!gnmi_delete_metric($metric_id)) {
		return gnmi_management_result(false, 500, 'action_failed', 'Failed to delete metric.');
	}

	return gnmi_management_result(true, 200, 'metric_deleted', 'Metric deleted successfully.');
}

function gnmi_dispatch_create_datasource($request) {
	$metric_id = gnmi_management_positive_int($request, 'metric_id');
	$metric = db_fetch_row_prepared(
		'SELECT local_data_id, datasource_created FROM plugin_gnmi_metrics WHERE id = ?',
		array($metric_id)
	);

	if (!empty($metric['datasource_created']) && !empty($metric['local_data_id'])) {
		return gnmi_management_result(true, 200, 'datasource_exists', 'Data source already exists.', array(
			'local_data_id' => (int)$metric['local_data_id'],
		));
	}

	$local_data_id = gnmi_create_data_source_for_metric($metric_id);
	if (!$local_data_id) {
		return gnmi_management_result(false, 500, 'action_failed', 'Failed to create data source.');
	}

	return gnmi_management_result(true, 201, 'datasource_created', 'Data source created successfully.', array(
		'local_data_id' => (int)$local_data_id,
	));
}

function gnmi_dispatch_create_graph($request) {
	$metric_id = gnmi_management_positive_int($request, 'metric_id');
	$graph_id = gnmi_create_graph_for_metric($metric_id);
	if ($graph_id === false) {
		return gnmi_management_result(false, 500, 'action_failed', 'Graph creation failed. Ensure the required data source exists.');
	}

	return gnmi_management_result(true, 201, 'graph_created', 'Graph created successfully.', array(
		'graph_local_id' => (int)$graph_id,
	));
}

function gnmi_dispatch_restart_daemon($request) {
	$host_id = gnmi_management_positive_int($request, 'host_id');
	if (!gnmi_restart_daemon_by_host_id($host_id)) {
		return gnmi_management_result(false, 500, 'action_failed', 'Failed to restart daemon. Check the Cacti logs for details.');
	}

	return gnmi_management_result(true, 200, 'daemon_restarted', 'Daemon restart initiated successfully.');
}

/**
 * CLI-only compatibility adapter for the historical phase test harnesses.
 *
 * Web mutations must use ajax_handler.php and provide host_id explicitly.
 *
 * @return bool
 */
function gnmi_process_subscription_action() {
	if (PHP_SAPI !== 'cli' || !gnmi_current_user_can_manage('ajax_handler.php') || !gnmi_validate_csrf_request()) {
		return false;
	}

	$request = $_POST;
	$action = isset($request['action']) ? (string)$request['action'] : '';
	if (!isset($request['host_id'])) {
		if ($action === 'add_subscription' && isset($request['device_id'])) {
			$request['host_id'] = db_fetch_cell_prepared(
				'SELECT host_id FROM plugin_gnmi_devices WHERE id = ?',
				array((int)$request['device_id'])
			);
		} elseif (in_array($action, array('update_subscription', 'delete_subscription', 'add_metric'), true)
			&& isset($request['subscription_id'])) {
			$request['host_id'] = db_fetch_cell_prepared(
				'SELECT d.host_id FROM plugin_gnmi_subscriptions s '
				. 'INNER JOIN plugin_gnmi_devices d ON d.id = s.device_id WHERE s.id = ?',
				array((int)$request['subscription_id'])
			);
		} elseif (isset($request['metric_id'])) {
			$request['host_id'] = db_fetch_cell_prepared(
				'SELECT d.host_id FROM plugin_gnmi_metrics m '
				. 'INNER JOIN plugin_gnmi_subscriptions s ON s.id = m.subscription_id '
				. 'INNER JOIN plugin_gnmi_devices d ON d.id = s.device_id WHERE m.id = ?',
				array((int)$request['metric_id'])
			);
		}
	}

	$result = gnmi_dispatch_management_action($request);
	return !empty($result['success']);
}

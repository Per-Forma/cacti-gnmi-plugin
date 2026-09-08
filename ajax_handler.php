<?php
/**
 * gNMI management AJAX endpoint.
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

/**
 * Emit the public AJAX response contract and terminate the request.
 *
 * @param array $result
 * @return void
 */
function gnmi_emit_ajax_result($result) {
	$status = isset($result['status']) ? (int)$result['status'] : 500;
	unset($result['status']);

	http_response_code($status);
	echo json_encode($result, JSON_UNESCAPED_SLASHES);
	exit;
}

try {
	$gnmi_request_method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper($_SERVER['REQUEST_METHOD']) : '';
	// Cacti's global bootstrap normally performs a redirecting CSRF check
	// during include. Defer that behavior for this JSON endpoint and run the
	// same validator explicitly below so failures keep the response contract.
	if ($gnmi_request_method === 'POST') {
		$_SERVER['REQUEST_METHOD'] = 'GET';
	}
	try {
		require_once(__DIR__ . '/../../include/global.php');
	} finally {
		if ($gnmi_request_method !== '') {
			$_SERVER['REQUEST_METHOD'] = $gnmi_request_method;
		}
	}

	require_once(__DIR__ . '/include/functions.php');

	if (!isset($_SERVER['REQUEST_METHOD']) || strtoupper($_SERVER['REQUEST_METHOD']) !== 'POST') {
		gnmi_emit_ajax_result(array(
			'success' => false,
			'status' => 405,
			'code' => 'method_not_allowed',
			'message' => 'POST is required.',
		));
	}

	if (!gnmi_current_user_has_plugin_realm('ajax_handler.php')) {
		gnmi_emit_ajax_result(array(
			'success' => false,
			'status' => 403,
			'code' => 'permission_denied',
			'message' => 'Permission denied.',
		));
	}

	if (!gnmi_validate_csrf_request()) {
		cacti_log('gNMI: AJAX CSRF token validation failed', false, 'PLUGIN');
		gnmi_emit_ajax_result(array(
			'success' => false,
			'status' => 403,
			'code' => 'invalid_csrf',
			'message' => 'Invalid request token.',
		));
	}

	require_once(__DIR__ . '/include/subscription_functions.php');
	require_once(__DIR__ . '/include/subscription_actions.php');

	$action = isset($_POST['action']) && is_string($_POST['action']) ? $_POST['action'] : '';
	$log_action = substr(preg_replace('/[^a-z0-9_-]/i', '', $action), 0, 64);
	cacti_log(
		'gNMI: AJAX management request action=' . $log_action,
		false,
		'PLUGIN',
		POLLER_VERBOSITY_DEBUG
	);

	gnmi_emit_ajax_result(gnmi_dispatch_management_action($_POST));
} catch (Throwable $error) {
	$action = isset($_POST['action']) && is_string($_POST['action']) ? $_POST['action'] : 'unknown';
	$action = substr(preg_replace('/[^a-z0-9_-]/i', '', $action), 0, 64);
	$host_id = isset($_POST['host_id']) && (is_string($_POST['host_id']) || is_int($_POST['host_id']))
		? (int)$_POST['host_id']
		: 0;
	if (function_exists('cacti_log')) {
		cacti_log(
			'gNMI: Unhandled AJAX management failure action=' . $action
			. ' host_id=' . $host_id
			. ' exception=' . get_class($error),
			false,
			'PLUGIN'
		);
	}

	gnmi_emit_ajax_result(array(
		'success' => false,
		'status' => 500,
		'code' => 'internal_error',
		'message' => 'An unexpected server error occurred. Check the Cacti logs for details.',
	));
}

<?php
/**
 * Authorized state-changing actions for the gNMI status dashboards.
 */

if (!defined('CACTI_VERSION')) {
	die('Access denied');
}

require_once __DIR__ . '/access.php';

/**
 * Execute a dashboard action only for an authorized, CSRF-protected POST.
 *
 * The caller is responsible for displaying the returned message and applying
 * the post/redirect/get pattern.
 *
 * @param string $action Requested dashboard action
 * @param int $device_id Plugin device ID for device-specific actions
 * @return array Result containing success, message, and message level
 */
function gnmi_process_dashboard_action($action, $device_id = 0) {
	$method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper($_SERVER['REQUEST_METHOD']) : '';
	if ($method !== 'POST') {
		return array(
			'success' => false,
			'message' => __('Dashboard actions require a POST request.', 'gnmi'),
			'level' => MESSAGE_LEVEL_ERROR,
		);
	}

	if (!gnmi_current_user_can_manage_daemons()) {
		return array(
			'success' => false,
			'message' => __('You are not authorized to manage gNMI daemons.', 'gnmi'),
			'level' => MESSAGE_LEVEL_ERROR,
		);
	}

	if (!gnmi_validate_csrf_request()) {
		return array(
			'success' => false,
			'message' => __('Invalid request token. Please try again.', 'gnmi'),
			'level' => MESSAGE_LEVEL_ERROR,
		);
	}

	if ($action === 'restart') {
		if ((!is_string($device_id) && !is_int($device_id))
			|| filter_var($device_id, FILTER_VALIDATE_INT, array('options' => array('min_range' => 1))) === false) {
			return array(
				'success' => false,
				'message' => __('Target not found or permission denied.', 'gnmi'),
				'level' => MESSAGE_LEVEL_ERROR,
			);
		}

		$device = db_fetch_row_prepared(
			'SELECT * FROM plugin_gnmi_devices WHERE id = ?',
			array($device_id)
		);

		if (!$device || !gnmi_current_user_can_restart_device($device, false)) {
			return array('success' => false, 'message' => __('Target not found or permission denied.', 'gnmi'),
				'level' => MESSAGE_LEVEL_ERROR);
		}

		if (!gnmi_restart_daemon($device)) {
			return array(
				'success' => false,
				'message' => __('Failed to restart daemon for device %d', $device_id, 'gnmi'),
				'level' => MESSAGE_LEVEL_ERROR,
			);
		}

		gnmi_log_event($device_id, 'daemon_restart', array(
			'reason' => 'user_action',
			'user' => isset($_SESSION['sess_user_id']) ? $_SESSION['sess_user_id'] : 'unknown',
		));

		return array(
			'success' => true,
			'message' => __('Daemon restarted successfully for device %d', $device_id, 'gnmi'),
			'level' => MESSAGE_LEVEL_INFO,
		);
	}

	if ($action === 'cleanup_orphans') {
		if (!gnmi_current_user_is_installation_admin()) {
			return array('success' => false, 'message' => __('You are not authorized to perform installation maintenance.', 'gnmi'),
				'level' => MESSAGE_LEVEL_ERROR);
		}
		$counts = null;
		gnmi_cleanup_orphans(true, $counts);
		cacti_log('gNMI: Manual orphan cleanup actor=' . (int)$_SESSION['sess_user_id']
			. ' outcome=completed orphans_found=' . (int)$counts['orphans_found']
			. ' processes_stopped=' . (int)$counts['processes_stopped'], false, 'GNMI');

		return array(
			'success' => true,
			'message' => __('Orphan cleanup completed', 'gnmi'),
			'level' => MESSAGE_LEVEL_INFO,
		);
	}

	return array(
		'success' => false,
		'message' => __('Unknown dashboard action.', 'gnmi'),
		'level' => MESSAGE_LEVEL_ERROR,
	);
}

<?php
/**
 * gNMI Plugin - AJAX Handler
 *
 * Properly integrated AJAX endpoint for subscription management.
 * This follows Cacti's security patterns and hook system.
 */

// Include Cacti's global functions
require_once(__DIR__ . '/../../include/global.php');
require_once(__DIR__ . '/include/functions.php');

// Set JSON content type
header('Content-Type: application/json');

// Include required files
require_once($config['base_path'] . '/lib/database.php');
require_once(__DIR__ . '/include/subscription_functions.php');
require_once(__DIR__ . '/pages/subscription_actions.php');

// Check if user is logged in and authorized for gNMI/device management.
if (!gnmi_current_user_can_manage('ajax_handler.php')) {
    echo json_encode(['success' => false, 'message' => 'Permission denied']);
    exit;
}

// Explicit CSRF guard for plugin mutation endpoints.
if (!gnmi_validate_csrf_request()) {
    cacti_log('gNMI: AJAX CSRF token validation failed', false, 'PLUGIN');
    echo json_encode(['success' => false, 'message' => 'Invalid request token']);
    exit;
}

// Get action from request
$action = isset($_POST['action']) ? $_POST['action'] : '';

// Log the request for debugging
cacti_log("gNMI: AJAX request - action: $action", false, 'PLUGIN', POLLER_VERBOSITY_DEBUG);
cacti_log("gNMI: AJAX POST data: " . gnmi_sanitized_post_for_log(), false, 'PLUGIN', POLLER_VERBOSITY_DEBUG);

// Validate action
if (empty($action) || !in_array($action, ['add_subscription', 'add_metric', 'update_subscription', 'delete_subscription', 'update_metric', 'delete_metric', 'create_datasource', 'create_graph', 'restart_daemon'])) {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid action: ' . $action
    ]);
    exit;
}

// Handle restart_daemon separately — it uses functions.php, not subscription_actions.php
if ($action === 'restart_daemon') {
    $host_id = isset($_POST['host_id']) ? (int)$_POST['host_id'] : 0;
    if ($host_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid host_id']);
        exit;
    }
    $result = gnmi_restart_daemon_by_host_id($host_id);
    echo json_encode([
        'success' => (bool)$result,
        'message' => $result ? 'Daemon restart initiated successfully' : 'Failed to restart daemon — check the Cacti logs for details'
    ]);
    exit;
}

// Process the subscription action
$result = gnmi_process_subscription_action();

if ($result) {
    echo json_encode(['success' => true, 'message' => 'Subscription processed successfully']);
} else {
    echo json_encode(['success' => false, 'message' => 'Failed to process subscription']);
}
exit;

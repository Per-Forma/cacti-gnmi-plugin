<?php
/**
 * gNMI Plugin Phase 3.3 - Subscription Management Functions
 *
 * CRUD operations for subscriptions and metrics with full validation.
 * All functions use prepared statements and input sanitization.
 */

/**
 * Create a new gNMI subscription
 *
 * @param int $device_id Device ID from plugin_gnmi_devices
 * @param string $subscription_path gNMI subscription path
 * @param string $instance_identifier Instance name (e.g., ettp-40, eth0)
 * @param array $options Additional options (enabled, notes, etc.)
 * @return int|false Subscription ID on success, false on failure
 */
function gnmi_create_subscription($device_id, $subscription_path, $instance_identifier, $options = []) {
    // Validate inputs
    if (empty($device_id) || !is_numeric($device_id)) {
        cacti_log('gNMI: Invalid device_id for subscription creation', false, 'PLUGIN');
        return false;
    }

    if (!gnmi_validate_subscription_path($subscription_path)) {
        cacti_log('gNMI: Invalid subscription path', false, 'PLUGIN');
        return false;
    }

    if (empty($instance_identifier)) {
        cacti_log('gNMI: Instance identifier required', false, 'PLUGIN');
        return false;
    }

    // Verify device exists
    $device = db_fetch_row_prepared(
        'SELECT id FROM plugin_gnmi_devices WHERE id = ?',
        array($device_id)
    );

    if (!$device) {
        cacti_log("gNMI: Device $device_id not found", false, 'PLUGIN');
        return false;
    }

    // Sanitize inputs
    $device_id = (int)$device_id;
    $subscription_path = html_escape($subscription_path);
    $instance_identifier = html_escape($instance_identifier);
    $enabled = isset($options['enabled']) ? (bool)$options['enabled'] : true;
    $notes = isset($options['notes']) ? html_escape($options['notes']) : '';

    $auto_create = isset($options['auto_create_datasources']) ? (int)(bool)$options['auto_create_datasources'] : 0;
    $auto_create_graphs = isset($options['auto_create_graphs']) ? (int)(bool)$options['auto_create_graphs'] : 1;

    // Create subscription
    $result = db_execute_prepared("
        INSERT INTO plugin_gnmi_subscriptions (
            device_id, subscription_path, instance_identifier, enabled, notes,
            auto_create_datasources, auto_create_graphs
        ) VALUES (?, ?, ?, ?, ?, ?, ?)
    ", array($device_id, $subscription_path, $instance_identifier, $enabled, $notes, $auto_create, $auto_create_graphs));

    if ($result) {
        $subscription_id = db_fetch_cell('SELECT LAST_INSERT_ID()');
    } else {
        $subscription_id = false;
    }

    if ($subscription_id) {
        cacti_log("gNMI: Created subscription $subscription_id for device $device_id", false, 'PLUGIN');
        return $subscription_id;
    } else {
        cacti_log('gNMI: Failed to create subscription', false, 'PLUGIN');
        return false;
    }
}

/**
 * Get subscription by ID
 *
 * @param int $subscription_id Subscription ID
 * @return array|false Subscription data or false if not found
 */
function gnmi_get_subscription($subscription_id) {
    if (empty($subscription_id) || !is_numeric($subscription_id)) {
        return false;
    }

    $subscription = db_fetch_row_prepared(
        'SELECT * FROM plugin_gnmi_subscriptions WHERE id = ?',
        array((int)$subscription_id)
    );

    return $subscription ?: false;
}

/**
 * Get all subscriptions for a device
 *
 * @param int $device_id Device ID
 * @param bool $enabled_only Only return enabled subscriptions
 * @return array Array of subscription data
 */
function gnmi_get_device_subscriptions($device_id, $enabled_only = false) {
    if (empty($device_id) || !is_numeric($device_id)) {
        return [];
    }

    $sql = 'SELECT * FROM plugin_gnmi_subscriptions WHERE device_id = ?';
    $params = [(int)$device_id];

    if ($enabled_only) {
        $sql .= ' AND enabled = 1';
    }

    $sql .= ' ORDER BY created_on DESC';

    $subscriptions = db_fetch_assoc_prepared($sql, $params);

    return $subscriptions ?: [];
}

/**
 * Update subscription fields
 *
 * @param int $subscription_id Subscription ID
 * @param array $fields Fields to update (subscription_path, instance_identifier, enabled, notes, auto_create_datasources)
 * @return bool Success status
 */
function gnmi_update_subscription($subscription_id, $fields) {
    if (empty($subscription_id) || !is_numeric($subscription_id)) {
        cacti_log('gNMI: Invalid subscription_id for update', false, 'PLUGIN');
        return false;
    }

    if (empty($fields) || !is_array($fields)) {
        cacti_log('gNMI: No fields provided for subscription update', false, 'PLUGIN');
        return false;
    }

    // Validate subscription exists
    $subscription = gnmi_get_subscription($subscription_id);
    if (!$subscription) {
        cacti_log("gNMI: Subscription $subscription_id not found", false, 'PLUGIN');
        return false;
    }

    // Build update query
    $update_fields = [];
    $params = [];

    if (isset($fields['subscription_path'])) {
        if (!gnmi_validate_subscription_path($fields['subscription_path'])) {
            cacti_log('gNMI: Invalid subscription path in update', false, 'PLUGIN');
            return false;
        }
        $update_fields[] = 'subscription_path = ?';
        $params[] = html_escape($fields['subscription_path']);
    }

    if (isset($fields['instance_identifier'])) {
        if (empty($fields['instance_identifier'])) {
            cacti_log('gNMI: Instance identifier cannot be empty', false, 'PLUGIN');
            return false;
        }
        $update_fields[] = 'instance_identifier = ?';
        $params[] = html_escape($fields['instance_identifier']);
    }

    if (isset($fields['enabled'])) {
        $update_fields[] = 'enabled = ?';
        $params[] = (bool)$fields['enabled'];
    }

    if (isset($fields['notes'])) {
        $update_fields[] = 'notes = ?';
        $params[] = html_escape($fields['notes']);
    }

    if (isset($fields['auto_create_datasources'])) {
        $update_fields[] = 'auto_create_datasources = ?';
        $params[] = (bool)$fields['auto_create_datasources'];
    }

    if (empty($update_fields)) {
        cacti_log('gNMI: No valid fields to update', false, 'PLUGIN');
        return false;
    }

    $params[] = (int)$subscription_id;

    $sql = 'UPDATE plugin_gnmi_subscriptions SET ' . implode(', ', $update_fields) . ' WHERE id = ?';

    $result = db_execute_prepared($sql, $params);

    if ($result) {
        cacti_log("gNMI: Updated subscription $subscription_id", false, 'PLUGIN');
        return true;
    } else {
        cacti_log("gNMI: Failed to update subscription $subscription_id", false, 'PLUGIN');
        return false;
    }
}

/**
 * Delete subscription (CASCADE deletes metrics)
 *
 * @param int $subscription_id Subscription ID
 * @return bool Success status
 */
function gnmi_delete_subscription($subscription_id) {
    if (empty($subscription_id) || !is_numeric($subscription_id)) {
        cacti_log('gNMI: Invalid subscription_id for deletion', false, 'PLUGIN');
        return false;
    }

    // Verify subscription exists
    $subscription = gnmi_get_subscription($subscription_id);
    if (!$subscription) {
        cacti_log("gNMI: Subscription $subscription_id not found", false, 'PLUGIN');
        return false;
    }

    // Delete subscription (CASCADE will handle metrics)
    $result = db_execute_prepared(
        'DELETE FROM plugin_gnmi_subscriptions WHERE id = ?',
        array((int)$subscription_id)
    );

    if ($result) {
        cacti_log("gNMI: Deleted subscription $subscription_id", false, 'PLUGIN');
        return true;
    } else {
        cacti_log("gNMI: Failed to delete subscription $subscription_id", false, 'PLUGIN');
        return false;
    }
}

/**
 * Add metric to subscription
 *
 * @param int $subscription_id Subscription ID
 * @param string $metric_name Raw metric name from device
 * @param string $rrd_type RRD data source type (COUNTER, GAUGE, DERIVE, ABSOLUTE)
 * @param array $options Additional options (rrd_heartbeat, rrd_min, rrd_max, enabled)
 * @return int|false Metric ID on success, false on failure
 */
function gnmi_add_metric_to_subscription($subscription_id, $metric_name, $rrd_type = 'COUNTER', $options = []) {
    // Validate inputs
    if (empty($subscription_id) || !is_numeric($subscription_id)) {
        cacti_log('gNMI: Invalid subscription_id for metric creation', false, 'PLUGIN');
        return false;
    }

    if (empty($metric_name)) {
        cacti_log('gNMI: Metric name required', false, 'PLUGIN');
        return false;
    }

    $valid_rrd_types = ['COUNTER', 'GAUGE', 'DERIVE', 'ABSOLUTE'];
    if (!in_array($rrd_type, $valid_rrd_types)) {
        cacti_log("gNMI: Invalid RRD type: $rrd_type", false, 'PLUGIN');
        return false;
    }

    // Verify subscription exists
    $subscription = gnmi_get_subscription($subscription_id);
    if (!$subscription) {
        cacti_log("gNMI: Subscription $subscription_id not found", false, 'PLUGIN');
        return false;
    }

    // Sanitize metric name
    $sanitized_name = gnmi_sanitize_metric_name($metric_name);

    // Check for collision and resolve if needed
    $final_name = gnmi_detect_metric_collision($subscription_id, $sanitized_name);

    // Classify metric for graph grouping
    if (!function_exists('gnmi_classify_metric')) {
        global $config;
        include_once($config['base_path'] . '/plugins/gnmi/include/functions.php');
    }
    $classification = gnmi_classify_metric($metric_name);
    $metric_group    = $classification['group'];
    $metric_direction = $classification['direction'];
    $metric_graph_key = isset($classification['graph_key']) ? $classification['graph_key'] : null;

    // Sanitize inputs
    $subscription_id = (int)$subscription_id;
    $metric_name = html_escape($metric_name);
    $default_heartbeat = function_exists('gnmi_get_poller_interval') ? gnmi_get_poller_interval() * 2 : 600;
    $rrd_heartbeat = isset($options['rrd_heartbeat']) ? (int)$options['rrd_heartbeat'] : $default_heartbeat;
    $rrd_min = isset($options['rrd_min']) ? html_escape($options['rrd_min']) : '0';
    $rrd_max = isset($options['rrd_max']) ? html_escape($options['rrd_max']) : 'U';
    $enabled = isset($options['enabled']) ? (bool)$options['enabled'] : true;

    // Create metric
    $result = db_execute_prepared("
        INSERT INTO plugin_gnmi_metrics (
            subscription_id, metric_name, cacti_field_name, rrd_type,
            rrd_heartbeat, rrd_min, rrd_max, enabled,
            metric_group, metric_direction, metric_graph_key
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ", array($subscription_id, $metric_name, $final_name, $rrd_type, $rrd_heartbeat, $rrd_min, $rrd_max, $enabled, $metric_group, $metric_direction, $metric_graph_key));

    if ($result) {
        $metric_id = db_fetch_cell('SELECT LAST_INSERT_ID()');
    } else {
        $metric_id = false;
    }

    if ($metric_id) {
        cacti_log("gNMI: Created metric $metric_id for subscription $subscription_id", false, 'PLUGIN');

        // Auto-create data sources if subscription has auto_create_datasources enabled
        // The called function is idempotent - it only creates data sources for metrics
        // that don't already have one (local_data_id IS NULL OR datasource_created = 0)
        if ($subscription['auto_create_datasources']) {
            $enabled_metrics = gnmi_get_subscription_metrics($subscription_id, true);

            if (!empty($enabled_metrics)) {
                // Include the function if not already loaded
                if (!function_exists('gnmi_create_data_sources_for_subscription')) {
                    global $config;
                    include_once($config['base_path'] . '/plugins/gnmi/include/functions.php');
                }

                cacti_log("gNMI: Auto-creating per-metric data source for metric $metric_id (auto_create_datasources enabled)", false, 'PLUGIN');
                $data_local_id = gnmi_create_data_source_for_metric($metric_id);

                if ($data_local_id) {
                    cacti_log("gNMI: Auto-created data source $data_local_id for metric $metric_id", false, 'PLUGIN');
                } else {
                    cacti_log("gNMI: Failed to auto-create data source for metric $metric_id", false, 'PLUGIN');
                }
            }
        }

        return $metric_id;
    } else {
        cacti_log('gNMI: Failed to create metric', false, 'PLUGIN');
        return false;
    }
}

/**
 * Get metrics for a subscription
 *
 * @param int $subscription_id Subscription ID
 * @param bool $enabled_only Only return enabled metrics
 * @return array Array of metric data
 */
function gnmi_get_subscription_metrics($subscription_id, $enabled_only = false) {
    if (empty($subscription_id) || !is_numeric($subscription_id)) {
        return [];
    }

    $sql = 'SELECT * FROM plugin_gnmi_metrics WHERE subscription_id = ?';
    $params = [(int)$subscription_id];

    if ($enabled_only) {
        $sql .= ' AND enabled = 1';
    }

    $sql .= ' ORDER BY created_on ASC';

    $metrics = db_fetch_assoc_prepared($sql, $params);

    return $metrics ?: [];
}

/**
 * Update metric fields
 *
 * @param int $metric_id Metric ID
 * @param array $fields Fields to update
 * @return bool Success status
 */
function gnmi_update_metric($metric_id, $fields) {
    if (empty($metric_id) || !is_numeric($metric_id)) {
        cacti_log('gNMI: Invalid metric_id for update', false, 'PLUGIN');
        return false;
    }

    if (empty($fields) || !is_array($fields)) {
        cacti_log('gNMI: No fields provided for metric update', false, 'PLUGIN');
        return false;
    }

    // Validate metric exists
    $metric = db_fetch_row_prepared(
        'SELECT * FROM plugin_gnmi_metrics WHERE id = ?',
        array((int)$metric_id)
    );

    if (!$metric) {
        cacti_log("gNMI: Metric $metric_id not found", false, 'PLUGIN');
        return false;
    }

    // Build update query
    $update_fields = [];
    $params = [];

    if (isset($fields['metric_name'])) {
        $update_fields[] = 'metric_name = ?';
        $params[] = html_escape($fields['metric_name']);
    }

    if (isset($fields['rrd_type'])) {
        $valid_rrd_types = ['COUNTER', 'GAUGE', 'DERIVE', 'ABSOLUTE'];
        if (!in_array($fields['rrd_type'], $valid_rrd_types)) {
            cacti_log("gNMI: Invalid RRD type: {$fields['rrd_type']}", false, 'PLUGIN');
            return false;
        }
        $update_fields[] = 'rrd_type = ?';
        $params[] = $fields['rrd_type'];
    }

    if (isset($fields['rrd_heartbeat'])) {
        $update_fields[] = 'rrd_heartbeat = ?';
        $params[] = (int)$fields['rrd_heartbeat'];
    }

    if (isset($fields['rrd_min'])) {
        $update_fields[] = 'rrd_min = ?';
        $params[] = html_escape($fields['rrd_min']);
    }

    if (isset($fields['rrd_max'])) {
        $update_fields[] = 'rrd_max = ?';
        $params[] = html_escape($fields['rrd_max']);
    }

    if (isset($fields['enabled'])) {
        $update_fields[] = 'enabled = ?';
        $params[] = (bool)$fields['enabled'];
    }

    if (isset($fields['local_data_id'])) {
        $update_fields[] = 'local_data_id = ?';
        $params[] = (int)$fields['local_data_id'];
    }

    if (isset($fields['datasource_created'])) {
        $update_fields[] = 'datasource_created = ?';
        $params[] = (bool)$fields['datasource_created'];
    }

    if (isset($fields['graph_local_id'])) {
        $update_fields[] = 'graph_local_id = ?';
        $params[] = (int)$fields['graph_local_id'];
    }

    if (isset($fields['graph_created'])) {
        $update_fields[] = 'graph_created = ?';
        $params[] = $fields['graph_created'] ? 1 : 0;
    }

    if (isset($fields['metric_group'])) {
        $valid_groups = ['traffic', 'packets', 'errors', 'discards', 'generic'];
        if (!in_array($fields['metric_group'], $valid_groups)) {
            cacti_log("gNMI: Invalid metric group: {$fields['metric_group']}", false, 'PLUGIN');
            return false;
        }
        $update_fields[] = 'metric_group = ?';
        $params[] = $fields['metric_group'];
    }

    if (isset($fields['metric_direction'])) {
        $valid_directions = ['inbound', 'outbound', 'none'];
        if (!in_array($fields['metric_direction'], $valid_directions)) {
            cacti_log("gNMI: Invalid metric direction: {$fields['metric_direction']}", false, 'PLUGIN');
            return false;
        }
        $update_fields[] = 'metric_direction = ?';
        $params[] = $fields['metric_direction'];
    }

    if (array_key_exists('metric_graph_key', $fields)) {
        $update_fields[] = 'metric_graph_key = ?';
        $params[] = $fields['metric_graph_key'] !== null ? html_escape($fields['metric_graph_key']) : null;
    }

    if (empty($update_fields)) {
        cacti_log('gNMI: No valid fields to update', false, 'PLUGIN');
        return false;
    }

    $params[] = (int)$metric_id;

    $sql = 'UPDATE plugin_gnmi_metrics SET ' . implode(', ', $update_fields) . ' WHERE id = ?';

    $result = db_execute_prepared($sql, $params);

    if ($result) {
        cacti_log("gNMI: Updated metric $metric_id", false, 'PLUGIN');
        return true;
    } else {
        cacti_log("gNMI: Failed to update metric $metric_id", false, 'PLUGIN');
        return false;
    }
}

/**
 * Delete metric
 *
 * @param int $metric_id Metric ID
 * @return bool Success status
 */
function gnmi_delete_metric($metric_id) {
    if (empty($metric_id) || !is_numeric($metric_id)) {
        cacti_log('gNMI: Invalid metric_id for deletion', false, 'PLUGIN');
        return false;
    }

    // Verify metric exists
    $metric = db_fetch_row_prepared(
        'SELECT * FROM plugin_gnmi_metrics WHERE id = ?',
        array((int)$metric_id)
    );

    if (!$metric) {
        cacti_log("gNMI: Metric $metric_id not found", false, 'PLUGIN');
        return false;
    }

    // Delete metric
    $result = db_execute_prepared(
        'DELETE FROM plugin_gnmi_metrics WHERE id = ?',
        array((int)$metric_id)
    );

    if ($result) {
        cacti_log("gNMI: Deleted metric $metric_id", false, 'PLUGIN');
        return true;
    } else {
        cacti_log("gNMI: Failed to delete metric $metric_id", false, 'PLUGIN');
        return false;
    }
}

/**
 * Validate subscription path
 *
 * @param string $path gNMI subscription path
 * @return bool True if valid, false otherwise
 */
function gnmi_validate_subscription_path($path) {
    if (empty($path) || !is_string($path)) {
        return false;
    }

    $path = trim($path);

    if (empty($path)) {
        return false;
    }

    // Basic validation - must contain at least one slash
    if (strpos($path, '/') === false) {
        return false;
    }

    return true;
}

/**
 * Sanitize metric name for Cacti/RRD compatibility
 *
 * @param string $metric_name Raw metric name from device
 * @return string Sanitized metric name (max 19 chars)
 */
function gnmi_sanitize_metric_name($metric_name) {
    if (empty($metric_name) || !is_string($metric_name)) {
        return 'unknown_metric';
    }

    // 1. Lowercase
    $name = strtolower($metric_name);

    // 2. Replace hyphens with underscores
    $name = str_replace('-', '_', $name);

    // 3. Remove invalid characters (keep alphanumeric + underscore, remove special chars)
    $name = preg_replace('/[^a-z0-9_]/', '_', $name);

    // 4. Handle leading numbers (invalid for RRD)
    if (preg_match('/^(\d+)(.*)$/', $name, $matches)) {
        $name = $matches[2] . '_' . $matches[1]; // Move to end
        // Remove leading underscore if it exists
        $name = ltrim($name, '_');
    }

    // 5. Ensure starts with letter or underscore
    if (!preg_match('/^[a-z_]/', $name)) {
        $name = 'metric_' . $name;
    }

    // 6. Truncate to 19 characters (RRD DS name limit)
    if (strlen($name) > 19) {
        $name = substr($name, 0, 19);
    }

    return $name;
}

/**
 * Detect and resolve metric name collisions
 *
 * @param int $subscription_id Subscription ID
 * @param string $sanitized_name Sanitized metric name
 * @return string Unique metric name
 */
function gnmi_detect_metric_collision($subscription_id, $sanitized_name) {
    // Check if name already exists in this subscription
    $exists = db_fetch_cell_prepared(
        'SELECT id FROM plugin_gnmi_metrics
         WHERE subscription_id = ? AND cacti_field_name = ?',
        array($subscription_id, $sanitized_name)
    );

    if (!$exists) {
        return $sanitized_name;
    }

    // Find next available suffix: name2, name3, etc.
    for ($i = 2; $i <= 99; $i++) {
        // Truncate to fit suffix
        $base = substr($sanitized_name, 0, 19 - strlen((string)$i));
        $new_name = $base . $i;

        $collision = db_fetch_cell_prepared(
            'SELECT id FROM plugin_gnmi_metrics
             WHERE subscription_id = ? AND cacti_field_name = ?',
            array($subscription_id, $new_name)
        );

        if (!$collision) {
            return $new_name;
        }
    }

    // Fallback: use hash suffix
    return substr($sanitized_name, 0, 15) . substr(md5($sanitized_name), 0, 4);
}

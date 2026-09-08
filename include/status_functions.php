<?php
/**
 * gNMI Status Dashboard Functions (Phase 3.2)
 *
 * Business logic layer for status dashboard.
 * Handles event logging, data retrieval, and daemon health checks.
 */

if (!defined('CACTI_VERSION')) {
	die('Access denied');
}

/**
 * Log an event to the audit trail.
 *
 * Stores event in plugin_gnmi_events table with structured data.
 * Automatically truncates old events if table exceeds 10,000 rows (configurable).
 *
 * @param int $device_id Device ID from plugin_gnmi_devices
 * @param string $event_type Event category: config_change, daemon_start, daemon_stop, daemon_restart, error, orphan_cleanup
 * @param array $event_data Structured event data (will be JSON encoded)
 * @return bool Success status
 */
function gnmi_log_event($device_id, $event_type, $event_data) {
	// Validate device exists
	$device_exists = db_fetch_cell_prepared(
		'SELECT COUNT(*) FROM plugin_gnmi_devices WHERE id = ?',
		array($device_id)
	);

	if (!$device_exists) {
		cacti_log("gNMI: Cannot log event - device $device_id does not exist", false, 'GNMI', POLLER_VERBOSITY_LOW);
		return false;
	}

	// Validate event type
	$valid_types = array('config_change', 'daemon_start', 'daemon_stop', 'daemon_restart', 'error', 'orphan_cleanup');
	if (!in_array($event_type, $valid_types)) {
		cacti_log("gNMI: Invalid event type: $event_type", false, 'GNMI', POLLER_VERBOSITY_LOW);
		return false;
	}

	// Encode event data as JSON
	$event_json = json_encode($event_data);
	if ($event_json === false) {
		cacti_log("gNMI: Failed to encode event data for device $device_id", false, 'GNMI', POLLER_VERBOSITY_LOW);
		return false;
	}

	// Insert event
	$success = db_execute_prepared(
		'INSERT INTO plugin_gnmi_events (device_id, event_type, event_data, created_at) VALUES (?, ?, ?, NOW())',
		array($device_id, $event_type, $event_json)
	);

	if ($success) {
		cacti_log("gNMI: Logged event '$event_type' for device $device_id", false, 'GNMI', POLLER_VERBOSITY_DEBUG);

		// Cleanup old events (keep last 10,000 globally)
		gnmi_cleanup_old_events(10000);
	}

	return $success;
}

/**
 * Cleanup old events to prevent unbounded table growth.
 *
 * Keeps the N most recent events across all devices.
 * Called automatically by gnmi_log_event().
 *
 * @param int $keep_count Number of events to retain (default: 10000)
 * @return int Number of events deleted
 */
function gnmi_cleanup_old_events($keep_count = 10000) {
	// Count total events
	$total_events = db_fetch_cell('SELECT COUNT(*) FROM plugin_gnmi_events');

	if ($total_events <= $keep_count) {
		return 0; // Nothing to clean up
	}

	// Find the cutoff ID (keep events with ID >= cutoff)
	$cutoff_id = db_fetch_cell("
		SELECT id FROM plugin_gnmi_events
		ORDER BY id DESC
		LIMIT 1 OFFSET $keep_count
	");

	if (!$cutoff_id) {
		return 0;
	}

	// Delete old events
	db_execute_prepared(
		'DELETE FROM plugin_gnmi_events WHERE id < ?',
		array($cutoff_id)
	);

	$deleted = db_affected_rows();

	if ($deleted > 0) {
		cacti_log("gNMI: Cleaned up $deleted old events (kept $keep_count)", false, 'GNMI', POLLER_VERBOSITY_LOW);
	}

	return $deleted;
}

/**
 * Get recent events for a device or all devices.
 *
 * Returns events in reverse chronological order (newest first).
 *
 * @param int|null $device_id Device ID (null for all devices)
 * @param string|null $event_type Filter by event type (null for all types)
 * @param int $limit Maximum number of events to return (default: 50)
 * @return array Array of event records with parsed JSON data
 */
function gnmi_get_recent_events($device_id = null, $event_type = null, $limit = 50) {
	$limit = intval($limit);
	$where_clauses = array();
	$params = array();

	if ($device_id !== null) {
		$where_clauses[] = 'e.device_id = ?';
		$params[] = intval($device_id);
	}

	if ($event_type !== null) {
		$where_clauses[] = 'e.event_type = ?';
		$params[] = $event_type;
	}

	$where_sql = count($where_clauses) > 0 ? 'WHERE ' . implode(' AND ', $where_clauses) : '';

	$events = db_fetch_assoc_prepared("
		SELECT
			e.id,
			e.device_id,
			e.event_type,
			e.event_data,
			e.created_at,
			d.hostname as device_hostname,
			h.description as device_description
		FROM plugin_gnmi_events e
		INNER JOIN plugin_gnmi_devices d ON e.device_id = d.id
		LEFT JOIN host h ON d.host_id = h.id
		$where_sql
		ORDER BY e.created_at DESC
		LIMIT $limit
	", $params);

	// Parse JSON event_data for each event
	foreach ($events as &$event) {
		$event['event_data'] = json_decode($event['event_data'], true);
	}

	return $events;
}

/**
 * Get dashboard summary for all enabled gNMI devices.
 *
 * Returns array of device status summaries including daemon health,
 * last poll status, and data freshness.
 *
 * @return array Array of device summaries
 */
function gnmi_get_dashboard_summary() {
	// Get all enabled devices with Cacti host information
	$devices = db_fetch_assoc('
		SELECT
			d.id as device_id,
			d.host_id,
			d.hostname,
			d.tls_cipher_policy,
			d.last_poll_time,
			d.last_poll_status,
			d.last_error_message,
			h.description as device_description
		FROM plugin_gnmi_devices d
		LEFT JOIN host h ON d.host_id = h.id
		WHERE d.enabled = 1
		ORDER BY h.description, d.hostname
	');

	$summary = array();

	foreach ($devices as $device) {
		$device_id = $device['device_id'];

		// Get daemon status
		$storage_dir = gnmi_get_storage_dir();
		$pid_file = $storage_dir . '/device_' . $device_id . '.pid';
		$pid_validation = gnmi_validate_pid_file($device_id, $pid_file);

		$daemon_status = 'stopped';
		$daemon_pid = null;
		$uptime_seconds = 0;

		if ($pid_validation['status'] === 'valid') {
			$daemon_status = 'running';
			$daemon_pid = $pid_validation['pid'];
			$uptime_seconds = gnmi_calculate_uptime($device_id);
		}

		// Get data freshness
		$data_age_seconds = gnmi_get_data_freshness($device_id);

		// Calculate overall health status
		$health = gnmi_calculate_health_status($daemon_status, $data_age_seconds, $device['last_poll_status']);

		$summary[] = array(
			'device_id' => $device_id,
			'host_id' => $device['host_id'],
			'hostname' => $device['hostname'],
			'tls_cipher_policy' => (($device['tls_cipher_policy'] ?? 'default') === 'legacy_compatibility')
				? 'legacy_compatibility'
				: 'default',
			'device_description' => $device['device_description'] ?: 'N/A',
			'daemon_status' => $daemon_status,
			'daemon_pid' => $daemon_pid,
			'uptime_seconds' => $uptime_seconds,
			'last_poll_time' => $device['last_poll_time'],
			'last_poll_status' => $device['last_poll_status'],
			'last_error_message' => $device['last_error_message'],
			'data_age_seconds' => $data_age_seconds,
			'health' => $health
		);
	}

	return $summary;
}

/**
 * Get detailed daemon statistics for a specific device.
 *
 * @param int $device_id Device ID
 * @return array|null Detailed stats array or null if device not found
 */
function gnmi_get_daemon_stats($device_id) {
	$device_id = intval($device_id);

	// Verify device exists
	$device = db_fetch_row_prepared(
		'SELECT * FROM plugin_gnmi_devices WHERE id = ?',
		array($device_id)
	);

	if (!$device) {
		return null;
	}

	$storage_dir = gnmi_get_storage_dir();
	$logs_dir = gnmi_get_logs_dir();
	$pid_file = $storage_dir . '/device_' . $device_id . '.pid';
	$json_file = $storage_dir . '/device_' . $device_id . '.json';
	$log_file = $logs_dir . '/device_' . $device_id . '.log';

	// Get PID validation
	$pid_validation = gnmi_validate_pid_file($device_id, $pid_file);

	$stats = array(
		'device_id' => $device_id,
		'pid' => $pid_validation['pid'],
		'daemon_status' => $pid_validation['status'],
		'uptime_seconds' => 0,
		'memory_mb' => 0,
		'cpu_percent' => 0,
		'data_age_seconds' => null,
		'json_file_size_kb' => 0,
		'log_file_size_kb' => 0,
		'log_line_count' => 0,
		'recent_errors' => array()
	);

	// Get uptime if daemon running
	if ($pid_validation['status'] === 'valid') {
		$stats['uptime_seconds'] = gnmi_calculate_uptime($device_id);

		// Get process metrics (Linux only - /proc filesystem)
		$pid = $pid_validation['pid'];
		if (file_exists("/proc/$pid/status")) {
			// Parse memory from /proc/PID/status
			$status_content = @file_get_contents("/proc/$pid/status");
			if ($status_content && preg_match('/VmRSS:\s+(\d+)\s+kB/', $status_content, $matches)) {
				$stats['memory_mb'] = round(intval($matches[1]) / 1024, 2);
			}
		}
	}

	// Get data freshness
	$stats['data_age_seconds'] = gnmi_get_data_freshness($device_id);

	// Get file sizes
	if (file_exists($json_file)) {
		$stats['json_file_size_kb'] = round(filesize($json_file) / 1024, 2);
	}

	if (file_exists($log_file)) {
		$stats['log_file_size_kb'] = round(filesize($log_file) / 1024, 2);
		$stats['log_line_count'] = count(file($log_file));

		// Extract recent errors (last 10 lines containing 'ERROR')
		$log_lines = file($log_file);
		$error_lines = array();
		foreach (array_reverse($log_lines) as $line) {
			if (stripos($line, 'ERROR') !== false) {
				$error_lines[] = trim($line);
				if (count($error_lines) >= 10) break;
			}
		}
		$stats['recent_errors'] = $error_lines;
	}

	return $stats;
}

/**
 * Calculate daemon uptime in seconds.
 *
 * @param int $device_id Device ID
 * @return int Uptime in seconds, 0 if not running
 */
function gnmi_calculate_uptime($device_id) {
	$storage_dir = gnmi_get_storage_dir();
	$pid_file = $storage_dir . '/device_' . $device_id . '.pid';

	if (!file_exists($pid_file)) {
		return 0;
	}

	// PID file mtime = daemon start time
	$start_time = filemtime($pid_file);
	if ($start_time === false) {
		return 0;
	}

	$uptime = time() - $start_time;
	return max(0, $uptime); // Ensure non-negative
}

/**
 * Get data freshness (age) in seconds.
 *
 * @param int $device_id Device ID
 * @return int|null Seconds since last update, or null if no data file
 */
function gnmi_get_data_freshness($device_id) {
	$storage_dir = gnmi_get_storage_dir();
	$json_file = $storage_dir . '/device_' . $device_id . '.json';

	if (!file_exists($json_file)) {
		return null;
	}

	$last_modified = filemtime($json_file);
	if ($last_modified === false) {
		return null;
	}

	$age = time() - $last_modified;
	return max(0, $age); // Ensure non-negative
}

/**
 * Calculate overall health status based on multiple factors.
 *
 * @param string $daemon_status Daemon status from gnmi_validate_pid_file()
 * @param int|null $data_age_seconds Data freshness in seconds
 * @param string $last_poll_status Last poll status: success|error|never
 * @return string Health status: healthy|warning|critical|unknown
 */
function gnmi_calculate_health_status($daemon_status, $data_age_seconds, $last_poll_status) {
	// Critical: Daemon not running
	// Accepts 'valid' (raw from gnmi_validate_pid_file) or 'running' (mapped by gnmi_get_dashboard_summary)
	if ($daemon_status !== 'valid' && $daemon_status !== 'running') {
		return 'critical';
	}

	// Critical: Data very stale (> 2x poller interval)
	if ($data_age_seconds !== null && $data_age_seconds > gnmi_get_poller_interval() * 2) {
		return 'critical';
	}

	// Critical: Last poll was error
	if ($last_poll_status === 'error') {
		return 'critical';
	}

	// Warning: Data moderately stale (1x to 2x poller interval)
	if ($data_age_seconds !== null && $data_age_seconds > gnmi_get_poller_interval()) {
		return 'warning';
	}

	// Unknown: Never polled yet
	if ($last_poll_status === 'never') {
		return 'unknown';
	}

	// Healthy: All checks passed
	return 'healthy';
}

/**
 * Get orphan summary for dashboard display.
 *
 * @return array Orphan summary with counts and details
 */
function gnmi_get_orphan_summary() {
	// Use existing Phase 2.8 functions
	$orphan_pids = gnmi_find_orphan_pids();
	$orphan_processes = gnmi_find_orphan_processes();

	return array(
		'orphan_pids' => count($orphan_pids),
		'orphan_processes' => count($orphan_processes),
		'total_orphans' => count($orphan_pids) + count($orphan_processes),
		'pid_details' => $orphan_pids,
		'process_details' => $orphan_processes
	);
}

<?php
/**
 * gNMI Status Dashboard Display Functions (Phase 3.2)
 *
 * Presentation layer for status dashboard.
 * Renders HTML components from data provided by status_functions.php
 */

if (!defined('CACTI_VERSION')) {
	die('Access denied');
}

/**
 * Render the main device summary table.
 *
 * @param array $devices Array of device summaries from gnmi_get_dashboard_summary()
 * @return string HTML table markup
 */
function gnmi_render_summary_table($devices) {
	if (empty($devices)) {
		return '<p>No gNMI devices configured or enabled.</p>';
	}

	$html = '<table class="cactiTable" style="width:100%">';

	// Table header
	$html .= '<thead>';
	$html .= '<tr class="tableHeader">';
	$html .= '<th>Device</th>';
	$html .= '<th>Hostname</th>';
	$html .= '<th>Health</th>';
	$html .= '<th>Daemon Status</th>';
	$html .= '<th>Uptime</th>';
	$html .= '<th>Data Age</th>';
	$html .= '<th>Last Poll</th>';
	$html .= '<th>Actions</th>';
	$html .= '</tr>';
	$html .= '</thead>';

	// Table body
	$html .= '<tbody>';
	foreach ($devices as $device) {
		$html .= '<tr>';

		// Device description
		$html .= '<td>' . html_escape($device['device_description']) . '</td>';

		// Hostname
		$html .= '<td>' . html_escape($device['hostname']);
		if (($device['tls_cipher_policy'] ?? 'default') === 'legacy_compatibility') {
			$html .= ' <span title="Legacy TLS compatibility is enabled" '
				. 'style="color:#8a4b08;font-weight:bold;">Legacy TLS</span>';
		}
		$html .= '</td>';

		// Health badge
		$html .= '<td>' . gnmi_render_health_badge($device['health']) . '</td>';

		// Daemon status
		$status_text = $device['daemon_status'];
		if ($device['daemon_pid']) {
			$status_text .= ' (PID ' . $device['daemon_pid'] . ')';
		}
		$html .= '<td>' . html_escape($status_text) . '</td>';

		// Uptime (formatted)
		$html .= '<td>' . gnmi_format_uptime($device['uptime_seconds']) . '</td>';

		// Data age (formatted)
		$data_age_display = 'No data';
		if ($device['data_age_seconds'] !== null) {
			$data_age_display = $device['data_age_seconds'] . 's ago';
			// Red when age exceeds one full poller interval (warning-level threshold)
			if ($device['data_age_seconds'] > gnmi_get_poller_interval()) {
				$data_age_display = '<span style="color:red;">' . $data_age_display . '</span>';
			}
		}
		$html .= '<td>' . $data_age_display . '</td>';

		// Last poll time
		$poll_time = $device['last_poll_time'] ?: 'Never';
		$html .= '<td>' . html_escape($poll_time) . '</td>';

		// Actions (expand details, restart daemon)
		$html .= '<td>';
		$html .= '<a href="#" onclick="toggleDeviceDetails(' . $device['device_id'] . '); return false;">Details</a> | ';
		$html .= '<form method="post" action="" style="display:inline">';
		$html .= '<input type="hidden" name="action" value="restart">';
		$html .= '<input type="hidden" name="device_id" value="' . (int)$device['device_id'] . '">';
		$html .= '<button type="submit" class="linkOverDark" style="border:0;background:none;padding:0;cursor:pointer">Restart</button>';
		$html .= '</form>';
		$html .= '</td>';

		$html .= '</tr>';

		// Hidden detail row (expanded via JavaScript)
		$html .= '<tr id="device_details_' . $device['device_id'] . '" style="display:none;">';
		$html .= '<td colspan="8">';
		$html .= gnmi_render_device_detail($device);
		$html .= '</td>';
		$html .= '</tr>';
	}
	$html .= '</tbody>';

	$html .= '</table>';

	return $html;
}

/**
 * Render health status badge with color coding.
 *
 * @param string $health Health status: healthy|warning|critical|unknown
 * @return string HTML span with styled badge
 */
function gnmi_render_health_badge($health) {
	$colors = array(
		'healthy' => '#28a745',   // Green
		'warning' => '#ffc107',   // Yellow
		'critical' => '#dc3545',  // Red
		'unknown' => '#6c757d'    // Gray
	);

	$icons = array(
		'healthy' => '✓',
		'warning' => '⚠',
		'critical' => '✗',
		'unknown' => '?'
	);

	$color = $colors[$health] ?? $colors['unknown'];
	$icon = $icons[$health] ?? $icons['unknown'];
	$label = ucfirst($health);

	return '<span style="background-color:' . $color . '; color:white; padding:4px 8px; border-radius:4px; font-weight:bold;">'
	       . $icon . ' ' . $label .
	       '</span>';
}

/**
 * Render detailed device information panel.
 *
 * @param array $device Device summary data
 * @return string HTML markup for detail panel
 */
function gnmi_render_device_detail($device) {
	$device_id = $device['device_id'];

	// Get detailed stats
	$stats = gnmi_get_daemon_stats($device_id);
	if (!is_array($stats)) {
		$stats = array(
			'pid' => null,
			'uptime_seconds' => 0,
			'memory_mb' => 0,
			'data_age_seconds' => null,
			'json_file_size_kb' => 0,
			'log_file_size_kb' => 0,
			'log_line_count' => 0,
		);
	}

	$html = '<div class="deviceDetailPanel" style="padding:10px; background-color:#f8f9fa; border:1px solid #dee2e6; margin:5px 0;">';

	// Section 1: Daemon Metrics
	$html .= '<h4>Daemon Metrics</h4>';
	$html .= '<table class="cactiTable" style="width:50%;">';
	$html .= '<tr><td><strong>PID:</strong></td><td>' . ($stats['pid'] ?: 'N/A') . '</td></tr>';
	$html .= '<tr><td><strong>Uptime:</strong></td><td>' . gnmi_format_uptime($stats['uptime_seconds']) . '</td></tr>';
	$html .= '<tr><td><strong>Memory Usage:</strong></td><td>' . $stats['memory_mb'] . ' MB</td></tr>';
	$html .= '<tr><td><strong>Data Age:</strong></td><td>' . ($stats['data_age_seconds'] !== null ? $stats['data_age_seconds'] . 's' : 'No data') . '</td></tr>';
	$html .= '<tr><td><strong>JSON File Size:</strong></td><td>' . $stats['json_file_size_kb'] . ' KB</td></tr>';
	$html .= '<tr><td><strong>Log File Size:</strong></td><td>' . $stats['log_file_size_kb'] . ' KB (' . $stats['log_line_count'] . ' lines)</td></tr>';
	$tls_policy_label = (($device['tls_cipher_policy'] ?? 'default') === 'legacy_compatibility')
		? 'Legacy TLS compatibility'
		: 'gRPC defaults';
	$html .= '<tr><td><strong>TLS Cipher Policy:</strong></td><td>' . html_escape($tls_policy_label) . '</td></tr>';
	$html .= '</table>';

	// Section 2: Recent Events
	$html .= '<h4>Recent Events</h4>';
	$events = gnmi_get_recent_events($device_id, null, 10);
	if (empty($events)) {
		$html .= '<p>No events recorded.</p>';
	} else {
		$html .= gnmi_render_events_table($events);
	}

	// Section 3: Recent Errors
	if (!empty($stats['recent_errors'])) {
		$html .= '<h4>Recent Errors (from log file)</h4>';
		$html .= '<ul>';
		foreach ($stats['recent_errors'] as $error) {
			$html .= '<li style="color:red;">' . html_escape($error) . '</li>';
		}
		$html .= '</ul>';
	}

	// Section 4: Last Error Message (from database)
	if (!empty($device['last_error_message'])) {
		$html .= '<h4>Last Poll Error</h4>';
		$html .= '<pre style="background-color:#fff; padding:10px; border:1px solid #ddd;">' . html_escape($device['last_error_message']) . '</pre>';
	}

	$html .= '</div>';

	return $html;
}

/**
 * Render recent events table.
 *
 * @param array $events Array of events from gnmi_get_recent_events()
 * @return string HTML table markup
 */
function gnmi_render_events_table($events) {
	$html = '<table class="cactiTable" style="width:100%;">';
	$html .= '<thead>';
	$html .= '<tr class="tableHeader">';
	$html .= '<th>Timestamp</th>';
	$html .= '<th>Event Type</th>';
	$html .= '<th>Details</th>';
	$html .= '</tr>';
	$html .= '</thead>';
	$html .= '<tbody>';

	foreach ($events as $event) {
		$html .= '<tr>';
		$html .= '<td>' . html_escape($event['created_at']) . '</td>';
		$html .= '<td>' . html_escape($event['event_type']) . '</td>';

		// Format event data based on type
		$details = gnmi_format_event_data($event['event_type'], $event['event_data']);
		$html .= '<td>' . $details . '</td>';

		$html .= '</tr>';
	}

	$html .= '</tbody>';
	$html .= '</table>';

	return $html;
}

/**
 * Format event data for display based on event type.
 *
 * @param string $event_type Event type
 * @param array $event_data Parsed JSON event data
 * @return string Formatted HTML string
 */
function gnmi_format_event_data($event_type, $event_data) {
	if (empty($event_data)) {
		return 'N/A';
	}

	switch ($event_type) {
		case 'config_change':
			if (isset($event_data['changed_fields'])) {
				return 'Changed: ' . html_escape(implode(', ', $event_data['changed_fields']));
			}
			break;

		case 'daemon_restart':
			return 'Daemon restarted' . (isset($event_data['reason']) ? ' (' . html_escape($event_data['reason']) . ')' : '');

		case 'error':
			return '<span style="color:red;">' . html_escape($event_data['message'] ?? 'Unknown error') . '</span>';

		case 'orphan_cleanup':
			$count = $event_data['orphans_cleaned'] ?? 0;
			return "Cleaned up $count orphan(s)";
	}

	// Default: JSON encode
	return '<pre style="margin:0;">' . html_escape(json_encode($event_data, JSON_PRETTY_PRINT)) . '</pre>';
}

/**
 * Render orphan summary panel widget.
 *
 * @param array $orphan_summary Orphan data from gnmi_get_orphan_summary()
 * @return string HTML markup for orphan panel
 */
function gnmi_render_orphan_panel($orphan_summary) {
	$total = $orphan_summary['total_orphans'];

	$html = '<div class="orphanPanel" style="padding:15px; border:2px solid #6c757d; border-radius:5px; margin:10px 0; background-color:#f8f9fa;">';
	$html .= '<h3 style="margin-top:0;">Orphaned Daemons</h3>';

	if ($total == 0) {
		$html .= '<p style="color:green; font-weight:bold;">✓ No orphans detected - system clean!</p>';
	} else {
		$html .= '<p style="color:red; font-weight:bold;">⚠ ' . $total . ' orphan(s) detected</p>';
		$html .= '<ul>';
		$html .= '<li>Orphaned PID files: ' . $orphan_summary['orphan_pids'] . '</li>';
		$html .= '<li>Orphaned processes: ' . $orphan_summary['orphan_processes'] . '</li>';
		$html .= '</ul>';
		$html .= '<form method="post" action="">';
		$html .= '<input type="hidden" name="action" value="cleanup_orphans">';
		$html .= '<button type="submit" class="btn btn-warning">Clean Up Orphans Now</button>';
		$html .= '</form>';
		$html .= '<p style="font-size:0.9em; color:#6c757d;">Note: Orphans are automatically cleaned up every poller cycle.</p>';
	}

	$html .= '</div>';

	return $html;
}

/**
 * Format uptime seconds into human-readable string.
 *
 * @param int $seconds Uptime in seconds
 * @return string Formatted string (e.g., "2d 5h 30m" or "45m 30s")
 */
function gnmi_format_uptime($seconds) {
	if ($seconds == 0) {
		return 'Not running';
	}

	$days = floor($seconds / 86400);
	$hours = floor(($seconds % 86400) / 3600);
	$minutes = floor(($seconds % 3600) / 60);
	$secs = $seconds % 60;

	$parts = array();

	if ($days > 0) $parts[] = $days . 'd';
	if ($hours > 0) $parts[] = $hours . 'h';
	if ($minutes > 0) $parts[] = $minutes . 'm';
	if ($secs > 0 && $days == 0) $parts[] = $secs . 's'; // Only show seconds if < 1 day

	return implode(' ', $parts);
}

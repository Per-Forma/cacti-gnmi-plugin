<?php
/**
 * gNMI Status Dashboard (Phase 3.2)
 *
 * Main status page showing daemon health, metrics, and operational history.
 * Accessible via: /cacti/plugins/gnmi/status.php
 */

// Include Cacti environment and authentication
require_once(__DIR__ . '/../../include/auth.php');
include_once(__DIR__ . '/include/functions.php');
include_once(__DIR__ . '/include/status_functions.php');
include_once(__DIR__ . '/include/status_display.php');
include_once(__DIR__ . '/include/dashboard_actions.php');

// Set page title
$title = __('gNMI Status Dashboard', 'gnmi');

// Handle state-changing actions with post/redirect/get.
if (isset_request_var('action')) {
	$action = isset($_POST['action']) ? (string)$_POST['action'] : '';
	$device_id = isset($_POST['device_id']) ? (int)$_POST['device_id'] : 0;
	$result = gnmi_process_dashboard_action($action, $device_id);
	raise_message('gnmi_dashboard_action', $result['message'], $result['level']);
	header('Location: status.php');
	exit;
}

// Get dashboard data
$devices = gnmi_get_dashboard_summary();
$orphan_summary = gnmi_get_orphan_summary();

// Start page output
top_header();

// Display page title and refresh button
html_start_box($title, '100%', '', '3', 'center', '');
?>

<tr>
	<td>
		<p>This dashboard shows real-time status of all gNMI-enabled devices and their daemons.</p>
		<p>
			<a href="status.php" class="btn btn-primary">Refresh Now</a>
			<label style="margin-left:20px;">
				<input type="checkbox" id="auto_refresh" checked> Auto-refresh every 10 seconds
			</label>
		</p>
	</td>
</tr>

<?php
html_end_box();

// Display orphan summary panel
html_start_box(__('System Health', 'gnmi'), '100%', '', '3', 'center', '');
?>

<tr>
	<td>
		<?php echo gnmi_render_orphan_panel($orphan_summary); ?>
	</td>
</tr>

<?php
html_end_box();

// Display device summary table
html_start_box(__('Device Status', 'gnmi'), '100%', '', '3', 'center', '');
?>

<tr>
	<td>
		<?php
		if (empty($devices)) {
			echo '<p>No gNMI devices configured. <a href="' . html_escape($config['url_path']) . 'host.php">Add devices</a> and enable gNMI telemetry.</p>';
		} else {
			echo gnmi_render_summary_table($devices);
		}
		?>
	</td>
</tr>

<?php
html_end_box();

// JavaScript for interactivity
?>

<script type="text/javascript">
// Toggle device detail rows
function toggleDeviceDetails(deviceId) {
	var detailRow = document.getElementById('device_details_' + deviceId);
	if (detailRow) {
		if (detailRow.style.display === 'none') {
			detailRow.style.display = 'table-row';
		} else {
			detailRow.style.display = 'none';
		}
	}
}

// Auto-refresh functionality
var autoRefreshInterval = null;

function startAutoRefresh() {
	if (autoRefreshInterval === null) {
		autoRefreshInterval = setInterval(function() {
			location.reload();
		}, 10000); // 10 seconds
	}
}

function stopAutoRefresh() {
	if (autoRefreshInterval !== null) {
		clearInterval(autoRefreshInterval);
		autoRefreshInterval = null;
	}
}

// Listen to checkbox changes
document.getElementById('auto_refresh').addEventListener('change', function() {
	if (this.checked) {
		startAutoRefresh();
	} else {
		stopAutoRefresh();
	}
});

// Start auto-refresh if checkbox is checked
if (document.getElementById('auto_refresh').checked) {
	startAutoRefresh();
}
</script>

<?php
bottom_footer();

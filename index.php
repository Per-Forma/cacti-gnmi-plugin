<?php
/**
 * gNMI Status Dashboard (Phase 3.2)
 *
 * Main status page showing daemon health, metrics, and operational history.
 * This is the main entry point for the gNMI plugin.
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
	header('Location: index.php');
	exit;
}

// Get dashboard data
$devices = gnmi_get_dashboard_summary();
$orphan_summary = gnmi_get_orphan_summary();

// Start page output
top_header();

// Show dependency warning banner if any dependencies are missing
if (function_exists('gnmi_requirements_ok') && !gnmi_requirements_ok()) {
    global $config;

    // Run unified dependency check (without auto-remediation at runtime)
    $dep_state = gnmi_check_all_dependencies(false);

    // Try to auto-continue setup if system deps became available
    if (!$dep_state['all_ok']) {
        if (function_exists('gnmi_auto_continue_setup')) {
            gnmi_auto_continue_setup();
            // Re-check state after auto-continue
            $dep_state = gnmi_check_all_dependencies(false);
        }
    }

    $messages = array();
    $python_bin = false;

    // Collect messages based on actual state
    if (!$dep_state['venv_module']) {
        // Get Python version for version-specific package suggestion
        $version_cmd = 'python3 --version 2>&1';
        $version_out = array();
        $version_rc = 0;
        @exec($version_cmd, $version_out, $version_rc);
        $python_version = '3.x';
        if ($version_rc === 0 && !empty($version_out)) {
            if (preg_match('/Python\s+(\d+\.\d+)/', $version_out[0], $matches)) {
                $python_version = $matches[1];
            }
        }

        $messages[] = "<strong>python3-venv system package:</strong> Missing or ensurepip not available. Required for virtual environment creation.<br>"
            . "<strong>Docker:</strong> <code>docker exec -u root cacti_app bash -lc 'apt-get update && apt-get install -y python3-venv python" . htmlspecialchars($python_version) . "-venv'</code><br>"
            . "<strong>Debian/Ubuntu:</strong> <code>apt install python3-venv python" . htmlspecialchars($python_version) . "-venv</code><br>"
            . "<strong>RHEL/CentOS:</strong> <code>yum install python3-venv</code>";
    } else {
        $python_bin = gnmi_get_python_binary();
        if ($python_bin === false) {
            $messages[] = "<strong>Virtual environment:</strong> Missing. The plugin requires a Python virtual environment.<br>"
                . "The virtual environment should have been created during installation. If missing, create it manually:<br>"
                . "<code>cd " . htmlspecialchars($config['base_path'] . '/plugins/gnmi') . " && python3 -m venv venv</code>";
        } else {
            if (!$dep_state['rrdtool_dev']) {
                $messages[] = "<strong>RRDtool development packages:</strong> Missing. Required for building rrdtool Python module.<br>"
                    . "<strong>Docker:</strong> <code>docker exec -u root cacti_app bash -lc 'apt-get update && apt-get install -y librrd-dev python3-dev'</code><br>"
                    . "<strong>Debian/Ubuntu:</strong> <code>apt install librrd-dev python3-dev</code><br>"
                    . "<strong>RHEL/CentOS:</strong> <code>yum install rrdtool-devel python3-devel</code>";
            }

            if (!$dep_state['rrdtool']) {
                $messages[] = "<strong>Python rrdtool module:</strong> Missing in virtual environment (requires build from source).<br>"
                    . "1. Install system development packages (if not already done):<br>"
                    . "   <strong>Docker:</strong> <code>docker exec -u root cacti_app bash -lc 'apt-get update && apt-get install -y librrd-dev python3-dev'</code><br>"
                    . "   <strong>Debian/Ubuntu:</strong> <code>apt install librrd-dev python3-dev</code><br>"
                    . "   <strong>RHEL/CentOS:</strong> <code>yum install rrdtool-devel python3-devel</code><br>"
                    . "2. Build and install in venv: <code>" . htmlspecialchars($python_bin) . " -m pip install rrdtool-bindings==0.5.0</code>";
            }

            if (!$dep_state['pygnmi']) {
                $req_path = $config['base_path'] . '/plugins/gnmi/scripts/requirements.txt';
                $messages[] = "<strong>Python pygnmi package:</strong> Missing gNMI protocol library in virtual environment.<br>"
                    . "<strong>Install in venv:</strong> <code>" . htmlspecialchars($python_bin) . " -m pip install -r " . htmlspecialchars($req_path) . "</code>";
            }

			if (!$dep_state['pymysql']) {
				$req_path = $config['base_path'] . '/plugins/gnmi/scripts/requirements.txt';
				$messages[] = "<strong>Python pymysql package:</strong> Missing database client required by the poller bridge.<br>"
					. "<strong>Install in venv:</strong> <code>" . htmlspecialchars($python_bin) . " -m pip install -r " . htmlspecialchars($req_path) . "</code>";
			}
        }
    }

    if (!empty($messages)) {
        // Determine appropriate banner title
        if (!$dep_state['venv_module']) {
            $banner_title = "<strong>gNMI:</strong> Missing required system dependencies. Plugin cannot create virtual environment until installed.";
        } elseif (!$dep_state['rrdtool_dev']) {
            $banner_title = "<strong>gNMI:</strong> Missing required system dependencies. Plugin cannot build rrdtool module until installed.";
        } elseif ($python_bin === false) {
            $banner_title = "<strong>gNMI:</strong> Virtual environment is missing. Plugin will not function until created.";
        } else {
            $banner_title = "<strong>gNMI:</strong> Missing required dependencies in virtual environment. Daemon will not run until installed.";
        }

        print "<div class='warning' style='margin: 10px 0; padding: 10px; background-color: #fff3cd; border: 1px solid #ffc107; border-radius: 4px;'>" . $banner_title . "<br><br>";
        foreach ($messages as $msg) {
            print $msg . "<br><br>";
        }
        print "</div>";
    }
}

// Display page title and refresh button
html_start_box($title, '100%', '', '3', 'center', '');
?>

<tr>
	<td>
		<p>This dashboard shows real-time status of all gNMI-enabled devices and their daemons.</p>
		<p>
			<a href="index.php" class="btn btn-primary">Refresh Now</a>
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

<?php
/**
 * gNMI Plugin setup file
 *
 * Provides metadata and installation hooks required by the Cacti plugin framework.
 */

function plugin_gnmi_version() {
	return array(
		'name'        => 'gNMI Telemetry',
		'version'     => '1.0.0-beta.3',
		'longname'    => 'gNMI Telemetry Collection for Cacti',
		'author'      => 'Jarred Masterson',
		'homepage'    => 'https://github.com/Per-Forma/cacti-gnmi-plugin',
		'email'       => 'jarred.masterson@gmail.com',
		'licence'     => 'GPLv2 or later',
		'description' => 'Collect telemetry from gNMI-capable devices.',
		'compat'      => '1.2.25',
	);
}

function plugin_gnmi_config() {
	return array(
		'name' => 'gNMI Telemetry',
		'version' => '1.0.0-beta.3',
		'author' => 'Jarred Masterson',
		'homepage' => 'https://github.com/Per-Forma/cacti-gnmi-plugin',
		'email' => 'jarred.masterson@gmail.com',
		'licence' => 'GPLv2 or later',
		'implies' => true,
		'compat' => '1.2.25',
	);
}

function plugin_init_gnmi() {
	global $config;
	global $plugin_hooks;

	// Register hooks via plugin_hooks array
	$plugin_hooks['config_arrays']['gnmi'] = 'gnmi_config_arrays';
	$plugin_hooks['poller_bottom']['gnmi'] = 'gnmi_poller_bottom';
	$plugin_hooks['top_header_tabs']['gnmi'] = 'gnmi_show_tab';
	$plugin_hooks['top_graph_header_tabs']['gnmi'] = 'gnmi_show_tab';
	// Phase 3.1: Device form integration hooks
	// device_edit_pre_bottom fires inside the form (before form_save_button/form_end),
	// so our fields land naturally inside #host_form without DOM relocation.
	$plugin_hooks['device_edit_pre_bottom']['gnmi'] = 'gnmi_extend_device_edit_form';
	$plugin_hooks['host_save']['gnmi'] = 'gnmi_save_device_form';
}

function gnmi_config_arrays() {
	global $menu;

	// Add status page to plugin menu
	$menu[__('Management')]['plugins/gnmi/status.php'] = __('gNMI Status', 'gnmi');

	return array();
}

/**
 * Check teardown-sensitive tables without Cacti's db_table_exists() cache.
 * Long-running poller processes can otherwise retain a pre-uninstall result.
 *
 * @return bool True when the tables required by daemon management exist.
 */
function gnmi_poller_tables_available() {
	$devices = db_fetch_assoc("SHOW TABLES LIKE 'plugin_gnmi_devices'");
	$metrics = db_fetch_assoc("SHOW TABLES LIKE 'plugin_gnmi_metrics'");

	return is_array($devices) && !empty($devices) && is_array($metrics) && !empty($metrics);
}

/**
 * Poller bottom hook - manages daemon lifecycle and collects telemetry for all enabled gNMI devices.
 *
 * Called by Cacti poller every N seconds (configured in Settings → Poller).
 * For gNMI plugin, recommend 10-second interval for high-frequency telemetry.
 *
 * This hook:
 * - Queries database for all enabled gNMI devices
 * - Checks daemon health for each device
 * - Auto-starts daemons if not running
 * - Collects telemetry data from daemon JSON storage
 * - Creates RRD files programmatically if needed
 * - Writes data directly to RRD files
 * - Logs status to Cacti log
 */
function gnmi_poller_bottom() {
	global $config;

	cacti_log("gNMI: Hook gnmi_poller_bottom() called", false, 'POLLER', POLLER_VERBOSITY_LOW);

	// Include functions file if not already loaded
	if (!function_exists('gnmi_manage_daemons')) {
		include_once($config['base_path'] . '/plugins/gnmi/include/functions.php');
	}

	// Cacti removes registered hooks after plugin_gnmi_uninstall() returns. A
	// cron poll can therefore enter this callback during that short teardown
	// window. Exit cleanly instead of querying tables that are being removed.
	if (!gnmi_poller_tables_available()) {
		cacti_log('gNMI: Skipping poller hook because plugin tables are unavailable', false, 'POLLER', POLLER_VERBOSITY_LOW);
		return;
	}

	// Fail closed if plugin install did not create and protect the runtime tree.
	if (!gnmi_ensure_storage_directory()) {
		cacti_log('gNMI Plugin: Skipping daemon management - runtime directories are missing, unwritable, or unprotected', false, 'POLLER', POLLER_VERBOSITY_LOW);
		return;
	}

	// Check requirements using unified checker
	if (function_exists('gnmi_check_all_dependencies')) {
		$dep_state = gnmi_check_all_dependencies(false);

		if (!$dep_state['all_ok']) {
			// Log which dependencies are missing
			$missing = array();
			if (!$dep_state['venv_module']) $missing[] = 'python3-venv';
			if (!$dep_state['rrdtool_dev']) $missing[] = 'rrdtool-dev packages';
			if (!$dep_state['rrdtool']) $missing[] = 'rrdtool Python module';
			if (!$dep_state['pygnmi']) $missing[] = 'pygnmi';
			if (!$dep_state['pymysql']) $missing[] = 'pymysql';

			$missing_str = implode(', ', $missing);
			static $noted = false;
			if (!$noted) {
				cacti_log('gNMI Plugin: Skipping daemon management - missing dependencies: ' . $missing_str, false, 'POLLER', POLLER_VERBOSITY_LOW);
				$noted = true;
			}
			return;
		}
	}

	// Serialize daemon management + telemetry vs concurrent poller.php processes (multi-poller).
	gnmi_with_poller_exclusive_lock(function () {
		// Uninstall uses the same lock and may have removed tables while this
		// poller was waiting. Recheck inside the critical section using the
		// uncached probe before issuing any plugin-table query.
		if (!gnmi_poller_tables_available()) {
			cacti_log('gNMI: Skipping poller hook because plugin tables became unavailable', false, 'POLLER', POLLER_VERBOSITY_LOW);
			return;
		}

		gnmi_manage_daemons();
	});

	cacti_log("gNMI: Hook gnmi_poller_bottom() completed", false, 'POLLER', POLLER_VERBOSITY_LOW);
}

function gnmi_show_tab() {
	global $config, $tabs;

	$tabs['gnmi'] = array(
		'name' => __('gNMI', 'gnmi'),
		'url'  => $config['url_path'] . 'plugins/gnmi/index.php',
	);
}

/**
 * Detect schemas from unsupported private or development builds.
 *
 * The public beta supports fresh installs only.  Refuse to install over the
 * legacy assignment-table layout rather than dropping data or leaving a mixed
 * schema that current runtime code cannot use.
 *
 * @return array Human-readable legacy schema markers; empty when installation
 *               may proceed.
 */
function gnmi_find_unsupported_legacy_schema() {
	$legacy = array();

	foreach (array('plugin_gnmi_device_metrics', 'plugin_gnmi_device_settings') as $table) {
		if (db_table_exists($table)) {
			$legacy[] = $table;
		}
	}

	if (db_table_exists('plugin_gnmi_metrics')) {
		$columns = db_fetch_assoc('DESCRIBE plugin_gnmi_metrics');
		if (!is_array($columns) || empty($columns)) {
			$legacy[] = 'plugin_gnmi_metrics (schema could not be inspected)';
		} else {
			$column_names = array_column($columns, 'Field');
			if (!in_array('subscription_id', $column_names, true)) {
				$legacy[] = 'plugin_gnmi_metrics (missing subscription_id)';
			}
		}
	}

	return $legacy;
}

/**
 * Install hook - creates database tables for gNMI plugin.
 *
 * Schema Design:
 * - plugin_gnmi_devices: Stores gNMI connection parameters per Cacti device
 * - plugin_gnmi_subscriptions: Defines subscription paths per device
 * - plugin_gnmi_metrics: Defines metrics belonging to subscriptions
 * - plugin_gnmi_events: Records lifecycle and configuration events
 *
 * Security note:
 * Credentials are stored in plaintext. Secure database access, restrict
 * runtime-file permissions, and use a least-privilege telemetry account.
 *
 * @return bool Success status
 */
function plugin_gnmi_install() {
	global $config;

	$legacy_schema = gnmi_find_unsupported_legacy_schema();
	if (!empty($legacy_schema)) {
		cacti_log(
			'gNMI Plugin: Install blocked because unsupported private/development schema was found: ' .
			implode(', ', $legacy_schema) .
			'. The public beta is fresh-install only; existing tables were left unchanged. ' .
			'Back up and remove the earlier plugin installation before retrying.',
			false,
			'INSTALL',
			POLLER_VERBOSITY_LOW
		);
		return false;
	}

	// Include functions.php for helper functions like gnmi_get_storage_dir()
	include_once($config['base_path'] . '/plugins/gnmi/include/functions.php');

	// Fresh-install runtime hardening: create the protected runtime tree before
	// registering hooks or creating schema.  If this fails, block installation
	// rather than allowing credentials/runtime files to fall back to public paths.
	if (!gnmi_ensure_runtime_directories()) {
		cacti_log('gNMI Plugin: ERROR - Runtime directory initialization failed; install aborted. Check GNMI_RUNTIME_DIR/path ownership and web-server .htaccess support.', false, 'INSTALL', POLLER_VERBOSITY_LOW);
		return false;
	}
	cacti_log('gNMI Plugin: Runtime directories initialized under ' . gnmi_get_runtime_dir(), false, 'INSTALL', POLLER_VERBOSITY_MEDIUM);

	// Register hooks in the database so Cacti's plugin system knows to call them.
	// Use status=4 (inactive) for new rows: same convention as api_plugin_disable_hooks() so
	// api_plugin_hook() does not run poller/UI hooks until the plugin is enabled in Console.
	// Enabling the plugin runs api_plugin_enable_hooks(), which sets all gnmi hooks to status=1.
	$hooks = array(
		array('hook' => 'poller_bottom', 'function' => 'gnmi_poller_bottom'),
		array('hook' => 'device_edit_pre_bottom', 'function' => 'gnmi_extend_device_edit_form'),
		array('hook' => 'host_save', 'function' => 'gnmi_save_device_form'),
	);

	foreach ($hooks as $hook) {
		$hook_exists = db_fetch_cell_prepared(
			"SELECT COUNT(*) FROM plugin_hooks WHERE name = 'gnmi' AND hook = ? AND function = ?",
			array($hook['hook'], $hook['function'])
		);

		if (!$hook_exists) {
			db_execute_prepared(
				"INSERT INTO plugin_hooks (name, hook, file, function, status) VALUES (?, ?, 'setup.php', ?, 4)",
				array('gnmi', $hook['hook'], $hook['function'])
			);
			cacti_log("gNMI Plugin: Registered hook '{$hook['hook']}' -> '{$hook['function']}' (inactive until plugin enabled)", false, 'INSTALL', POLLER_VERBOSITY_MEDIUM);
		}
	}

	// Subscription mutations are handled only by ajax_handler.php. Remove the
	// obsolete hook if this install follows an interrupted prerelease upgrade.
	gnmi_remove_legacy_subscription_action_hook();

	// Register plugin pages/realms for access control
	// This allows Cacti to manage permissions for plugin pages
	// Parameter 4 (1) grants access to admin user by default
	api_plugin_register_realm('gnmi', 'index.php', __('View gNMI Dashboard', 'gnmi'), 1);
	api_plugin_register_realm('gnmi', 'status.php', __('View gNMI Status', 'gnmi'), 1);
	api_plugin_register_realm('gnmi', 'dashboard_actions.php', __('Manage gNMI Daemons', 'gnmi'), 0);
	api_plugin_register_realm('gnmi', 'ajax_handler.php', __('gNMI AJAX Handler', 'gnmi'), 1);
	api_plugin_register_realm('gnmi', 'test_connection.php', __('gNMI Test Connection', 'gnmi'), 1);

	cacti_log('gNMI Plugin: Registered page realms for access control', false, 'INSTALL', POLLER_VERBOSITY_MEDIUM);

	// Table: plugin_gnmi_devices
	// Stores gNMI connection configuration for each managed device
	db_execute("CREATE TABLE IF NOT EXISTS `plugin_gnmi_devices` (
		`id` int(11) unsigned NOT NULL AUTO_INCREMENT COMMENT 'Primary key - daemon identifier',
		`host_id` MEDIUMINT(8) UNSIGNED NOT NULL UNIQUE COMMENT 'Foreign key to Cacti host.id - which Cacti device owns this config',
		`enabled` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'Enable/disable gNMI collection',
		`hostname` varchar(255) NOT NULL COMMENT 'gNMI target hostname or IP address (may differ from Cacti hostname)',
		`hostname_source` ENUM('device','custom') NOT NULL DEFAULT 'device' COMMENT 'Whether hostname follows host.hostname or is explicitly configured',
		`port` int(5) unsigned NOT NULL DEFAULT 9339 COMMENT 'gNMI port (default 9339)',
		`username` varchar(255) NOT NULL COMMENT 'gNMI authentication username (stored plaintext)',
		`password` text COMMENT 'gNMI authentication password (stored plaintext)',
		`use_tls` boolean NOT NULL DEFAULT TRUE COMMENT 'Enable TLS for gNMI connection',
		`compatibility_mode` ENUM('standard','ciena_saos10') NOT NULL DEFAULT 'standard' COMMENT 'Optional vendor compatibility behavior',
		`ca_cert_path` varchar(255) COMMENT 'Path to CA certificate file for server verification',
		`client_key_path` varchar(255) COMMENT 'Path to client private key file (for mTLS)',
		`client_cert_path` varchar(255) COMMENT 'Path to client certificate file (for mTLS)',
		`tls_override` varchar(255) COMMENT 'Override server name for certificate validation (lab environments)',
		`skip_verify` boolean NOT NULL DEFAULT FALSE COMMENT 'Skip TLS certificate verification (NOT recommended for production)',
		`tls_cipher_policy` ENUM('default','legacy_compatibility') NOT NULL DEFAULT 'default' COMMENT 'Per-device gRPC TLS cipher policy',
		`collection_interval` int(5) unsigned NOT NULL DEFAULT 10 COMMENT 'Collection interval in seconds (must align with Cacti poll interval)',
		`encoding` varchar(50) NOT NULL DEFAULT 'JSON_IETF' COMMENT 'gNMI encoding format (JSON_IETF, PROTO, etc)',
		`last_poll_time` timestamp NULL DEFAULT NULL COMMENT 'Last successful poll timestamp',
		`last_poll_status` varchar(50) NOT NULL DEFAULT 'never' COMMENT 'Status: success, error, never',
		`last_error_message` text COMMENT 'Most recent error message if poll failed',
		`created_on` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'When this configuration was created',
		`modified_on` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Last modification timestamp',
		PRIMARY KEY (`id`),
		UNIQUE KEY `uk_host_id` (`host_id`),
		KEY `idx_enabled` (`enabled`),
		KEY `idx_created_on` (`created_on`),
		FOREIGN KEY (`host_id`) REFERENCES `host`(`id`) ON DELETE CASCADE
	) ENGINE=InnoDB COMMENT='gNMI device connection and daemon configuration'");

	// Table: plugin_gnmi_events
	// Audit trail for config changes, daemon lifecycle events, errors
	db_execute("CREATE TABLE IF NOT EXISTS `plugin_gnmi_events` (
		`id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY COMMENT 'Event ID',
		`device_id` INT(11) UNSIGNED NOT NULL COMMENT 'Reference to plugin_gnmi_devices.id',
		`event_type` VARCHAR(50) NOT NULL COMMENT 'Event type: config_change, daemon_start, daemon_stop, daemon_restart, error, orphan_cleanup',
		`event_data` JSON COMMENT 'Event-specific data (changed fields, error messages, etc)',
		`created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'When event occurred',
		KEY `idx_device_id` (`device_id`),
		KEY `idx_event_type` (`event_type`),
		KEY `idx_created_at` (`created_at`),
		FOREIGN KEY (`device_id`) REFERENCES `plugin_gnmi_devices`(`id`) ON DELETE CASCADE
	) ENGINE=InnoDB COMMENT='Audit trail for gNMI daemon lifecycle and configuration events'");

	// Table: plugin_gnmi_subscriptions
	// Store user-defined gNMI subscription paths per device
	db_execute("CREATE TABLE IF NOT EXISTS `plugin_gnmi_subscriptions` (
		`id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
		`device_id` INT(11) UNSIGNED NOT NULL COMMENT 'FK to plugin_gnmi_devices.id',
		`subscription_path` TEXT NOT NULL COMMENT 'gNMI path (e.g., Ciena:cn-if:interface-telemetry-state/...)',
		`instance_identifier` VARCHAR(100) NOT NULL COMMENT 'Instance name (e.g., ettp-40, eth0, CPU0)',
		`enabled` BOOLEAN NOT NULL DEFAULT TRUE COMMENT 'Enable/disable this subscription',
		`discovery_mode` ENUM('manual', 'discovered', 'template') NOT NULL DEFAULT 'manual' COMMENT 'How the subscription definition was created',
		`auto_create_datasources` BOOLEAN NOT NULL DEFAULT TRUE COMMENT 'Auto-create Cacti data sources for metrics',
		`auto_create_graphs` BOOLEAN NOT NULL DEFAULT TRUE COMMENT 'Auto-create Cacti graphs for metrics',
		`last_discovery_time` TIMESTAMP NULL DEFAULT NULL COMMENT 'When metrics were last discovered',
		`discovery_status` ENUM('pending', 'success', 'failed') NULL DEFAULT NULL COMMENT 'Most recent discovery result',
		`notes` TEXT NULL COMMENT 'User notes about this subscription',
		`created_on` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
		`modified_on` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
		KEY `idx_device_id` (`device_id`),
		KEY `idx_enabled` (`enabled`),
		KEY `idx_discovery_mode` (`discovery_mode`),
		FOREIGN KEY (`device_id`) REFERENCES `plugin_gnmi_devices`(`id`) ON DELETE CASCADE
	) ENGINE=InnoDB COMMENT='gNMI subscription definitions per device'");

	// Table: plugin_gnmi_metrics
	db_execute("CREATE TABLE IF NOT EXISTS `plugin_gnmi_metrics` (
		`id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
		`subscription_id` INT(11) UNSIGNED NOT NULL COMMENT 'FK to plugin_gnmi_subscriptions.id',
		`metric_name` VARCHAR(255) NOT NULL COMMENT 'Raw metric name from device (e.g., in-octets)',
		`cacti_field_name` VARCHAR(19) NOT NULL COMMENT 'Sanitized for Cacti/RRD (e.g., in_octets, max 19 chars)',
		`rrd_type` ENUM('COUNTER', 'GAUGE', 'DERIVE', 'ABSOLUTE') NOT NULL DEFAULT 'COUNTER',
		`rrd_heartbeat` INT(5) UNSIGNED NOT NULL DEFAULT 600 COMMENT 'Seconds before data marked stale (SQL default 600 = 2x 300s fallback interval; runtime uses gnmi_get_poller_interval() * 2)',
		`rrd_min` VARCHAR(20) NOT NULL DEFAULT '0' COMMENT 'Minimum value (U for unlimited)',
		`rrd_max` VARCHAR(20) NOT NULL DEFAULT 'U' COMMENT 'Maximum value (U for unlimited)',
		`enabled` BOOLEAN NOT NULL DEFAULT TRUE COMMENT 'Enable/disable collection',
		`discovered` BOOLEAN NOT NULL DEFAULT FALSE COMMENT 'Whether the metric was discovered automatically',
		`datasource_created` BOOLEAN NOT NULL DEFAULT FALSE COMMENT 'Tracks if a Cacti data source exists',
		`metric_group` ENUM('traffic','packets','errors','discards','generic') NOT NULL DEFAULT 'generic',
		`metric_direction` ENUM('inbound','outbound','none') NOT NULL DEFAULT 'none',
		`metric_graph_key` VARCHAR(128) NULL DEFAULT NULL COMMENT 'Deterministic auto-graph grouping key derived from raw metric name',
		`graph_created` BOOLEAN NOT NULL DEFAULT FALSE COMMENT 'Tracks if a Cacti graph exists',
		`local_data_id` INT(11) UNSIGNED NULL DEFAULT NULL COMMENT 'FK to Cacti data_local.id (NULL until created)',
		`graph_local_id` INT(11) UNSIGNED NULL DEFAULT NULL COMMENT 'FK to Cacti graph_local.id (NULL until created)',
		`created_on` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
		`modified_on` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
		UNIQUE KEY `uk_subscription_metric` (`subscription_id`, `metric_name`),
		KEY `idx_enabled` (`enabled`),
		KEY `idx_datasource_created` (`datasource_created`),
		KEY `idx_metric_graph_key` (`subscription_id`, `metric_graph_key`, `metric_direction`),
		KEY `idx_discovered` (`discovered`),
		FOREIGN KEY (`subscription_id`) REFERENCES `plugin_gnmi_subscriptions`(`id`) ON DELETE CASCADE
	) ENGINE=InnoDB COMMENT='Individual metrics collected from gNMI subscriptions'");

	cacti_log('gNMI Plugin: Database tables created successfully', false, 'INSTALL', POLLER_VERBOSITY_MEDIUM);

	$profile_id = gnmi_ensure_data_source_profile();
	if (empty($profile_id)) {
		cacti_log('gNMI Plugin: WARNING - Failed to create or update 10-second data source profile', false, 'INSTALL', POLLER_VERBOSITY_LOW);
	}

	// Preflight: Check all dependencies using unified checker
	global $config;
	include_once($config['base_path'] . '/plugins/gnmi/include/functions.php');

	// Ensure template + data input metadata exists (stored via settings)
	$input_id = gnmi_get_passthrough_data_input_id();
	$template_id = gnmi_get_passthrough_data_template_id();
	if (empty($input_id) || empty($template_id)) {
		cacti_log('gNMI Plugin: ERROR - Failed to provision gNMI Data Input/Template metadata', false, 'INSTALL', POLLER_VERBOSITY_LOW);
	}

	// Run unified dependency check with auto-remediation enabled
	$dep_state = gnmi_check_all_dependencies(true);

	$messages = array();
	$any_missing = false;

	// Collect messages for missing dependencies
	if (!$dep_state['venv_module']) {
		$any_missing = true;
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
	}

	if (!$dep_state['rrdtool_dev']) {
		$any_missing = true;
		$messages[] = "<strong>RRDtool development packages:</strong> Missing. Required for building rrdtool Python module.<br>"
			. "<strong>Docker:</strong> <code>docker exec -u root cacti_app bash -lc 'apt-get update && apt-get install -y librrd-dev python3-dev'</code><br>"
			. "<strong>Debian/Ubuntu:</strong> <code>apt install librrd-dev python3-dev</code><br>"
			. "<strong>RHEL/CentOS:</strong> <code>yum install rrdtool-devel python3-devel</code>";
	}

	if (!$dep_state['rrdtool']) {
		$any_missing = true;
		$venv_python = $config['base_path'] . '/plugins/gnmi/venv/bin/python3';
		$messages[] = "<strong>Python rrdtool module:</strong> Missing in virtual environment (requires build from source).<br>"
			. "1. Install system development packages (if not already done):<br>"
			. "   <strong>Docker:</strong> <code>docker exec -u root cacti_app bash -lc 'apt-get update && apt-get install -y librrd-dev python3-dev'</code><br>"
			. "   <strong>Debian/Ubuntu:</strong> <code>apt install librrd-dev python3-dev</code><br>"
			. "   <strong>RHEL/CentOS:</strong> <code>yum install rrdtool-devel python3-devel</code><br>"
			. "2. Build and install in venv: <code>" . htmlspecialchars($venv_python) . " -m pip install rrdtool-bindings==0.5.0</code>";
	}

	if (!$dep_state['pygnmi']) {
		$any_missing = true;
		$req_path = $config['base_path'] . '/plugins/gnmi/scripts/requirements.txt';
		$venv_python = $config['base_path'] . '/plugins/gnmi/venv/bin/python3';
		$messages[] = "<strong>Python pygnmi package:</strong> Missing gNMI protocol library in virtual environment.<br>"
			. "<strong>Install in venv:</strong> <code>" . htmlspecialchars($venv_python) . " -m pip install -r " . htmlspecialchars($req_path) . "</code>";
	}

	if (!$dep_state['pymysql']) {
		$any_missing = true;
		$req_path = $config['base_path'] . '/plugins/gnmi/scripts/requirements.txt';
		$venv_python = $config['base_path'] . '/plugins/gnmi/venv/bin/python3';
		$messages[] = "<strong>Python pymysql package:</strong> Missing database client required by the poller bridge.<br>"
			. "<strong>Install in venv:</strong> <code>" . htmlspecialchars($venv_python) . " -m pip install -r " . htmlspecialchars($req_path) . "</code>";
	}

	// Display warnings if any dependencies missing
	if ($any_missing) {
		// Determine appropriate banner message
		if (!$dep_state['venv_module']) {
			$banner_title = "<strong>gNMI Plugin:</strong> Missing required system dependencies. Plugin cannot create virtual environment until installed.";
		} elseif (!$dep_state['rrdtool_dev']) {
			$banner_title = "<strong>gNMI Plugin:</strong> Missing required system dependencies. Plugin cannot build rrdtool module until installed.";
		} else {
			$banner_title = "<strong>gNMI Plugin:</strong> Missing required dependencies in virtual environment. Daemon will not run until installed.";
		}

		print "<div class='warning'>" . $banner_title . "<br><br>";
		foreach ($messages as $msg) {
			print $msg . "<br><br>";
		}
		print "</div>";
	} else {
		cacti_log('gNMI Plugin: All dependencies verified successfully.', false, 'INSTALL', POLLER_VERBOSITY_MEDIUM);
	}

	// Device hostname inheritance and Phase 3.4 schema additions.
	gnmi_apply_hostname_source_schema();
	gnmi_apply_compatibility_mode_schema();
	gnmi_apply_tls_cipher_policy_schema();
	gnmi_apply_schema_34();

	return true;
}

/**
 * Ensure the gNMI data source profile exists and matches the active poller interval.
 *
 * The profile's RRD step remains 10 seconds for high-resolution storage, but its
 * heartbeat must track the effective Cacti poller interval (`poller_interval * 2`)
 * so profile metadata matches the RRD item heartbeats created by the plugin.
 *
 * @param string $profile_name Data source profile name to create/update.
 * @return int|false Profile ID on success, false on failure.
 */
function gnmi_ensure_data_source_profile($profile_name = 'gNMI - 10 Second Collection') {
	global $config;

	if (!function_exists('gnmi_get_poller_interval')) {
		include_once($config['base_path'] . '/plugins/gnmi/include/functions.php');
	}

	$step = 10;
	$heartbeat = gnmi_get_poller_interval() * 2;

	$profile = db_fetch_row_prepared(
		'SELECT id, step, heartbeat FROM data_source_profiles WHERE name = ?',
		array($profile_name)
	);

	if (empty($profile)) {
		$profile_hash = function_exists('generate_hash') ? generate_hash() : md5(uniqid('gnmi_profile_', true));
		$result = db_execute_prepared(
			'INSERT INTO data_source_profiles (hash, name, step, heartbeat) VALUES (?, ?, ?, ?)',
			array($profile_hash, $profile_name, $step, $heartbeat)
		);

		if ($result === false) {
			cacti_log('gNMI Plugin: ERROR - SQL INSERT failed while creating data source profile: ' . db_fetch_error(), false, 'INSTALL', POLLER_VERBOSITY_LOW);
			return false;
		}

		$profile_id = (int)db_fetch_insert_id();
		if ($profile_id <= 0) {
			cacti_log('gNMI Plugin: WARNING - Failed to get profile ID after INSERT', false, 'INSTALL', POLLER_VERBOSITY_LOW);
			return false;
		}

		cacti_log("gNMI Plugin: Created data source profile '$profile_name' (ID: $profile_id, heartbeat={$heartbeat}s)", false, 'INSTALL', POLLER_VERBOSITY_MEDIUM);
	} else {
		$profile_id = (int)$profile['id'];
		$current_step = isset($profile['step']) ? (int)$profile['step'] : 0;
		$current_heartbeat = isset($profile['heartbeat']) ? (int)$profile['heartbeat'] : 0;

		if ($current_step !== $step || $current_heartbeat !== $heartbeat) {
			db_execute_prepared(
				'UPDATE data_source_profiles SET step = ?, heartbeat = ? WHERE id = ?',
				array($step, $heartbeat, $profile_id)
			);
			cacti_log("gNMI Plugin: Updated data source profile '$profile_name' heartbeat to {$heartbeat}s", false, 'INSTALL', POLLER_VERBOSITY_MEDIUM);
		} else {
			cacti_log("gNMI Plugin: Data source profile '$profile_name' already current", false, 'INSTALL', POLLER_VERBOSITY_MEDIUM);
		}
	}

	$consolidation_functions = array(
		'AVERAGE' => 1,
		'MIN'     => 2,
		'MAX'     => 3,
		'LAST'    => 4
	);

	foreach ($consolidation_functions as $cf_id) {
		$exists = db_fetch_cell_prepared(
			'SELECT COUNT(*) FROM data_source_profiles_cf WHERE data_source_profile_id = ? AND consolidation_function_id = ?',
			array($profile_id, $cf_id)
		);

		if ((int)$exists === 0) {
			db_execute_prepared(
				'INSERT INTO data_source_profiles_cf (data_source_profile_id, consolidation_function_id) VALUES (?, ?)',
				array($profile_id, $cf_id)
			);
		}
	}

	$rras = array(
		array('name' => 'High Resolution (10s for 6h)', 'steps' => 1, 'rows' => 2160, 'timespan' => 21600),
		array('name' => 'Daily (1 Minute Average)', 'steps' => 6, 'rows' => 1440, 'timespan' => 86400),
		array('name' => 'Weekly (10 Minute Average)', 'steps' => 60, 'rows' => 1680, 'timespan' => 604800),
		array('name' => 'Monthly (1 Hour Average)', 'steps' => 360, 'rows' => 744, 'timespan' => 2678400)
	);

	foreach ($rras as $rra) {
		$exists = db_fetch_cell_prepared(
			'SELECT COUNT(*) FROM data_source_profiles_rra WHERE data_source_profile_id = ? AND name = ? AND steps = ? AND `rows` = ? AND timespan = ?',
			array($profile_id, $rra['name'], $rra['steps'], $rra['rows'], $rra['timespan'])
		);

		if ((int)$exists === 0) {
			db_execute_prepared(
				'INSERT INTO data_source_profiles_rra (data_source_profile_id, name, steps, `rows`, timespan) VALUES (?, ?, ?, ?, ?)',
				array($profile_id, $rra['name'], $rra['steps'], $rra['rows'], $rra['timespan'])
			);
		}
	}

	return $profile_id;
}

/**
 * Add persistent tracking for inherited versus custom gNMI hostnames.
 *
 * Existing rows are classified only when the column is first added. This keeps
 * later idempotent runs from changing an explicit custom hostname that happens
 * to match the current Cacti device hostname.
 */
function gnmi_apply_hostname_source_schema() {
	$cols = db_fetch_assoc('DESCRIBE plugin_gnmi_devices');
	if (!is_array($cols)) {
		return false;
	}

	$hostname_source_col = null;
	foreach ($cols as $col) {
		if ($col['Field'] === 'hostname_source') {
			$hostname_source_col = $col;
			break;
		}
	}

	if ($hostname_source_col === null) {
		$result = db_execute("ALTER TABLE plugin_gnmi_devices
		ADD COLUMN hostname_source ENUM('device','custom') NULL DEFAULT NULL
		COMMENT 'Whether hostname follows host.hostname or is explicitly configured'
		AFTER hostname");
		if ($result === false) {
			cacti_log('gNMI: Failed to add hostname_source column: ' . db_fetch_error(), false, 'INSTALL', POLLER_VERBOSITY_LOW);
			return false;
		}

		$hostname_source_col = [
			'Null' => 'YES',
			'Default' => null,
		];
	}

	$result = db_execute("UPDATE plugin_gnmi_devices gd
		INNER JOIN host h ON h.id = gd.host_id
		SET gd.hostname_source = CASE
			WHEN gd.hostname = h.hostname THEN 'device'
			ELSE 'custom'
		END
		WHERE gd.hostname_source IS NULL");
	if ($result === false) {
		cacti_log('gNMI: Failed to classify existing gNMI hostnames: ' . db_fetch_error(), false, 'INSTALL', POLLER_VERBOSITY_LOW);
		return false;
	}

	if ($hostname_source_col['Null'] !== 'NO' || $hostname_source_col['Default'] !== 'device') {
		$result = db_execute("ALTER TABLE plugin_gnmi_devices
			MODIFY COLUMN hostname_source ENUM('device','custom') NOT NULL DEFAULT 'device'
			COMMENT 'Whether hostname follows host.hostname or is explicitly configured'");
		if ($result === false) {
			cacti_log('gNMI: Failed to finalize hostname_source column: ' . db_fetch_error(), false, 'INSTALL', POLLER_VERBOSITY_LOW);
			return false;
		}

		cacti_log('gNMI: Added hostname_source column and classified existing device hostnames', false, 'INSTALL', POLLER_VERBOSITY_MEDIUM);
	}

	return true;
}

/**
 * Add the explicit vendor compatibility selector to existing installations.
 *
 * Standard gNMI behavior is deliberately the default. Existing rows are also
 * migrated to standard; operators using a Ciena SAOS 10.x target must opt in
 * after upgrade so the Capabilities bypass is never applied to other vendors.
 *
 * @return bool True on success.
 */
function gnmi_apply_compatibility_mode_schema() {
	$cols = db_fetch_assoc('DESCRIBE plugin_gnmi_devices');
	if (!is_array($cols)) {
		return false;
	}

	$col_names = array_column($cols, 'Field');
	if (!in_array('compatibility_mode', $col_names, true)) {
		$result = db_execute("ALTER TABLE plugin_gnmi_devices
			ADD COLUMN compatibility_mode ENUM('standard','ciena_saos10') NOT NULL DEFAULT 'standard'
			COMMENT 'Optional vendor compatibility behavior'
			AFTER use_tls");
		if ($result === false) {
			cacti_log('gNMI: Failed to add compatibility_mode column: ' . db_fetch_error(), false, 'INSTALL', POLLER_VERBOSITY_LOW);
			return false;
		}

		cacti_log('gNMI: Added compatibility_mode column with standard gNMI behavior as the default', false, 'INSTALL', POLLER_VERBOSITY_MEDIUM);
	}

	return true;
}

/**
 * Add the vendor-neutral per-device TLS cipher policy selector.
 *
 * Existing devices retain gRPC's secure defaults. Operators must explicitly
 * opt a target into the legacy compatibility cipher allowance.
 *
 * @return bool True on success.
 */
function gnmi_apply_tls_cipher_policy_schema() {
	$cols = db_fetch_assoc('DESCRIBE plugin_gnmi_devices');
	if (!is_array($cols)) {
		return false;
	}

	$col_names = array_column($cols, 'Field');
	if (!in_array('tls_cipher_policy', $col_names, true)) {
		$result = db_execute("ALTER TABLE plugin_gnmi_devices
			ADD COLUMN tls_cipher_policy ENUM('default','legacy_compatibility') NOT NULL DEFAULT 'default'
			COMMENT 'Per-device gRPC TLS cipher policy'
			AFTER skip_verify");
		if ($result === false) {
			cacti_log('gNMI: Failed to add tls_cipher_policy column: ' . db_fetch_error(), false, 'INSTALL', POLLER_VERBOSITY_LOW);
			return false;
		}

		cacti_log('gNMI: Added tls_cipher_policy column with secure gRPC defaults', false, 'INSTALL', POLLER_VERBOSITY_MEDIUM);
	}

	return true;
}

/**
 * Apply Phase 3.4 schema additions.
 *
 * Adds columns for automatic graph creation tracking.
 * Idempotent — checks column existence before each ALTER TABLE.
 * Called from both plugin_gnmi_install() and plugin_gnmi_upgrade().
 */
function gnmi_apply_schema_34() {
	// plugin_gnmi_subscriptions: auto_create_graphs flag (mirrors auto_create_datasources)
	$cols = db_fetch_assoc('DESCRIBE plugin_gnmi_subscriptions');
	if (is_array($cols)) {
		$col_names = array_column($cols, 'Field');
		if (!in_array('auto_create_graphs', $col_names)) {
			db_execute("ALTER TABLE plugin_gnmi_subscriptions
				ADD COLUMN auto_create_graphs BOOLEAN NOT NULL DEFAULT TRUE
				AFTER auto_create_datasources");
			cacti_log('gNMI: Added auto_create_graphs column to plugin_gnmi_subscriptions', false, 'INSTALL', POLLER_VERBOSITY_MEDIUM);
		}
	}

	// plugin_gnmi_metrics: metric classification + graph tracking columns
	$cols = db_fetch_assoc('DESCRIBE plugin_gnmi_metrics');
	if (is_array($cols)) {
		$col_names = array_column($cols, 'Field');

		if (!in_array('metric_group', $col_names)) {
			db_execute("ALTER TABLE plugin_gnmi_metrics
				ADD COLUMN metric_group ENUM('traffic','packets','errors','discards','generic') NOT NULL DEFAULT 'generic'
				AFTER datasource_created");
			cacti_log('gNMI: Added metric_group column to plugin_gnmi_metrics', false, 'INSTALL', POLLER_VERBOSITY_MEDIUM);
		}

		if (!in_array('metric_direction', $col_names)) {
			db_execute("ALTER TABLE plugin_gnmi_metrics
				ADD COLUMN metric_direction ENUM('inbound','outbound','none') NOT NULL DEFAULT 'none'
				AFTER metric_group");
			cacti_log('gNMI: Added metric_direction column to plugin_gnmi_metrics', false, 'INSTALL', POLLER_VERBOSITY_MEDIUM);
		}

		if (!in_array('metric_graph_key', $col_names)) {
			db_execute("ALTER TABLE plugin_gnmi_metrics
				ADD COLUMN metric_graph_key VARCHAR(128) NULL DEFAULT NULL
				COMMENT 'Deterministic auto-graph grouping key derived from raw metric name'
				AFTER metric_direction");
			cacti_log('gNMI: Added metric_graph_key column to plugin_gnmi_metrics', false, 'INSTALL', POLLER_VERBOSITY_MEDIUM);
		}

		if (!in_array('graph_created', $col_names)) {
			db_execute("ALTER TABLE plugin_gnmi_metrics
				ADD COLUMN graph_created BOOLEAN NOT NULL DEFAULT FALSE
				AFTER metric_graph_key");
			cacti_log('gNMI: Added graph_created column to plugin_gnmi_metrics', false, 'INSTALL', POLLER_VERBOSITY_MEDIUM);
		}

		$graph_key_index = db_fetch_cell("SHOW INDEX FROM plugin_gnmi_metrics WHERE Key_name = 'idx_metric_graph_key'");
		if (!$graph_key_index) {
			db_execute("ALTER TABLE plugin_gnmi_metrics
				ADD KEY idx_metric_graph_key (subscription_id, metric_graph_key, metric_direction)");
			cacti_log('gNMI: Added idx_metric_graph_key index to plugin_gnmi_metrics', false, 'INSTALL', POLLER_VERBOSITY_MEDIUM);
		}
	}
}

/**
 * Normalize positive integer IDs returned from a Cacti database query.
 *
 * @param array $rows Query result rows.
 * @param string $column Column containing the ID.
 * @return array Sorted, unique integer IDs.
 */
function gnmi_uninstall_normalize_ids($rows, $column) {
	$ids = array();

	foreach ((array)$rows as $row) {
		$id = isset($row[$column]) ? (int)$row[$column] : 0;
		if ($id > 0) {
			$ids[$id] = $id;
		}
	}

	ksort($ids, SORT_NUMERIC);
	return array_values($ids);
}

/**
 * Capture every external resource owned by the plugin before its tables vanish.
 *
 * @return array Device IDs, Cacti graph/data-source IDs, and existing RRD paths.
 */
function gnmi_uninstall_collect_resources() {
	global $config;

	$resources = array(
		'device_ids' => array(),
		'graph_ids' => array(),
		'data_source_ids' => array(),
		'rrd_files' => array(),
	);

	if (db_table_exists('plugin_gnmi_devices')) {
		$resources['device_ids'] = gnmi_uninstall_normalize_ids(
			db_fetch_assoc('SELECT id FROM plugin_gnmi_devices ORDER BY id'),
			'id'
		);
	}

	if (!db_table_exists('plugin_gnmi_metrics')) {
		return $resources;
	}

	$columns = array_column(db_fetch_assoc('DESCRIBE plugin_gnmi_metrics'), 'Field');
	if (in_array('graph_local_id', $columns, true)) {
		$resources['graph_ids'] = gnmi_uninstall_normalize_ids(
			db_fetch_assoc(
				'SELECT DISTINCT graph_local_id FROM plugin_gnmi_metrics ' .
				'WHERE graph_local_id IS NOT NULL AND graph_local_id > 0 ORDER BY graph_local_id'
			),
			'graph_local_id'
		);
	}

	if (in_array('local_data_id', $columns, true)) {
		$resources['data_source_ids'] = gnmi_uninstall_normalize_ids(
			db_fetch_assoc(
				'SELECT DISTINCT local_data_id FROM plugin_gnmi_metrics ' .
				'WHERE local_data_id IS NOT NULL AND local_data_id > 0 ORDER BY local_data_id'
			),
			'local_data_id'
		);
	}

	if (!empty($resources['data_source_ids'])) {
		$id_sql = implode(',', $resources['data_source_ids']);
		$rrd_root = !empty($config['rra_path'])
			? rtrim($config['rra_path'], '/')
			: rtrim($config['base_path'], '/') . '/rra';
		$path_rows = db_fetch_assoc(
			'SELECT local_data_id, data_source_path FROM data_template_data ' .
			'WHERE local_data_id IN (' . $id_sql . ')'
		);

		foreach ($path_rows as $row) {
			$path = str_replace('<path_rra>', $rrd_root, (string)$row['data_source_path']);
			if ($path !== '' && file_exists($path)) {
				$resources['rrd_files'][$path] = $path;
			}
		}
		$resources['rrd_files'] = array_values($resources['rrd_files']);
	}

	return $resources;
}

/**
 * Stop configured daemons and remove their per-device runtime files.
 *
 * @param array $device_ids Plugin device IDs.
 * @return bool True when every configured daemon stopped successfully.
 */
function gnmi_uninstall_stop_daemons($device_ids) {
	global $config;

	if (empty($device_ids)) {
		return true;
	}

	if (!function_exists('gnmi_stop_daemon')) {
		include_once($config['base_path'] . '/plugins/gnmi/include/functions.php');
	}

	$storage_dir = gnmi_get_storage_dir();
	$logs_dir = function_exists('gnmi_get_logs_dir') ? gnmi_get_logs_dir() : $storage_dir;
	$all_stopped = true;

	foreach ($device_ids as $device_id) {
		if (!gnmi_stop_daemon($device_id)) {
			$all_stopped = false;
			cacti_log("gNMI Plugin: Failed to stop daemon $device_id during uninstall", false, 'INSTALL', POLLER_VERBOSITY_LOW);
			continue;
		}

		$runtime_files = array(
			$storage_dir . '/device_' . $device_id . '.pid',
			$storage_dir . '/device_' . $device_id . '_config.json',
			$storage_dir . '/device_' . $device_id . '.json',
			$logs_dir . '/device_' . $device_id . '.log',
		);

		foreach ($runtime_files as $runtime_file) {
			if (file_exists($runtime_file) && !@unlink($runtime_file)) {
				$all_stopped = false;
				cacti_log("gNMI Plugin: Failed to remove runtime file during uninstall: $runtime_file", false, 'INSTALL', POLLER_VERBOSITY_LOW);
			}
		}
	}

	return $all_stopped;
}

/**
 * Remove plugin-owned Cacti metadata while preserving physical RRD files.
 *
 * Cacti's data-source API queues RRD deletion when autoclean is enabled. The
 * transaction keeps those queue rows invisible while they are removed, so no
 * purge worker can delete an RRD between metadata removal and queue cleanup.
 *
 * @param array $resources Result from gnmi_uninstall_collect_resources().
 * @return bool Success status.
 */
function gnmi_uninstall_remove_cacti_objects($resources) {
	global $config;

	$graph_ids = $resources['graph_ids'];
	$data_source_ids = $resources['data_source_ids'];
	if (empty($graph_ids) && empty($data_source_ids)) {
		return true;
	}

	if (!function_exists('poller_push_to_remote_db_connect') || !function_exists('get_remote_poller_ids_from_data_sources')) {
		include_once($config['base_path'] . '/lib/poller.php');
	}
	if (!function_exists('api_aggregate_disassociate')) {
		include_once($config['base_path'] . '/lib/aggregate.php');
	}
	if (!function_exists('api_graph_remove_multi')) {
		include_once($config['base_path'] . '/lib/api_graph.php');
	}
	if (!function_exists('api_data_source_remove_multi')) {
		include_once($config['base_path'] . '/lib/api_data_source.php');
	}

	if (db_execute('START TRANSACTION') === false) {
		cacti_log('gNMI Plugin: Could not start uninstall cleanup transaction', false, 'INSTALL', POLLER_VERBOSITY_LOW);
		return false;
	}

	try {
		if (!empty($graph_ids)) {
			api_graph_remove_multi($graph_ids);
		}

		if (!empty($data_source_ids)) {
			api_data_source_remove_multi($data_source_ids);
			$id_sql = implode(',', $data_source_ids);

			// Preserve RRDs even when Cacti's global autoclean setting is enabled.
			if (db_table_exists('data_source_purge_action')) {
				db_execute('DELETE FROM data_source_purge_action WHERE local_data_id IN (' . $id_sql . ')');
			}

			$remaining_data_sources = (int)db_fetch_cell(
				'SELECT COUNT(*) FROM data_local WHERE id IN (' . $id_sql . ')'
			);
			if ($remaining_data_sources !== 0) {
				throw new RuntimeException('Cacti data-source metadata cleanup was incomplete');
			}
		}

		if (!empty($graph_ids)) {
			$graph_sql = implode(',', $graph_ids);
			$remaining_graphs = (int)db_fetch_cell(
				'SELECT COUNT(*) FROM graph_local WHERE id IN (' . $graph_sql . ')'
			);
			if ($remaining_graphs !== 0) {
				throw new RuntimeException('Cacti graph metadata cleanup was incomplete');
			}
		}

		if (db_execute('COMMIT') === false) {
			throw new RuntimeException('Could not commit Cacti metadata cleanup');
		}
	} catch (Throwable $error) {
		db_execute('ROLLBACK');
		cacti_log('gNMI Plugin: Uninstall cleanup failed: ' . $error->getMessage(), false, 'INSTALL', POLLER_VERBOSITY_LOW);
		return false;
	}

	foreach ($resources['rrd_files'] as $rrd_file) {
		if (!file_exists($rrd_file)) {
			cacti_log("gNMI Plugin: RRD unexpectedly missing after metadata cleanup: $rrd_file", false, 'INSTALL', POLLER_VERBOSITY_LOW);
			return false;
		}
	}

	return true;
}

/**
 * Remove the shared Cacti definitions provisioned exclusively for this plugin.
 *
 * Device-specific graphs and data sources must be removed first. If another
 * Cacti object still references one of these definitions, fail closed instead
 * of deleting metadata that is still in use.
 *
 * @return bool Success status.
 */
function gnmi_uninstall_remove_provisioned_metadata() {
	global $config;

	$template_id = (int)db_fetch_cell_prepared(
		'SELECT id FROM data_template WHERE name = ?',
		array('gNMI - Passthrough')
	);
	$input_id = (int)db_fetch_cell_prepared(
		'SELECT id FROM data_input WHERE name = ?',
		array('gNMI - Passthrough')
	);
	$profile_id = (int)db_fetch_cell_prepared(
		'SELECT id FROM data_source_profiles WHERE name = ?',
		array('gNMI - 10 Second Collection')
	);

	if ($template_id > 0) {
		$live_template_uses = (int)db_fetch_cell_prepared(
			'SELECT COUNT(*) FROM data_template_data WHERE data_template_id = ? AND local_data_id > 0',
			array($template_id)
		);
		if ($live_template_uses !== 0) {
			cacti_log('gNMI Plugin: Uninstall aborted because the gNMI data template is still in use', false, 'INSTALL', POLLER_VERBOSITY_LOW);
			return false;
		}
	}

	if (db_execute('START TRANSACTION') === false) {
		return false;
	}

	try {
		if ($template_id > 0) {
			$queries = array(
				'DELETE FROM data_input_data WHERE data_template_data_id IN (SELECT id FROM data_template_data WHERE data_template_id = ? AND local_data_id = 0)',
				'DELETE FROM snmp_query_graph_rrd WHERE data_template_id = ?',
				'DELETE FROM snmp_query_graph_rrd_sv WHERE data_template_id = ?',
				'DELETE FROM data_template_rrd WHERE data_template_id = ? AND local_data_id = 0',
				'DELETE FROM data_template_data WHERE data_template_id = ? AND local_data_id = 0',
				'DELETE FROM data_template WHERE id = ?',
			);
			foreach ($queries as $query) {
				if (db_execute_prepared($query, array($template_id)) === false) {
					throw new RuntimeException('Could not remove the gNMI data template');
				}
			}
		}

		if ($input_id > 0) {
			$input_uses = (int)db_fetch_cell_prepared(
				'SELECT COUNT(*) FROM data_template_data WHERE data_input_id = ?',
				array($input_id)
			);
			if ($input_uses !== 0) {
				throw new RuntimeException('The gNMI data input is still in use');
			}
			if (!function_exists('api_data_input_remove')) {
				include_once($config['base_path'] . '/lib/api_data_source.php');
			}
			if (!function_exists('update_replication_crc')) {
				include_once($config['base_path'] . '/lib/utility.php');
			}
			api_data_input_remove($input_id);
		}

		if ($profile_id > 0) {
			$profile_uses = (int)db_fetch_cell_prepared(
				'SELECT COUNT(*) FROM data_template_data WHERE data_source_profile_id = ?',
				array($profile_id)
			);
			if ($profile_uses !== 0) {
				throw new RuntimeException('The gNMI data-source profile is still in use');
			}
			if (db_execute_prepared('DELETE FROM data_source_profiles_rra WHERE data_source_profile_id = ?', array($profile_id)) === false ||
				db_execute_prepared('DELETE FROM data_source_profiles_cf WHERE data_source_profile_id = ?', array($profile_id)) === false ||
				db_execute_prepared('DELETE FROM data_source_profiles WHERE id = ?', array($profile_id)) === false) {
				throw new RuntimeException('Could not remove the gNMI data-source profile');
			}
		}

		if (db_execute("DELETE FROM settings WHERE name LIKE 'gnmi_%'") === false) {
			throw new RuntimeException('Could not remove gNMI settings');
		}

		if (db_execute('COMMIT') === false) {
			throw new RuntimeException('Could not commit provisioned metadata cleanup');
		}
	} catch (Throwable $error) {
		db_execute('ROLLBACK');
		cacti_log('gNMI Plugin: Provisioned metadata cleanup failed: ' . $error->getMessage(), false, 'INSTALL', POLLER_VERBOSITY_LOW);
		return false;
	}

	return true;
}

/**
 * Uninstall hook - stops daemons, removes Cacti metadata, and drops plugin tables.
 *
 * WARNING: This will permanently delete all gNMI device configurations,
 * metric definitions, assignments, and Cacti graph/data-source metadata.
 * Physical RRD files are intentionally preserved.
 *
 * @return bool Success status
 */
function plugin_gnmi_uninstall() {
	global $config;

	if (!function_exists('gnmi_with_poller_exclusive_lock')) {
		include_once($config['base_path'] . '/plugins/gnmi/include/functions.php');
	}

	$uninstall_result = false;
	$lock_acquired = gnmi_with_poller_exclusive_lock(function () use (&$uninstall_result) {
		$resources = gnmi_uninstall_collect_resources();

		if (!gnmi_uninstall_stop_daemons($resources['device_ids'])) {
			cacti_log('gNMI Plugin: Uninstall aborted because daemon cleanup failed', false, 'INSTALL', POLLER_VERBOSITY_LOW);
			return;
		}

		if (!gnmi_uninstall_remove_cacti_objects($resources)) {
			cacti_log('gNMI Plugin: Uninstall aborted because Cacti metadata cleanup failed', false, 'INSTALL', POLLER_VERBOSITY_LOW);
			return;
		}

		if (!gnmi_uninstall_remove_provisioned_metadata()) {
			cacti_log('gNMI Plugin: Uninstall aborted because provisioned metadata cleanup failed', false, 'INSTALL', POLLER_VERBOSITY_LOW);
			return;
		}

		// Drop tables in dependency order (children before parents) to satisfy InnoDB FKs:
		// device_metrics -> devices + metrics; events -> devices;
		// metrics (Phase 3.3) -> subscriptions; subscriptions -> devices.
		db_execute('DROP TABLE IF EXISTS `plugin_gnmi_device_metrics`');
		db_execute('DROP TABLE IF EXISTS `plugin_gnmi_events`');  // Phase 3.2
		db_execute('DROP TABLE IF EXISTS `plugin_gnmi_metrics`');
		db_execute('DROP TABLE IF EXISTS `plugin_gnmi_subscriptions`');  // Phase 3.3, FK to devices
		db_execute('DROP TABLE IF EXISTS `plugin_gnmi_device_settings`');
		db_execute('DROP TABLE IF EXISTS `plugin_gnmi_devices`');

		cacti_log('gNMI Plugin: Database tables dropped successfully', false, 'INSTALL', POLLER_VERBOSITY_MEDIUM);
		$uninstall_result = true;
	}, null, true);

	if (!$lock_acquired) {
		cacti_log('gNMI Plugin: Uninstall aborted because the poller lock could not be acquired', false, 'INSTALL', POLLER_VERBOSITY_LOW);
	}

	return $lock_acquired && $uninstall_result;
}

/**
 * Version upgrade hook - handles schema migrations between versions.
 *
 * Called automatically by Cacti when plugin version changes.
 * Add migration logic here for future schema changes.
 *
 * @return bool Success status
 */
function plugin_gnmi_upgrade() {
	global $config;
	cacti_log('gNMI: Running upgrade', false, 'UPGRADE', POLLER_VERBOSITY_MEDIUM);

	if (!function_exists('gnmi_get_poller_interval')) {
		include_once($config['base_path'] . '/plugins/gnmi/include/functions.php');
	}

	// Keep existing installs aligned with the active Cacti poller interval.
	gnmi_ensure_data_source_profile();

	// Apply schema additions (idempotent).
	gnmi_apply_hostname_source_schema();
	gnmi_apply_compatibility_mode_schema();
	gnmi_apply_tls_cipher_policy_schema();
	gnmi_apply_schema_34();
	gnmi_remove_legacy_subscription_action_hook();

	return true;
}

/**
 * Remove the pre-beta.3 hook that attempted to process AJAX actions in host.php.
 *
 * The endpoint is now the only mutation entry point. This cleanup is safe on
 * fresh installs and repeat upgrades.
 *
 * @return bool
 */
function gnmi_remove_legacy_subscription_action_hook() {
	return db_execute_prepared(
		"DELETE FROM plugin_hooks WHERE name = ? AND hook = ? AND function = ?",
		array('gnmi', 'host_edit_top', 'gnmi_process_subscription_actions')
	);
}

/**
 * Device edit form hook (Phase 3.1) - extends device edit form with gNMI configuration section
 *
 * Called by Cacti's device_edit_pre_bottom hook, which fires INSIDE #host_form
 * before form_save_button()/form_end(). Rendering directly here — with no hidden-div
 * or setTimeout DOM relocation — ensures our named inputs are part of the form from
 * the moment the page loads, so Cacti's formArray baseline is captured correctly and
 * the "Unsaved Changes Detected" dialog is never triggered spuriously.
 *
 * Note: device_edit_pre_bottom only fires when editing an existing device ($host['id'] != 0).
 * Creating a brand-new device omits the gNMI section; the user configures gNMI on the
 * first subsequent edit.
 */
function gnmi_extend_device_edit_form() {
	global $config;

	// Get the host_id from the request variable
	$host_id = 0;
	if (isset_request_var('id')) {
		$host_id = get_filter_request_var('id');
	}

	// Only meaningful for existing devices
	if ($host_id <= 0) {
		return;
	}

	cacti_log("gNMI: Rendering form for host_id=$host_id", false, 'GNMI', POLLER_VERBOSITY_LOW);

	if (!function_exists('gnmi_get_certs_dir')) {
		include_once($config['base_path'] . '/plugins/gnmi/include/functions.php');
	}

	// Import form functions
	$form_file = $config['base_path'] . '/plugins/gnmi/include/form_functions.php';
	if (!file_exists($form_file)) {
		return;
	}

	include_once($form_file);

	$gnmi_settings = db_fetch_row_prepared(
		'SELECT * FROM plugin_gnmi_devices WHERE host_id = ?',
		array($host_id)
	);
	if (!is_array($gnmi_settings)) {
		$gnmi_settings = [];
	}

	$device_hostname = db_fetch_cell_prepared(
		'SELECT hostname FROM host WHERE id = ?',
		array($host_id)
	);
	if (!is_string($device_hostname)) {
		$device_hostname = '';
	}

	// Render directly inside the form — no DOM relocation needed.
	html_start_box(__('gNMI Telemetry Configuration'), '100%', true, '3', 'center', '');

	echo '<tr><td colspan="2">';
	echo '<table class="cactiTable" style="width:100%">';

	if (function_exists('gnmi_render_device_form_section')) {
		gnmi_render_device_form_section($host_id, $gnmi_settings, [], $device_hostname);
	}

	echo '</table>';
	echo '</td></tr>';

	html_end_box(true, true);
}

/**
 * Device save hook (Phase 3.1) - processes gNMI form data when device is saved
 *
 * Called by Cacti's host_save hook after device is successfully saved via api_device_save() in host.php.
 * This hook receives the host_id in an array parameter.
 *
 * @param array $args - Array containing 'host_id' key with the device host_id
 */
function gnmi_save_device_form($args) {
	global $config;

	// Extract host_id from the args array passed by Cacti's host_save hook
	$host_id = isset($args['host_id']) ? $args['host_id'] : 0;

	// Skip processing for new devices
	if ($host_id == 0) {
		return;
	}

	// Log hook execution for debugging
	cacti_log("gNMI: Hook gnmi_save_device_form() called for host_id=$host_id", false, 'GNMI', POLLER_VERBOSITY_LOW);

	// Import the form functions
	include_once($config['base_path'] . '/plugins/gnmi/include/form_functions.php');
	if (!function_exists('gnmi_validate_csrf_request')) {
		include_once($config['base_path'] . '/plugins/gnmi/include/functions.php');
	}

	// Process the save
	gnmi_process_device_save($host_id);
}

/**
 * Ignore a cached pre-beta.3 subscription-action hook without mutating state.
 *
 * @param array $args Legacy hook arguments (unused)
 * @return void
 */
function gnmi_process_subscription_actions($args) {
	// Compatibility shim for a cached pre-beta.3 host_edit_top hook. Mutation
	// requests are intentionally ignored; ajax_handler.php is the only entry.
	cacti_log(
		'gNMI: Ignored obsolete host_edit_top subscription action hook',
		false,
		'PLUGIN',
		POLLER_VERBOSITY_DEBUG
	);
}

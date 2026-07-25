<?php
/**
 * gNMI Device Form Functions (Phase 3.1)
 *
 * Provides form rendering and processing for gNMI configuration
 * integrated into Cacti's device edit form.
 */

/**
 * Return a sorted list of certificate or key files from the protected runtime certs directory.
 *
 * Scans plugins/gnmi/runtime/certs/ by default (or GNMI_RUNTIME_DIR/certs)
 * and returns full absolute paths filtered by type:
 *   'cert' → .pem, .crt, .cer
 *   'key'  → .pem, .key
 *
 * @param string $type 'cert' or 'key'
 * @return array Sorted, unique list of absolute file paths (empty if dir absent)
 */
function gnmi_list_cert_files($type) {
	global $config;
	$cert_dir = function_exists('gnmi_get_certs_dir')
		? gnmi_get_certs_dir() . '/'
		: $config['base_path'] . '/plugins/gnmi/runtime/certs/';
	if (!is_dir($cert_dir)) return [];
	$exts = ($type === 'key') ? ['pem', 'key'] : ['pem', 'crt', 'cer'];
	$files = [];
	foreach ($exts as $ext) {
		$found = glob($cert_dir . '*.' . $ext);
		if ($found) $files = array_merge($files, $found);
	}
	sort($files);
	return array_unique($files);
}

/**
 * Render a hybrid dropdown + text input for a TLS file path field.
 *
 * Emits a <select> (no name attr, UI only) listing discovered files plus a
 * manual path option, and a single text <input name="$name"> that is
 * always submitted as the actual form value.
 *
 * Behaviour:
 *  - Current value in $files → dropdown pre-selects it; text input hidden (value pre-set)
 *  - Current value not in $files → manual path input visible
 *  - Current value empty → dropdown shows placeholder; text input hidden (empty value)
 *
 * The text input is always in the DOM and always submitted. JS keeps its value
 * in sync when the user changes the dropdown selection.
 *
 * @param string $id            HTML id prefix; select gets "{$id}_select", text gets "{$id}_text"
 * @param string $name          name attribute for the text <input> (the POST field)
 * @param string $current_value Saved path value (may be empty)
 * @param array  $files         Absolute paths returned by gnmi_list_cert_files()
 */
function gnmi_render_cert_field($id, $name, $current_value, $files) {
	$current_value = (string)$current_value;
	$in_list       = in_array($current_value, $files, true);
	$is_custom     = ($current_value !== '' && !$in_list);

	// Dropdown pre-selected value (empty string = placeholder)
	$select_val      = $in_list ? $current_value : '';

	// Checkbox pre-checked when current value is a custom (non-list) path
	$cb_checked      = $is_custom ? 'checked' : '';

	// Dropdown wrapper hidden in custom mode.
	// NOTE: jQuery UI Selectmenu hides the native <select> and inserts a
	// .ui-selectmenu-button sibling right after it — both end up inside this
	// wrapper span, so toggling the wrapper hides/shows both together.
	$dropdown_style  = $is_custom ? 'display:none;' : 'display:inline-block;';

	// Text wrap visible only in custom mode.
	$text_style      = $is_custom ? 'display:inline-block; margin-top:4px;' : 'display:none; margin-top:4px;';

	$escaped_id      = html_escape($id);
	$escaped_name    = html_escape($name);
	$js_id           = addslashes($id);
	?>
	<span id="<?php echo $escaped_id; ?>_dropdown_wrap" style="<?php echo $dropdown_style; ?>">
		<select id="<?php echo $escaped_id; ?>_select"
		        onchange="gnmiCertSelectChange('<?php echo $js_id; ?>')">
			<option value="" <?php echo ($select_val === '') ? 'selected' : ''; ?> disabled>
				(select from certs/ directory)
			</option>
			<?php foreach ($files as $file): ?>
				<option value="<?php echo html_escape($file); ?>"
				        <?php echo ($select_val === $file) ? 'selected' : ''; ?>>
					<?php echo html_escape(basename($file)); ?>
				</option>
			<?php endforeach; ?>
		</select>
	</span>
	<label style="margin-left:8px; font-weight:normal; cursor:pointer;">
		<input type="checkbox" id="<?php echo $escaped_id; ?>_cb"
		       name="gnmi_<?php echo $escaped_id; ?>_custom"
		       onchange="gnmiCertToggleCustom('<?php echo $js_id; ?>')"
		       <?php echo $cb_checked; ?>>
		Enter path under certs/
	</label>
	<!--
	  Single text input — always in DOM, always submitted as the POST field.
	  Dropdown mode: hidden, value synced from select onchange.
	  Manual mode: visible, user types an absolute path beneath runtime/certs/.
	-->
	<span id="<?php echo $escaped_id; ?>_text_wrap" style="<?php echo $text_style; ?>">
		<input type="text" id="<?php echo $escaped_id; ?>_text"
		       name="<?php echo $escaped_name; ?>"
		       size="50"
		       value="<?php echo html_escape($current_value); ?>"
		       placeholder="/path/to/cacti/plugins/gnmi/runtime/certs/file.pem">
	</span>
	<?php
}

/**
 * Validate gNMI form input from $_POST
 *
 * Checks required fields, numeric ranges, and formats.
 * Returns array of error messages (empty if valid).
 *
 * @param array $post_data - Form data (typically $_POST)
 * @return array - Array of error messages, empty if valid
 */
function gnmi_validate_form_input($post_data) {
	$errors = [];

	// Check required fields
	if (empty($post_data['gnmi_hostname'])) {
		$errors[] = 'gNMI Hostname is required';
	}

	if (empty($post_data['gnmi_port'])) {
		$errors[] = 'gNMI Port is required';
	} else if (!is_numeric($post_data['gnmi_port'])) {
		$errors[] = 'gNMI Port must be a valid number';
	} else {
		$port = intval($post_data['gnmi_port']);
		if ($port < 1 || $port > 65535) {
			$errors[] = 'gNMI Port must be between 1 and 65535';
		}
	}

	if (empty($post_data['gnmi_username'])) {
		$errors[] = 'Username is required';
	}

	if (empty($post_data['gnmi_password'])) {
		$errors[] = 'Password is required';
	}

	// Validate optional numeric fields if provided
	if (!empty($post_data['collection_interval'])) {
		if (!is_numeric($post_data['collection_interval'])) {
			$errors[] = 'Collection Interval must be a valid number';
		} else {
			$interval = intval($post_data['collection_interval']);
			if ($interval < 5 || $interval > 300) {
				$errors[] = 'Collection Interval must be between 5 and 300 seconds';
			}
		}
	}

	$compatibility_mode = $post_data['compatibility_mode'] ?? 'standard';
	if (!in_array($compatibility_mode, ['standard', 'ciena_saos10'], true)) {
		$errors[] = 'Compatibility Mode is invalid';
	}

	// This module is loaded directly by Cacti's host-save hook and by standalone
	// harnesses. Load the shared path guard at the point it is needed instead of
	// relying on a particular plugin include order.
	if (!function_exists('gnmi_certificate_path_is_allowed')) {
		require_once(__DIR__ . '/functions.php');
	}

	$certificate_fields = array(
		'ca_cert_path' => 'CA certificate',
		'client_key_path' => 'client key',
		'client_cert_path' => 'client certificate',
	);
	foreach ($certificate_fields as $field => $label) {
		$path = isset($post_data[$field]) ? trim((string)$post_data[$field]) : '';
		if (!gnmi_certificate_path_is_allowed($path)) {
			$errors[] = ucfirst($label) . ' must be an existing file under the protected gNMI runtime certs directory';
		}
	}

	return $errors;
}

/**
 * Normalize the optional vendor compatibility selector to a safe value.
 * Unknown or missing values always use standards-compliant gNMI behavior.
 *
 * @param mixed $value Submitted or stored compatibility mode.
 * @return string 'standard' or 'ciena_saos10'.
 */
function gnmi_normalize_compatibility_mode($value) {
	return ($value === 'ciena_saos10') ? 'ciena_saos10' : 'standard';
}

/**
 * Select the wire encoding required by the active compatibility mode.
 *
 * Ciena SAOS 10 accepts the legacy JSON encoding for sampled telemetry but can
 * acknowledge a JSON_IETF subscription without emitting counter updates.
 * Standards-compliant targets continue to use JSON_IETF.
 *
 * @param mixed $compatibility_mode Submitted or stored compatibility mode.
 * @return string 'JSON' for Ciena SAOS 10, otherwise 'JSON_IETF'.
 */
function gnmi_encoding_for_compatibility_mode($compatibility_mode) {
	return gnmi_normalize_compatibility_mode($compatibility_mode) === 'ciena_saos10'
		? 'JSON'
		: 'JSON_IETF';
}

/**
 * Preserve a submitted gNMI credential exactly as entered.
 *
 * Credentials are opaque protocol values, not search terms or HTML. Applying
 * sanitize_search_string() here removes valid punctuation (for example the
 * trailing "!" in SR Linux's Containerlab password) and breaks authentication.
 * Prepared database statements provide the required SQL safety.
 *
 * @param mixed $value Submitted username or password.
 * @return string Credential value without lossy filtering.
 */
function gnmi_preserve_credential($value) {
	return is_string($value) ? $value : (string)$value;
}

/**
 * Resolve the hostname and source that should be persisted for a form save.
 *
 * @param string $hostname_source Requested source mode.
 * @param string $gnmi_hostname Submitted gNMI-specific hostname.
 * @param string $device_hostname Current Cacti device hostname.
 * @return array Resolved hostname and normalized source.
 */
function gnmi_resolve_hostname($hostname_source, $gnmi_hostname, $device_hostname) {
	$hostname_source = ($hostname_source === 'custom') ? 'custom' : 'device';
	$hostname = ($hostname_source === 'custom') ? $gnmi_hostname : $device_hostname;

	return [
		'hostname' => trim((string)$hostname),
		'hostname_source' => $hostname_source,
	];
}

/**
 * Detect which configuration fields changed between database and form POST.
 *
 * Compares existing device record from database with new values from form
 * submission to determine if daemon restart is needed.
 *
 * @param array $existing_device Device record from database
 * @param array $post_data Form POST data ($_POST)
 * @return array List of field names that changed (empty if no changes)
 *               Example: ['hostname', 'port', 'ca_cert_path']
 */
function gnmi_detect_config_changes($existing_device, $post_data) {
	$changed_fields = [];

	// Define fields that require daemon restart if changed
	// Maps database field name => POST field name
	$monitored_fields = [
		'hostname' => 'gnmi_hostname',
		'port' => 'gnmi_port',
		'username' => 'gnmi_username',
		'password' => 'gnmi_password',
		'use_tls' => 'use_tls',
		'ca_cert_path' => 'ca_cert_path',
		'client_key_path' => 'client_key_path',
		'client_cert_path' => 'client_cert_path',
		'tls_override' => 'tls_override',
		'skip_verify' => 'skip_verify',
		'collection_interval' => 'collection_interval',
		'compatibility_mode' => 'compatibility_mode',
		'encoding' => 'encoding'
	];

	foreach ($monitored_fields as $db_field => $post_field) {
		$old_value = $existing_device[$db_field] ?? '';
		$new_value = $post_data[$post_field] ?? '';

		// Normalize for comparison based on field type
		if ($db_field === 'port' || $db_field === 'collection_interval') {
			// Numeric fields
			$old_value = intval($old_value);
			$new_value = intval($new_value);
		} elseif ($db_field === 'use_tls' || $db_field === 'skip_verify') {
			// Boolean fields (checkboxes)
			$old_value = (bool)$old_value;
			// Checkbox: present in POST if checked, absent if unchecked
			$new_value = isset($post_data[$post_field]) && $post_data[$post_field] == 1;
		} elseif ($db_field === 'compatibility_mode') {
			$old_value = gnmi_normalize_compatibility_mode($old_value);
			$new_value = gnmi_normalize_compatibility_mode($new_value);
		} else {
			// String fields - trim for comparison
			$old_value = trim($old_value);
			$new_value = trim($new_value);
		}

		// Compare normalized values
		if ($old_value != $new_value) {
			$changed_fields[] = $db_field;
		}
	}

	return $changed_fields;
}

/**
 * Process gNMI device save from form submission
 *
 * Validates input, inserts/updates plugin_gnmi_device_settings record,
 * and signals daemon to reload configuration.
 *
 * @param int $host_id - Cacti host.id being saved
 * @return bool - Success status
 */
function gnmi_process_device_save($host_id) {
	global $config;

	// Debug: Log what we received in POST
	$gnmi_enabled_value = isset($_POST['gnmi_enabled']) ? $_POST['gnmi_enabled'] : 'NOT SET';
	cacti_log("gNMI DEBUG: gnmi_enabled in POST = '$gnmi_enabled_value' for host_id=$host_id", false, 'gnmi', POLLER_VERBOSITY_DEBUG);

	// Check if gNMI is enabled in form
	if (!isset($_POST['gnmi_enabled']) || $_POST['gnmi_enabled'] != 1) {
		// BEFORE deleting record, stop the daemon gracefully
		$device = db_fetch_row_prepared(
			'SELECT id FROM plugin_gnmi_devices WHERE host_id = ?',
			array($host_id)
		);

		if (!empty($device)) {
			$device_id = $device['id'];

			// Include functions.php for gnmi_stop_daemon()
			global $config;
			if (!function_exists('gnmi_stop_daemon')) {
				include_once($config['base_path'] . '/plugins/gnmi/include/functions.php');
			}

			// Stop daemon gracefully
			cacti_log("gNMI: Stopping daemon for device_id=$device_id (gNMI disabled for host_id=$host_id)", false, 'gnmi');
			gnmi_stop_daemon($device_id);
		}

		// gNMI not enabled - delete from consolidated table
		db_execute_prepared(
			'DELETE FROM plugin_gnmi_devices WHERE host_id = ?',
			array($host_id)
		);
		cacti_log("gNMI disabled for host_id=$host_id", false, 'gnmi');
		return true;
	}

	$existing_device = db_fetch_row_prepared(
		'SELECT * FROM plugin_gnmi_devices WHERE host_id = ?',
		array($host_id)
	);

	$device_hostname = db_fetch_cell_prepared(
		'SELECT hostname FROM host WHERE id = ?',
		array($host_id)
	);

	$default_hostname_source = !empty($existing_device['hostname_source'])
		? $existing_device['hostname_source']
		: 'device';
	$requested_hostname_source = $_POST['gnmi_hostname_source'] ?? $default_hostname_source;
	$resolved_hostname = gnmi_resolve_hostname(
		$requested_hostname_source,
		$_POST['gnmi_hostname'] ?? '',
		$device_hostname
	);

	$resolved_post_data = $_POST;
	$resolved_post_data['gnmi_hostname'] = $resolved_hostname['hostname'];
	$resolved_post_data['gnmi_hostname_source'] = $resolved_hostname['hostname_source'];
	$resolved_post_data['encoding'] = gnmi_encoding_for_compatibility_mode(
		$resolved_post_data['compatibility_mode'] ?? 'standard'
	);

	// Validate required fields
	$errors = gnmi_validate_form_input($resolved_post_data);

	if (!empty($errors)) {
		// Log validation errors
		foreach ($errors as $error) {
			cacti_log("gNMI validation error for host_id=$host_id: $error", false, 'gnmi');
		}
		raise_message('gnmi_validation_error', __('gNMI Configuration Error: %s', implode('; ', $errors)), MESSAGE_LEVEL_ERROR);
		return false;
	}

	// Prepare settings for insert/update in consolidated table
	$gnmi_settings = [
		'host_id' => $host_id,
		'enabled' => 1,
		'hostname' => sanitize_search_string($resolved_post_data['gnmi_hostname']),
		'hostname_source' => $resolved_post_data['gnmi_hostname_source'],
		'port' => intval($_POST['gnmi_port']),
		'username' => gnmi_preserve_credential($_POST['gnmi_username']),
		'password' => gnmi_preserve_credential($_POST['gnmi_password']),
		'use_tls' => isset($_POST['use_tls']) ? 1 : 0,
		'ca_cert_path' => sanitize_search_string($_POST['ca_cert_path'] ?? ''),
		'client_key_path' => sanitize_search_string($_POST['client_key_path'] ?? ''),
		'client_cert_path' => sanitize_search_string($_POST['client_cert_path'] ?? ''),
		'tls_override' => sanitize_search_string($_POST['tls_override'] ?? ''),
		'skip_verify' => isset($_POST['skip_verify']) ? 1 : 0,
		'collection_interval' => intval($_POST['collection_interval'] ?? 10),
		'compatibility_mode' => gnmi_normalize_compatibility_mode($_POST['compatibility_mode'] ?? 'standard'),
		'encoding' => $resolved_post_data['encoding']
	];

	if (!empty($existing_device)) {
		// BEFORE updating database, detect config changes
		$changed_fields = gnmi_detect_config_changes($existing_device, $resolved_post_data);

		// UPDATE existing record
		$update_sql = 'UPDATE plugin_gnmi_devices SET ' .
			'enabled = ?, hostname = ?, hostname_source = ?, port = ?, username = ?, password = ?, ' .
			'use_tls = ?, ca_cert_path = ?, client_key_path = ?, ' .
			'client_cert_path = ?, tls_override = ?, skip_verify = ?, compatibility_mode = ?, ' .
			'collection_interval = ?, encoding = ?, modified_on = NOW() ' .
			'WHERE host_id = ?';

		db_execute_prepared($update_sql, array(
			$gnmi_settings['enabled'],
			$gnmi_settings['hostname'],
			$gnmi_settings['hostname_source'],
			$gnmi_settings['port'],
			$gnmi_settings['username'],
			$gnmi_settings['password'],
			$gnmi_settings['use_tls'],
			$gnmi_settings['ca_cert_path'],
			$gnmi_settings['client_key_path'],
			$gnmi_settings['client_cert_path'],
			$gnmi_settings['tls_override'],
			$gnmi_settings['skip_verify'],
			$gnmi_settings['compatibility_mode'],
			$gnmi_settings['collection_interval'],
			$gnmi_settings['encoding'],
			$host_id
		));

		cacti_log(
			"gNMI settings updated for host_id=$host_id: " .
			"hostname={$gnmi_settings['hostname']}, port={$gnmi_settings['port']}, " .
			"use_tls={$gnmi_settings['use_tls']}, collection_interval={$gnmi_settings['collection_interval']}",
			false,
			'gnmi'
		);

		// AFTER update, restart daemon if config changed
		if (!empty($changed_fields)) {
			// Include functions.php for gnmi_restart_daemon_by_host_id()
			global $config;
			if (!function_exists('gnmi_restart_daemon_by_host_id')) {
				include_once($config['base_path'] . '/plugins/gnmi/include/functions.php');
			}

			cacti_log(
				"gNMI: Config changed for host_id=$host_id (fields: " .
				implode(', ', $changed_fields) . "), restarting daemon",
				false,
				'gnmi',
				POLLER_VERBOSITY_MEDIUM
			);

			gnmi_restart_daemon_by_host_id($host_id);
		}
	} else {
		// INSERT new record into consolidated table
		$insert_sql = 'INSERT INTO plugin_gnmi_devices (' .
			'host_id, enabled, hostname, hostname_source, port, username, password, ' .
			'use_tls, ca_cert_path, client_key_path, client_cert_path, tls_override, skip_verify, compatibility_mode, ' .
			'collection_interval, encoding, created_on' .
			') VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())';

		db_execute_prepared($insert_sql, array(
			$gnmi_settings['host_id'],
			$gnmi_settings['enabled'],
			$gnmi_settings['hostname'],
			$gnmi_settings['hostname_source'],
			$gnmi_settings['port'],
			$gnmi_settings['username'],
			$gnmi_settings['password'],
			$gnmi_settings['use_tls'],
			$gnmi_settings['ca_cert_path'],
			$gnmi_settings['client_key_path'],
			$gnmi_settings['client_cert_path'],
			$gnmi_settings['tls_override'],
			$gnmi_settings['skip_verify'],
			$gnmi_settings['compatibility_mode'],
			$gnmi_settings['collection_interval'],
			$gnmi_settings['encoding']
		));

		cacti_log(
			"gNMI settings created for host_id=$host_id: " .
			"hostname={$gnmi_settings['hostname']}, port={$gnmi_settings['port']}, " .
			"use_tls={$gnmi_settings['use_tls']}, collection_interval={$gnmi_settings['collection_interval']}",
			false,
			'gnmi'
		);
	}

	// Note: plugin_gnmi_devices now handles all configuration (Phase 2.7 consolidated)
	// Daemon will be notified of changes via poller hook on next cycle

	return true;
}

/**
 * Get daemon status for a host
 *
 * Queries database for daemon health information.
 * Returns array with status and last check time.
 *
 * @param int $host_id - Cacti host.id
 * @return array - Status info ['status' => 'running'|'unknown', 'last_check' => timestamp]
 */
function gnmi_get_daemon_status($host_id) {
	// Note: plugin_gnmi_devices uses device_id not host_id, so we need to query differently
	// For now, just return a simple status based on whether device exists and is enabled
	$daemon = db_fetch_row_prepared(
		'SELECT id, enabled, last_poll_time, last_poll_status
		 FROM plugin_gnmi_devices
		 WHERE host_id = ?',
		array($host_id)
	);

	if (empty($daemon)) {
		return ['status' => 'not_configured', 'last_check' => 'N/A'];
	}

	// Determine status based on enabled flag and last poll
	$status = 'unknown';
	if ($daemon['enabled'] == 1 && $daemon['last_poll_status'] == 'success') {
		$status = 'running';
	} else if ($daemon['enabled'] == 1) {
		$status = 'enabled';
	} else {
		$status = 'disabled';
	}

	return [
		'status' => $status,
		'last_check' => $daemon['last_poll_time'] ?? 'Never'
	];
}

/**
 * Render gNMI configuration section in device edit form
 *
 * Displays checkbox to enable/disable, then conditionally shows
 * connection settings, TLS options, metric groups, and daemon status.
 *
 * @param int $host_id - Cacti host.id being edited (0 for new)
 * @param array $gnmi_settings - Existing settings or empty array
 * @param array $metric_groups - Available metric groups
 * @param string $device_hostname - Current Cacti device hostname
 */
function gnmi_render_device_form_section($host_id, $gnmi_settings = [], $metric_groups = [], $device_hostname = '') {
	global $config;

	// Ensure $gnmi_settings is an array
	if (!is_array($gnmi_settings)) {
		$gnmi_settings = [];
	}

	// Set defaults for new devices
	$gnmi_settings = array_merge([
		'enabled' => 0,
		'hostname' => '',
		'hostname_source' => 'device',
		'port' => 9339,
		'username' => '',
		'password' => '',
		'use_tls' => 1,
		'ca_cert_path' => '',
		'client_key_path' => '',
		'client_cert_path' => '',
		'tls_override' => '',
		'skip_verify' => 0,
		'compatibility_mode' => 'standard',
		'collection_interval' => 10,
	], $gnmi_settings);

	$resolved_hostname = gnmi_resolve_hostname(
		$gnmi_settings['hostname_source'],
		$gnmi_settings['hostname'],
		$device_hostname
	);
	$hostname_source = $resolved_hostname['hostname_source'];
	$display_hostname = $resolved_hostname['hostname'];
	$reset_display = ($hostname_source === 'custom') ? 'inline' : 'none';

	$gnmi_display = (!empty($gnmi_settings) && $gnmi_settings['enabled']) ? '' : 'display: none;';
	$tls_display = 'display: none;';
	?>
	<!-- ===== gNMI TELEMETRY SECTION ===== -->

	<!-- Enable gNMI Checkbox -->
	<tr>
		<td class="textEditTitle">
			<label for="gnmi_enabled">Enable gNMI Telemetry</label>
		</td>
		<td>
			<input type="checkbox" id="gnmi_enabled" name="gnmi_enabled"
				   value="1" <?php echo ($gnmi_settings['enabled'] ? 'checked' : ''); ?>
				   onchange="toggleGnmiSettings()">
			<span class="textInfo" style="margin-left: 10px;">
				Check to enable high-frequency telemetry collection from this device
			</span>
		</td>
	</tr>

	<!-- Connection Settings Section -->
	<tr class="gnmi-setting-row tableHeader" style="<?php echo $gnmi_display; ?>">
		<td colspan="2" class="tableSubHeaderColumn">Connection Settings</td>
	</tr>

	<tr class="gnmi-setting-row" style="<?php echo $gnmi_display; ?>">
		<td class="textEditTitle" style="padding-left: 30px;">
			<label for="gnmi_hostname">gNMI Hostname/IP *</label>
		</td>
		<td>
			<input type="text" id="gnmi_hostname" name="gnmi_hostname"
				   size="40" value="<?php echo html_escape($display_hostname); ?>"
				   oninput="gnmiMarkHostnameCustom()" required>
			<input type="hidden" id="gnmi_hostname_source" name="gnmi_hostname_source"
				   value="<?php echo html_escape($hostname_source); ?>">
			<input type="hidden" id="gnmi_original_hostname_source" name="gnmi_original_hostname_source"
				   value="<?php echo html_escape($hostname_source); ?>">
			<input type="hidden" id="gnmi_original_hostname" name="gnmi_original_hostname"
				   value="<?php echo html_escape($display_hostname); ?>">
			<input type="hidden" id="gnmi_original_device_hostname" name="gnmi_original_device_hostname"
				   value="<?php echo html_escape($device_hostname); ?>">
			<span class="textInfo">Usually same as device hostname, can differ</span>
			<button type="button" id="gnmi_use_device_hostname"
					class="linkEditMain"
					style="background:none;border:none;padding:0;margin-left:8px;cursor:pointer;display:<?php echo $reset_display; ?>;"
					onclick="gnmiUseDeviceHostname()">Use device hostname</button>
		</td>
	</tr>

	<tr class="gnmi-setting-row" style="<?php echo $gnmi_display; ?>">
		<td class="textEditTitle" style="padding-left: 30px;">
			<label for="gnmi_port">gNMI Port *</label>
		</td>
		<td>
			<input type="number" id="gnmi_port" name="gnmi_port"
				   min="1" max="65535" value="<?php echo $gnmi_settings['port'] ?? 9339; ?>" required>
			<span class="textInfo">Default: 9339</span>
		</td>
	</tr>

	<tr class="gnmi-setting-row" style="<?php echo $gnmi_display; ?>">
		<td class="textEditTitle" style="padding-left: 30px;">
			<label for="gnmi_username">Username *</label>
		</td>
		<td>
			<input type="text" id="gnmi_username" name="gnmi_username"
				   size="40" value="<?php echo html_escape($gnmi_settings['username'] ?? ''); ?>" required>
		</td>
	</tr>

	<tr class="gnmi-setting-row" style="<?php echo $gnmi_display; ?>">
		<td class="textEditTitle" style="padding-left: 30px;">
			<label for="gnmi_password">Password *</label>
		</td>
		<td>
			<input type="password" id="gnmi_password" name="gnmi_password"
				   size="40" value="<?php echo html_escape($gnmi_settings['password'] ?? ''); ?>" required>
			<span class="textInfo" style="color: #ff6600;">Stored in plaintext. Use a dedicated, least-privilege telemetry account.</span>
		</td>
	</tr>

	<tr class="gnmi-setting-row" style="<?php echo $gnmi_display; ?>">
		<td class="textEditTitle" style="padding-left: 30px;">
			<label for="collection_interval">Collection Interval (seconds)</label>
		</td>
		<td>
			<input type="number" id="collection_interval" name="collection_interval"
				   min="5" max="300" value="<?php echo $gnmi_settings['collection_interval'] ?? 10; ?>">
			<span class="textInfo">Default: 10 seconds. Must match Cacti polling interval.</span>
		</td>
	</tr>

	<tr class="gnmi-setting-row" style="<?php echo $gnmi_display; ?>">
		<td class="textEditTitle" style="padding-left: 30px;">
			<label for="compatibility_mode">Compatibility Mode</label>
		</td>
		<td>
			<select id="compatibility_mode" name="compatibility_mode">
				<option value="standard" <?php echo ($gnmi_settings['compatibility_mode'] === 'standard') ? 'selected' : ''; ?>>Standard gNMI</option>
				<option value="ciena_saos10" <?php echo ($gnmi_settings['compatibility_mode'] === 'ciena_saos10') ? 'selected' : ''; ?>>Ciena SAOS 10.x</option>
			</select>
			<span class="textInfo">Use Ciena mode only for SAOS 10.x targets that do not answer Capabilities RPCs.</span>
		</td>
	</tr>

	<tr class="gnmi-setting-row" style="<?php echo $gnmi_display; ?>">
		<td class="textEditTitle" style="padding-left: 30px;"></td>
		<td>
			<?php if (!empty($gnmi_settings['id'])): ?>
			<button type="button"
			        onclick="gnmiTestConnection(<?php echo (int)$host_id; ?>, <?php echo (int)$gnmi_settings['id']; ?>)"
			        class="linkEditMain" style="background:none;border:none;padding:0;cursor:pointer;">[Test Connection]</button>
			<?php else: ?>
			<span class="textInfo" style="color:#888; font-style:italic;">Save device first to enable connection test.</span>
			<?php endif; ?>
		</td>
	</tr>

	<!-- TLS/Advanced Settings (Collapsible) -->
	<tr class="gnmi-setting-row tableHeader" style="<?php echo $gnmi_display; ?>">
		<td colspan="2" class="tableSubHeaderColumn">
			<a href="#" onclick="toggleTlsSettings(); return false;" style="text-decoration: none; color: inherit;">
				+ TLS / Advanced Settings
			</a>
		</td>
	</tr>

	<tr class="gnmi-setting-row tls-setting-row" style="<?php echo $gnmi_display; ?> <?php echo $tls_display; ?>">
		<td class="textEditTitle" style="padding-left: 30px;">
			<label for="use_tls">
				<input type="checkbox" id="use_tls" name="use_tls"
					   value="1" <?php echo ($gnmi_settings['use_tls'] ? 'checked' : ''); ?>>
				Use TLS
			</label>
		</td>
		<td>
			<span class="textInfo">Enable TLS for secure gNMI connection</span>
		</td>
	</tr>

	<tr class="gnmi-setting-row tls-setting-row" style="<?php echo $gnmi_display; ?> <?php echo $tls_display; ?>">
		<td class="textEditTitle" style="padding-left: 30px;">
			<label for="ca_cert_path_select">CA Certificate Path</label>
		</td>
		<td>
			<?php gnmi_render_cert_field(
				'ca_cert_path', 'ca_cert_path',
				$gnmi_settings['ca_cert_path'],
				gnmi_list_cert_files('cert')
			); ?>
			<span class="textInfo">Server certificate verification (.pem/.crt/.cer)</span>
		</td>
	</tr>

	<tr class="gnmi-setting-row tls-setting-row" style="<?php echo $gnmi_display; ?> <?php echo $tls_display; ?>">
		<td class="textEditTitle" style="padding-left: 30px;">
			<label for="client_key_path_select">Client Key Path</label>
		</td>
		<td>
			<?php gnmi_render_cert_field(
				'client_key_path', 'client_key_path',
				$gnmi_settings['client_key_path'],
				gnmi_list_cert_files('key')
			); ?>
			<span class="textInfo">Client private key for mTLS (.pem/.key)</span>
		</td>
	</tr>

	<tr class="gnmi-setting-row tls-setting-row" style="<?php echo $gnmi_display; ?> <?php echo $tls_display; ?>">
		<td class="textEditTitle" style="padding-left: 30px;">
			<label for="client_cert_path_select">Client Certificate Path</label>
		</td>
		<td>
			<?php gnmi_render_cert_field(
				'client_cert_path', 'client_cert_path',
				$gnmi_settings['client_cert_path'],
				gnmi_list_cert_files('cert')
			); ?>
			<span class="textInfo">Client certificate for mTLS (.pem/.crt/.cer)</span>
		</td>
	</tr>

	<tr class="gnmi-setting-row tls-setting-row" style="<?php echo $gnmi_display; ?> <?php echo $tls_display; ?>">
		<td class="textEditTitle" style="padding-left: 30px;">
			<label for="tls_override">TLS Server Name Override</label>
		</td>
		<td>
			<input type="text" id="tls_override" name="tls_override"
				   size="40" value="<?php echo html_escape($gnmi_settings['tls_override']); ?>">
			<span class="textInfo">Override server name in certificate validation (for lab environments)</span>
		</td>
	</tr>

	<tr class="gnmi-setting-row tls-setting-row" style="<?php echo $gnmi_display; ?> <?php echo $tls_display; ?>">
		<td class="textEditTitle" style="padding-left: 30px;">
			<label for="skip_verify">
				<input type="checkbox" id="skip_verify" name="skip_verify"
					   value="1" <?php echo ($gnmi_settings['skip_verify'] ? 'checked' : ''); ?>>
				Skip TLS Verification
			</label>
		</td>
		<td>
			<span class="textInfo" style="color: #ff0000;">Not recommended for production</span>
		</td>
	</tr>

	<!-- Metric Group Selection -->
	<tr class="gnmi-setting-row tableHeader" style="<?php echo $gnmi_display; ?>">
		<td colspan="2" class="tableSubHeaderColumn">Data Collection Groups</td>
	</tr>

	<tr class="gnmi-setting-row" style="<?php echo $gnmi_display; ?>">
		<td class="textEditTitle" style="padding-left: 30px;" colspan="2">
			<span class="textInfo">Select which telemetry groups to collect from this device:</span>
		</td>
	</tr>

	<?php if (!empty($metric_groups)): ?>
		<?php foreach ($metric_groups as $group): ?>
			<tr class="gnmi-setting-row" style="<?php echo $gnmi_display; ?>">
				<td style="padding-left: 50px;">
					<label>
						<input type="checkbox" name="metric_groups[]"
							   value="<?php echo $group['id']; ?>">
						<strong><?php echo html_escape($group['name']); ?></strong>
					</label>
				</td>
				<td>
					<span class="textInfo"><?php echo html_escape($group['description']); ?></span>
				</td>
			</tr>
		<?php endforeach; ?>
	<?php else: ?>
		<tr class="gnmi-setting-row" style="<?php echo $gnmi_display; ?>">
			<td class="textEditTitle" style="padding-left: 50px;" colspan="2">
				<span class="textInfo">No metric groups available. Check plugin installation.</span>
			</td>
		</tr>
	<?php endif; ?>

	<!-- Phase 3.3: Subscriptions & Metrics Management -->
	<?php if ($host_id > 0 && !empty($gnmi_settings) && $gnmi_settings['enabled']): ?>
		<tr class="gnmi-setting-row tableHeader" style="<?php echo $gnmi_display; ?>">
			<td colspan="2" class="tableSubHeaderColumn">Subscriptions &amp; Metrics</td>
		</tr>

		<tr class="gnmi-setting-row" style="<?php echo $gnmi_display; ?>">
			<td colspan="2" style="padding: 10px;">
				<?php
				// Get device ID for this host
				$device_id = db_fetch_cell_prepared(
					'SELECT id FROM plugin_gnmi_devices WHERE host_id = ?',
					array($host_id)
				);

				if ($device_id) {
					// Include subscription functions and display functions
					require_once($config['base_path'] . '/plugins/gnmi/include/subscription_functions.php');
					require_once($config['base_path'] . '/plugins/gnmi/include/subscription_display.php');

					// Render subscription management section
					gnmi_render_subscription_section($device_id, $host_id);
				} else {
					echo '<div class="textInfo">Save device configuration first to manage subscriptions.</div>';
				}
				?>
			</td>
		</tr>
	<?php endif; ?>

	<!-- Daemon Status (Information Only) -->
	<tr class="gnmi-setting-row tableHeader" style="<?php echo $gnmi_display; ?>">
		<td colspan="2" class="tableSubHeaderColumn">Daemon Status</td>
	</tr>

	<tr class="gnmi-setting-row" style="<?php echo $gnmi_display; ?>">
		<td class="textEditTitle" style="padding-left: 30px;">
			Status
		</td>
		<td>
			<span id="daemon_status_display">
				<?php
				// Check if daemon is running for this device
				if ($host_id > 0) {
					$daemon_status = gnmi_get_daemon_status($host_id);
					echo $daemon_status['status'] === 'running'
						? '<span style="color: green;">Connected</span>'
						: '<span style="color: orange;">Not Running</span>';
					echo ' (Last check: ' . ($daemon_status['last_check'] ?? 'Never') . ')';
				} else {
					echo '<span class="textInfo">Status available after device is saved</span>';
				}
				?>
			</span>
		</td>
	</tr>

	<?php if ($host_id > 0): ?>
		<tr class="gnmi-setting-row" style="<?php echo $gnmi_display; ?>">
			<td class="textEditTitle" style="padding-left: 30px;"></td>
			<td>
				<button type="button" class="ui-button ui-corner-all ui-widget"
						onclick="gnmi_restart_daemon(<?php echo $host_id; ?>)">
					Restart Daemon
				</button>
			</td>
		</tr>
	<?php endif; ?>

	<!-- gNMI Test Connection Modal -->
	<div id="gnmi-test-modal"
	     style="display:none; position:fixed; top:0; left:0; width:100%; height:100%;
	            background:rgba(0,0,0,0.5); z-index:9999; align-items:center; justify-content:center;">
		<div style="background:#fff; border:1px solid #ccc; border-radius:4px;
		            width:560px; max-width:95vw; box-shadow:0 4px 20px rgba(0,0,0,0.3);">
			<div style="background:#1a1a2e; color:#fff; padding:10px 16px;
			            display:flex; align-items:center; justify-content:space-between;
			            border-radius:4px 4px 0 0;">
				<span style="font-weight:bold;">gNMI Connection Test</span>
				<a href="javascript:void(0)" onclick="gnmiTestModalClose()"
				   style="color:#fff; text-decoration:none; font-size:18px;">&times;</a>
			</div>
			<div id="gnmi-test-output"
			     style="font-family:monospace; font-size:13px; padding:14px 16px;
			            min-height:90px; max-height:300px; overflow-y:auto;
			            background:#f8f8f8; border-bottom:1px solid #ddd; white-space:pre-wrap;
			            text-align:left;">
			</div>
			<div style="padding:10px 16px; text-align:right; background:#fafafa;
			            border-radius:0 0 4px 4px;">
				<button type="button" class="ui-button ui-corner-all ui-widget"
				        onclick="gnmiTestModalClose()">Close</button>
			</div>
		</div>
	</div>

	<!-- JavaScript for conditional visibility and interactivity -->
	<script type="text/javascript">
	// Called when the dropdown selection changes — sync the hidden text input value.
	function gnmiCertSelectChange(id) {
		var sel = document.getElementById(id + '_select');
		var txt = document.getElementById(id + '_text');
		if (!sel || !txt) return;
		txt.value = sel.value; // '' for placeholder, or the chosen absolute path
	}

	// Called when the "Custom path" checkbox is toggled.
	// Toggles the dropdown wrapper (which contains both the native <select>
	// and the jQuery UI selectmenu button) vs the free-text input.
	function gnmiCertToggleCustom(id) {
		var cb           = document.getElementById(id + '_cb');
		var dropdownWrap = document.getElementById(id + '_dropdown_wrap');
		var sel          = document.getElementById(id + '_select');
		var txt          = document.getElementById(id + '_text');
		var textWrap     = document.getElementById(id + '_text_wrap');
		if (!cb || !dropdownWrap || !sel || !txt || !textWrap) return;
		if (cb.checked) {
			// Switch to custom mode: hide dropdown wrapper, show text input
			dropdownWrap.style.display = 'none';
			textWrap.style.display     = 'inline-block';
			txt.value = '';
			txt.focus();
		} else {
			// Switch back to dropdown mode: show dropdown wrapper, hide text input
			dropdownWrap.style.display = 'inline-block';
			textWrap.style.display     = 'none';
			txt.value = sel.value;
		}
	}

	function toggleGnmiSettings() {
		var checkbox = document.getElementById('gnmi_enabled');
		if (checkbox.checked) {
			gnmiSyncInheritedHostname();
			$('.gnmi-setting-row').not('.tls-setting-row').show();
		} else {
			$('.gnmi-setting-row').hide();
		}
	}

	function gnmiGetDeviceHostname() {
		var hostname = document.querySelector('input[name="hostname"]');
		return hostname ? hostname.value : '';
	}

	function gnmiUpdateHostnameReset() {
		var source = document.getElementById('gnmi_hostname_source');
		var reset = document.getElementById('gnmi_use_device_hostname');
		if (!source || !reset) return;
		reset.style.display = source.value === 'custom' ? 'inline' : 'none';
	}

	function gnmiMarkHostnameCustom() {
		var source = document.getElementById('gnmi_hostname_source');
		if (!source) return;
		source.value = 'custom';
		gnmiUpdateHostnameReset();
	}

	function gnmiSyncInheritedHostname() {
		var source = document.getElementById('gnmi_hostname_source');
		var hostname = document.getElementById('gnmi_hostname');
		if (!source || !hostname || source.value !== 'device') return;
		hostname.value = gnmiGetDeviceHostname();
		gnmiUpdateHostnameReset();
	}

	function gnmiUseDeviceHostname() {
		var source = document.getElementById('gnmi_hostname_source');
		if (!source) return;
		source.value = 'device';
		gnmiSyncInheritedHostname();
	}

	$(function() {
		var deviceHostname = document.querySelector('input[name="hostname"]');
		if (deviceHostname) {
			$(deviceHostname)
				.off('.gnmiHostnameSync')
				.on('input.gnmiHostnameSync change.gnmiHostnameSync', gnmiSyncInheritedHostname);
		}
		gnmiSyncInheritedHostname();
		gnmiUpdateHostnameReset();
	});

	function toggleTlsSettings() {
		$('.tls-setting-row').toggle();
	}

	function gnmi_restart_daemon(host_id) {
		if (confirm('Restart the gNMI daemon for this device? Active connections will be briefly interrupted.')) {
			var formData = new FormData();
			formData.append('action', 'restart_daemon');
			formData.append('host_id', host_id);

			var csrfToken = document.querySelector('input[name="__csrf_magic"]');
			if (csrfToken) formData.append('__csrf_magic', csrfToken.value);

			fetch('plugins/gnmi/ajax_handler.php', {
				method: 'POST',
				body: formData,
				credentials: 'include'
			})
			.then(response => response.json())
			.then(data => {
				if (data.success) {
					alert('Daemon restart initiated successfully.');
				} else {
					alert('Failed to restart daemon: ' + (data.message || 'Unknown error'));
				}
			})
			.catch(error => {
				console.error('gNMI: restart daemon error:', error);
				alert('Error restarting daemon: ' + error.message);
			});
		}
	}

	function gnmiTestModalClose() {
		document.getElementById('gnmi-test-modal').style.display = 'none';
		if (window._gnmiTestAbort) {
			window._gnmiTestAbort.abort();
			window._gnmiTestAbort = null;
		}
	}

	function gnmiTestConnection(host_id, device_id) {
		var modal  = document.getElementById('gnmi-test-modal');
		var output = document.getElementById('gnmi-test-output');

		var fv = function(name) {
			var el = document.querySelector('[name="' + name + '"]');
			if (!el) return '';
			return el.type === 'checkbox' ? (el.checked ? '1' : '0') : (el.value || '');
		};

		var hostname = fv('gnmi_hostname');
		var port     = fv('gnmi_port') || '9339';

		output.innerHTML = '<em style="color:#555;">Testing ' + hostname + ':' + port + '...</em>\n';
		modal.style.display = 'flex';

		var fd = new FormData();
		fd.append('id',               String(host_id));
		fd.append('gnmi_device_id',   String(device_id));
		fd.append('gnmi_hostname',    hostname);
		fd.append('gnmi_port',        port);
		fd.append('gnmi_username',    fv('gnmi_username'));
		fd.append('gnmi_password',    fv('gnmi_password'));
		fd.append('use_tls',          fv('use_tls'));
		fd.append('ca_cert_path',     fv('ca_cert_path'));
		fd.append('client_key_path',  fv('client_key_path'));
		fd.append('client_cert_path', fv('client_cert_path'));
		fd.append('tls_override',     fv('tls_override'));
		fd.append('skip_verify',      fv('skip_verify'));
		fd.append('compatibility_mode', fv('compatibility_mode'));

		var csrf = document.querySelector('input[name="__csrf_magic"]');
		if (csrf) fd.append('__csrf_magic', csrf.value);

		if (window._gnmiTestAbort) window._gnmiTestAbort.abort();
		window._gnmiTestAbort = new AbortController();

		fetch('plugins/gnmi/test_connection.php', {
			method:      'POST',
			body:        fd,
			credentials: 'include',
			signal:      window._gnmiTestAbort.signal
		}).then(function(resp) {
			var reader    = resp.body.getReader();
			var dec       = new TextDecoder('utf-8');
			var buf       = '';
			var firstLine = true;

			function read() {
				reader.read().then(function(r) {
					if (r.done) {
						if (buf.trim()) {
							_gnmiHandleLine(buf.trim(), output, firstLine);
						}
						_gnmiTestDone(output);
						return;
					}
					buf += dec.decode(r.value, {stream: true});
					var lines = buf.split('\n');
					buf = lines.pop();
					lines.forEach(function(l) {
						if (l.trim()) {
							_gnmiHandleLine(l.trim(), output, firstLine);
							firstLine = false;
						}
					});
					output.scrollTop = output.scrollHeight;
					read();
				}).catch(function(e) {
					if (e.name !== 'AbortError') {
						output.innerHTML += '<span style="color:red;">Stream error: ' + e.message + '</span>\n';
					}
				});
			}
			read();
		}).catch(function(e) {
			if (e.name !== 'AbortError') {
				output.innerHTML += '<span style="color:red;">Fetch error: ' + e.message + '</span>\n';
			}
		});
	}

	function _gnmiHandleLine(line, output, isFirst) {
		try {
			var obj   = JSON.parse(line);
			if (isFirst) output.innerHTML = '';
			var ok    = obj.success;
			var icon  = ok ? '\u2713' : '\u2717';
			var color = ok ? '#2a7a2a' : '#cc0000';
			var label = (obj.stage || '').toUpperCase();
			var ms    = obj.duration_ms > 0 ? ' (' + obj.duration_ms + 'ms)' : '';
			output.innerHTML += '<span style="color:' + color + ';">' + icon + ' ' + label +
			                    '</span>  ' + obj.message + ms + '\n';
		} catch(e) {
			output.innerHTML += line + '\n';
		}
	}

	function _gnmiTestDone(output) {
		var failed = output.querySelectorAll('span[style*="cc0000"]').length > 0;
		output.innerHTML += '\n' + (failed
			? '<span style="color:#cc0000;font-weight:bold;">Connection test failed.</span>'
			: '<span style="color:#2a7a2a;font-weight:bold;">All tests passed.</span>');
	}
	</script>
	<?php
}
?>

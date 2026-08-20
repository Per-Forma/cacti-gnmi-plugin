<?php
/**
 * Common helper functions for the gNMI plugin.
 */

if (!defined('CACTI_VERSION')) {
	die('Access denied');
}

// Constants for template/data input provisioning
if (!defined('GNMI_DATA_INPUT_NAME')) {
	define('GNMI_DATA_INPUT_NAME', 'gNMI - Passthrough');
}

if (!defined('GNMI_DATA_TEMPLATE_NAME')) {
	define('GNMI_DATA_TEMPLATE_NAME', 'gNMI - Passthrough');
}

/**
 * Log runtime directory setup/validation issues without assuming every test
 * harness defines the full Cacti logging environment.
 *
 * @param string $message Message to log
 * @return void
 */
function gnmi_log_runtime_message($message) {
	if (function_exists('cacti_log')) {
		$verbosity = defined('POLLER_VERBOSITY_LOW') ? POLLER_VERBOSITY_LOW : 1;
		cacti_log($message, false, 'GNMI', $verbosity);
	}
}

/**
 * Recursively mask sensitive request/config values before logging.
 *
 * @param mixed $value Data to sanitize
 * @return mixed Sanitized data
 */
function gnmi_mask_sensitive_data($value) {
	$sensitive_keys = array(
		'__csrf_magic',
		'__csrf_magicSubmit',
		'ca_cert',
		'ca_cert_path',
		'client_cert',
		'client_cert_path',
		'client_key',
		'client_key_path',
		'gnmi_password',
		'password',
		'token',
	);

	if (!is_array($value)) {
		return $value;
	}

	$masked = array();
	foreach ($value as $key => $item) {
		$key_text = strtolower((string)$key);
		$is_sensitive = in_array($key_text, $sensitive_keys, true) ||
			strpos($key_text, 'password') !== false ||
			strpos($key_text, 'secret') !== false ||
			strpos($key_text, 'private') !== false ||
			strpos($key_text, 'token') !== false ||
			strpos($key_text, 'csrf') !== false;

		if ($is_sensitive) {
			$masked[$key] = ($item === '' || $item === null) ? $item : '[redacted]';
		} elseif (is_array($item)) {
			$masked[$key] = gnmi_mask_sensitive_data($item);
		} else {
			$masked[$key] = $item;
		}
	}

	return $masked;
}

/**
 * Return a printable sanitized version of POST data for debug logs.
 *
 * @return string
 */
function gnmi_sanitized_post_for_log() {
	return print_r(gnmi_mask_sensitive_data($_POST), true);
}

/**
 * Validate CSRF for plugin POST requests.
 *
 * Cacti's csrf-magic middleware validates web POSTs during global.php include.
 * This helper provides an explicit plugin guard and keeps CLI integration tests
 * usable without requiring a browser-generated token.
 *
 * @return bool True when request is safe to process
 */
function gnmi_validate_csrf_request() {
	if (PHP_SAPI === 'cli' && empty($GLOBALS['gnmi_enforce_csrf_in_cli'])) {
		return true;
	}

	$method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper($_SERVER['REQUEST_METHOD']) : '';
	if ($method !== 'POST') {
		return true;
	}

	if (function_exists('validate_csrf_token')) {
		return (bool)validate_csrf_token();
	}

	if (function_exists('csrf_check')) {
		return (bool)csrf_check(false);
	}

	return isset($_POST['__csrf_magic']) && $_POST['__csrf_magic'] !== '';
}

/**
 * Check whether the current user can manage gNMI plugin configuration.
 *
 * @param string $realm_file Plugin realm file to check first
 * @return bool True if logged in and authorized
 */
function gnmi_current_user_can_manage($realm_file = 'ajax_handler.php') {
	if (PHP_SAPI === 'cli') {
		return true;
	}

	if (!isset($_SESSION['sess_user_id']) || (int)$_SESSION['sess_user_id'] === 0) {
		return false;
	}

	if (function_exists('api_plugin_user_realm_auth') && api_plugin_user_realm_auth($realm_file)) {
		return true;
	}

	if (function_exists('api_user_realm_auth') && api_user_realm_auth('host.php')) {
		return true;
	}

	global $user_auth_realm_filenames;
	if (function_exists('is_realm_allowed') && isset($user_auth_realm_filenames['host.php'])) {
		return is_realm_allowed($user_auth_realm_filenames['host.php']);
	}

	return false;
}

/**
 * Get the gNMI runtime root.
 *
 * Defaults to <cacti>/plugins/gnmi/runtime and can be overridden with the
 * GNMI_RUNTIME_DIR environment variable.
 *
 * @return string Absolute runtime root path
 */
function gnmi_get_runtime_dir() {
	global $config;

	$override = getenv('GNMI_RUNTIME_DIR');
	if (is_string($override) && trim($override) !== '') {
		return rtrim(trim($override), '/');
	}

	return $config['base_path'] . '/plugins/gnmi/runtime';
}

/**
 * Get the storage directory path for daemon JSON/config/PID/lock files.
 *
 * @return string Absolute path to storage directory
 */
function gnmi_get_storage_dir() {
	return gnmi_get_runtime_dir() . '/storage';
}

/**
 * Get the directory used for operator-installed TLS certificate/key material.
 *
 * @return string Absolute path to cert directory
 */
function gnmi_get_certs_dir() {
	return gnmi_get_runtime_dir() . '/certs';
}

/**
 * Backward-compatible singular alias for callers/tests.
 *
 * @return string Absolute path to cert directory
 */
function gnmi_get_cert_dir() {
	return gnmi_get_certs_dir();
}

/**
 * Check a resolved filesystem path against an exact directory boundary.
 *
 * Resolving both paths prevents traversal and symlink escapes. Appending the
 * directory separator prevents sibling prefixes such as certs-other/ from
 * matching certs/.
 *
 * @param string $path Existing path to check
 * @param string $directory Existing containing directory
 * @return bool True when the path resolves inside the directory
 */
function gnmi_path_is_within_directory($path, $directory) {
	$real_path = realpath($path);
	$real_directory = realpath($directory);
	if ($real_path === false || $real_directory === false) {
		return false;
	}

	$boundary = rtrim($real_directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
	return strpos($real_path, $boundary) === 0;
}

/**
 * Validate the fresh-install certificate contract.
 *
 * Certificate and key paths must name existing files below runtime/certs.
 * Empty paths remain valid because TLS material is optional.
 *
 * @param string $path Certificate or key path
 * @return bool True when empty or an allowed certificate file
 */
function gnmi_certificate_path_is_allowed($path) {
	if ($path === '') {
		return true;
	}

	return is_file($path) && gnmi_path_is_within_directory($path, gnmi_get_certs_dir());
}

/**
 * Get the daemon log directory.
 *
 * @return string Absolute path to log directory
 */
function gnmi_get_logs_dir() {
	return gnmi_get_runtime_dir() . '/logs';
}

/**
 * Return all runtime directories that must exist and be protected.
 *
 * @return array<string,string> Map of logical name to absolute path
 */
function gnmi_get_runtime_directories() {
	return array(
		'runtime' => gnmi_get_runtime_dir(),
		'storage' => gnmi_get_storage_dir(),
		'certs' => gnmi_get_certs_dir(),
		'logs' => gnmi_get_logs_dir(),
	);
}

/**
 * Apache access-deny file content matching the Cacti 2.2/2.4 compatibility
 * pattern.  Non-Apache installs must configure equivalent deny rules.
 *
 * @return string .htaccess content
 */
function gnmi_runtime_deny_file_contents() {
	return "Options -Indexes\n" .
		"<IfModule mod_authz_core.c>\n" .
		"\tRequire all denied\n" .
		"</IfModule>\n" .
		"<IfModule !mod_authz_core.c>\n" .
		"\tOrder Deny,Allow\n" .
		"\tDeny from all\n" .
		"</IfModule>\n";
}

/**
 * Create/update the access-deny file for a runtime directory.
 *
 * @param string $dir Runtime directory
 * @return bool True when the deny file exists and contains the expected content
 */
function gnmi_write_runtime_deny_file($dir) {
	$deny_file = rtrim($dir, '/') . '/.htaccess';
	$contents = gnmi_runtime_deny_file_contents();

	if (@file_put_contents($deny_file, $contents, LOCK_EX) === false) {
		gnmi_log_runtime_message("gNMI: ERROR - Could not write runtime protection file: $deny_file");
		return false;
	}

	@chmod($deny_file, 0640);

	$written = @file_get_contents($deny_file);
	if ($written !== $contents) {
		gnmi_log_runtime_message("gNMI: ERROR - Runtime protection file verification failed: $deny_file");
		return false;
	}

	return true;
}

/**
 * Create and protect runtime directories.
 *
 * This is intended to run from plugin_gnmi_install(). Runtime consumers should
 * validate these paths and fail closed instead of creating fallback public
 * directories at poll time.
 *
 * @return bool True when runtime directories and deny files are ready
 */
function gnmi_ensure_runtime_directories() {
	$ok = true;

	foreach (gnmi_get_runtime_directories() as $name => $dir) {
		if (!is_dir($dir)) {
			if (!@mkdir($dir, 0750, true)) {
				gnmi_log_runtime_message("gNMI: ERROR - Could not create $name runtime directory: $dir");
				$ok = false;
				continue;
			}
		}

		@chmod($dir, 0750);

		if (!is_dir($dir) || !is_writable($dir)) {
			gnmi_log_runtime_message("gNMI: ERROR - $name runtime directory is not writable: $dir");
			$ok = false;
			continue;
		}

		if (!gnmi_write_runtime_deny_file($dir)) {
			$ok = false;
		}
	}

	return $ok;
}

/**
 * Validate runtime directories created at install time.
 *
 * @return bool True when storage/log directories are writable and all runtime
 *              directories have deny files.
 */
function gnmi_runtime_directories_ready() {
	$ok = true;

	foreach (gnmi_get_runtime_directories() as $name => $dir) {
		if (!is_dir($dir)) {
			gnmi_log_runtime_message("gNMI: ERROR - $name runtime directory is missing: $dir");
			$ok = false;
			continue;
		}

		if (($name === 'runtime' || $name === 'storage' || $name === 'logs') && !is_writable($dir)) {
			gnmi_log_runtime_message("gNMI: ERROR - $name runtime directory is not writable: $dir");
			$ok = false;
		}

		$deny_file = rtrim($dir, '/') . '/.htaccess';
		if (!is_file($deny_file)) {
			gnmi_log_runtime_message("gNMI: ERROR - Runtime protection file is missing: $deny_file");
			$ok = false;
		}
	}

	return $ok;
}

/**
 * Serialize gNMI work triggered from poller_bottom across concurrent poller.php processes
 * (remote pollers or overlapping invocations). Uses non-blocking advisory flock on a file
 * under the plugin storage directory; if the lock is held, skip this cycle (log + return
 * false)—the next poller interval retries.
 *
 * All participating hosts must share the same filesystem path for gnmi storage (local disk
 * or NFS where flock() is coherent across nodes; otherwise the lock will not serialize).
 *
 * @param callable $fn Code to run (typically wraps gnmi_manage_daemons()).
 * @param string|null $lock_path Absolute lock file path, or null for default
 *                             `storage/.gnmi_poller_bottom.lock` (used by tests for temp paths).
 * @param bool $blocking Wait for the lock instead of skipping when it is busy.
 *                       Uninstall uses blocking mode so tables cannot disappear
 *                       beneath an active poller callback.
 * @return bool True if $fn ran and returned; false if lock busy or fopen failed.
 */
function gnmi_with_poller_exclusive_lock(callable $fn, $lock_path = null, $blocking = false) {
	$dir = gnmi_get_storage_dir();
	if ($lock_path === null) {
		$lock_path = $dir . '/.gnmi_poller_bottom.lock';
		if (!gnmi_runtime_directories_ready()) {
			cacti_log('gNMI: Runtime directories are not ready; skipping gnmi cycle', false, 'POLLER', POLLER_VERBOSITY_LOW);
			return false;
		}
	} else {
		$dir = dirname($lock_path);
		if (!@is_dir($dir)) {
			@mkdir($dir, 0750, true);
		}
	}

	$fh = @fopen($lock_path, 'c+');
	if ($fh === false) {
		cacti_log('gNMI: Could not open poller lock file: ' . $lock_path, false, 'POLLER', POLLER_VERBOSITY_LOW);
		return false;
	}
	@chmod($lock_path, 0640);

	$lock_operation = LOCK_EX | ($blocking ? 0 : LOCK_NB);
	if (!flock($fh, $lock_operation)) {
		fclose($fh);
		cacti_log('gNMI: poller lock busy, skipping gnmi cycle', false, 'POLLER', POLLER_VERBOSITY_LOW);
		return false;
	}

	try {
		$fn();
		return true;
	} finally {
		flock($fh, LOCK_UN);
		fclose($fh);
	}
}

/**
 * Resolve the effective Cacti poller interval in seconds.
 *
 * Reads from Cacti's settings table via read_config_option(), which handles
 * caching and gracefully returns an empty string when the key is absent.
 * Falls back to 300 seconds (the default 5-minute Cacti interval) when the
 * value is missing, empty, non-numeric, or zero — ensuring all downstream
 * formulas (buffer sizing, staleness thresholds, heartbeat) remain safe.
 *
 * Called once per formula that needs an interval-derived value; the Cacti
 * settings cache means repeated calls within a poller cycle are cheap.
 *
 * @return int Poller interval in seconds (minimum 1, default fallback 300)
 */
function gnmi_get_poller_interval($force_refresh = false) {
	// read_config_option caches in-process; $force_refresh bypasses the cache.
	// In production every poller cycle gets the same cached value (correct).
	// In tests, pass true after mutating the settings table to get a fresh read.
	$interval = read_config_option('poller_interval', $force_refresh);

	if (empty($interval) || !is_numeric($interval) || (int)$interval <= 0) {
		cacti_log('gNMI: poller_interval not set or invalid, defaulting to 300s', false, 'PLUGIN', POLLER_VERBOSITY_MEDIUM);
		return 300;
	}

	return (int)$interval;
}

/**
 * Returns whether the plugin has been configured for at least one device.
 *
 * @return bool True if at least one gNMI device exists in the database
 */
function gnmi_is_configured() {
	$count = db_fetch_cell('SELECT COUNT(*) FROM plugin_gnmi_devices');
	return ($count > 0);
}

/**
 * Ensure runtime storage/log directories are ready.
 *
 * Runtime directories are created by plugin_gnmi_install(). Poller/runtime code
 * validates them and fails closed if install did not create/protect them.
 *
 * @return bool True if directory exists and is writable, False otherwise
 */
function gnmi_ensure_storage_directory() {
	return gnmi_runtime_directories_ready();
}

/**
 * Ensure RRD parent directory exists and is writable.
 *
 * Creates the parent directory for an RRD file path if it doesn't exist.
 * Follows Cacti core patterns: recursive creation, 0775 permissions,
 * ownership inheritance from base RRA directory.
 *
 * @param string $rrd_path Full path to RRD file (e.g., /path/to/cacti/rra/2/6.rrd)
 * @param int $local_data_id Data source ID for logging context
 * @return bool True if directory exists and is writable, false otherwise
 */
function gnmi_ensure_rrd_directory($rrd_path, $local_data_id) {
	global $config;

	// Extract parent directory from RRD file path
	$rrd_dir = dirname($rrd_path);

	// Security check: Verify path is within expected RRA directory
	$rra_base = $config['base_path'] . '/rra';
	if (strpos(realpath($rrd_dir) ?: $rrd_dir, realpath($rra_base) ?: $rra_base) !== 0) {
		// Path is not within the RRA directory - this shouldn't happen with valid data
		// but we check defensively to prevent writing outside expected locations
		cacti_log("gNMI: RRD path outside expected directory: $rrd_dir for DS $local_data_id", false, 'POLLER', POLLER_VERBOSITY_LOW);
		return false;
	}

	// Check if directory already exists
	if (is_dir($rrd_dir)) {
		// Directory exists - verify it's writable
		if (is_writable($rrd_dir)) {
			return true; // Directory exists and is writable - no logging needed
		} else {
			cacti_log("gNMI: RRD directory exists but is not writable: $rrd_dir for DS $local_data_id", false, 'POLLER', POLLER_VERBOSITY_LOW);
			return false;
		}
	}

	// Directory doesn't exist - create it with race-safe pattern
	// Pattern: if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir))
	// This handles concurrent creation: if another process creates it between checks, we still succeed
	if (!is_dir($rrd_dir) && !@mkdir($rrd_dir, 0775, true) && !is_dir($rrd_dir)) {
		// mkdir failed and directory still doesn't exist (not a race condition)
		cacti_log("gNMI: Failed to create RRD directory $rrd_dir for DS $local_data_id", false, 'POLLER', POLLER_VERBOSITY_LOW);
		return false;
	}

	// Directory was created (or existed due to race) - set permissions
	@chmod($rrd_dir, 0775);

	// Inherit ownership from base RRA directory (Cacti core pattern)
	// Only applies when running as root (posix_getuid() == 0)
	if (function_exists('posix_getuid') && posix_getuid() == 0) {
		$owner_id = @fileowner($rra_base);
		$group_id = @filegroup($rra_base);
		if ($owner_id !== false) {
			@chown($rrd_dir, $owner_id);
		}
		if ($group_id !== false) {
			@chgrp($rrd_dir, $group_id);
		}
	}

	// Verify creation succeeded
	if (is_dir($rrd_dir) && is_writable($rrd_dir)) {
		cacti_log("gNMI: Created RRD directory $rrd_dir for DS $local_data_id", false, 'POLLER', POLLER_VERBOSITY_DEBUG);
		return true;
	} else {
		cacti_log("gNMI: Failed to verify RRD directory creation: $rrd_dir for DS $local_data_id", false, 'POLLER', POLLER_VERBOSITY_LOW);
		return false;
	}
}

/**
 * Validate that a file is a readable RRD database.
 *
 * @param string $path Full RRD path
 * @param string|null $error Validation error output
 * @return bool True when rrdtool can read the file
 */
function gnmi_rrd_is_valid(string $path, ?string &$error = null): bool {
	$error = null;
	clearstatcache(true, $path);

	if (!is_file($path)) {
		$error = 'RRD file does not exist';
		return false;
	}

	if (filesize($path) <= 0) {
		$error = 'RRD file is empty';
		return false;
	}

	$rrdtool = read_config_option('path_rrdtool');
	if (empty($rrdtool)) {
		$rrdtool = 'rrdtool';
	}

	$output = array();
	$return_var = 0;
	exec(
		escapeshellarg($rrdtool) . ' info ' . escapeshellarg($path) . ' 2>&1',
		$output,
		$return_var
	);

	if ($return_var !== 0) {
		$error = !empty($output)
			? implode(' ', $output)
			: "rrdtool info exited with status $return_var";
		return false;
	}

	return true;
}

/**
 * Create a hard link without replacing an existing destination.
 *
 * link() provides atomic no-clobber publication: it fails if the destination
 * appears before or during the operation. Symlinks are rejected explicitly.
 *
 * @param string $source Existing regular file
 * @param string $destination Destination path that must not exist
 * @param string|null $error Error output
 * @return bool True when the link was created
 */
function gnmi_link_file_no_clobber(
	string $source,
	string $destination,
	?string &$error = null
): bool {
	$error = null;
	clearstatcache(true, $source);
	clearstatcache(true, $destination);

	if (!is_file($source) || is_link($source)) {
		$error = "Source is not a regular file: $source";
		return false;
	}

	if (file_exists($destination) || is_link($destination)) {
		$error = "Destination already exists: $destination";
		return false;
	}

	if (!@link($source, $destination)) {
		$last_error = error_get_last();
		$error = !empty($last_error['message'])
			? $last_error['message']
			: "Unable to link $source to $destination";
		return false;
	}

	return true;
}

/**
 * Create an RRD file using the Cacti metadata for a local data source.
 *
 * @param string $path Full RRD path
 * @param int $local_data_id Cacti local data source ID
 * @param string $label Human-readable data source label for logs
 * @param int|null $oldest_epoch Oldest buffered sample epoch
 * @return bool True when a non-empty RRD file was created
 */
function gnmi_create_rrd_file(
	string $path,
	int $local_data_id,
	string $label,
	?int $oldest_epoch
): bool {
	clearstatcache(true, $path);
	if (file_exists($path) || is_link($path)) {
		cacti_log(
			"gNMI: Refusing to create RRD for DS $local_data_id ($label): target already exists: $path",
			false,
			'POLLER',
			POLLER_VERBOSITY_LOW
		);
		return false;
	}

	if (!gnmi_ensure_rrd_directory($path, $local_data_id)) {
		cacti_log(
			"gNMI: Cannot create RRD file for DS $local_data_id ($label): parent directory is unavailable",
			false,
			'POLLER',
			POLLER_VERBOSITY_LOW
		);
		return false;
	}

	$profile = db_fetch_row_prepared(
		'SELECT dtd.data_source_profile_id, dtd.rrd_step, dsp.step AS profile_step
		 FROM data_template_data AS dtd
		 LEFT JOIN data_source_profiles AS dsp
		 ON dsp.id = dtd.data_source_profile_id
		 WHERE dtd.local_data_id = ?',
		array($local_data_id)
	);

	if (empty($profile)) {
		cacti_log(
			"gNMI: Cannot create RRD file for DS $local_data_id ($label): data source profile metadata missing",
			false,
			'POLLER',
			POLLER_VERBOSITY_LOW
		);
		return false;
	}

	$profile_id = (int)$profile['data_source_profile_id'];
	$step = (int)$profile['profile_step'];
	if ($step <= 0) {
		$step = (int)$profile['rrd_step'];
	}
	if ($step <= 0) {
		$step = 10;
	}

	$rrd_items = db_fetch_assoc_prepared(
		'SELECT data_source_name, data_source_type_id, rrd_heartbeat,
		        rrd_minimum, rrd_maximum
		 FROM data_template_rrd
		 WHERE local_data_id = ?
		 ORDER BY id',
		array($local_data_id)
	);

	$rras = db_fetch_assoc_prepared(
		'SELECT steps, `rows`, name
		 FROM data_source_profiles_rra
		 WHERE data_source_profile_id = ?
		 ORDER BY id',
		array($profile_id)
	);

	if (empty($rrd_items) || empty($rras)) {
		cacti_log(
			"gNMI: Cannot create RRD file for DS $local_data_id ($label): DS or RRA metadata missing",
			false,
			'POLLER',
			POLLER_VERBOSITY_LOW
		);
		return false;
	}

	$rrdtool = read_config_option('path_rrdtool');
	if (empty($rrdtool)) {
		$rrdtool = 'rrdtool';
	}

	$start = ($oldest_epoch !== null && $oldest_epoch > 0)
		? max(0, $oldest_epoch - 1)
		: max(0, time() - $step);

	$arguments = array(
		$rrdtool,
		'create',
		$path,
		'--start',
		(string)$start,
		'--step',
		(string)$step
	);

	$type_map = array(
		1 => 'GAUGE',
		2 => 'COUNTER',
		3 => 'DERIVE',
		4 => 'ABSOLUTE'
	);

	foreach ($rrd_items as $item) {
		$type_id = (int)$item['data_source_type_id'];
		$ds_type = $type_map[$type_id] ?? 'GAUGE';
		$arguments[] = sprintf(
			'DS:%s:%s:%s:%s:%s',
			$item['data_source_name'],
			$ds_type,
			$item['rrd_heartbeat'],
			$item['rrd_minimum'],
			$item['rrd_maximum']
		);
	}

	$average_rras = array();
	foreach ($rras as $rra) {
		$cf = 'AVERAGE';
		if (strpos($rra['name'], 'MIN') !== false) {
			$cf = 'MIN';
		} elseif (strpos($rra['name'], 'MAX') !== false) {
			$cf = 'MAX';
		} elseif (strpos($rra['name'], 'LAST') !== false) {
			$cf = 'LAST';
		}

		$arguments[] = sprintf(
			'RRA:%s:0.5:%s:%s',
			$cf,
			$rra['steps'],
			$rra['rows']
		);

		if ($cf === 'AVERAGE') {
			$average_rras[] = $rra;
		}
	}

	// Traffic graph templates request MAX as well as AVERAGE data.
	foreach ($average_rras as $rra) {
		$arguments[] = sprintf(
			'RRA:MAX:0.5:%s:%s',
			$rra['steps'],
			$rra['rows']
		);
	}

	$command = implode(' ', array_map('escapeshellarg', $arguments));
	$output = array();
	$return_var = 0;
	exec($command . ' 2>&1', $output, $return_var);

	$validation_error = null;
	if ($return_var !== 0 || !gnmi_rrd_is_valid($path, $validation_error)) {
		$error = !empty($output)
			? implode(' ', $output)
			: ($validation_error ?: "Exit code: $return_var (no output captured)");
		cacti_log(
			"gNMI: Failed to create RRD file for DS $local_data_id ($label): $error",
			false,
			'POLLER',
			POLLER_VERBOSITY_LOW
		);
		return false;
	}

	return true;
}

/**
 * Recover a missing or invalid RRD with bounded evidence retention and cooldown.
 *
 * @param string $path Full RRD path
 * @param int $local_data_id Cacti local data source ID
 * @param string $label Human-readable data source label for logs
 * @param int|null $oldest_epoch Oldest buffered sample epoch
 * @return bool True when a replacement RRD is ready
 */
function gnmi_recover_invalid_rrd_file(
	string $path,
	int $local_data_id,
	string $label,
	?int $oldest_epoch
): bool {
	if (!gnmi_ensure_rrd_directory($path, $local_data_id)) {
		return false;
	}

	$invalid_path = $path . '.invalid';
	$marker_path = $path . '.recovery-pending';
	$cooldown = max(900, gnmi_get_poller_interval() * 5);

	clearstatcache(true, $marker_path);
	if (is_file($marker_path)) {
		$marker_age = time() - filemtime($marker_path);
		if ($marker_age >= 0 && $marker_age < $cooldown) {
			cacti_log(
				"gNMI: RRD recovery throttled for DS $local_data_id ($label); retry after cooldown",
				false,
				'POLLER',
				POLLER_VERBOSITY_DEBUG
			);
			return false;
		}
		@unlink($marker_path);
	}

	if (@file_put_contents($marker_path, gmdate('c')) === false) {
		cacti_log(
			"gNMI: Cannot create RRD recovery marker for DS $local_data_id ($label): $marker_path",
			false,
			'POLLER',
			POLLER_VERBOSITY_LOW
		);
		return false;
	}

	clearstatcache(true, $path);
	clearstatcache(true, $invalid_path);
	$current_exists = is_file($path) || is_link($path);
	$invalid_exists = is_file($invalid_path) || is_link($invalid_path);

	if ($current_exists && $invalid_exists) {
		cacti_log(
			"gNMI: RRD recovery requires manual intervention for DS $local_data_id ($label): " .
			"both the current RRD and preserved invalid RRD exist",
			false,
			'POLLER',
			POLLER_VERBOSITY_LOW
		);
		return false;
	}

	if ($current_exists) {
		$link_error = null;
		if (!gnmi_link_file_no_clobber($path, $invalid_path, $link_error)) {
			cacti_log(
				"gNMI: Cannot preserve invalid RRD for DS $local_data_id ($label): $link_error",
				false,
				'POLLER',
				POLLER_VERBOSITY_LOW
			);
			return false;
		}

		// The bytes remain preserved by the hard link at .invalid. Removing the
		// original directory entry is required to publish a replacement, but it
		// does not delete or alter the preserved inode.
		if (!@unlink($path)) {
			cacti_log(
				"gNMI: Preserved invalid RRD but could not vacate original path for DS $local_data_id ($label): $path",
				false,
				'POLLER',
				POLLER_VERBOSITY_LOW
			);
			return false;
		}
	}

	try {
		$random_suffix = bin2hex(random_bytes(8));
	} catch (Throwable $e) {
		$random_suffix = str_replace('.', '', uniqid('', true));
	}

	$candidate_path = sprintf(
		'%s.replacement.%d.%s',
		$path,
		function_exists('getmypid') ? getmypid() : 0,
		$random_suffix
	);

	if (!gnmi_create_rrd_file(
		$candidate_path,
		$local_data_id,
		$label,
		$oldest_epoch
	)) {
		if (is_file($candidate_path) || is_link($candidate_path)) {
			@unlink($candidate_path);
		}
		return false;
	}

	$validation_error = null;
	if (!gnmi_rrd_is_valid($candidate_path, $validation_error)) {
		cacti_log(
			"gNMI: Replacement RRD validation failed for DS $local_data_id ($label): $validation_error",
			false,
			'POLLER',
			POLLER_VERBOSITY_LOW
		);
		@unlink($candidate_path);
		return false;
	}

	$publish_error = null;
	if (!gnmi_link_file_no_clobber($candidate_path, $path, $publish_error)) {
		cacti_log(
			"gNMI: Cannot publish replacement RRD for DS $local_data_id ($label): $publish_error",
			false,
			'POLLER',
			POLLER_VERBOSITY_LOW
		);
		@unlink($candidate_path);
		return false;
	}

	if (!@unlink($candidate_path)) {
		cacti_log(
			"gNMI: Replacement RRD published for DS $local_data_id ($label), but temporary link remains: $candidate_path",
			false,
			'POLLER',
			POLLER_VERBOSITY_LOW
		);
	}

	@unlink($marker_path);
	cacti_log(
		"gNMI: Recovered RRD file for DS $local_data_id ($label): $path",
		false,
		'POLLER',
		POLLER_VERBOSITY_MEDIUM
	);
	return true;
}

/**
 * Ensure an RRD exists, recovering missing and zero-byte files when permitted.
 *
 * @param string $path Full RRD path
 * @param int $local_data_id Cacti local data source ID
 * @param string $label Human-readable data source label for logs
 * @param int|null $oldest_epoch Oldest buffered sample epoch
 * @return bool True when the RRD is ready for update
 */
function gnmi_prepare_rrd_file(
	string $path,
	int $local_data_id,
	string $label,
	?int $oldest_epoch
): bool {
	clearstatcache(true, $path);
	if (is_file($path) && filesize($path) > 0) {
		return true;
	}

	return gnmi_recover_invalid_rrd_file(
		$path,
		$local_data_id,
		$label,
		$oldest_epoch
	);
}

/**
 * Execute an RRD update and recover a structurally invalid file once.
 *
 * @param string $command Complete rrdtool update command without stderr redirect
 * @param string $path Full RRD path
 * @param int $local_data_id Cacti local data source ID
 * @param string $label Human-readable data source label for logs
 * @param int|null $oldest_epoch Oldest buffered sample epoch
 * @return bool True when the initial update or one recovery retry succeeds
 */
function gnmi_update_rrd_with_recovery(
	string $command,
	string $path,
	int $local_data_id,
	string $label,
	?int $oldest_epoch
): bool {
	$output = array();
	$return_var = 0;
	exec($command . ' 2>&1', $output, $return_var);

	if ($return_var === 0) {
		return true;
	}

	$update_error = !empty($output)
		? implode(' ', $output)
		: "Exit code: $return_var (no output captured)";
	$validation_error = null;

	if (gnmi_rrd_is_valid($path, $validation_error)) {
		cacti_log(
			"gNMI: RRD update failed for DS $local_data_id ($label): $update_error",
			false,
			'POLLER',
			POLLER_VERBOSITY_LOW
		);
		return false;
	}

	cacti_log(
		"gNMI: Invalid RRD detected for DS $local_data_id ($label): $validation_error",
		false,
		'POLLER',
		POLLER_VERBOSITY_LOW
	);

	if (!gnmi_recover_invalid_rrd_file(
		$path,
		$local_data_id,
		$label,
		$oldest_epoch
	)) {
		return false;
	}

	$output = array();
	$return_var = 0;
	exec($command . ' 2>&1', $output, $return_var);

	if ($return_var !== 0) {
		$retry_error = !empty($output)
			? implode(' ', $output)
			: "Exit code: $return_var (no output captured)";
		cacti_log(
			"gNMI: RRD update retry failed for DS $local_data_id ($label): $retry_error",
			false,
			'POLLER',
			POLLER_VERBOSITY_LOW
		);
		return false;
	}

	return true;
}

/**
 * Fetch a Cacti core setting value.
 *
 * @param string $name Setting key
 * @param mixed $default Default value if not found
 * @return mixed
 */
function gnmi_get_setting_value($name, $default = null) {
	$value = db_fetch_cell_prepared(
		'SELECT value FROM settings WHERE name = ?',
		array($name)
	);
	return ($value !== null) ? $value : $default;
}

/**
 * Persist a Cacti core setting value.
 *
 * @param string $name Setting key
 * @param mixed $value Value to store
 * @return void
 */
function gnmi_set_setting_value($name, $value) {
	db_execute_prepared(
		'REPLACE INTO settings (name, value) VALUES (?, ?)',
		array($name, $value)
	);
}

/**
 * Ensure the gNMI passthrough Data Input Method exists and return its ID.
 *
 * @return int|null
 */
function gnmi_get_passthrough_data_input_id() {
	static $cached_id = null;

	if ($cached_id !== null) {
		return $cached_id;
	}

	$stored = (int)gnmi_get_setting_value('gnmi_data_input_id', 0);
	if ($stored > 0) {
		$exists = db_fetch_cell_prepared(
			'SELECT id FROM data_input WHERE id = ?',
			array($stored)
		);
		if ($exists) {
			$cached_id = $stored;
			return $cached_id;
		}
	}

	$current = db_fetch_cell_prepared(
		'SELECT id FROM data_input WHERE name = ?',
		array(GNMI_DATA_INPUT_NAME)
	);
	if ($current) {
		$cached_id = (int)$current;
		gnmi_set_setting_value('gnmi_data_input_id', $cached_id);
		return $cached_id;
	}

	if (!function_exists('generate_hash')) {
		cacti_log('gNMI: ERROR - generate_hash() not available while creating Data Input Method', false, 'INSTALL');
		return null;
	}

	$hash = generate_hash();
	$input_string = 'python3 <path_cacti>/plugins/gnmi/scripts/gnmi_poller_bridge.py --local-data-id <local_data_id>';
	$created = db_execute_prepared(
		'INSERT INTO data_input (hash, name, input_string, type_id) VALUES (?, ?, ?, 1)',
		array($hash, GNMI_DATA_INPUT_NAME, $input_string)
	);

	if ($created === false) {
		cacti_log('gNMI: ERROR - Failed to create Data Input Method (database insert failed)', false, 'INSTALL');
		return null;
	}

	$data_input_id = (int)db_fetch_insert_id();

	$input_fields = array(
		array('Device ID', 'device_id'),
		array('Subscription ID', 'subscription_id'),
		array('Instance Identifier', 'instance_identifier'),
		array('Metric Set', 'metric_set')
	);

	$sequence = 0;
	foreach ($input_fields as $field) {
		db_execute_prepared(
			"INSERT INTO data_input_fields (hash, data_input_id, name, data_name, input_output, update_rra, sequence, type_code, regexp_match, allow_nulls)
			 VALUES (?, ?, ?, ?, 'in', '', ?, '', '', '')",
			array(generate_hash(), $data_input_id, $field[0], $field[1], $sequence)
		);
		$sequence++;
	}

	// Add a placeholder output field for documentation/compatibility
	db_execute_prepared(
		"INSERT INTO data_input_fields (hash, data_input_id, name, data_name, input_output, update_rra, sequence, type_code, regexp_match, allow_nulls)
		 VALUES (?, ?, ?, ?, 'out', 'on', ?, '', '', '')",
		array(generate_hash(), $data_input_id, 'Bridge Output', 'bridge_output', $sequence)
	);

	gnmi_set_setting_value('gnmi_data_input_id', $data_input_id);
	$cached_id = $data_input_id;

	return $cached_id;
}

/**
 * Ensure the gNMI passthrough Data Source Template exists and return its ID.
 *
 * @return int|null
 */
function gnmi_get_passthrough_data_template_id() {
	static $cached_id = null;

	$current = $cached_id;

	if ($current === null) {
		$stored = (int)gnmi_get_setting_value('gnmi_data_template_id', 0);
		if ($stored > 0) {
			$exists = db_fetch_cell_prepared(
				'SELECT id FROM data_template WHERE id = ?',
				array($stored)
			);
			if ($exists) {
				$current = $stored;
			}
		}

		if (!$current) {
			$current = db_fetch_cell_prepared(
				'SELECT id FROM data_template WHERE name = ?',
				array(GNMI_DATA_TEMPLATE_NAME)
			);

			if (!$current && function_exists('generate_hash')) {
				db_execute_prepared(
					'INSERT INTO data_template (hash, name) VALUES (?, ?)',
					array(generate_hash(), GNMI_DATA_TEMPLATE_NAME)
				);
				$current = db_fetch_insert_id();
			}
		}
	}

	if (!$current) {
		cacti_log('gNMI: ERROR - Failed to create or locate Data Source Template for gNMI', false, 'INSTALL');
		return null;
	}

	$data_input_id = gnmi_get_passthrough_data_input_id();
	if (empty($data_input_id)) {
		return null;
	}

	$profile_id = db_fetch_cell_prepared(
		"SELECT id FROM data_source_profiles WHERE name = 'gNMI - 10 Second Collection'"
	);
	if (empty($profile_id)) {
		cacti_log('gNMI: ERROR - 10-second data source profile missing while creating template', false, 'INSTALL');
		return null;
	}

	$template_data = db_fetch_row_prepared(
		'SELECT id, data_source_path
		 FROM data_template_data
		 WHERE data_template_id = ? AND local_data_id = 0
		 LIMIT 1',
		array($current)
	);

	$expected_data_source_path = '<path_rra>/|host_id|/|local_data_id|.rrd';

	if (empty($template_data)) {
		$name_pattern = '|host_description| - gNMI - |query_instance| - |gnmi_metric_set|';
		db_execute_prepared(
			"INSERT INTO data_template_data (
				local_data_template_data_id, local_data_id, data_template_id, data_input_id,
				t_name, name, name_cache, data_source_path,
				t_active, active, t_rrd_step, rrd_step,
				t_data_source_profile_id, data_source_profile_id
			) VALUES (
				0, 0, ?, ?, '', ?, ?, ?, '', 'on', '', ?, '', ?
			)",
			array(
				$current,
				$data_input_id,
				$name_pattern,
				$name_pattern,
				$expected_data_source_path,
				10,
				$profile_id
			)
		);
	} elseif ($template_data['data_source_path'] !== $expected_data_source_path) {
		db_execute_prepared(
			'UPDATE data_template_data SET data_source_path = ? WHERE id = ?',
			array($expected_data_source_path, $template_data['id'])
		);
	}

	gnmi_set_setting_value('gnmi_data_template_id', $current);
	$cached_id = (int)$current;
	return $cached_id;
}

/**
 * Detect a metric set label based on available metrics.
 *
 * @param array $metrics Metric rows from plugin_gnmi_metrics
 * @return string
 */
function gnmi_detect_metric_set(array $metrics) {
	if (empty($metrics)) {
		return 'custom';
	}

	$names = array();
	foreach ($metrics as $metric) {
		$names[] = strtolower(str_replace('-', '_', $metric['metric_name']));
		$names[] = strtolower(str_replace('-', '_', $metric['cacti_field_name']));
	}
	$names = array_unique($names);

	$traffic_metrics = array('in_octets', 'out_octets', 'in_pkts', 'out_pkts');
	$error_metrics = array('in_errors', 'out_errors', 'in_crc_error_pkts', 'out_crc_error_pkts');

	if (count(array_intersect($names, $traffic_metrics)) >= 2) {
		return 'interface_traffic';
	}

	if (count(array_intersect($names, $error_metrics)) >= 2) {
		return 'interface_errors';
	}

	return 'custom';
}

/**
 * Fetch a Data Input Field ID by name with caching.
 *
 * @param int $data_input_id
 * @param string $data_name
 * @return int|null
 */
function gnmi_get_data_input_field_id($data_input_id, $data_name) {
	static $cache = array();
	$cache_key = $data_input_id . ':' . $data_name;

	if (isset($cache[$cache_key])) {
		return $cache[$cache_key];
	}

	$field_id = db_fetch_cell_prepared(
		'SELECT id FROM data_input_fields WHERE data_input_id = ? AND data_name = ?',
		array($data_input_id, $data_name)
	);

	if ($field_id) {
		$cache[$cache_key] = (int)$field_id;
		return $cache[$cache_key];
	}

	return null;
}

/**
 * Build daemon configuration from database subscriptions (Phase 3.3)
 *
 * Reads device connection info and enabled subscriptions from database,
 * builds configuration JSON for Python daemon.
 *
 * @param int $device_id gNMI device ID
 * @return array|false Daemon configuration array or false if no subscriptions
 */
function gnmi_build_daemon_config($device_id) {
	global $config;

	if (empty($device_id) || !is_numeric($device_id)) {
		cacti_log('gNMI: Invalid device_id for daemon config', false, 'PLUGIN');
		return false;
	}

	// Get device connection info
	$device = db_fetch_row_prepared(
		'SELECT * FROM plugin_gnmi_devices WHERE id = ?',
		array($device_id)
	);

	if (!$device) {
		cacti_log("gNMI: Device $device_id not found", false, 'PLUGIN');
		return false;
	}

	// Phase 3.3: Get subscriptions from database
	$subscriptions = db_fetch_assoc_prepared(
		'SELECT * FROM plugin_gnmi_subscriptions
		 WHERE device_id = ? AND enabled = 1',
		array($device_id)
	);

	if (empty($subscriptions)) {
		cacti_log("gNMI: No enabled subscriptions for device $device_id", false, 'POLLER', POLLER_VERBOSITY_LOW);
		return false;
	}

	// Build subscription list with metrics
	$subscription_configs = [];
	foreach ($subscriptions as $sub) {
		$metrics = db_fetch_assoc_prepared(
			'SELECT metric_name, cacti_field_name, rrd_type
			 FROM plugin_gnmi_metrics
			 WHERE subscription_id = ? AND enabled = 1',
			array($sub['id'])
		);

		if (empty($metrics)) {
			continue; // Skip subscriptions with no metrics
		}

		$subscription_configs[] = [
			'path' => $sub['subscription_path'],
			'instance' => $sub['instance_identifier'],
			'metrics' => array_column($metrics, 'metric_name'),
			'field_mapping' => array_column($metrics, 'cacti_field_name', 'metric_name')
		];
	}

	if (empty($subscription_configs)) {
		cacti_log("gNMI: No subscriptions with metrics for device $device_id", false, 'POLLER', POLLER_VERBOSITY_LOW);
		return false;
	}

	// Build full daemon config
	return [
		'device_id' => $device_id,
		'host_id' => $device['host_id'],
		'hostname' => $device['hostname'],
		'port' => (int)$device['port'],
		'username' => $device['username'],
		'password' => $device['password'],
		// pygnmi's insecure flag is the inverse of the user-facing TLS setting.
		'insecure' => !(bool)$device['use_tls'],
		'use_tls' => (bool)$device['use_tls'],
		'compatibility_mode' => (($device['compatibility_mode'] ?? 'standard') === 'ciena_saos10')
			? 'ciena_saos10'
			: 'standard',
		'skip_verify' => (bool)$device['skip_verify'],
		'tls_cipher_policy' => (($device['tls_cipher_policy'] ?? 'default') === 'legacy_compatibility')
			? 'legacy_compatibility'
			: 'default',
		'ca_cert' => !empty($device['ca_cert_path']) ? $device['ca_cert_path'] : '',
		'client_key' => !empty($device['client_key_path']) ? $device['client_key_path'] : '',
		'client_cert' => !empty($device['client_cert_path']) ? $device['client_cert_path'] : '',
		'tls_override' => !empty($device['tls_override']) ? $device['tls_override'] : '',
		'encoding' => $device['encoding'],
		'subscription_mode' => 'STREAM',
		'sample_interval' => 5,
		'collection_interval' => (int)$device['collection_interval'],
		// Interval-aware values: daemon uses these to size buffers and detect staleness
		'poller_interval' => gnmi_get_poller_interval(),
		'staleness_threshold' => gnmi_get_poller_interval() * 2,
		'subscriptions' => $subscription_configs  // NEW: dynamic subscriptions
	];
}

/**
 * Find all orphaned daemon PID files (device not in database or disabled).
 *
 * Checks all PID files in storage directory and validates each device_id
 * exists in plugin_gnmi_devices with enabled=1.
 *
 * @return array List of orphan device_ids with PID info
 *               Format: [['device_id' => 999, 'pid' => 12345, 'pid_file' => '/path', 'reason' => 'not_in_db'], ...]
 */
function gnmi_find_orphan_pids() {
	$storage_dir = gnmi_get_storage_dir();
	$orphans = [];

	// Get all valid enabled device IDs
	$valid_devices = db_fetch_assoc('SELECT id FROM plugin_gnmi_devices WHERE enabled = 1');
	if (!is_array($valid_devices)) {
		cacti_log('gNMI: Unable to query enabled devices while checking orphan PIDs', false, 'POLLER', POLLER_VERBOSITY_LOW);
		return $orphans;
	}
	$valid_ids = array_column($valid_devices, 'id');

	// Scan for PID files
	$pid_files = glob("$storage_dir/device_*.pid");

	if (empty($pid_files)) {
		return $orphans;  // No PID files found
	}

	foreach ($pid_files as $pid_file) {
		// Extract device_id from filename: device_123.pid → 123
		if (preg_match('/device_(\d+)\.pid$/', basename($pid_file), $matches)) {
			$device_id = intval($matches[1]);
			$pid = intval(@file_get_contents($pid_file));

			// Check if device_id is in valid enabled list
			if (!in_array($device_id, $valid_ids)) {
				// Not in enabled list - check if it exists but is disabled, or doesn't exist at all
				$device = db_fetch_row_prepared(
					'SELECT id, enabled FROM plugin_gnmi_devices WHERE id = ?',
					array($device_id)
				);

				$reason = empty($device) ? 'not_in_db' : 'disabled';

				$orphans[] = array(
					'device_id' => $device_id,
					'pid' => $pid,
					'pid_file' => $pid_file,
					'reason' => $reason
				);

				cacti_log("gNMI: Orphan PID file detected - device_id=$device_id, PID=$pid, reason=$reason", false, 'POLLER', POLLER_VERBOSITY_DEBUG);
			}
		}
	}

	return $orphans;
}

/**
 * Find orphaned daemon processes running without PID files.
 *
 * Scans system processes for gnmi_daemon.py processes and checks if they
 * have corresponding PID files. Processes without PID files are orphans.
 *
 * @return array List of orphan processes
 *               Format: [['device_id' => 1, 'pid' => 12345, 'reason' => 'no_pid_file'], ...]
 */
function gnmi_find_orphan_processes() {
	$orphans = [];
	$storage_dir = gnmi_get_storage_dir();

	// Get all gnmi_daemon.py processes
	$ps_output = shell_exec("ps aux | grep '[g]nmi_daemon.py'");

	if (empty($ps_output)) {
		return $orphans;
	}

	$lines = explode("\n", trim($ps_output));

	foreach ($lines as $line) {
		// Parse: www-data  12345  ... --device-id 999 ...
		if (preg_match('/\s+(\d+)\s+.*--device-id\s+(\d+)/', $line, $matches)) {
			$pid = intval($matches[1]);
			$device_id = intval($matches[2]);

			// Check if PID file exists
			$expected_pid_file = "$storage_dir/device_{$device_id}.pid";

			if (!file_exists($expected_pid_file)) {
				// No PID file at all - orphan
				$orphans[] = array(
					'device_id' => $device_id,
					'pid' => $pid,
					'pid_file' => null,
					'reason' => 'no_pid_file'
				);

				cacti_log("gNMI: Orphan process detected - device_id=$device_id PID=$pid (no PID file)", false, 'POLLER', POLLER_VERBOSITY_DEBUG);
			} else {
				// PID file exists - check if THIS PID matches the file
				$registered_pid = intval(@file_get_contents($expected_pid_file));

				if ($pid != $registered_pid) {
					// Process PID doesn't match PID file - duplicate orphan
					$orphans[] = array(
						'device_id' => $device_id,
						'pid' => $pid,
						'pid_file' => $expected_pid_file,
						'reason' => 'duplicate'
					);

					cacti_log("gNMI: Duplicate daemon detected - device_id=$device_id PID=$pid (registered PID=$registered_pid)", false, 'POLLER', POLLER_VERBOSITY_DEBUG);
				}
			}
		}
	}

	return $orphans;
}

/**
 * Clean up all orphaned daemon processes and their files.
 *
 * Finds orphaned daemons (not in database or disabled), kills processes
 * gracefully (SIGTERM → SIGKILL), and removes PID/config/JSON files.
 *
 * Called at the start of gnmi_manage_daemons() every poller cycle.
 *
 * @return int Number of orphans cleaned up
 */
function gnmi_cleanup_orphans() {
	$storage_dir = gnmi_get_storage_dir();
	$logs_dir = gnmi_get_logs_dir();
	$orphans_killed = 0;

	// Find all orphan PID files
	$orphans_from_files = gnmi_find_orphan_pids();

	// Find orphaned processes without PID files
	$orphans_from_processes = gnmi_find_orphan_processes();

	// Merge both lists
	$orphans = array_merge($orphans_from_files, $orphans_from_processes);

	if (empty($orphans)) {
		return 0;  // No orphans to clean up
	}

	cacti_log("gNMI: Found " . count($orphans) . " orphan(s) to clean up", false, 'POLLER', POLLER_VERBOSITY_MEDIUM);

	foreach ($orphans as $orphan) {
		$device_id = $orphan['device_id'];
		$pid = $orphan['pid'];
		$pid_file = $orphan['pid_file'];
		$reason = $orphan['reason'];

		cacti_log("gNMI: Cleaning orphan - device_id=$device_id PID=$pid reason=$reason", false, 'POLLER', POLLER_VERBOSITY_MEDIUM);

		// Kill process if it exists
		// Use numeric signal values for compatibility with PHP web context (SIGTERM/SIGKILL constants
		// are only available when pcntl extension is loaded, which is CLI-only in most setups)
		$sigterm = defined('SIGTERM') ? SIGTERM : 15;
		$sigkill = defined('SIGKILL') ? SIGKILL : 9;
		if ($pid > 0 && posix_kill($pid, 0)) {
			// Try graceful shutdown first
			posix_kill($pid, $sigterm);
			sleep(2);

			// Force kill if still running
			if (posix_kill($pid, 0)) {
				posix_kill($pid, $sigkill);
				cacti_log("gNMI: Force killed orphan PID=$pid (device_id=$device_id)", false, 'POLLER', POLLER_VERBOSITY_MEDIUM);
			} else {
				cacti_log("gNMI: Gracefully stopped orphan PID=$pid (device_id=$device_id)", false, 'POLLER', POLLER_VERBOSITY_DEBUG);
			}

			$orphans_killed++;
		} else {
			// PID file exists but process doesn't - just clean up files
			cacti_log("gNMI: Removing stale files for device_id=$device_id (PID=$pid not running)", false, 'POLLER', POLLER_VERBOSITY_DEBUG);
		}

		// Clean up all files for this orphaned device
		gnmi_remove_orphan_pid_file($pid_file);

		$config_file = "$storage_dir/device_{$device_id}_config.json";
		if (file_exists($config_file)) {
			@unlink($config_file);
		}

		$json_file = "$storage_dir/device_{$device_id}.json";
		if (file_exists($json_file)) {
			@unlink($json_file);
		}

		$log_file = "$logs_dir/device_{$device_id}.log";
		if (file_exists($log_file)) {
			@unlink($log_file);
		}
	}

	cacti_log("gNMI: Orphan cleanup complete - removed " . count($orphans) . " orphan(s), killed $orphans_killed process(es)", false, 'POLLER', POLLER_VERBOSITY_MEDIUM);

	// Phase 3.2: Log orphan cleanup event
	if ($orphans_killed > 0 && function_exists('gnmi_log_event')) {
		$first_device_id = !empty($orphans) ? $orphans[0]['device_id'] : 0;
		gnmi_log_event($first_device_id, 'orphan_cleanup', array(
			'orphans_cleaned' => count($orphans),
			'orphans_killed' => $orphans_killed,
			'trigger' => 'auto'
		));
	}

	return $orphans_killed;
}

/**
 * Remove an orphan PID file when one is associated with the process record.
 * Process-discovery orphans intentionally carry a null pid_file.
 *
 * @param mixed $pid_file Candidate PID-file path.
 * @return bool True when a file was removed, false when absent/invalid.
 */
function gnmi_remove_orphan_pid_file($pid_file) {
	if (!is_string($pid_file) || $pid_file === '' || !file_exists($pid_file)) {
		return false;
	}

	return @unlink($pid_file);
}

/**
 * Coerce bool-like device fields for comparing on-disk JSON to plugin_gnmi_devices rows.
 *
 * Daemon config JSON encodes use_tls/skip_verify as booleans; json_decode yields true/false.
 * MySQL typically returns these columns as "0"/"1" strings. A naive (string) cast breaks for
 * false because (string)false === "" while (string)"0" === "0", causing perpetual "config changed".
 *
 * @param mixed $value From json_decode() or a DB row cell
 * @return bool Normalized truth value
 */
function gnmi_coerce_daemon_config_bool($value) {
	if ($value === null) {
		return false;
	}
	if (is_bool($value)) {
		return $value;
	}
	if (is_int($value) || is_float($value)) {
		return ((int) $value) !== 0;
	}
	if (is_string($value)) {
		$v = strtolower(trim($value));
		if ($v === '' || $v === '0' || $v === 'false' || $v === 'no' || $v === 'off') {
			return false;
		}
		if ($v === '1' || $v === 'true' || $v === 'yes' || $v === 'on') {
			return true;
		}
		// Numeric strings other than 0/1 (unexpected for tinyint/boolean columns)
		if (is_numeric($v)) {
			return ((int) $v) !== 0;
		}
		return false;
	}
	return (bool) $value;
}

/**
 * Check if subscription/metric config has changed since daemon was last started.
 *
 * Compares the subscriptions portion of the current database state with the
 * on-disk config file written when the daemon last started. This detects
 * added/removed/changed metrics and subscriptions.
 *
 * @param int $device_id gNMI device ID
 * @param array|null $db_subscriptions Pre-built subscriptions array (for testing). If null, builds from DB.
 * @param string|null $storage_dir Override storage directory (for testing). If null, uses default.
 * @return bool True if config has changed and daemon needs restart
 */
function gnmi_check_subscription_config_changed($device_id, $db_subscriptions = null, $storage_dir = null) {
	if ($storage_dir === null) {
		$storage_dir = gnmi_get_storage_dir();
	}
	$config_file = "$storage_dir/device_{$device_id}_config.json";

	// If no config file on disk, config has "changed" (daemon needs fresh config)
	if (!file_exists($config_file)) {
		return true;
	}

	// Read on-disk config
	$disk_content = @file_get_contents($config_file);
	if ($disk_content === false || empty($disk_content)) {
		return true;
	}

	$disk_config = json_decode($disk_content, true);
	if ($disk_config === null) {
		return true; // Corrupt file
	}

	// Get current subscriptions from database if not provided
	if ($db_subscriptions === null) {
		$db_config = gnmi_build_daemon_config($device_id);
		if ($db_config === false) {
			// No subscriptions in DB - if daemon has subscriptions on disk, that's a change
			return !empty($disk_config['subscriptions']);
		}
		$db_subscriptions = $db_config['subscriptions'] ?? [];
	}

	// Compare subscription portions using json_encode for canonical comparison
	$disk_subs = json_encode($disk_config['subscriptions'] ?? []);
	$db_subs = json_encode($db_subscriptions);

	if ($disk_subs !== $db_subs) {
		return true;
	}

	// Also check key device-level config fields (connection + interval)
	$device = db_fetch_row_prepared('SELECT * FROM plugin_gnmi_devices WHERE id = ?', array($device_id));
	if ($device) {
		$fields_to_check = ['collection_interval', 'hostname', 'port', 'username', 'password', 'use_tls', 'tls_override', 'skip_verify', 'tls_cipher_policy', 'encoding', 'compatibility_mode'];
		// Booleans in JSON vs "0"/"1" from MySQL — must not use (string) cast (see gnmi_coerce_daemon_config_bool).
		$bool_fields = ['use_tls', 'skip_verify'];
		foreach ($fields_to_check as $field) {
			if (in_array($field, $bool_fields, true)) {
				$disk_b = gnmi_coerce_daemon_config_bool(isset($disk_config[$field]) ? $disk_config[$field] : null);
				$db_b   = gnmi_coerce_daemon_config_bool(isset($device[$field]) ? $device[$field] : null);
				if ($disk_b !== $db_b) {
					return true;
				}
				continue;
			}
			$disk_val = isset($disk_config[$field]) ? (string) $disk_config[$field] : '';
			$db_val   = isset($device[$field]) ? (string) $device[$field] : '';
			if ($disk_val !== $db_val) {
				return true;
			}
		}
	}

	return false;
}

/**
 * Manage all gNMI daemons - check health and start if needed.
 * Also collects telemetry data and updates RRD files.
 *
 * Called by poller_bottom hook every 10 seconds (when Cacti configured for 10s polling).
 * Ensures all enabled gNMI devices have running daemons, then collects telemetry.
 */
function gnmi_manage_daemons() {
	global $config;

	// Load status_functions so gnmi_log_event() is available during poller cycles
	$status_functions_path = $config['base_path'] . '/plugins/gnmi/include/status_functions.php';
	if (file_exists($status_functions_path) && !function_exists('gnmi_log_event')) {
		include_once($status_functions_path);
	}

	cacti_log("gNMI: gnmi_manage_daemons() called", false, 'POLLER', POLLER_VERBOSITY_LOW);

	// Safety check: Ensure venv exists (defensive programming)
	$python_bin = gnmi_get_python_binary();
	if ($python_bin === false) {
		cacti_log('gNMI: Virtual environment not found. Daemon management skipped.', false, 'SYSTEM');
		return;
	}

	// STEP 1: Clean up orphaned daemons FIRST (before managing valid daemons)
	gnmi_cleanup_orphans();

	// STEP 2: Get all enabled gNMI devices
	$devices = db_fetch_assoc('SELECT * FROM plugin_gnmi_devices WHERE enabled = 1');

	if (empty($devices)) {
		cacti_log("gNMI: No enabled devices found", false, 'POLLER', POLLER_VERBOSITY_LOW);
		return; // No devices to manage
	}

	cacti_log("gNMI: Found " . count($devices) . " enabled device(s)", false, 'POLLER', POLLER_VERBOSITY_LOW);

	// $python_bin already validated above
	$script_path = $config['base_path'] . '/plugins/gnmi/scripts';
	$daemon_ctl = $script_path . '/gnmi_daemon_ctl.py';

	foreach ($devices as $device) {
		$device_id = $device['id'];
		$storage_dir = gnmi_get_storage_dir();
		$logs_dir = gnmi_get_logs_dir();

		// Check daemon health
		$health_cmd = escapeshellarg($python_bin) . " " . escapeshellarg($daemon_ctl) .
			" health --device-id " . escapeshellarg($device_id) .
			" --storage-dir " . escapeshellarg($storage_dir) .
			" --log-dir " . escapeshellarg($logs_dir) .
			" --staleness-threshold " . escapeshellarg(gnmi_get_poller_interval() * 2) .
			" 2>&1";
		$health_output = shell_exec($health_cmd);

		// Add null check before json_decode
		if (empty($health_output)) {
			cacti_log("gNMI: No output from health check for device $device_id", false, 'POLLER', POLLER_VERBOSITY_DEBUG);
			gnmi_start_daemon($device);
			continue;
		}

		$health = json_decode($health_output, true);

		// Add validation after decode
		if ($health === null || !is_array($health)) {
			cacti_log("gNMI: Invalid health response for device $device_id: $health_output", false, 'POLLER', POLLER_VERBOSITY_LOW);
			gnmi_start_daemon($device);
			continue;
		}

		// Now safe to check array keys
		if (!isset($health['running']) || !$health['running']) {
			cacti_log("gNMI: Starting daemon for device $device_id ({$device['hostname']})", false, 'POLLER', POLLER_VERBOSITY_MEDIUM);
			gnmi_start_daemon($device);
		} elseif (isset($health['stale']) && $health['stale']) {
			// Data is stale, restart daemon
			cacti_log("gNMI: Restarting daemon for device $device_id (stale data)", false, 'POLLER', POLLER_VERBOSITY_MEDIUM);
			gnmi_restart_daemon($device);
		} elseif (gnmi_check_subscription_config_changed($device_id)) {
			// Subscription/metric config changed in DB since daemon started
			cacti_log("gNMI: Restarting daemon for device $device_id (subscription config changed)", false, 'POLLER', POLLER_VERBOSITY_MEDIUM);
			gnmi_restart_daemon($device);
		}
	}

	// After managing daemons, collect telemetry data
	gnmi_collect_telemetry();
}

/**
 * Start a daemon for a specific device.
 *
 * @param array $device Device record from database
 * @return bool Success status
 */
function gnmi_start_daemon(array $device) {
	global $config;

	$python_bin = gnmi_get_python_binary();
	if ($python_bin === false) {
		cacti_log("gNMI: Cannot start daemon - virtual environment not found", false, 'POLLER', POLLER_VERBOSITY_LOW);
		return false;
	}

	$script_path = $config['base_path'] . '/plugins/gnmi/scripts';
	$daemon_ctl = $script_path . '/gnmi_daemon_ctl.py';
	$device_id = $device['id'];
	$storage_dir = gnmi_get_storage_dir();
	$logs_dir = gnmi_get_logs_dir();
	$pid_file = "$storage_dir/device_{$device_id}.pid";

	// STEP 1: Validate PID file (prevent duplicate starts)
	$pid_check = gnmi_validate_pid_file($device_id, $pid_file);

	if ($pid_check['status'] === 'valid') {
		cacti_log("gNMI: Daemon already running for device $device_id (PID {$pid_check['pid']})", false, 'POLLER', POLLER_VERBOSITY_DEBUG);
		return true; // Don't start duplicate
	}

	// STEP 2: PID file is missing, stale, or invalid - safe to start
	// Build configuration JSON for daemon (Phase 3.3: dynamic subscriptions)
	$daemon_config = gnmi_build_daemon_config($device_id);

	if ($daemon_config === false) {
		cacti_log("gNMI: Cannot start daemon for device $device_id - no enabled subscriptions", false, 'POLLER', POLLER_VERBOSITY_LOW);
		return false;
	}

	$config_json = json_encode($daemon_config);
	$config_arg = escapeshellarg($config_json);
	$storage_dir = gnmi_get_storage_dir();
	$logs_dir = gnmi_get_logs_dir();

	// Start daemon
	$start_cmd = escapeshellarg($python_bin) . " " . escapeshellarg($daemon_ctl) . " start --device-id " . escapeshellarg($device_id) . " --config " . $config_arg . " --storage-dir " . escapeshellarg($storage_dir) . " --log-dir " . escapeshellarg($logs_dir) . " 2>&1";
	$output = shell_exec($start_cmd);

	// Add null check before strpos
	if (empty($output)) {
		cacti_log("gNMI: No output from daemon start command for device $device_id", false, 'POLLER', POLLER_VERBOSITY_LOW);
		return false;
	}

	// Log result
	if (strpos($output, 'Daemon started') !== false) {
		cacti_log("gNMI: Daemon started successfully for device $device_id", false, 'POLLER', POLLER_VERBOSITY_DEBUG);

		// Phase 3.2: Log daemon start event
		if (function_exists('gnmi_log_event')) {
			gnmi_log_event($device_id, 'daemon_start', array(
				'method' => 'auto',
				'trigger' => 'poller_cycle'
			));
		}

		return true;
	} else {
		cacti_log("gNMI: Failed to start daemon for device $device_id: $output", false, 'POLLER', POLLER_VERBOSITY_LOW);
		return false;
	}
}

/**
 * Restart a daemon for a specific device.
 *
 * @param array $device Device record from database
 * @return bool Success status
 */
function gnmi_restart_daemon(array $device) {
	global $config;

	// Ensure gnmi_log_event() is available regardless of call context
	$status_functions_path = $config['base_path'] . '/plugins/gnmi/include/status_functions.php';
	if (file_exists($status_functions_path) && !function_exists('gnmi_log_event')) {
		include_once($status_functions_path);
	}

	$script_path = $config['base_path'] . '/plugins/gnmi/scripts';
	$daemon_ctl = $script_path . '/gnmi_daemon_ctl.py';
	$python_bin = gnmi_get_python_binary();
	$device_id = $device['id'];

	// Build configuration JSON for daemon (Phase 3.3: dynamic subscriptions)
	$daemon_config = gnmi_build_daemon_config($device_id);

	if ($daemon_config === false) {
		cacti_log("gNMI: Cannot restart daemon for device $device_id - no enabled subscriptions", false, 'POLLER', POLLER_VERBOSITY_LOW);
		return false;
	}

	$config_json = json_encode($daemon_config);
	$config_arg = escapeshellarg($config_json);
	$storage_dir = gnmi_get_storage_dir();
	$logs_dir = gnmi_get_logs_dir();

	// Restart daemon
	$restart_cmd = escapeshellarg($python_bin) . " " . escapeshellarg($daemon_ctl) . " restart --device-id " . escapeshellarg($device_id) . " --config " . $config_arg . " --storage-dir " . escapeshellarg($storage_dir) . " --log-dir " . escapeshellarg($logs_dir) . " 2>&1";
	$output = shell_exec($restart_cmd);

	if (empty($output)) {
		return false;
	}

	// The Cacti poller can start the daemon between restart's stop and start
	// phases. daemon_ctl verifies PID ownership before reporting this state.
	$success = (strpos($output, 'Daemon started') !== false ||
		strpos($output, 'Daemon already running') !== false);

	// Phase 3.2: Log daemon restart event
	if ($success && function_exists('gnmi_log_event')) {
		gnmi_log_event($device_id, 'daemon_restart', array(
			'reason' => 'config_change',
			'trigger' => 'auto'
		));
	}

	return $success;
}

/**
 * Validate a PID file matches an actual running gNMI daemon process.
 *
 * Checks:
 * 1. PID file exists
 * 2. PID value is valid integer
 * 3. Process with that PID exists
 * 4. Process is actually gnmi_daemon.py (not another process)
 *
 * Side Effects: Removes stale or invalid PID files
 *
 * @param int $device_id Device ID
 * @param string $pid_file Path to PID file
 * @return array Status array with keys: 'status', 'pid'
 *               Possible statuses: 'valid', 'missing', 'stale', 'wrong_process', 'invalid'
 */
function gnmi_validate_pid_file($device_id, $pid_file) {
	// Check if file exists
	if (!file_exists($pid_file)) {
		return array('status' => 'missing', 'pid' => null);
	}

	// Read PID from file
	$pid_content = @file_get_contents($pid_file);
	if ($pid_content === false || empty(trim($pid_content))) {
		return array('status' => 'invalid', 'pid' => null);
	}

	$pid = intval(trim($pid_content));
	if ($pid <= 0) {
		return array('status' => 'invalid', 'pid' => 0);
	}

	// Check if process exists
	if (!posix_kill($pid, 0)) {
		// Process doesn't exist - stale PID file
		cacti_log("gNMI: Stale PID file for device $device_id (PID $pid not running)", false, 'POLLER', POLLER_VERBOSITY_DEBUG);
		@unlink($pid_file);
		return array('status' => 'stale', 'pid' => $pid);
	}

	// Verify it's actually a gnmi_daemon.py process
	// Note: /proc only available on Linux, this check is optional
	$cmdline_file = "/proc/$pid/cmdline";
	if (file_exists($cmdline_file)) {
		$cmdline = @file_get_contents($cmdline_file);
		if ($cmdline && strpos($cmdline, 'gnmi_daemon.py') === false) {
			cacti_log("gNMI: PID $pid is not a gNMI daemon (device $device_id)", false, 'POLLER', POLLER_VERBOSITY_LOW);
			@unlink($pid_file);
			return array('status' => 'wrong_process', 'pid' => $pid);
		}
	}

	return array('status' => 'valid', 'pid' => $pid);
}

/**
 * Stop daemon for a specific device.
 *
 * Calls daemon_ctl.py stop command, which sends SIGTERM and waits
 * for graceful shutdown (up to 10 seconds), then SIGKILL if needed.
 *
 * @param int $device_id Device ID (daemon identifier)
 * @return bool True if daemon stopped or was not running
 */
function gnmi_stop_daemon($device_id) {
	global $config;

	$python_bin = gnmi_get_python_binary();
	if ($python_bin === false) {
		cacti_log("gNMI: Cannot stop daemon - virtual environment not found", false, 'POLLER', POLLER_VERBOSITY_LOW);
		return false;
	}

	$script_path = $config['base_path'] . '/plugins/gnmi/scripts';
	$daemon_ctl = $script_path . '/gnmi_daemon_ctl.py';
	$storage_dir = gnmi_get_storage_dir();
	$logs_dir = gnmi_get_logs_dir();

	// Call daemon control stop command
	$stop_cmd = escapeshellarg($python_bin) . " " . escapeshellarg($daemon_ctl) .
	            " stop --device-id " . escapeshellarg($device_id) .
	            " --storage-dir " . escapeshellarg($storage_dir) .
	            " --log-dir " . escapeshellarg($logs_dir) . " 2>&1";

	$output = shell_exec($stop_cmd);

	// Check output
	if (empty($output)) {
		cacti_log("gNMI: No output from daemon stop for device_id=$device_id", false, 'POLLER', POLLER_VERBOSITY_LOW);
		return false;
	}

	// Success if stopped or was not running
	if (strpos($output, 'Daemon stopped') !== false ||
	    strpos($output, 'not running') !== false) {
		cacti_log("gNMI: Daemon stopped for device_id=$device_id", false, 'POLLER', POLLER_VERBOSITY_DEBUG);

		// Phase 3.2: Log daemon stop event
		if (function_exists('gnmi_log_event') && strpos($output, 'Daemon stopped') !== false) {
			gnmi_log_event($device_id, 'daemon_stop', array(
				'method' => 'graceful',
				'trigger' => 'user_action'
			));
		}

		return true;
	}

	cacti_log("gNMI: Failed to stop daemon for device_id=$device_id: $output", false, 'POLLER', POLLER_VERBOSITY_LOW);
	return false;
}

/**
 * Restart daemon for a device by host_id.
 *
 * Looks up device record from database by host_id, then calls gnmi_restart_daemon().
 * Used when config changes detected in form save.
 *
 * @param int $host_id Cacti host ID
 * @return bool True if restart initiated successfully
 */
function gnmi_restart_daemon_by_host_id($host_id) {
	$device = db_fetch_row_prepared(
		'SELECT * FROM plugin_gnmi_devices WHERE host_id = ?',
		array($host_id)
	);

	if (empty($device)) {
		cacti_log("gNMI: No device found for host_id=$host_id", false, 'POLLER', POLLER_VERBOSITY_LOW);
		return false;
	}

	cacti_log("gNMI: Restarting daemon for host_id=$host_id (device_id={$device['id']})", false, 'POLLER', POLLER_VERBOSITY_MEDIUM);

	return gnmi_restart_daemon($device);
}

/**
 * Attempt to install Python dependencies in the virtual environment.
 *
 * Installs and verifies all runtime packages from requirements.txt.
 * Logs success/failure but doesn't fail the plugin installation if this fails
 * (user can install manually if needed).
 *
 * @return array Runtime dependency availability after the install attempt
 */
function gnmi_auto_install_dependencies() {
	global $config;

	$result = array(
		'rrdtool' => false,
		'pygnmi' => false,
		'pymysql' => false
	);

	$python_bin = gnmi_get_python_binary();
	if ($python_bin === false) {
		cacti_log('gNMI Plugin: Cannot auto-install dependencies - virtual environment not found.', false, 'INSTALL', POLLER_VERBOSITY_LOW);
		return $result;
	}

	$req_path = $config['base_path'] . '/plugins/gnmi/scripts/requirements.txt';

	if (!file_exists($req_path)) {
		cacti_log('gNMI Plugin: requirements.txt not found at ' . $req_path, false, 'INSTALL', POLLER_VERBOSITY_LOW);
		return $result;
	}

	// Install all dependencies from requirements.txt
	cacti_log('gNMI Plugin: Attempting to auto-install Python dependencies from requirements.txt...', false, 'INSTALL', POLLER_VERBOSITY_MEDIUM);
	$install_cmd = escapeshellarg($python_bin) . ' -m pip install --quiet -r ' . escapeshellarg($req_path) . ' 2>&1';
	$install_out = array();
	$install_rc = 0;
	@exec($install_cmd, $install_out, $install_rc);

	if ($install_rc === 0) {
		// Check if packages were actually installed
		$result['rrdtool'] = gnmi_is_rrdtool_available();
		$result['pygnmi'] = gnmi_are_python_deps_available();
		$result['pymysql'] = gnmi_is_pymysql_available();

		if ($result['rrdtool'] && $result['pygnmi'] && $result['pymysql']) {
			cacti_log('gNMI Plugin: Successfully auto-installed all Python dependencies.', false, 'INSTALL', POLLER_VERBOSITY_MEDIUM);
		} else {
			cacti_log('gNMI Plugin: pip install completed but some packages may not be available. rrdtool=' . ($result['rrdtool'] ? 'OK' : 'MISSING') . ', pygnmi=' . ($result['pygnmi'] ? 'OK' : 'MISSING') . ', pymysql=' . ($result['pymysql'] ? 'OK' : 'MISSING'), false, 'INSTALL', POLLER_VERBOSITY_LOW);
		}
	} else {
		$error_msg = implode("\n", $install_out);
		cacti_log('gNMI Plugin: Failed to auto-install Python dependencies from requirements.txt. Error: ' . $error_msg, false, 'INSTALL', POLLER_VERBOSITY_LOW);

		// Check what actually got installed despite the error
		$result['rrdtool'] = gnmi_is_rrdtool_available();
		$result['pygnmi'] = gnmi_are_python_deps_available();
		$result['pymysql'] = gnmi_is_pymysql_available();
	}

	return $result;
}

/**
 * Check if librrd-dev system package is available.
 *
 * For Debian/Ubuntu: Checks if librrd-dev package is installed
 * For RHEL/CentOS: Checks if rrdtool-devel package is installed
 *
 * @return bool True if development headers are available
 */
function gnmi_is_rrdtool_dev_available() {
	// Try Debian/Ubuntu first (dpkg)
	$dpkg_cmd = 'dpkg -l 2>/dev/null | grep -E "^ii\s+librrd-dev" 2>&1';
	$dpkg_out = array();
	$dpkg_rc = 0;
	@exec($dpkg_cmd, $dpkg_out, $dpkg_rc);

	if ($dpkg_rc === 0 && !empty($dpkg_out)) {
		return true; // Found librrd-dev on Debian/Ubuntu
	}

	// Try RHEL/CentOS (rpm)
	$rpm_cmd = 'rpm -q rrdtool-devel 2>&1';
	$rpm_out = array();
	$rpm_rc = 0;
	@exec($rpm_cmd, $rpm_out, $rpm_rc);

	if ($rpm_rc === 0 && !empty($rpm_out) && strpos($rpm_out[0], 'not installed') === false) {
		return true; // Found rrdtool-devel on RHEL/CentOS
	}

	return false; // Package not found
}

/**
 * Check if python3-dev system package is available.
 *
 * For Debian/Ubuntu: Checks if python3-dev package is installed
 * For RHEL/CentOS: Checks if python3-devel package is installed
 *
 * @return bool True if Python dev headers are available
 */
function gnmi_is_python_dev_available() {
	// Try Debian/Ubuntu first (dpkg)
	$dpkg_cmd = 'dpkg -l 2>/dev/null | grep -E "^ii\s+python3-dev" 2>&1';
	$dpkg_out = array();
	$dpkg_rc = 0;
	@exec($dpkg_cmd, $dpkg_out, $dpkg_rc);

	if ($dpkg_rc === 0 && !empty($dpkg_out)) {
		return true; // Found python3-dev on Debian/Ubuntu
	}

	// Try RHEL/CentOS (rpm)
	$rpm_cmd = 'rpm -q python3-devel 2>&1';
	$rpm_out = array();
	$rpm_rc = 0;
	@exec($rpm_cmd, $rpm_out, $rpm_rc);

	if ($rpm_rc === 0 && !empty($rpm_out) && strpos($rpm_out[0], 'not installed') === false) {
		return true; // Found python3-devel on RHEL/CentOS
	}

	return false; // Package not found
}

/**
 * Check if python3-venv system package is available and functional.
 *
 * The venv module requires the python3-venv system package to be installed.
 * Additionally, ensurepip must be available for venv creation to work.
 * This checks both the venv module and ensurepip availability.
 *
 * @return bool True if venv can actually be created
 */
function gnmi_is_venv_module_available() {
	$system_python = 'python3';

	// First check if venv module exists
	$venv_cmd = escapeshellarg($system_python) . ' -m venv --help 2>&1';
	$venv_out = array();
	$venv_rc = 0;
	@exec($venv_cmd, $venv_out, $venv_rc);
	if ($venv_rc !== 0) {
		return false; // venv module not available
	}

	// Check if ensurepip is available (required for venv creation)
	// Try to import ensurepip in Python - this is more reliable than checking the module
	$ensurepip_cmd = escapeshellarg($system_python) . ' -c "import ensurepip" 2>&1';
	$ensurepip_out = array();
	$ensurepip_rc = 0;
	@exec($ensurepip_cmd, $ensurepip_out, $ensurepip_rc);
	if ($ensurepip_rc !== 0) {
		return false; // ensurepip not available or not importable
	}

	return true; // Both venv module and ensurepip are available
}

/**
 * Ensure virtual environment exists for the plugin.
 *
 * Creates a Python virtual environment if it doesn't exist.
 * The venv will be created at plugins/gnmi/venv/
 *
 * Requires python3-venv system package to be installed.
 *
 * @return bool True if venv exists or was created successfully, false on failure
 */
function gnmi_ensure_venv() {
	global $config;

	$plugin_dir = $config['base_path'] . '/plugins/gnmi';
	$venv_dir = $plugin_dir . '/venv';
	$venv_python = $venv_dir . '/bin/python3';

	// If venv already exists, we're good
	if (file_exists($venv_python)) {
		return true;
	}

	// Check if system python3 is available
	$system_python = 'python3';
	$cmd = escapeshellarg($system_python) . ' --version 2>&1';
	$out = array();
	$rc = 0;
	@exec($cmd, $out, $rc);

	if ($rc !== 0) {
		cacti_log('gNMI Plugin: ERROR - python3 not found in PATH. Cannot create virtual environment.', false, 'INSTALL', POLLER_VERBOSITY_LOW);
		return false;
	}

	// Check if python3-venv module is available (required for venv creation)
	if (!gnmi_is_venv_module_available()) {
		// Try to get Python version to suggest version-specific package
		$version_cmd = escapeshellarg($system_python) . ' --version 2>&1';
		$version_out = array();
		$version_rc = 0;
		@exec($version_cmd, $version_out, $version_rc);
		$python_version = '3.x';
		if ($version_rc === 0 && !empty($version_out)) {
			// Extract version like "Python 3.12.5" -> "3.12"
			if (preg_match('/Python\s+(\d+\.\d+)/', $version_out[0], $matches)) {
				$python_version = $matches[1];
			}
		}

		cacti_log('gNMI Plugin: ERROR - python3-venv module or ensurepip not available. System package python3-venv (or python' . $python_version . '-venv) must be installed.', false, 'INSTALL', POLLER_VERBOSITY_LOW);
		return false;
	}

	// Create venv directory if it doesn't exist
	if (!file_exists($plugin_dir)) {
		@mkdir($plugin_dir, 0755, true);
	}

	// Create virtual environment
	$venv_cmd = escapeshellarg($system_python) . ' -m venv ' . escapeshellarg($venv_dir) . ' 2>&1';
	$venv_out = array();
	$venv_rc = 0;
	@exec($venv_cmd, $venv_out, $venv_rc);

	if ($venv_rc !== 0 || !file_exists($venv_python)) {
		$error_msg = implode("\n", $venv_out);

		// Check if the error is about ensurepip
		$is_ensurepip_error = (stripos($error_msg, 'ensurepip') !== false || stripos($error_msg, 'python3-venv') !== false);

		if ($is_ensurepip_error) {
			// Try to get Python version for better error message
			$version_cmd = escapeshellarg($system_python) . ' --version 2>&1';
			$version_out = array();
			$version_rc = 0;
			@exec($version_cmd, $version_out, $version_rc);
			$python_version = '3.x';
			if ($version_rc === 0 && !empty($version_out)) {
				if (preg_match('/Python\s+(\d+\.\d+)/', $version_out[0], $matches)) {
					$python_version = $matches[1];
				}
			}
			cacti_log('gNMI Plugin: ERROR - ensurepip not available for venv creation. Install python' . $python_version . '-venv package. Error: ' . $error_msg, false, 'INSTALL', POLLER_VERBOSITY_LOW);
		} else {
			cacti_log('gNMI Plugin: ERROR - Failed to create virtual environment: ' . $error_msg, false, 'INSTALL', POLLER_VERBOSITY_LOW);
		}
		return false;
	}

	cacti_log('gNMI Plugin: Created virtual environment at ' . $venv_dir, false, 'INSTALL', POLLER_VERBOSITY_MEDIUM);
	return true;
}

/**
 * Get Python binary path from virtual environment.
 *
 * Requires the venv to exist - returns false if venv not found.
 * Use gnmi_ensure_venv() to create it first.
 *
 * @return string|false Path to Python binary in venv, or false if venv doesn't exist
 */
function gnmi_get_python_binary() {
	global $config;

	$venv_python = $config['base_path'] . '/plugins/gnmi/venv/bin/python3';

	if (file_exists($venv_python)) {
		return $venv_python;
	}

	// Venv doesn't exist - return false instead of falling back to system Python
	return false;
}

/**
 * Check if Python rrdtool binding is available in the virtual environment.
 *
 * Requires venv to exist. Returns false if venv is missing.
 * Uses the same Python interpreter that the daemon will use.
 *
 * @return bool True if rrdtool module is available in venv
 */
function gnmi_is_rrdtool_available() {
    $python_bin = gnmi_get_python_binary();
    if ($python_bin === false) {
        return false; // Venv doesn't exist
    }
    $cmd = escapeshellarg($python_bin) . ' -c "import rrdtool" 2>&1';
    $out = array();
    $rc = 0;
    @exec($cmd, $out, $rc);
    return ($rc === 0);
}

/**
 * Check if Python dependencies (pygnmi and related packages) are available in the virtual environment.
 *
 * Requires venv to exist. Returns false if venv is missing.
 * Uses the same Python interpreter that the daemon will use.
 * Returns true if `import pygnmi` succeeds. This is the primary dependency
 * required for gNMI protocol communication.
 *
 * @return bool True if pygnmi module is available in venv
 */
function gnmi_are_python_deps_available() {
    $python_bin = gnmi_get_python_binary();
    if ($python_bin === false) {
        return false; // Venv doesn't exist
    }
    $cmd = escapeshellarg($python_bin) . ' -c "import pygnmi" 2>&1';
    $out = array();
    $rc = 0;
    @exec($cmd, $out, $rc);
    return ($rc === 0);
}

/**
 * Check whether the bridge database client is importable in the plugin venv.
 *
 * @return bool True if pymysql is available in the runtime venv
 */
function gnmi_is_pymysql_available() {
	$python_bin = gnmi_get_python_binary();
	if ($python_bin === false) {
		return false;
	}

	$cmd = escapeshellarg($python_bin) . ' -c "import pymysql" 2>&1';
	$out = array();
	$rc = 0;
	@exec($cmd, $out, $rc);
	return ($rc === 0);
}

/**
 * Return true only when every daemon and bridge runtime dependency is ready.
 *
 * @param array $state Dependency state
 * @return bool True only when every required state flag is truthy
 */
function gnmi_dependency_state_is_ready($state) {
	$required = array('venv_module', 'rrdtool_dev', 'rrdtool', 'pygnmi', 'pymysql');
	foreach ($required as $name) {
		if (empty($state[$name])) {
			return false;
		}
	}

	return true;
}

/**
 * Unified dependency state checker.
 *
 * Checks all dependencies and updates settings flags.
 * Automatically continues setup when system dependencies become available.
 *
 * @param bool $auto_remediate If true, attempts auto-install of Python deps
 * @return array State array for every required system and Python dependency
 */
function gnmi_check_all_dependencies($auto_remediate = false) {
	$state = array(
		'venv_module' => false,
		'rrdtool_dev' => false,
		'rrdtool' => false,
		'pygnmi' => false,
		'pymysql' => false,
		'all_ok' => false
	);

	// Step 1: Check python3-venv system package
	$venv_module_available = gnmi_is_venv_module_available();
	$venv_module_val = $venv_module_available ? '0' : '1';
	db_execute_prepared("REPLACE INTO settings (name, value) VALUES ('gnmi_req_venv_module_missing', ?)", array($venv_module_val));
	$state['venv_module'] = $venv_module_available;

	// If venv module missing, can't proceed further
	if (!$venv_module_available) {
		db_execute_prepared("REPLACE INTO settings (name, value) VALUES ('gnmi_req_rrdtool_dev_missing', '1')");
		db_execute_prepared("REPLACE INTO settings (name, value) VALUES ('gnmi_req_rrdtool_missing', '1')");
		db_execute_prepared("REPLACE INTO settings (name, value) VALUES ('gnmi_req_pygnmi_missing', '1')");
		db_execute_prepared("REPLACE INTO settings (name, value) VALUES ('gnmi_req_pymysql_missing', '1')");
		return $state;
	}

	// Step 2: Try to create venv if it doesn't exist
	$python_bin = gnmi_get_python_binary();
	if ($python_bin === false) {
		$venv_created = gnmi_ensure_venv();
		if ($venv_created) {
			$python_bin = gnmi_get_python_binary();
		}
	}

	// If venv still doesn't exist, can't check Python deps
	if ($python_bin === false) {
		db_execute_prepared("REPLACE INTO settings (name, value) VALUES ('gnmi_req_rrdtool_dev_missing', '1')");
		db_execute_prepared("REPLACE INTO settings (name, value) VALUES ('gnmi_req_rrdtool_missing', '1')");
		db_execute_prepared("REPLACE INTO settings (name, value) VALUES ('gnmi_req_pygnmi_missing', '1')");
		db_execute_prepared("REPLACE INTO settings (name, value) VALUES ('gnmi_req_pymysql_missing', '1')");
		return $state;
	}

	// Step 3: Check rrdtool dev packages (librrd-dev, python3-dev)
	$rrdtool_dev_available = gnmi_is_rrdtool_dev_available() && gnmi_is_python_dev_available();
	$rrdtool_dev_val = $rrdtool_dev_available ? '0' : '1';
	db_execute_prepared("REPLACE INTO settings (name, value) VALUES ('gnmi_req_rrdtool_dev_missing', ?)", array($rrdtool_dev_val));
	$state['rrdtool_dev'] = $rrdtool_dev_available;

	// Step 4: Check Python dependencies
	$rrdtool_available = gnmi_is_rrdtool_available();
	$rrdtool_val = $rrdtool_available ? '0' : '1';
	db_execute_prepared("REPLACE INTO settings (name, value) VALUES ('gnmi_req_rrdtool_missing', ?)", array($rrdtool_val));
	$state['rrdtool'] = $rrdtool_available;

	$pygnmi_available = gnmi_are_python_deps_available();
	$pygnmi_val = $pygnmi_available ? '0' : '1';
	db_execute_prepared("REPLACE INTO settings (name, value) VALUES ('gnmi_req_pygnmi_missing', ?)", array($pygnmi_val));
	$state['pygnmi'] = $pygnmi_available;

	$pymysql_available = gnmi_is_pymysql_available();
	$pymysql_val = $pymysql_available ? '0' : '1';
	db_execute_prepared("REPLACE INTO settings (name, value) VALUES ('gnmi_req_pymysql_missing', ?)", array($pymysql_val));
	$state['pymysql'] = $pymysql_available;

	// Step 5: Auto-remediation if enabled and conditions met
	if ($auto_remediate) {
		// If venv exists and rrdtool dev packages are now available, try installing rrdtool
		if ($state['rrdtool_dev'] && !$state['rrdtool']) {
			cacti_log('gNMI Plugin: System dev packages available, attempting to auto-install rrdtool...', false, 'INSTALL', POLLER_VERBOSITY_MEDIUM);
			$install_result = gnmi_auto_install_dependencies();
			if ($install_result['rrdtool']) {
				$state['rrdtool'] = true;
				db_execute_prepared("REPLACE INTO settings (name, value) VALUES ('gnmi_req_rrdtool_missing', '0')");
			}
		}

		// Always try to install runtime Python packages if either import is missing.
		if (!$state['pygnmi'] || !$state['pymysql']) {
			cacti_log('gNMI Plugin: Attempting to auto-install runtime Python dependencies...', false, 'INSTALL', POLLER_VERBOSITY_MEDIUM);
			$install_result = gnmi_auto_install_dependencies();
			if ($install_result['pygnmi']) {
				$state['pygnmi'] = true;
				db_execute_prepared("REPLACE INTO settings (name, value) VALUES ('gnmi_req_pygnmi_missing', '0')");
			}
			if ($install_result['pymysql']) {
				$state['pymysql'] = true;
				db_execute_prepared("REPLACE INTO settings (name, value) VALUES ('gnmi_req_pymysql_missing', '0')");
			}
		}
	}

	$state['all_ok'] = gnmi_dependency_state_is_ready($state);
	return $state;
}

/**
 * Determine if all runtime requirements are satisfied.
 *
 * Checks:
 * - python3-venv system package available (required for venv creation)
 * - Virtual environment exists
 * - rrdtool (system package binding) available in venv
 * - pygnmi (Python package for gNMI protocol) available in venv
 * - pymysql (Python package used by the poller bridge) available in venv
 *
 * Uses cached flags in the Cacti `settings` table when present, and
 * self-heals by testing the environment if missing. Auto-detects when
 * dependencies are installed after initial detection.
 *
 * @return bool True if all requirements OK; false if any dependency missing
 */
function gnmi_requirements_ok() {
	$state = gnmi_check_all_dependencies(false);
	return $state['all_ok'];
}

/**
 * Auto-continue setup when system dependencies become available.
 *
 * Called from runtime banner display or poller hook to detect when
 * user has installed missing system dependencies and automatically
 * continue plugin setup (create venv, install Python deps).
 *
 * @return bool True if setup was continued, false if nothing to do
 */
function gnmi_auto_continue_setup() {
	// Check current state
	$dep_state = gnmi_check_all_dependencies(false);

	// If venv module was missing but is now available, try to create venv
	if (!$dep_state['venv_module']) {
		// Re-check venv module
		$venv_module_now = gnmi_is_venv_module_available();
		if ($venv_module_now) {
			cacti_log('gNMI Plugin: python3-venv now available, attempting to create virtual environment...', false, 'INSTALL', POLLER_VERBOSITY_MEDIUM);
			$venv_created = gnmi_ensure_venv();
			if ($venv_created) {
				// Update state
				db_execute_prepared("REPLACE INTO settings (name, value) VALUES ('gnmi_req_venv_module_missing', '0')");
				// Re-run dependency check with auto-remediation
				$dep_state = gnmi_check_all_dependencies(true);
				return true;
			}
		}
		return false;
	}

	// If venv exists and rrdtool dev packages are now available, try installing rrdtool
	$python_bin = gnmi_get_python_binary();
	if ($python_bin !== false && !$dep_state['rrdtool_dev']) {
		$rrdtool_dev_now = gnmi_is_rrdtool_dev_available() && gnmi_is_python_dev_available();
		if ($rrdtool_dev_now) {
			cacti_log('gNMI Plugin: RRDtool dev packages now available, attempting to auto-install rrdtool...', false, 'INSTALL', POLLER_VERBOSITY_MEDIUM);
			db_execute_prepared("REPLACE INTO settings (name, value) VALUES ('gnmi_req_rrdtool_dev_missing', '0')");
			// Re-run dependency check with auto-remediation
			$dep_state = gnmi_check_all_dependencies(true);
			return true;
		}
	}

	// If venv exists and Python deps are missing, try auto-install
	if ($python_bin !== false && (!$dep_state['rrdtool'] || !$dep_state['pygnmi'] || !$dep_state['pymysql'])) {
		// Re-run with auto-remediation
		$old_state = $dep_state;
		$dep_state = gnmi_check_all_dependencies(true);

		// Check if state changed
		if ($old_state['rrdtool'] != $dep_state['rrdtool'] ||
			$old_state['pygnmi'] != $dep_state['pygnmi'] ||
			$old_state['pymysql'] != $dep_state['pymysql']) {
			return true;
		}
	}

	return false;
}

/**
 * Get list of missing dependencies for logging/display purposes.
 *
 * @return array Array of missing dependency names (empty if all present)
 */
function gnmi_get_missing_dependencies() {
    $missing = array();

    $venv_module_val = db_fetch_cell_prepared("SELECT value FROM settings WHERE name = 'gnmi_req_venv_module_missing'");
    if ($venv_module_val === '1') {
        $missing[] = 'python3-venv';
    }

    $rrdtool_dev_val = db_fetch_cell_prepared("SELECT value FROM settings WHERE name = 'gnmi_req_rrdtool_dev_missing'");
    if ($rrdtool_dev_val === '1') {
        $missing[] = 'rrdtool-dev packages';
    }

    $rrdtool_val = db_fetch_cell_prepared("SELECT value FROM settings WHERE name = 'gnmi_req_rrdtool_missing'");
    if ($rrdtool_val === '1') {
        $missing[] = 'rrdtool Python module';
    }

    $pygnmi_val = db_fetch_cell_prepared("SELECT value FROM settings WHERE name = 'gnmi_req_pygnmi_missing'");
    if ($pygnmi_val === '1') {
        $missing[] = 'pygnmi';
    }

	$pymysql_val = db_fetch_cell_prepared("SELECT value FROM settings WHERE name = 'gnmi_req_pymysql_missing'");
	if ($pymysql_val === '1') {
		$missing[] = 'pymysql';
	}

    return $missing;
}

/**
 * Create Cacti data sources for a gNMI subscription (Phase 3.3)
 *
 * This function creates Cacti data sources for all enabled metrics in a subscription.
 * It sets up the necessary database entries for RRD file creation and data collection.
 *
 * @param int $subscription_id gNMI subscription ID (from plugin_gnmi_subscriptions)
 * @return int|false local_data_id on success, false on failure
 */
function gnmi_create_data_sources_for_subscription($subscription_id) {
	global $config;

	// Ensure subscription functions are loaded
	if (!function_exists('gnmi_get_subscription')) {
		include_once($config['base_path'] . '/plugins/gnmi/include/subscription_functions.php');
	}

	// Get subscription details
	$subscription = gnmi_get_subscription($subscription_id);
	if (!$subscription) {
		cacti_log("gNMI: Subscription $subscription_id not found", false, 'PLUGIN');
		return false;
	}

	// Get device and Cacti host_id
	$device = db_fetch_row_prepared(
		'SELECT * FROM plugin_gnmi_devices WHERE id = ?',
		array($subscription['device_id'])
	);

	if (!$device || !$device['host_id']) {
		cacti_log("gNMI: Device {$subscription['device_id']} has no host_id", false, 'PLUGIN');
		return false;
	}

	$host_id = $device['host_id'];

	// Get all enabled metrics that need data sources (per-metric architecture)
	$metrics_needing_ds = db_fetch_assoc_prepared("
		SELECT id, metric_name, cacti_field_name, rrd_type, rrd_heartbeat, rrd_min, rrd_max, local_data_id
		FROM plugin_gnmi_metrics
		WHERE subscription_id = ?
		AND enabled = 1
		AND (local_data_id IS NULL OR datasource_created = 0)",
		array($subscription_id)
	);

	if (empty($metrics_needing_ds)) {
		// Check if any metrics exist at all (for backwards compatibility)
		$existing_ds = db_fetch_cell_prepared(
			'SELECT local_data_id FROM plugin_gnmi_metrics
			 WHERE subscription_id = ? AND local_data_id IS NOT NULL LIMIT 1',
			array($subscription_id)
		);
		if ($existing_ds) {
			cacti_log("gNMI: All metrics already have data sources for subscription $subscription_id", false, 'PLUGIN', POLLER_VERBOSITY_DEBUG);
			return $existing_ds;
		}
		cacti_log("gNMI: No enabled metrics needing data sources for subscription $subscription_id", false, 'PLUGIN');
		return false;
	}

	// Batch creation: create one new data_local for all enabled metrics in the subscription.
	$template_id = gnmi_get_passthrough_data_template_id();
	$data_input_id = gnmi_get_passthrough_data_input_id();
	if (empty($template_id) || empty($data_input_id)) {
		cacti_log('gNMI: Missing passthrough template/data input metadata', false, 'PLUGIN');
		return false;
	}

	$profile_id = db_fetch_cell("
		SELECT id FROM data_source_profiles
		WHERE name = 'gNMI - 10 Second Collection'
	");
	if (!$profile_id) {
		cacti_log("gNMI: 10-second profile not found", false, 'PLUGIN');
		return false;
	}

	// Batch creation: all enabled metrics share one new data_local
	$metrics = gnmi_get_subscription_metrics($subscription_id, true);
	if (empty($metrics)) {
		cacti_log("gNMI: No enabled metrics for subscription $subscription_id", false, 'PLUGIN');
		return false;
	}

	$display_name = sprintf('gNMI - %s', $subscription['instance_identifier']);
	if (strlen($display_name) > 250) {
		$display_name = substr($display_name, 0, 250);
	}

	$local_insert = db_execute_prepared(
		"INSERT INTO data_local (host_id, data_template_id, snmp_query_id, snmp_index, orphan)
		 VALUES (?, ?, 0, '', 0)",
		array($host_id, $template_id)
	);
	if (!$local_insert) {
		cacti_log("gNMI: Failed to create data_local", false, 'PLUGIN');
		return false;
	}

	$data_local_id = (int)db_fetch_insert_id();
	$rrd_path = sprintf('<path_rra>/%d/%d.rrd', $host_id, $data_local_id);

	$template_data_insert = db_execute_prepared(
		"INSERT INTO data_template_data (
			local_data_template_data_id, local_data_id, data_template_id, data_input_id,
			t_name, name, name_cache, data_source_path,
			t_active, active, t_rrd_step, rrd_step,
			t_data_source_profile_id, data_source_profile_id
		) VALUES (
			0, ?, ?, ?, '', ?, ?, ?, '', 'on', '', ?, '', ?
		)",
		array(
			$data_local_id,
			$template_id,
			$data_input_id,
			$display_name,
			$display_name,
			$rrd_path,
			10,
			$profile_id
		)
	);

	if (!$template_data_insert) {
		db_execute_prepared('DELETE FROM data_local WHERE id = ?', array($data_local_id));
		cacti_log("gNMI: Failed to create data_template_data", false, 'PLUGIN');
		return false;
	}

	$data_template_data_id = (int)db_fetch_insert_id();
	$metric_set = gnmi_detect_metric_set($metrics);
	$metric_set = $metric_set ?: 'custom';

	$metadata_values = array(
		'device_id' => $device['id'],
		'subscription_id' => $subscription_id,
		'instance_identifier' => $subscription['instance_identifier'],
		'metric_set' => $metric_set
	);

	foreach ($metadata_values as $field_name => $field_value) {
		$field_id = gnmi_get_data_input_field_id($data_input_id, $field_name);
		if ($field_id) {
			db_execute_prepared(
				"REPLACE INTO data_input_data (data_input_field_id, data_template_data_id, t_value, value)
				 VALUES (?, ?, '', ?)",
				array($field_id, $data_template_data_id, $field_value)
			);
		}
	}

	$created_count = 0;
	foreach ($metrics_needing_ds as $metric) {
		$hash = function_exists('generate_hash') ? generate_hash() : md5(uniqid('gnmi_rrd_', true));
		$insert_rrd = db_execute_prepared(
			"INSERT INTO data_template_rrd (
				hash, local_data_template_rrd_id, local_data_id, data_template_id,
				t_rrd_maximum, rrd_maximum, t_rrd_minimum, rrd_minimum,
				t_rrd_heartbeat, rrd_heartbeat, t_data_source_type_id, data_source_type_id,
				t_data_source_name, data_source_name, t_data_input_field_id, data_input_field_id
			) VALUES (
				?, 0, ?, ?, '', ?, '', ?, '', ?, '', ?, '', ?, '', 0
			)",
			array(
				$hash,
				$data_local_id,
				$template_id,
				$metric['rrd_max'],
				$metric['rrd_min'],
				$metric['rrd_heartbeat'],
				gnmi_get_rrd_type_id($metric['rrd_type']),
				$metric['cacti_field_name']
			)
		);

		if ($insert_rrd) {
			gnmi_update_metric($metric['id'], array(
				'local_data_id' => $data_local_id,
				'datasource_created' => true
			));
			$created_count++;
		} else {
			cacti_log("gNMI: Failed to create RRD item for {$metric['metric_name']}", false, 'PLUGIN');
		}
	}

	if ($created_count === 0) {
		// Total failure - cleanup
		db_execute_prepared('DELETE FROM data_template_rrd WHERE local_data_id = ?', array($data_local_id));
		db_execute_prepared('DELETE FROM data_template_data WHERE local_data_id = ?', array($data_local_id));
		db_execute_prepared('DELETE FROM data_local WHERE id = ?', array($data_local_id));
		cacti_log("gNMI: Failed to create any RRD items", false, 'PLUGIN');
		return false;
	}



	cacti_log("gNMI: Created data source $data_local_id with $created_count metrics for subscription $subscription_id", false, 'PLUGIN');

	return $data_local_id;
}

/**
 * Create a Cacti data source for a single metric.
 *
 * Each metric gets its own data_local, data_template_data, data_template_rrd row, and RRD
 * file under Cacti's normal RRA layout. Telemetry is written by the poller_bottom hook via
 * gnmi_poller_bridge.py (no poller_item rows for gNMI passthrough collection).
 *
 * @param int $metric_id The metric ID from plugin_gnmi_metrics
 * @return int|false The data_local_id on success, false on failure
 */
function gnmi_create_data_source_for_metric($metric_id) {
	global $config;

	// Ensure subscription functions are loaded
	if (!function_exists('gnmi_get_subscription')) {
		include_once($config['base_path'] . '/plugins/gnmi/include/subscription_functions.php');
	}

	// Get metric details with subscription and device info
	$metric = db_fetch_row_prepared("
		SELECT m.*, s.device_id, s.instance_identifier, d.host_id, d.hostname
		FROM plugin_gnmi_metrics m
		JOIN plugin_gnmi_subscriptions s ON m.subscription_id = s.id
		JOIN plugin_gnmi_devices d ON s.device_id = d.id
		WHERE m.id = ?",
		array($metric_id)
	);

	if (empty($metric)) {
		cacti_log("gNMI: Metric $metric_id not found", false, 'PLUGIN');
		return false;
	}

	// Skip if already has data source
	if (!empty($metric['local_data_id'])) {
		cacti_log("gNMI: Metric $metric_id already has data source {$metric['local_data_id']}", false, 'PLUGIN', POLLER_VERBOSITY_DEBUG);
		return $metric['local_data_id'];
	}

	$host_id = $metric['host_id'];
	if (!$host_id) {
		cacti_log("gNMI: Metric $metric_id has no host_id", false, 'PLUGIN');
		return false;
	}

	// Get required IDs
	$template_id = gnmi_get_passthrough_data_template_id();
	$data_input_id = gnmi_get_passthrough_data_input_id();
	if (empty($template_id) || empty($data_input_id)) {
		cacti_log('gNMI: Missing passthrough template/data input metadata', false, 'PLUGIN');
		return false;
	}

	$profile_id = db_fetch_cell("
		SELECT id FROM data_source_profiles
		WHERE name = 'gNMI - 10 Second Collection'
	");
	if (!$profile_id) {
		cacti_log("gNMI: 10-second profile not found", false, 'PLUGIN');
		return false;
	}

	// Generate data source name: "Host - gNMI - instance - metric_name"
	$host_desc = db_fetch_cell_prepared('SELECT description FROM host WHERE id = ?', array($host_id));
	if (empty($host_desc)) {
		$host_desc = "Host $host_id";
	}

	$ds_name = sprintf('%s - gNMI - %s - %s',
		$host_desc,
		$metric['instance_identifier'],
		$metric['cacti_field_name']
	);

	// 1. Create data_local entry
	db_execute_prepared(
		"INSERT INTO data_local (host_id, data_template_id, snmp_query_id, snmp_index, orphan)
		 VALUES (?, ?, 0, '', 0)",
		array($host_id, $template_id)
	);
	$data_local_id = db_fetch_insert_id();

	if (!$data_local_id) {
		cacti_log("gNMI: Failed to create data_local for metric $metric_id", false, 'PLUGIN');
		return false;
	}

	// 2. Create data_template_data entry
	$rrd_path = sprintf('<path_rra>/%d/%d.rrd', $host_id, $data_local_id);
	db_execute_prepared(
		"INSERT INTO data_template_data (
			local_data_template_data_id, local_data_id, data_template_id, data_input_id,
			t_name, name, data_source_path, t_active, active, t_rrd_step, rrd_step,
			t_data_source_profile_id, data_source_profile_id, name_cache
		) VALUES (0, ?, ?, ?, '', ?, ?, '', 'on', '', 10, '', ?, ?)",
		array($data_local_id, $template_id, $data_input_id, $ds_name, $rrd_path, $profile_id, $ds_name)
	);

	// 3. Create single data_template_rrd entry (one RRD item per metric)
	$hash = function_exists('generate_hash') ? generate_hash() : md5(uniqid('gnmi_rrd_', true));
	$rrd_max = isset($metric['rrd_max']) ? $metric['rrd_max'] : 'U';
	$rrd_min = isset($metric['rrd_min']) ? $metric['rrd_min'] : '0';
	$rrd_heartbeat = isset($metric['rrd_heartbeat']) ? $metric['rrd_heartbeat'] : gnmi_get_poller_interval() * 2;
	$rrd_type = isset($metric['rrd_type']) ? $metric['rrd_type'] : 'COUNTER';

	db_execute_prepared(
		"INSERT INTO data_template_rrd (
			hash, local_data_template_rrd_id, local_data_id, data_template_id,
			t_rrd_maximum, rrd_maximum, t_rrd_minimum, rrd_minimum,
			t_rrd_heartbeat, rrd_heartbeat, t_data_source_type_id, data_source_type_id,
			t_data_source_name, data_source_name, t_data_input_field_id, data_input_field_id
		) VALUES (?, 0, ?, ?, '', ?, '', ?, '', ?, '', ?, '', ?, '', 0)",
		array(
			$hash,
			$data_local_id,
			$template_id,
			$rrd_max,
			$rrd_min,
			$rrd_heartbeat,
			gnmi_get_rrd_type_id($rrd_type),
			$metric['cacti_field_name']
		)
	);

	// 4. Update metric with data source link
	gnmi_update_metric($metric_id, array(
		'local_data_id' => $data_local_id,
		'datasource_created' => true
	));

	cacti_log("gNMI: Created data source $data_local_id for metric {$metric['cacti_field_name']} (metric_id=$metric_id)", false, 'PLUGIN');

	// 6. Auto-create graph if subscription has auto_create_graphs enabled
	$auto_graph = db_fetch_cell_prepared(
		'SELECT auto_create_graphs FROM plugin_gnmi_subscriptions WHERE id = ?',
		array($metric['subscription_id'])
	);
	if ($auto_graph) {
		gnmi_create_graph_for_metric($metric_id);
	}

	return $data_local_id;
}

/**
 * Get RRD type ID for type name
 *
 * @param string $type_name RRD type name (COUNTER, GAUGE, DERIVE, ABSOLUTE)
 * @return int RRD type ID (1=GAUGE, 2=COUNTER, 3=DERIVE, 4=ABSOLUTE)
 */
function gnmi_get_rrd_type_id($type_name) {
	// 1=GAUGE, 2=COUNTER, 3=DERIVE, 4=ABSOLUTE
	$map = [
		'GAUGE' => 1,
		'COUNTER' => 2,
		'DERIVE' => 3,
		'ABSOLUTE' => 4
	];
	return $map[$type_name] ?? 1; // Default to GAUGE
}

/**
 * Normalize a raw metric name into a stable token string for graph grouping.
 *
 * This intentionally does not apply the 19-character RRD data-source limit.
 *
 * @param string $metric_name Raw metric name from device/config
 * @return string Lowercase underscore-delimited metric name
 */
function gnmi_normalize_metric_graph_name($metric_name) {
	$name = strtolower((string)$metric_name);
	$name = preg_replace('/[^a-z0-9]+/', '_', $name);
	$name = preg_replace('/_+/', '_', $name);
	return trim($name, '_');
}

/**
 * Classify a metric by its raw metric name into graph grouping metadata.
 *
 * Pure function - no database access. Classification uses the raw name so long
 * packet-size buckets are not misclassified after Cacti's 19-character DS
 * truncation.
 *
 * @param string $metric_name Raw metric name from device/config
 * @return array ['group' => string, 'direction' => string, 'graph_key' => ?string]
 */
function gnmi_classify_metric($metric_name) {
	$name = gnmi_normalize_metric_graph_name($metric_name);
	if ($name === '') {
		return array('group' => 'generic', 'direction' => 'none', 'graph_key' => null);
	}

	$direction = 'none';
	$base = $name;
	if (preg_match('/^(in|rx)_(.+)$/', $name, $matches)) {
		$direction = 'inbound';
		$base = $matches[2];
	} elseif (preg_match('/^(out|tx)_(.+)$/', $name, $matches)) {
		$direction = 'outbound';
		$base = $matches[2];
	}

	if ($direction === 'none' || $base === '') {
		return array('group' => 'generic', 'direction' => 'none', 'graph_key' => null);
	}

	$is_discard = (bool)preg_match('/(^|_)(discard|discards|drop|drops|dropped)($|_)/', $base);
	$is_error = (bool)preg_match('/(^|_)(error|errors|err|errs|crc|jabber|oversize|undersize|fragment)($|_)/', $base);
	$is_octet = (bool)preg_match('/(^|_)(octet|octets|byte|bytes)($|_)/', $base);
	$is_packet = (bool)preg_match('/(^|_)(pkt|pkts|packet|packets)($|_)/', $base);

	if ($is_discard || $is_error) {
		return array(
			'group' => $is_discard ? 'discards' : 'errors',
			'direction' => $direction,
			'graph_key' => $is_octet ? 'integrity_octets' : 'integrity_packets'
		);
	}

	if ($is_packet) {
		return array('group' => 'packets', 'direction' => $direction, 'graph_key' => $base);
	}

	if ($is_octet) {
		return array('group' => 'traffic', 'direction' => $direction, 'graph_key' => $base);
	}

	return array('group' => 'generic', 'direction' => 'none', 'graph_key' => null);
}

// =============================================================================
// Phase 3.4: Graph Template Provisioning
// =============================================================================

/**
 * Ensure the "Turn Bytes into Bits (gNMI)" CDEF exists and return its ID.
 *
 * Idempotent — safe to call multiple times.
 * Caches the ID in Cacti settings as 'gnmi_cdef_bytes_to_bits_id'.
 *
 * CDEF formula: CURRENT_DATA_SOURCE, 8, * (multiply by 8 to convert bytes→bits)
 *
 * @return int|null CDEF ID, or null on failure
 */
function gnmi_ensure_cdef_bytes_to_bits() {
	$cached = gnmi_get_setting_value('gnmi_cdef_bytes_to_bits_id');
	if ($cached) {
		$exists = db_fetch_cell_prepared('SELECT id FROM cdef WHERE id = ?', array((int)$cached));
		if ($exists) return (int)$cached;
	}

	$cdef_name = 'Turn Bytes into Bits (gNMI)';
	$existing_id = db_fetch_cell_prepared('SELECT id FROM cdef WHERE name = ?', array($cdef_name));
	if ($existing_id) {
		gnmi_set_setting_value('gnmi_cdef_bytes_to_bits_id', $existing_id);
		return (int)$existing_id;
	}

	$hash = function_exists('generate_hash') ? generate_hash() : md5(uniqid('gnmi_cdef_', true));
	db_execute_prepared(
		"INSERT INTO cdef (hash, system, name) VALUES (?, 0, ?)",
		array($hash, $cdef_name)
	);
	$cdef_id = (int)db_fetch_insert_id();
	if (!$cdef_id) {
		cacti_log('gNMI: Failed to create CDEF bytes-to-bits', false, 'PLUGIN');
		return null;
	}

	// Item 1: CURRENT_DATA_SOURCE (type=4)
	$hash1 = function_exists('generate_hash') ? generate_hash() : md5(uniqid('gnmi_cdef1_', true));
	db_execute_prepared(
		"INSERT INTO cdef_items (hash, cdef_id, sequence, type, value) VALUES (?, ?, 1, 4, 'CURRENT_DATA_SOURCE')",
		array($hash1, $cdef_id)
	);

	// Item 2: literal '8' (type=6)
	$hash2 = function_exists('generate_hash') ? generate_hash() : md5(uniqid('gnmi_cdef2_', true));
	db_execute_prepared(
		"INSERT INTO cdef_items (hash, cdef_id, sequence, type, value) VALUES (?, ?, 2, 6, '8')",
		array($hash2, $cdef_id)
	);

	// Item 3: multiply operator (type=2, value=3)
	$hash3 = function_exists('generate_hash') ? generate_hash() : md5(uniqid('gnmi_cdef3_', true));
	db_execute_prepared(
		"INSERT INTO cdef_items (hash, cdef_id, sequence, type, value) VALUES (?, ?, 3, 2, '3')",
		array($hash3, $cdef_id)
	);

	gnmi_set_setting_value('gnmi_cdef_bytes_to_bits_id', $cdef_id);
	cacti_log("gNMI: Created CDEF 'Turn Bytes into Bits (gNMI)' (id=$cdef_id)", false, 'PLUGIN');
	return $cdef_id;
}

/**
 * Get or create a color entry by hex code. Returns the color.id.
 *
 * @param string $hex 6-character hex code without # (e.g. '00CF00')
 * @return int|null Color ID, or null on failure
 */
function gnmi_get_color_id($hex) {
	$hex = strtoupper(trim($hex, '#'));
	$id = db_fetch_cell_prepared('SELECT id FROM colors WHERE hex = ?', array($hex));
	if ($id) return (int)$id;

	// Create the color
	db_execute_prepared("INSERT INTO colors (hex, read_only) VALUES (?, '')", array($hex));
	$id = (int)db_fetch_insert_id();
	if (!$id) {
		cacti_log("gNMI: Failed to create color entry for $hex", false, 'PLUGIN');
		return null;
	}
	return $id;
}

/**
 * Get or create the gNMI Passthrough single-DS graph template.
 *
 * Template design: LINE2 (blue) + GPRINT:LAST + GPRINT:AVERAGE + GPRINT:MAX + GPRINT:MIN
 * Used for generic metrics that don't have a paired inbound/outbound counterpart.
 *
 * Idempotent — cached in settings as 'gnmi_passthrough_graph_template_id'.
 *
 * @return int|null Graph template ID, or null on failure
 */
function gnmi_get_passthrough_graph_template_id() {
	$cached = gnmi_get_setting_value('gnmi_passthrough_graph_template_id');
	if ($cached) {
		$exists = db_fetch_cell_prepared('SELECT id FROM graph_templates WHERE id = ?', array((int)$cached));
		if ($exists) return (int)$cached;
	}

	$name = 'gNMI - Passthrough';
	$existing_id = db_fetch_cell_prepared('SELECT id FROM graph_templates WHERE name = ?', array($name));
	if ($existing_id) {
		gnmi_set_setting_value('gnmi_passthrough_graph_template_id', $existing_id);
		return (int)$existing_id;
	}

	// Ensure the data template exists so we can get its template-level data_template_rrd row
	$data_template_id = gnmi_get_passthrough_data_template_id();
	if (!$data_template_id) {
		cacti_log('gNMI: Cannot create graph template — missing passthrough data template', false, 'PLUGIN');
		return null;
	}

	// Get the template-level data_template_rrd row (local_data_id = 0)
	$dt_rrd_id = (int)db_fetch_cell_prepared(
		'SELECT id FROM data_template_rrd WHERE data_template_id = ? AND local_data_id = 0 LIMIT 1',
		array($data_template_id)
	);

	$blue_color_id  = gnmi_get_color_id('0000FF');

	// (a) Create graph_templates row
	$hash = function_exists('generate_hash') ? generate_hash() : md5(uniqid('gnmi_gt_', true));
	db_execute_prepared(
		"INSERT INTO graph_templates (hash, name) VALUES (?, ?)",
		array($hash, $name)
	);
	$gt_id = (int)db_fetch_insert_id();
	if (!$gt_id) {
		cacti_log('gNMI: Failed to create passthrough graph_templates row', false, 'PLUGIN');
		return null;
	}

	// (b) Create graph_templates_graph row (template-level: local_graph_id = 0)
	$hash = function_exists('generate_hash') ? generate_hash() : md5(uniqid('gnmi_gtg_', true));
	db_execute_prepared(
		"INSERT INTO graph_templates_graph (
			local_graph_template_graph_id, local_graph_id, graph_template_id,
			t_image_format_id, image_format_id,
			t_title, title, title_cache,
			t_height, height, t_width, width,
			t_upper_limit, upper_limit,
			t_lower_limit, lower_limit,
			t_vertical_label, vertical_label,
			t_slope_mode, slope_mode,
			t_auto_scale, auto_scale,
			t_auto_scale_opts, auto_scale_opts,
			t_auto_padding, auto_padding,
			t_base_value, base_value
		) VALUES (
			0, 0, ?,
			'', 1,
			'', '|host_description| - gNMI - |query_ifName|', '',
			'', 200, '', 700,
			'', '',
			'', '0',
			'', 'value',
			'', 'on',
			'', 'on',
			'', 1,
			'', 'on',
			'', 1000
		)",
		array($gt_id)
	);

	// (c) Create graph_template_input (one DS slot)
	$hash = function_exists('generate_hash') ? generate_hash() : md5(uniqid('gnmi_gti_', true));
	db_execute_prepared(
		"INSERT INTO graph_template_input (hash, graph_template_id, name, description, column_name)
		 VALUES (?, ?, 'Data Source', 'gNMI metric data source', 'task_item_id')",
		array($hash, $gt_id)
	);
	$input_id = (int)db_fetch_insert_id();

	// (d) Create graph_templates_item rows (template-level: local_graph_id = 0)
	// Sequence order: LINE2, GPRINT:LAST, GPRINT:AVERAGE, GPRINT:MAX, GPRINT:MIN
	$items = array(
		// graph_type_id: LINE2=5; consolidation_function_id: AVERAGE=1
		array('type' => 5,  'color_id' => $blue_color_id, 'cf' => 1,  'text' => '',          'hard_return' => ''),
		// GPRINT:LAST=11
		array('type' => 11, 'color_id' => 0,              'cf' => 4,  'text' => 'Last:  ',    'hard_return' => ''),
		// GPRINT:AVERAGE=14
		array('type' => 14, 'color_id' => 0,              'cf' => 1,  'text' => 'Avg:   ',    'hard_return' => ''),
		// GPRINT:MAX=12
		array('type' => 12, 'color_id' => 0,              'cf' => 3,  'text' => 'Max:   ',    'hard_return' => ''),
		// GPRINT:MIN=13
		array('type' => 13, 'color_id' => 0,              'cf' => 2,  'text' => 'Min:   ',    'hard_return' => 'on'),
	);

	$item_ids = array();
	foreach ($items as $seq => $item) {
		$hash = function_exists('generate_hash') ? generate_hash() : md5(uniqid('gnmi_gti_item_', true));
		db_execute_prepared(
			"INSERT INTO graph_templates_item (
				hash, local_graph_template_item_id, local_graph_id, graph_template_id,
				task_item_id, color_id, alpha, graph_type_id, line_width,
				dashes, dash_offset, cdef_id, vdef_id, shift,
				consolidation_function_id, textalign,
				text_format, value, hard_return, gprint_id, sequence
			) VALUES (
				?, 0, 0, ?,
				?, ?, 'FF', ?, 1.00,
				'', 0, 0, 0, '',
				?, '',
				?, '', ?, 2, ?
			)",
			array(
				$hash, $gt_id,
				$dt_rrd_id, (int)$item['color_id'], (int)$item['type'],
				(int)$item['cf'],
				$item['text'], $item['hard_return'],
				$seq + 1
			)
		);
		$item_id = (int)db_fetch_insert_id();
		$item_ids[] = $item_id;

		// Link DS-bearing items to the input slot
		if ($input_id) {
			db_execute_prepared(
				"INSERT INTO graph_template_input_defs (graph_template_input_id, graph_template_item_id)
				 VALUES (?, ?)",
				array($input_id, $item_id)
			);
		}
	}

	gnmi_set_setting_value('gnmi_passthrough_graph_template_id', $gt_id);
	cacti_log("gNMI: Created passthrough graph template (id=$gt_id)", false, 'PLUGIN');
	return $gt_id;
}

/**
 * Get or create the gNMI Interface Traffic multi-DS graph template.
 *
 * Template design (Cacti convention):
 *   AREA(inbound, green, bytes→bits)  + GPRINT:LAST + GPRINT:AVERAGE + GPRINT:MAX + GPRINT:MIN
 *   LINE2(outbound, blue, bytes→bits) + GPRINT:LAST + GPRINT:AVERAGE + GPRINT:MAX + GPRINT:MIN
 *
 * Two graph_template_input slots: "Inbound Data Source" and "Outbound Data Source".
 * Graph instances bind each slot to the specific metric's data_template_rrd row.
 *
 * Idempotent — cached in settings as 'gnmi_traffic_graph_template_id'.
 *
 * @return int|null Graph template ID, or null on failure
 */
function gnmi_get_traffic_graph_template_id() {
	$cached = gnmi_get_setting_value('gnmi_traffic_graph_template_id');
	if ($cached) {
		$exists = db_fetch_cell_prepared('SELECT id FROM graph_templates WHERE id = ?', array((int)$cached));
		if ($exists) return (int)$cached;
	}

	$name = 'gNMI - Interface Traffic';
	$existing_id = db_fetch_cell_prepared('SELECT id FROM graph_templates WHERE name = ?', array($name));
	if ($existing_id) {
		gnmi_set_setting_value('gnmi_traffic_graph_template_id', $existing_id);
		return (int)$existing_id;
	}

	$data_template_id = gnmi_get_passthrough_data_template_id();
	if (!$data_template_id) {
		cacti_log('gNMI: Cannot create traffic graph template — missing passthrough data template', false, 'PLUGIN');
		return null;
	}

	$dt_rrd_id = (int)db_fetch_cell_prepared(
		'SELECT id FROM data_template_rrd WHERE data_template_id = ? AND local_data_id = 0 LIMIT 1',
		array($data_template_id)
	);

	$cdef_id      = gnmi_ensure_cdef_bytes_to_bits();
	$green_color_id = gnmi_get_color_id('00CF00');
	$blue_color_id  = gnmi_get_color_id('0000FF');

	// (a) Create graph_templates row
	$hash = function_exists('generate_hash') ? generate_hash() : md5(uniqid('gnmi_gt_traffic_', true));
	db_execute_prepared(
		"INSERT INTO graph_templates (hash, name) VALUES (?, ?)",
		array($hash, $name)
	);
	$gt_id = (int)db_fetch_insert_id();
	if (!$gt_id) {
		cacti_log('gNMI: Failed to create traffic graph_templates row', false, 'PLUGIN');
		return null;
	}

	// (b) Create graph_templates_graph row
	$hash = function_exists('generate_hash') ? generate_hash() : md5(uniqid('gnmi_gtg_traffic_', true));
	db_execute_prepared(
		"INSERT INTO graph_templates_graph (
			local_graph_template_graph_id, local_graph_id, graph_template_id,
			t_image_format_id, image_format_id,
			t_title, title, title_cache,
			t_height, height, t_width, width,
			t_upper_limit, upper_limit,
			t_lower_limit, lower_limit,
			t_vertical_label, vertical_label,
			t_slope_mode, slope_mode,
			t_auto_scale, auto_scale,
			t_auto_scale_opts, auto_scale_opts,
			t_auto_scale_rigid, auto_scale_rigid,
			t_auto_padding, auto_padding,
			t_base_value, base_value
		) VALUES (
			0, 0, ?,
			'', 1,
			'', '|host_description| - Interface Traffic - |query_ifName|', '',
			'', 200, '', 700,
			'', '',
			'', '0',
			'', 'bits per second',
			'', 'on',
			'', 'on',
			'', 2,
			'', 'on',
			'', 'on',
			'', 1000
		)",
		array($gt_id)
	);

	// (c) Create two graph_template_input slots
	$hash_in = function_exists('generate_hash') ? generate_hash() : md5(uniqid('gnmi_gtin_in_', true));
	db_execute_prepared(
		"INSERT INTO graph_template_input (hash, graph_template_id, name, description, column_name)
		 VALUES (?, ?, 'Inbound Data Source', 'Inbound (in_octets) metric', 'task_item_id')",
		array($hash_in, $gt_id)
	);
	$input_in_id = (int)db_fetch_insert_id();

	$hash_out = function_exists('generate_hash') ? generate_hash() : md5(uniqid('gnmi_gtin_out_', true));
	db_execute_prepared(
		"INSERT INTO graph_template_input (hash, graph_template_id, name, description, column_name)
		 VALUES (?, ?, 'Outbound Data Source', 'Outbound (out_octets) metric', 'task_item_id')",
		array($hash_out, $gt_id)
	);
	$input_out_id = (int)db_fetch_insert_id();

	// (d) Create graph items (10 items: 5 inbound + 5 outbound)
	// Each series: LINE1 (MAX, opaque) → AREA (AVERAGE, 50% alpha) → 3× GPRINT
	// graph_type_id: LINE1=4, AREA=7, GPRINT=9
	// consolidation_function_id: AVERAGE=1, MAX=3, LAST=4
	$cdef_val = $cdef_id ? (int)$cdef_id : 0;
	$items = array(
		// --- Inbound ---
		// LINE1: opaque thin line at MAX value (the "peak")
		array('input' => $input_in_id,  'type' => 4, 'color_id' => $green_color_id, 'alpha' => 'FF', 'line_width' => 1.00, 'cf' => 3, 'cdef' => $cdef_val, 'text' => '',          'hard_return' => ''),
		// AREA: 50% transparent fill at AVERAGE value
		array('input' => $input_in_id,  'type' => 7, 'color_id' => $green_color_id, 'alpha' => '7F', 'line_width' => 0.00, 'cf' => 1, 'cdef' => $cdef_val, 'text' => 'Inbound  ', 'hard_return' => ''),
		array('input' => $input_in_id,  'type' => 9, 'color_id' => 0,               'alpha' => 'FF', 'line_width' => 0.00, 'cf' => 4, 'cdef' => $cdef_val, 'text' => 'Current: ', 'hard_return' => ''),
		array('input' => $input_in_id,  'type' => 9, 'color_id' => 0,               'alpha' => 'FF', 'line_width' => 0.00, 'cf' => 1, 'cdef' => $cdef_val, 'text' => 'Average: ', 'hard_return' => ''),
		array('input' => $input_in_id,  'type' => 9, 'color_id' => 0,               'alpha' => 'FF', 'line_width' => 0.00, 'cf' => 3, 'cdef' => $cdef_val, 'text' => 'Maximum: ', 'hard_return' => 'on'),
		// --- Outbound ---
		array('input' => $input_out_id, 'type' => 4, 'color_id' => $blue_color_id,  'alpha' => 'FF', 'line_width' => 1.00, 'cf' => 3, 'cdef' => $cdef_val, 'text' => '',          'hard_return' => ''),
		array('input' => $input_out_id, 'type' => 7, 'color_id' => $blue_color_id,  'alpha' => '7F', 'line_width' => 0.00, 'cf' => 1, 'cdef' => $cdef_val, 'text' => 'Outbound ', 'hard_return' => ''),
		array('input' => $input_out_id, 'type' => 9, 'color_id' => 0,               'alpha' => 'FF', 'line_width' => 0.00, 'cf' => 4, 'cdef' => $cdef_val, 'text' => 'Current: ', 'hard_return' => ''),
		array('input' => $input_out_id, 'type' => 9, 'color_id' => 0,               'alpha' => 'FF', 'line_width' => 0.00, 'cf' => 1, 'cdef' => $cdef_val, 'text' => 'Average: ', 'hard_return' => ''),
		array('input' => $input_out_id, 'type' => 9, 'color_id' => 0,               'alpha' => 'FF', 'line_width' => 0.00, 'cf' => 3, 'cdef' => $cdef_val, 'text' => 'Maximum: ', 'hard_return' => 'on'),
	);

	foreach ($items as $seq => $item) {
		$hash = function_exists('generate_hash') ? generate_hash() : md5(uniqid('gnmi_gti_traffic_', true));
		db_execute_prepared(
			"INSERT INTO graph_templates_item (
				hash, local_graph_template_item_id, local_graph_id, graph_template_id,
				task_item_id, color_id, alpha, graph_type_id, line_width,
				dashes, dash_offset, cdef_id, vdef_id, shift,
				consolidation_function_id, textalign,
				text_format, value, hard_return, gprint_id, sequence
			) VALUES (
				?, 0, 0, ?,
				?, ?, ?, ?, ?,
				'', 0, ?, 0, '',
				?, '',
				?, '', ?, 2, ?
			)",
			array(
				$hash, $gt_id,
				$dt_rrd_id, (int)$item['color_id'], $item['alpha'], (int)$item['type'], (float)$item['line_width'],
				(int)$item['cdef'],
				(int)$item['cf'],
				$item['text'], $item['hard_return'],
				$seq + 1
			)
		);
		$item_id = (int)db_fetch_insert_id();

		// Link item to its input slot
		if ($item['input']) {
			db_execute_prepared(
				"INSERT INTO graph_template_input_defs (graph_template_input_id, graph_template_item_id)
				 VALUES (?, ?)",
				array((int)$item['input'], $item_id)
			);
		}
	}

	gnmi_set_setting_value('gnmi_traffic_graph_template_id', $gt_id);
	cacti_log("gNMI: Created Interface Traffic graph template (id=$gt_id)", false, 'PLUGIN');
	return $gt_id;
}

/**
 * Create/retrieve a two-slot inbound/outbound graph template without changing
 * existing traffic template behavior.
 */
function gnmi_get_two_slot_graph_template_id($setting_name, $name, $title_fragment, $vertical_label, $use_cdef = false) {
	$cached = gnmi_get_setting_value($setting_name);
	if ($cached) {
		$exists = db_fetch_cell_prepared('SELECT id FROM graph_templates WHERE id = ?', array((int)$cached));
		if ($exists) return (int)$cached;
	}

	$existing_id = db_fetch_cell_prepared('SELECT id FROM graph_templates WHERE name = ?', array($name));
	if ($existing_id) {
		gnmi_set_setting_value($setting_name, $existing_id);
		return (int)$existing_id;
	}

	$data_template_id = gnmi_get_passthrough_data_template_id();
	if (!$data_template_id) {
		cacti_log("gNMI: Cannot create graph template '$name' - missing passthrough data template", false, 'PLUGIN');
		return null;
	}

	$dt_rrd_id = (int)db_fetch_cell_prepared(
		'SELECT id FROM data_template_rrd WHERE data_template_id = ? AND local_data_id = 0 LIMIT 1',
		array($data_template_id)
	);

	$cdef_val = $use_cdef ? (int)gnmi_ensure_cdef_bytes_to_bits() : 0;
	$green_color_id = gnmi_get_color_id('00CF00');
	$blue_color_id  = gnmi_get_color_id('0000FF');

	$hash = function_exists('generate_hash') ? generate_hash() : md5(uniqid('gnmi_gt_pair_', true));
	db_execute_prepared(
		"INSERT INTO graph_templates (hash, name) VALUES (?, ?)",
		array($hash, $name)
	);
	$gt_id = (int)db_fetch_insert_id();
	if (!$gt_id) {
		cacti_log("gNMI: Failed to create graph_templates row for '$name'", false, 'PLUGIN');
		return null;
	}

	$hash = function_exists('generate_hash') ? generate_hash() : md5(uniqid('gnmi_gtg_pair_', true));
	db_execute_prepared(
		"INSERT INTO graph_templates_graph (
			local_graph_template_graph_id, local_graph_id, graph_template_id,
			t_image_format_id, image_format_id,
			t_title, title, title_cache,
			t_height, height, t_width, width,
			t_upper_limit, upper_limit,
			t_lower_limit, lower_limit,
			t_vertical_label, vertical_label,
			t_slope_mode, slope_mode,
			t_auto_scale, auto_scale,
			t_auto_scale_opts, auto_scale_opts,
			t_auto_scale_rigid, auto_scale_rigid,
			t_auto_padding, auto_padding,
			t_base_value, base_value
		) VALUES (
			0, 0, ?,
			'', 1,
			'', ?, '',
			'', 200, '', 700,
			'', '',
			'', '0',
			'', ?,
			'', 'on',
			'', 'on',
			'', 2,
			'', 'on',
			'', 'on',
			'', 1000
		)",
		array($gt_id, '|host_description| - ' . $title_fragment . ' - |query_ifName|', $vertical_label)
	);

	$hash_in = function_exists('generate_hash') ? generate_hash() : md5(uniqid('gnmi_gtin_pair_in_', true));
	db_execute_prepared(
		"INSERT INTO graph_template_input (hash, graph_template_id, name, description, column_name)
		 VALUES (?, ?, 'Inbound Data Source', 'Inbound metric', 'task_item_id')",
		array($hash_in, $gt_id)
	);
	$input_in_id = (int)db_fetch_insert_id();

	$hash_out = function_exists('generate_hash') ? generate_hash() : md5(uniqid('gnmi_gtin_pair_out_', true));
	db_execute_prepared(
		"INSERT INTO graph_template_input (hash, graph_template_id, name, description, column_name)
		 VALUES (?, ?, 'Outbound Data Source', 'Outbound metric', 'task_item_id')",
		array($hash_out, $gt_id)
	);
	$input_out_id = (int)db_fetch_insert_id();

	$items = array(
		array('input' => $input_in_id,  'type' => 4, 'color_id' => $green_color_id, 'alpha' => 'FF', 'line_width' => 1.00, 'cf' => 3, 'cdef' => $cdef_val, 'text' => '',          'hard_return' => ''),
		array('input' => $input_in_id,  'type' => 7, 'color_id' => $green_color_id, 'alpha' => '7F', 'line_width' => 0.00, 'cf' => 1, 'cdef' => $cdef_val, 'text' => 'Inbound  ', 'hard_return' => ''),
		array('input' => $input_in_id,  'type' => 9, 'color_id' => 0,               'alpha' => 'FF', 'line_width' => 0.00, 'cf' => 4, 'cdef' => $cdef_val, 'text' => 'Current: ', 'hard_return' => ''),
		array('input' => $input_in_id,  'type' => 9, 'color_id' => 0,               'alpha' => 'FF', 'line_width' => 0.00, 'cf' => 1, 'cdef' => $cdef_val, 'text' => 'Average: ', 'hard_return' => ''),
		array('input' => $input_in_id,  'type' => 9, 'color_id' => 0,               'alpha' => 'FF', 'line_width' => 0.00, 'cf' => 3, 'cdef' => $cdef_val, 'text' => 'Maximum: ', 'hard_return' => 'on'),
		array('input' => $input_out_id, 'type' => 4, 'color_id' => $blue_color_id,  'alpha' => 'FF', 'line_width' => 1.00, 'cf' => 3, 'cdef' => $cdef_val, 'text' => '',          'hard_return' => ''),
		array('input' => $input_out_id, 'type' => 7, 'color_id' => $blue_color_id,  'alpha' => '7F', 'line_width' => 0.00, 'cf' => 1, 'cdef' => $cdef_val, 'text' => 'Outbound ', 'hard_return' => ''),
		array('input' => $input_out_id, 'type' => 9, 'color_id' => 0,               'alpha' => 'FF', 'line_width' => 0.00, 'cf' => 4, 'cdef' => $cdef_val, 'text' => 'Current: ', 'hard_return' => ''),
		array('input' => $input_out_id, 'type' => 9, 'color_id' => 0,               'alpha' => 'FF', 'line_width' => 0.00, 'cf' => 1, 'cdef' => $cdef_val, 'text' => 'Average: ', 'hard_return' => ''),
		array('input' => $input_out_id, 'type' => 9, 'color_id' => 0,               'alpha' => 'FF', 'line_width' => 0.00, 'cf' => 3, 'cdef' => $cdef_val, 'text' => 'Maximum: ', 'hard_return' => 'on'),
	);

	foreach ($items as $seq => $item) {
		$hash = function_exists('generate_hash') ? generate_hash() : md5(uniqid('gnmi_gti_pair_', true));
		db_execute_prepared(
			"INSERT INTO graph_templates_item (
				hash, local_graph_template_item_id, local_graph_id, graph_template_id,
				task_item_id, color_id, alpha, graph_type_id, line_width,
				dashes, dash_offset, cdef_id, vdef_id, shift,
				consolidation_function_id, textalign,
				text_format, value, hard_return, gprint_id, sequence
			) VALUES (
				?, 0, 0, ?,
				?, ?, ?, ?, ?,
				'', 0, ?, 0, '',
				?, '',
				?, '', ?, 2, ?
			)",
			array(
				$hash, $gt_id,
				$dt_rrd_id, (int)$item['color_id'], $item['alpha'], (int)$item['type'], (float)$item['line_width'],
				(int)$item['cdef'],
				(int)$item['cf'],
				$item['text'], $item['hard_return'],
				$seq + 1
			)
		);
		$item_id = (int)db_fetch_insert_id();
		if ($item['input']) {
			db_execute_prepared(
				"INSERT INTO graph_template_input_defs (graph_template_input_id, graph_template_item_id)
				 VALUES (?, ?)",
				array((int)$item['input'], $item_id)
			);
		}
	}

	gnmi_set_setting_value($setting_name, $gt_id);
	cacti_log("gNMI: Created graph template '$name' (id=$gt_id)", false, 'PLUGIN');
	return $gt_id;
}

/**
 * Get or create the paired packet graph template.
 */
function gnmi_get_packets_graph_template_id() {
	return gnmi_get_two_slot_graph_template_id(
		'gnmi_packets_graph_template_id',
		'gNMI - Interface Packets',
		'Interface Packets',
		'packets per second',
		false
	);
}

/**
 * Get or create an integrity graph template shell.
 *
 * Integrity graph instances add their data-source items dynamically, so the
 * template only needs the graph metadata row.
 */
function gnmi_get_integrity_graph_template_id($metric_graph_key) {
	$is_octets = ($metric_graph_key === 'integrity_octets');
	$setting_name = $is_octets ? 'gnmi_integrity_octets_graph_template_id' : 'gnmi_integrity_packets_graph_template_id';
	$name = $is_octets ? 'gNMI - Interface Integrity Octets' : 'gNMI - Interface Integrity Packets Events';
	$title_fragment = $is_octets ? 'Interface Integrity Octets' : 'Interface Integrity Packets/Events';
	$vertical_label = $is_octets ? 'octets per second' : 'packets/events per second';

	$cached = gnmi_get_setting_value($setting_name);
	if ($cached) {
		$exists = db_fetch_cell_prepared('SELECT id FROM graph_templates WHERE id = ?', array((int)$cached));
		if ($exists) return (int)$cached;
	}

	$existing_id = db_fetch_cell_prepared('SELECT id FROM graph_templates WHERE name = ?', array($name));
	if ($existing_id) {
		gnmi_set_setting_value($setting_name, $existing_id);
		return (int)$existing_id;
	}

	$hash = function_exists('generate_hash') ? generate_hash() : md5(uniqid('gnmi_gt_integrity_', true));
	db_execute_prepared(
		"INSERT INTO graph_templates (hash, name) VALUES (?, ?)",
		array($hash, $name)
	);
	$gt_id = (int)db_fetch_insert_id();
	if (!$gt_id) {
		cacti_log("gNMI: Failed to create graph template '$name'", false, 'PLUGIN');
		return null;
	}

	$hash = function_exists('generate_hash') ? generate_hash() : md5(uniqid('gnmi_gtg_integrity_', true));
	db_execute_prepared(
		"INSERT INTO graph_templates_graph (
			local_graph_template_graph_id, local_graph_id, graph_template_id,
			t_image_format_id, image_format_id,
			t_title, title, title_cache,
			t_height, height, t_width, width,
			t_upper_limit, upper_limit,
			t_lower_limit, lower_limit,
			t_vertical_label, vertical_label,
			t_slope_mode, slope_mode,
			t_auto_scale, auto_scale,
			t_auto_scale_opts, auto_scale_opts,
			t_auto_scale_rigid, auto_scale_rigid,
			t_auto_padding, auto_padding,
			t_base_value, base_value
		) VALUES (
			0, 0, ?,
			'', 1,
			'', ?, '',
			'', 200, '', 700,
			'', '',
			'', '0',
			'', ?,
			'', 'on',
			'', 'on',
			'', 2,
			'', 'on',
			'', 'on',
			'', 1000
		)",
		array($gt_id, '|host_description| - ' . $title_fragment . ' - |query_ifName|', $vertical_label)
	);

	gnmi_set_setting_value($setting_name, $gt_id);
	cacti_log("gNMI: Created graph template '$name' (id=$gt_id)", false, 'PLUGIN');
	return $gt_id;
}

/**
 * Auto-create data sources for subscriptions that need them (poller hook check)
 *
 * This function checks for subscriptions with auto_create_datasources enabled
 * that have enabled metrics but no data sources yet, and creates them.
 * Called periodically from the poller hook to catch any missed cases.
 *
 * @return int Number of data sources created
 */
function gnmi_auto_create_missing_data_sources() {
	// Find subscriptions with auto_create_datasources enabled that need data sources
	$subscriptions = db_fetch_assoc("
		SELECT s.id, s.device_id, s.instance_identifier
		FROM plugin_gnmi_subscriptions s
		WHERE s.enabled = 1
		AND s.auto_create_datasources = 1
		AND EXISTS (
			SELECT 1 FROM plugin_gnmi_metrics m
			WHERE m.subscription_id = s.id
			AND m.enabled = 1
			AND (m.local_data_id IS NULL OR m.datasource_created = 0)
			LIMIT 1
		)
	");

	if (empty($subscriptions)) {
		return 0;
	}

	$created_count = 0;
	foreach ($subscriptions as $sub) {
		cacti_log("gNMI: Auto-creating missing data source for subscription {$sub['id']} (poller hook check)", false, 'POLLER', POLLER_VERBOSITY_LOW);
		$data_local_id = gnmi_create_data_sources_for_subscription($sub['id']);

		if ($data_local_id) {
			cacti_log("gNMI: Auto-created data source $data_local_id for subscription {$sub['id']} (poller hook)", false, 'POLLER', POLLER_VERBOSITY_LOW);
			$created_count++;
		} else {
			cacti_log("gNMI: Failed to auto-create data source for subscription {$sub['id']} (poller hook)", false, 'POLLER', POLLER_VERBOSITY_LOW);
		}
	}

	if ($created_count > 0) {
		cacti_log("gNMI: Auto-created $created_count data source(s) from poller hook check", false, 'POLLER', POLLER_VERBOSITY_LOW);
	}

	return $created_count;
}

/**
 * Resolve graph metadata for a metric row using raw metric_name.
 *
 * Existing rows may not have metric_graph_key populated. Use in-memory
 * classification so ungraphed legacy metrics avoid unsafe row-order pairing
 * without mutating existing metadata during upgrade.
 */
function gnmi_resolve_metric_graph_metadata($metric) {
	$classification = gnmi_classify_metric(isset($metric['metric_name']) ? $metric['metric_name'] : '');
	$metric['metric_group'] = $classification['group'];
	$metric['metric_direction'] = $classification['direction'];
	$metric['metric_graph_key'] = $classification['graph_key'];
	return $metric;
}

/**
 * Create a graph_local row plus graph_templates_graph instance row.
 */
function gnmi_create_graph_instance_shell($gt_id, $host_id, $title_instance, $title_cache_val) {
	db_execute_prepared(
		"INSERT INTO graph_local (host_id, graph_template_id, snmp_query_id, snmp_index) VALUES (?, ?, 0, '')",
		array($host_id, $gt_id)
	);
	$graph_local_id = (int)db_fetch_insert_id();
	if (!$graph_local_id) return false;

	$tpl_gtg = db_fetch_row_prepared(
		'SELECT * FROM graph_templates_graph WHERE graph_template_id = ? AND local_graph_id = 0 LIMIT 1',
		array($gt_id)
	);
	if (!$tpl_gtg) {
		db_execute_prepared('DELETE FROM graph_local WHERE id = ?', array($graph_local_id));
		return false;
	}

	$ok = db_execute_prepared(
		"INSERT INTO graph_templates_graph (
			local_graph_template_graph_id, local_graph_id, graph_template_id,
			t_image_format_id, image_format_id,
			t_title, title, title_cache,
			t_height, height, t_width, width,
			t_upper_limit, upper_limit,
			t_lower_limit, lower_limit,
			t_vertical_label, vertical_label,
			t_slope_mode, slope_mode,
			t_auto_scale, auto_scale,
			t_auto_scale_opts, auto_scale_opts,
			t_auto_padding, auto_padding,
			t_base_value, base_value
		) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
		array(
			$tpl_gtg['id'], $graph_local_id, $gt_id,
			$tpl_gtg['t_image_format_id'], $tpl_gtg['image_format_id'],
			'on', $title_instance, $title_cache_val,
			$tpl_gtg['t_height'], $tpl_gtg['height'], $tpl_gtg['t_width'], $tpl_gtg['width'],
			$tpl_gtg['t_upper_limit'], $tpl_gtg['upper_limit'],
			$tpl_gtg['t_lower_limit'], $tpl_gtg['lower_limit'],
			$tpl_gtg['t_vertical_label'], $tpl_gtg['vertical_label'],
			$tpl_gtg['t_slope_mode'], $tpl_gtg['slope_mode'],
			$tpl_gtg['t_auto_scale'], $tpl_gtg['auto_scale'],
			$tpl_gtg['t_auto_scale_opts'], $tpl_gtg['auto_scale_opts'],
			$tpl_gtg['t_auto_padding'], $tpl_gtg['auto_padding'],
			$tpl_gtg['t_base_value'], $tpl_gtg['base_value']
		)
	);
	if (!$ok) {
		db_execute_prepared('DELETE FROM graph_local WHERE id = ?', array($graph_local_id));
		return false;
	}

	return $graph_local_id;
}

/**
 * Resolve the Cacti data_template_rrd row for a specific gNMI metric.
 */
function gnmi_get_metric_data_template_rrd_id($metric) {
	if (empty($metric['local_data_id'])) return 0;

	$dt_rrd_id = 0;
	if (!empty($metric['cacti_field_name'])) {
		$dt_rrd_id = (int)db_fetch_cell_prepared(
			'SELECT id FROM data_template_rrd WHERE local_data_id = ? AND data_source_name = ? LIMIT 1',
			array((int)$metric['local_data_id'], $metric['cacti_field_name'])
		);
	}

	if (!$dt_rrd_id) {
		$dt_rrd_id = (int)db_fetch_cell_prepared(
			'SELECT id FROM data_template_rrd WHERE local_data_id = ? LIMIT 1',
			array((int)$metric['local_data_id'])
		);
	}

	return $dt_rrd_id;
}

/**
 * Check whether a graph instance already references all expected data-template
 * RRD rows.
 */
function gnmi_graph_contains_task_items($graph_local_id, $task_item_ids) {
	$expected = array();
	foreach ($task_item_ids as $task_item_id) {
		$task_item_id = (int)$task_item_id;
		if ($task_item_id > 0) {
			$expected[$task_item_id] = true;
		}
	}

	if (empty($expected)) return false;

	$rows = db_fetch_assoc_prepared(
		'SELECT DISTINCT task_item_id FROM graph_templates_item WHERE local_graph_id = ? AND task_item_id > 0',
		array((int)$graph_local_id)
	);

	$actual = array();
	foreach ($rows as $row) {
		$actual[(int)$row['task_item_id']] = true;
	}

	foreach (array_keys($expected) as $task_item_id) {
		if (!isset($actual[$task_item_id])) {
			return false;
		}
	}

	return true;
}

/**
 * Create a Cacti graph instance for a given metric.
 *
 * Routes to exact in/out pair graphs, dynamic integrity graphs, or passthrough
 * graphs based on raw-name classification.
 *
 * @param int $metric_id Metric ID from plugin_gnmi_metrics
 * @return int|false graph_local.id on success, false if deferred or on error
 */
function gnmi_create_graph_for_metric($metric_id) {
	global $config;

	if (!function_exists('gnmi_get_subscription')) {
		include_once($config['base_path'] . '/plugins/gnmi/include/subscription_functions.php');
	}

	$metric = db_fetch_row_prepared("
		SELECT m.*, s.device_id, s.instance_identifier, d.host_id
		FROM plugin_gnmi_metrics m
		JOIN plugin_gnmi_subscriptions s ON m.subscription_id = s.id
		JOIN plugin_gnmi_devices d ON s.device_id = d.id
		WHERE m.id = ?",
		array((int)$metric_id)
	);

	if (empty($metric)) {
		cacti_log("gNMI: gnmi_create_graph_for_metric: metric $metric_id not found", false, 'PLUGIN');
		return false;
	}

	$metric = gnmi_resolve_metric_graph_metadata($metric);
	$host_id = (int)$metric['host_id'];

	if (!empty($metric['graph_local_id'])) {
		if (!empty($metric['local_data_id']) &&
			($metric['metric_group'] === 'errors' || $metric['metric_group'] === 'discards') &&
			in_array($metric['metric_graph_key'], array('integrity_packets', 'integrity_octets'))) {
			return gnmi_create_integrity_graph_instance($metric, $host_id);
		}
		return (int)$metric['graph_local_id'];
	}

	if (empty($metric['local_data_id'])) {
		cacti_log("gNMI: gnmi_create_graph_for_metric: metric $metric_id has no data source yet", false, 'PLUGIN');
		return false;
	}

	if ($metric['metric_group'] === 'traffic' || $metric['metric_group'] === 'packets') {
		return gnmi_create_paired_graph_instance($metric, $host_id);
	}

	if (($metric['metric_group'] === 'errors' || $metric['metric_group'] === 'discards') &&
		in_array($metric['metric_graph_key'], array('integrity_packets', 'integrity_octets'))) {
		return gnmi_create_integrity_graph_instance($metric, $host_id);
	}

	return gnmi_create_passthrough_graph_instance($metric, $host_id);
}

/**
 * Create a passthrough (single-DS) graph instance for a metric.
 *
 * @param array $metric  Full metric row including host_id
 * @param int   $host_id Cacti host ID
 * @return int|false graph_local.id on success, false on failure
 */
function gnmi_create_passthrough_graph_instance($metric, $host_id) {
	$gt_id = gnmi_get_passthrough_graph_template_id();
	if (!$gt_id) {
		cacti_log('gNMI: gnmi_create_passthrough_graph_instance: no passthrough template', false, 'PLUGIN');
		return false;
	}

	// Instance-level data_template_rrd row for this metric's local_data_id
	$dt_rrd_id = gnmi_get_metric_data_template_rrd_id($metric);
	if (!$dt_rrd_id) {
		cacti_log("gNMI: gnmi_create_passthrough_graph_instance: no data_template_rrd for local_data_id={$metric['local_data_id']}", false, 'PLUGIN');
		return false;
	}

	// Create graph_local
	db_execute_prepared(
		"INSERT INTO graph_local (host_id, graph_template_id, snmp_query_id, snmp_index) VALUES (?, ?, 0, '')",
		array($host_id, $gt_id)
	);
	$graph_local_id = (int)db_fetch_insert_id();
	if (!$graph_local_id) {
		cacti_log('gNMI: gnmi_create_passthrough_graph_instance: failed to insert graph_local', false, 'PLUGIN');
		return false;
	}

	// Copy graph_templates_graph template row → instance row
	$tpl_gtg = db_fetch_row_prepared(
		'SELECT * FROM graph_templates_graph WHERE graph_template_id = ? AND local_graph_id = 0 LIMIT 1',
		array($gt_id)
	);
	if (!$tpl_gtg) {
		db_execute_prepared('DELETE FROM graph_local WHERE id = ?', array($graph_local_id));
		return false;
	}

	// Compute instance-level title from subscription context so the graph is
	// identifiable without a data query (|query_ifName| never resolves for gNMI graphs)
	$instance_identifier = (string)db_fetch_cell_prepared(
		'SELECT instance_identifier FROM plugin_gnmi_subscriptions WHERE id = ?',
		array((int)$metric['subscription_id'])
	);
	$host_description = (string)db_fetch_cell_prepared(
		'SELECT description FROM host WHERE id = ?',
		array((int)$host_id)
	);
	$metric_label    = !empty($metric['cacti_field_name']) ? $metric['cacti_field_name'] : $metric['metric_name'];
	$title_instance  = '|host_description| - ' . $instance_identifier . ' - ' . $metric_label;
	$title_cache_val = $host_description . ' - ' . $instance_identifier . ' - ' . $metric_label;

	$ok = db_execute_prepared(
		"INSERT INTO graph_templates_graph (
			local_graph_template_graph_id, local_graph_id, graph_template_id,
			t_image_format_id, image_format_id,
			t_title, title, title_cache,
			t_height, height, t_width, width,
			t_upper_limit, upper_limit,
			t_lower_limit, lower_limit,
			t_vertical_label, vertical_label,
			t_slope_mode, slope_mode,
			t_auto_scale, auto_scale,
			t_auto_scale_opts, auto_scale_opts,
			t_auto_padding, auto_padding,
			t_base_value, base_value
		) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
		array(
			$tpl_gtg['id'], $graph_local_id, $gt_id,
			$tpl_gtg['t_image_format_id'], $tpl_gtg['image_format_id'],
			'on', $title_instance, $title_cache_val,
			$tpl_gtg['t_height'], $tpl_gtg['height'], $tpl_gtg['t_width'], $tpl_gtg['width'],
			$tpl_gtg['t_upper_limit'], $tpl_gtg['upper_limit'],
			$tpl_gtg['t_lower_limit'], $tpl_gtg['lower_limit'],
			$tpl_gtg['t_vertical_label'], $tpl_gtg['vertical_label'],
			$tpl_gtg['t_slope_mode'], $tpl_gtg['slope_mode'],
			$tpl_gtg['t_auto_scale'], $tpl_gtg['auto_scale'],
			$tpl_gtg['t_auto_scale_opts'], $tpl_gtg['auto_scale_opts'],
			$tpl_gtg['t_auto_padding'], $tpl_gtg['auto_padding'],
			$tpl_gtg['t_base_value'], $tpl_gtg['base_value']
		)
	);
	if (!$ok) {
		db_execute_prepared('DELETE FROM graph_local WHERE id = ?', array($graph_local_id));
		return false;
	}

	// Copy template items → instance items (all using this metric's dt_rrd_id)
	$tpl_items = db_fetch_assoc_prepared(
		'SELECT * FROM graph_templates_item WHERE graph_template_id = ? AND local_graph_id = 0 ORDER BY sequence',
		array($gt_id)
	);
	foreach ($tpl_items as $tpl_item) {
		$hash = function_exists('generate_hash') ? generate_hash() : md5(uniqid('gnmi_gti_pt_', true));
		$ok = db_execute_prepared(
			"INSERT INTO graph_templates_item (
				hash, local_graph_template_item_id, local_graph_id, graph_template_id,
				task_item_id, color_id, alpha, graph_type_id, line_width,
				dashes, dash_offset, cdef_id, vdef_id, shift,
				consolidation_function_id, textalign,
				text_format, value, hard_return, gprint_id, sequence
			) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
			array(
				$hash, $tpl_item['id'], $graph_local_id, $gt_id,
				$dt_rrd_id, $tpl_item['color_id'], $tpl_item['alpha'], $tpl_item['graph_type_id'], $tpl_item['line_width'],
				$tpl_item['dashes'], $tpl_item['dash_offset'], $tpl_item['cdef_id'], $tpl_item['vdef_id'], $tpl_item['shift'],
				$tpl_item['consolidation_function_id'], $tpl_item['textalign'],
				$tpl_item['text_format'], $tpl_item['value'], $tpl_item['hard_return'], $tpl_item['gprint_id'], $tpl_item['sequence']
			)
		);
		if (!$ok) {
			db_execute_prepared('DELETE FROM graph_templates_item WHERE local_graph_id = ?', array($graph_local_id));
			db_execute_prepared('DELETE FROM graph_templates_graph WHERE local_graph_id = ?', array($graph_local_id));
			db_execute_prepared('DELETE FROM graph_local WHERE id = ?', array($graph_local_id));
			return false;
		}
	}

	gnmi_update_metric((int)$metric['id'], array('graph_local_id' => $graph_local_id, 'graph_created' => true));
	cacti_log("gNMI: Created passthrough graph $graph_local_id for metric {$metric['id']}", false, 'PLUGIN');
	return $graph_local_id;
}

/**
 * Backward-compatible wrapper for traffic graph creation.
 */
function gnmi_create_traffic_graph_instance($metric, $host_id) {
	$metric = gnmi_resolve_metric_graph_metadata($metric);
	return gnmi_create_paired_graph_instance($metric, $host_id);
}

/**
 * Find the exact opposite-direction partner for a paired graph.
 */
function gnmi_find_paired_partner($metric) {
	$opposite = ($metric['metric_direction'] === 'inbound') ? 'outbound' : 'inbound';
	$candidates = db_fetch_assoc_prepared(
		"SELECT * FROM plugin_gnmi_metrics
		 WHERE subscription_id = ? AND enabled = 1
		   AND local_data_id IS NOT NULL AND id <> ?
		 ORDER BY id ASC",
		array((int)$metric['subscription_id'], (int)$metric['id'])
	);

	foreach ($candidates as $candidate) {
		$candidate = gnmi_resolve_metric_graph_metadata($candidate);
		if ($candidate['metric_group'] !== $metric['metric_group']) continue;
		if ($candidate['metric_direction'] !== $opposite) continue;
		if ($candidate['metric_graph_key'] !== $metric['metric_graph_key']) continue;
		return $candidate;
	}

	return null;
}

/**
 * Create a deterministic inbound/outbound paired graph instance.
 */
function gnmi_create_paired_graph_instance($metric, $host_id) {
	if (empty($metric['metric_graph_key']) || !in_array($metric['metric_direction'], array('inbound', 'outbound'))) {
		return false;
	}

	$partner = gnmi_find_paired_partner($metric);
	$opposite = ($metric['metric_direction'] === 'inbound') ? 'outbound' : 'inbound';
	if (empty($partner)) {
		cacti_log("gNMI: Paired graph deferred: no $opposite partner ready for metric {$metric['id']} ({$metric['metric_graph_key']})", false, 'PLUGIN');
		return false;
	}

	$current_dt_rrd_id = gnmi_get_metric_data_template_rrd_id($metric);
	$partner_dt_rrd_id = gnmi_get_metric_data_template_rrd_id($partner);

	if (!$current_dt_rrd_id || !$partner_dt_rrd_id) {
		cacti_log('gNMI: gnmi_create_paired_graph_instance: missing data_template_rrd rows', false, 'PLUGIN');
		return false;
	}

	if (!empty($partner['graph_local_id'])) {
		if (!gnmi_graph_contains_task_items((int)$partner['graph_local_id'], array($current_dt_rrd_id, $partner_dt_rrd_id))) {
			cacti_log("gNMI: Paired graph skipped for metric {$metric['id']} ({$metric['metric_graph_key']}): partner graph {$partner['graph_local_id']} does not contain the exact pair data sources", false, 'PLUGIN');
			return false;
		}

		gnmi_update_metric((int)$metric['id'], array('graph_local_id' => (int)$partner['graph_local_id'], 'graph_created' => true));
		cacti_log("gNMI: Linked metric {$metric['id']} to existing paired graph {$partner['graph_local_id']}", false, 'PLUGIN');
		return (int)$partner['graph_local_id'];
	}

	$gt_id = ($metric['metric_group'] === 'traffic') ? gnmi_get_traffic_graph_template_id() : gnmi_get_packets_graph_template_id();
	if (!$gt_id) return false;

	$in_metric  = ($metric['metric_direction'] === 'inbound')  ? $metric  : $partner;
	$out_metric = ($metric['metric_direction'] === 'outbound') ? $metric  : $partner;

	$in_dt_rrd_id = ($in_metric['id'] == $metric['id']) ? $current_dt_rrd_id : $partner_dt_rrd_id;
	$out_dt_rrd_id = ($out_metric['id'] == $metric['id']) ? $current_dt_rrd_id : $partner_dt_rrd_id;

	$instance_identifier = (string)db_fetch_cell_prepared(
		'SELECT instance_identifier FROM plugin_gnmi_subscriptions WHERE id = ?',
		array((int)$in_metric['subscription_id'])
	);
	$host_description = (string)db_fetch_cell_prepared(
		'SELECT description FROM host WHERE id = ?',
		array((int)$host_id)
	);
	$label = ($metric['metric_group'] === 'traffic') ? 'Interface Traffic' : 'Interface Packets';
	$key_label = str_replace('_', ' ', (string)$metric['metric_graph_key']);
	$title_instance = '|host_description| - ' . $label . ' - ' . $instance_identifier . ' - ' . $key_label;
	$title_cache_val = $host_description . ' - ' . $label . ' - ' . $instance_identifier . ' - ' . $key_label;

	$graph_local_id = gnmi_create_graph_instance_shell($gt_id, $host_id, $title_instance, $title_cache_val);
	if (!$graph_local_id) return false;

	$inputs = db_fetch_assoc_prepared(
		'SELECT * FROM graph_template_input WHERE graph_template_id = ?',
		array($gt_id)
	);
	$input_in_id = $input_out_id = 0;
	foreach ($inputs as $inp) {
		if (stripos($inp['name'], 'Inbound') !== false)  $input_in_id  = (int)$inp['id'];
		if (stripos($inp['name'], 'Outbound') !== false) $input_out_id = (int)$inp['id'];
	}

	$item_to_slot = array();
	foreach (array('inbound' => $input_in_id, 'outbound' => $input_out_id) as $slot => $inp_id) {
		if (!$inp_id) continue;
		$rows = db_fetch_assoc_prepared(
			'SELECT graph_template_item_id FROM graph_template_input_defs WHERE graph_template_input_id = ?',
			array($inp_id)
		);
		foreach ($rows as $r) {
			$item_to_slot[(int)$r['graph_template_item_id']] = $slot;
		}
	}

	$tpl_items = db_fetch_assoc_prepared(
		'SELECT * FROM graph_templates_item WHERE graph_template_id = ? AND local_graph_id = 0 ORDER BY sequence',
		array($gt_id)
	);
	foreach ($tpl_items as $tpl_item) {
		$tpl_item_id = (int)$tpl_item['id'];
		$slot = isset($item_to_slot[$tpl_item_id]) ? $item_to_slot[$tpl_item_id] : 'none';
		if ($slot === 'inbound') {
			$task_item_id = $in_dt_rrd_id;
		} elseif ($slot === 'outbound') {
			$task_item_id = $out_dt_rrd_id;
		} else {
			$task_item_id = 0;
		}

		$hash = function_exists('generate_hash') ? generate_hash() : md5(uniqid('gnmi_gti_pair_inst_', true));
		$ok = db_execute_prepared(
			"INSERT INTO graph_templates_item (
				hash, local_graph_template_item_id, local_graph_id, graph_template_id,
				task_item_id, color_id, alpha, graph_type_id, line_width,
				dashes, dash_offset, cdef_id, vdef_id, shift,
				consolidation_function_id, textalign,
				text_format, value, hard_return, gprint_id, sequence
			) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
			array(
				$hash, $tpl_item_id, $graph_local_id, $gt_id,
				$task_item_id, $tpl_item['color_id'], $tpl_item['alpha'], $tpl_item['graph_type_id'], $tpl_item['line_width'],
				$tpl_item['dashes'], $tpl_item['dash_offset'], $tpl_item['cdef_id'], $tpl_item['vdef_id'], $tpl_item['shift'],
				$tpl_item['consolidation_function_id'], $tpl_item['textalign'],
				$tpl_item['text_format'], $tpl_item['value'], $tpl_item['hard_return'], $tpl_item['gprint_id'], $tpl_item['sequence']
			)
		);
		if (!$ok) {
			db_execute_prepared('DELETE FROM graph_templates_item WHERE local_graph_id = ?', array($graph_local_id));
			db_execute_prepared('DELETE FROM graph_templates_graph WHERE local_graph_id = ?', array($graph_local_id));
			db_execute_prepared('DELETE FROM graph_local WHERE id = ?', array($graph_local_id));
			return false;
		}
	}

	gnmi_update_metric((int)$in_metric['id'],  array('graph_local_id' => $graph_local_id, 'graph_created' => true));
	gnmi_update_metric((int)$out_metric['id'], array('graph_local_id' => $graph_local_id, 'graph_created' => true));
	cacti_log("gNMI: Created paired graph $graph_local_id for metrics {$in_metric['id']}/{$out_metric['id']} ({$metric['metric_graph_key']})", false, 'PLUGIN');
	return $graph_local_id;
}

/**
 * Find an existing integrity graph for a subscription/key.
 */
function gnmi_find_integrity_graph_id($subscription_id, $metric_graph_key) {
	$metrics = db_fetch_assoc_prepared(
		"SELECT * FROM plugin_gnmi_metrics
		 WHERE subscription_id = ? AND enabled = 1 AND graph_local_id IS NOT NULL
		 ORDER BY id ASC",
		array((int)$subscription_id)
	);

	foreach ($metrics as $metric) {
		$metric = gnmi_resolve_metric_graph_metadata($metric);
		if ($metric['metric_graph_key'] === $metric_graph_key &&
			($metric['metric_group'] === 'errors' || $metric['metric_group'] === 'discards')) {
			return (int)$metric['graph_local_id'];
		}
	}

	return 0;
}

/**
 * Create or attach to a dynamic integrity graph.
 */
function gnmi_create_integrity_graph_instance($metric, $host_id) {
	$subscription_id = (int)$metric['subscription_id'];
	$graph_key = $metric['metric_graph_key'];
	$gt_id = gnmi_get_integrity_graph_template_id($graph_key);
	if (!$gt_id) return false;

	$graph_local_id = gnmi_find_integrity_graph_id($subscription_id, $graph_key);
	if (!$graph_local_id) {
		$instance_identifier = (string)db_fetch_cell_prepared(
			'SELECT instance_identifier FROM plugin_gnmi_subscriptions WHERE id = ?',
			array($subscription_id)
		);
		$host_description = (string)db_fetch_cell_prepared(
			'SELECT description FROM host WHERE id = ?',
			array((int)$host_id)
		);
		$label = ($graph_key === 'integrity_octets') ? 'Interface Integrity Octets' : 'Interface Integrity Packets/Events';
		$title_instance = '|host_description| - ' . $label . ' - ' . $instance_identifier;
		$title_cache_val = $host_description . ' - ' . $label . ' - ' . $instance_identifier;
		$graph_local_id = gnmi_create_graph_instance_shell($gt_id, $host_id, $title_instance, $title_cache_val);
		if (!$graph_local_id) return false;
	}

	gnmi_attach_integrity_metrics_to_graph($subscription_id, $graph_key, $graph_local_id, $gt_id);
	return $graph_local_id;
}

/**
 * Add missing dynamic integrity graph items for all ready metrics in a group.
 */
function gnmi_attach_integrity_metrics_to_graph($subscription_id, $graph_key, $graph_local_id, $gt_id) {
	$metrics = db_fetch_assoc_prepared(
		"SELECT * FROM plugin_gnmi_metrics
		 WHERE subscription_id = ? AND enabled = 1 AND local_data_id IS NOT NULL
		 ORDER BY id ASC",
		array((int)$subscription_id)
	);

	$palette = array('D62728', 'FF7F0E', '9467BD', '8C564B', 'E377C2', '7F7F7F', '17BECF', 'BCBD22');
	$existing_series_count = (int)db_fetch_cell_prepared(
		'SELECT COUNT(DISTINCT task_item_id) FROM graph_templates_item WHERE local_graph_id = ? AND task_item_id > 0',
		array((int)$graph_local_id)
	);
	$attached = 0;
	foreach ($metrics as $metric) {
		$metric = gnmi_resolve_metric_graph_metadata($metric);
		if ($metric['metric_graph_key'] !== $graph_key) continue;
		if ($metric['metric_group'] !== 'errors' && $metric['metric_group'] !== 'discards') continue;

		$dt_rrd_id = gnmi_get_metric_data_template_rrd_id($metric);
		if (!$dt_rrd_id) continue;

		$existing = (int)db_fetch_cell_prepared(
			'SELECT COUNT(*) FROM graph_templates_item WHERE local_graph_id = ? AND task_item_id = ?',
			array((int)$graph_local_id, $dt_rrd_id)
		);
		if (!$existing) {
			$max_sequence = (int)db_fetch_cell_prepared(
				'SELECT COALESCE(MAX(sequence), 0) FROM graph_templates_item WHERE local_graph_id = ?',
				array((int)$graph_local_id)
			);
			$color_id = gnmi_get_color_id($palette[($existing_series_count + $attached) % count($palette)]);
			$label = substr((string)$metric['metric_name'], 0, 45);

			$hash = function_exists('generate_hash') ? generate_hash() : md5(uniqid('gnmi_gti_integrity_line_', true));
			db_execute_prepared(
				"INSERT INTO graph_templates_item (
					hash, local_graph_template_item_id, local_graph_id, graph_template_id,
					task_item_id, color_id, alpha, graph_type_id, line_width,
					dashes, dash_offset, cdef_id, vdef_id, shift,
					consolidation_function_id, textalign,
					text_format, value, hard_return, gprint_id, sequence
				) VALUES (?, 0, ?, ?, ?, ?, 'FF', 5, 1.00, '', 0, 0, 0, '', 1, '', ?, '', '', 2, ?)",
				array($hash, (int)$graph_local_id, (int)$gt_id, $dt_rrd_id, (int)$color_id, $label, $max_sequence + 1)
			);

			$hash = function_exists('generate_hash') ? generate_hash() : md5(uniqid('gnmi_gti_integrity_gprint_', true));
			db_execute_prepared(
				"INSERT INTO graph_templates_item (
					hash, local_graph_template_item_id, local_graph_id, graph_template_id,
					task_item_id, color_id, alpha, graph_type_id, line_width,
					dashes, dash_offset, cdef_id, vdef_id, shift,
					consolidation_function_id, textalign,
					text_format, value, hard_return, gprint_id, sequence
				) VALUES (?, 0, ?, ?, ?, 0, 'FF', 9, 0.00, '', 0, 0, 0, '', 4, '', 'Current: ', '', 'on', 2, ?)",
				array($hash, (int)$graph_local_id, (int)$gt_id, $dt_rrd_id, $max_sequence + 2)
			);
			$attached++;
		}

		gnmi_update_metric((int)$metric['id'], array('graph_local_id' => (int)$graph_local_id, 'graph_created' => true));
	}

	return $attached;
}

/**
 * Auto-create missing graphs for eligible metrics (poller hook safety net).
 *
 * Queries for metrics that have a data source but no graph yet, where the subscription
 * has auto_create_graphs enabled. Calls gnmi_create_graph_for_metric() for each.
 *
 * @return int Number of graphs created
 */
function gnmi_auto_create_missing_graphs() {
	// Failsafe: column may not exist on very old installs
	$cols = db_fetch_assoc('DESCRIBE plugin_gnmi_metrics');
	if (!is_array($cols)) return 0;
	$col_names = array_column($cols, 'Field');
	if (!in_array('graph_local_id', $col_names) || !in_array('graph_created', $col_names)) {
		return 0;
	}

	$metrics = db_fetch_assoc("
		SELECT m.id
		FROM plugin_gnmi_metrics m
		JOIN plugin_gnmi_subscriptions s ON m.subscription_id = s.id
		WHERE s.enabled = 1 AND s.auto_create_graphs = 1 AND m.enabled = 1
		  AND m.local_data_id IS NOT NULL
		  AND (m.graph_local_id IS NULL OR m.graph_created = 0)
	");

	if (empty($metrics)) return 0;

	$created = 0;
	foreach ($metrics as $m) {
		$result = gnmi_create_graph_for_metric((int)$m['id']);
		if ($result !== false) $created++;
	}

	if ($created > 0) {
		cacti_log("gNMI: Auto-created $created graph(s) from poller hook", false, 'POLLER', POLLER_VERBOSITY_LOW);
	}
	return $created;
}

/**
 * Collect gNMI telemetry data for all enabled devices and write directly to RRD files.
 *
 * This function is called by gnmi_manage_daemons() via the poller_bottom hook.
 * For each enabled gNMI device, it:
 * 1. Calls the poller bridge script with device-specific parameters
 * 2. Parses the output (format: "field:value field:value ...")
 * 3. Creates RRD files if they don't exist
 * 4. Writes data directly to RRD files using rrdtool update
 *
 * @return bool Success status
 */
function gnmi_collect_telemetry() {
	global $config;

	cacti_log('gNMI: gnmi_collect_telemetry() called', false, 'POLLER', POLLER_VERBOSITY_LOW);

	$template_id = gnmi_get_passthrough_data_template_id();
	if (empty($template_id)) {
		cacti_log('gNMI: Cannot collect telemetry - passthrough data template missing', false, 'POLLER', POLLER_VERBOSITY_LOW);
		return false;
	}

	// Check for subscriptions that need data sources auto-created
	gnmi_auto_create_missing_data_sources();

	// Check for metrics that need graphs auto-created
	gnmi_auto_create_missing_graphs();

	// Get all enabled gNMI devices
	$devices = db_fetch_assoc('SELECT * FROM plugin_gnmi_devices WHERE enabled = 1');

	if (empty($devices)) {
		cacti_log('gNMI: No enabled devices found for telemetry collection', false, 'POLLER', POLLER_VERBOSITY_LOW);
		return true;
	}

	cacti_log('gNMI: Collecting telemetry from ' . count($devices) . ' device(s)', false, 'POLLER', POLLER_VERBOSITY_LOW);

	$python_bin = gnmi_get_python_binary();
	if ($python_bin === false) {
		cacti_log('gNMI: Cannot collect telemetry - virtual environment not found', false, 'POLLER', POLLER_VERBOSITY_LOW);
		return;
	}

	$script_path = $config['base_path'] . '/plugins/gnmi/scripts/gnmi_poller_bridge.py';
	$cacti_config_path = $config['base_path'] . '/include/config.php';

	foreach ($devices as $device) {
		$device_id = $device['id'];
		$cacti_host_id = $device['host_id'];  // FK to Cacti's host table (new consolidated table field)

		// Get data sources for this device
		$data_sources = db_fetch_assoc_prepared(
			"SELECT
				dl.id AS local_data_id,
				dtd.name AS data_source_label
			FROM data_local dl
			INNER JOIN data_template_data dtd ON dl.id = dtd.local_data_id
			WHERE dl.host_id = ?
			  AND dl.data_template_id = ?
			GROUP BY dl.id, dtd.name",
			array($cacti_host_id, $template_id)
		);

		if (empty($data_sources)) {
			cacti_log("gNMI: No data sources found for device $device_id (host $cacti_host_id)", false, 'POLLER', POLLER_VERBOSITY_LOW);
			continue;
		}

		cacti_log("gNMI: Found " . count($data_sources) . " data source(s) for device $device_id", false, 'POLLER', POLLER_VERBOSITY_LOW);

		// Call bridge once per data source (Option A architecture)
		// This supports future per-data-source query intervals
		$storage_dir = gnmi_get_storage_dir();
		$device_poll_success = false;
		foreach ($data_sources as $ds) {
			$local_data_id = $ds['local_data_id'];
			$ds_label = $ds['data_source_label'];

		// Build command: bridge queries database for this data source's metrics
		// Use --output-history to get all buffered samples with timestamps for RRD backfill
		// Pass staleness threshold so bridge skips stale data at any poller interval
		$cmd = escapeshellarg($python_bin) . " " . escapeshellarg($script_path) .
		       " --device-id " . escapeshellarg($device_id) .
		       " --local-data-id " . escapeshellarg($local_data_id) .
		       " --storage-dir " . escapeshellarg($storage_dir) .
		       " --config-path " . escapeshellarg($cacti_config_path) .
		       " --staleness-threshold " . escapeshellarg(gnmi_get_poller_interval() * 2) .
		       " --output-history" .
		       " 2>&1";

			// Execute poller bridge for this data source
			$output = shell_exec($cmd);

			if (empty($output)) {
				cacti_log("gNMI: No output from bridge for data source $local_data_id (device $device_id)", false, 'POLLER', POLLER_VERBOSITY_LOW);
				continue;
			}

			// Check if output contains data (format: "EPOCH field:value field:value" per line)
			if (strpos($output, ':') === false) {
				cacti_log("gNMI: Invalid output from bridge for data source $local_data_id: " . substr($output, 0, 100), false, 'POLLER', POLLER_VERBOSITY_LOW);
				continue;
			}

			// Parse multi-line output: "EPOCH field:value field:value" per line
			// This format supports RRD backfill with multiple timestamped samples
			$lines = explode("\n", trim($output));
			$timestamped_samples = array();  // Array of [epoch => [field => value, ...]]

			foreach ($lines as $line) {
				$line = trim($line);
				if (empty($line)) continue;

				// Split into epoch and metrics: "1738561003 in_octets:123 out_octets:456"
				$parts = explode(' ', $line, 2);
				if (count($parts) != 2) continue;

				$epoch = intval($parts[0]);
				if ($epoch < 1000000000) continue;  // Basic validation (> year 2001)

				$metrics_str = $parts[1];
				$pairs = explode(' ', $metrics_str);
				$metrics = array();

				foreach ($pairs as $pair) {
					if (strpos($pair, ':') !== false) {
						list($field_name, $value) = explode(':', $pair, 2);
						$metrics[trim($field_name)] = trim($value);
					}
				}

				if (!empty($metrics)) {
					$timestamped_samples[$epoch] = $metrics;
				}
			}

			if (empty($timestamped_samples)) {
				cacti_log("gNMI: No timestamped samples parsed from bridge output for data source $local_data_id", false, 'POLLER', POLLER_VERBOSITY_LOW);
				continue;
			}

			// Sort by epoch (chronological order - required by rrdtool)
			ksort($timestamped_samples);

			cacti_log("gNMI: Parsed " . count($timestamped_samples) . " timestamped samples for data source $local_data_id ($ds_label)", false, 'POLLER', POLLER_VERBOSITY_LOW);

			// Get field count from first sample for validation
			$first_sample = reset($timestamped_samples);
			$metrics = $first_sample;  // For compatibility with downstream code that expects $metrics

			if (empty($metrics)) {
				cacti_log("gNMI: No metrics parsed from bridge output for data source $local_data_id", false, 'POLLER', POLLER_VERBOSITY_LOW);
				continue;
			}

			cacti_log("gNMI: Parsed " . count($metrics) . " metrics for data source $local_data_id ($ds_label)", false, 'POLLER', POLLER_VERBOSITY_LOW);

			// Bridge outputs all metrics for this data source
			// Get all expected field names for this data source to validate
			$ds_fields = db_fetch_assoc("
				SELECT data_source_name
				FROM data_template_rrd
				WHERE local_data_id = $local_data_id
			");

			// Build values array matching RRD data source order
			$values = array();
			foreach ($ds_fields as $ds_field) {
				$field_name = $ds_field['data_source_name'];
				if (isset($metrics[$field_name])) {
					$values[$field_name] = $metrics[$field_name];
				}
			}

			if (empty($values)) {
				cacti_log("gNMI: No matching metrics for data source $local_data_id", false, 'POLLER', POLLER_VERBOSITY_LOW);
				continue;
			}

			// Create RRD files if needed and write data directly
			cacti_log("gNMI: Processing DS $local_data_id ($ds_label) with " . count($values) . " values", false, 'POLLER', POLLER_VERBOSITY_LOW);
			// Resolve the path through Cacti so legacy rows with a blank
			// data_source_path use Cacti's established fallback generation.
			$rrd_path = get_data_source_path($local_data_id, true);

			if (empty($rrd_path)) {
				cacti_log("gNMI: No RRD path found for DS $local_data_id", false, 'POLLER', POLLER_VERBOSITY_LOW);
				continue;
			}

			$oldest_epoch = 0;
			foreach ($timestamped_samples as $sample_epoch => $unused_sample) {
				$oldest_epoch = (int)$sample_epoch;
				break;
			}
			if (!gnmi_prepare_rrd_file(
				$rrd_path,
				(int)$local_data_id,
				$ds_label,
				$oldest_epoch
			)) {
				continue;
			}

			// Write directly to RRD file
			// Build rrdupdate command with multiple timestamps for RRD backfill
			// Format: rrdtool update file.rrd EPOCH1:val1:val2 EPOCH2:val1:val2 ...

			// Get all RRD data source names in order (needed for value ordering)
			$rrd_items = db_fetch_assoc("
				SELECT data_source_name
				FROM data_template_rrd
				WHERE local_data_id = $local_data_id
				ORDER BY id
			");

			// Build update command with all timestamped samples
			// Using --skip-past-updates to ignore samples that are older than last RRD update
			$update_cmd = "rrdtool update --skip-past-updates " . escapeshellarg($rrd_path);

			$update_count = 0;
			foreach ($timestamped_samples as $epoch => $sample_metrics) {
				// Build value string for this timestamp
				$val_str = $epoch;
				foreach ($rrd_items as $item) {
					$ds_name = $item['data_source_name'];
					$val = isset($sample_metrics[$ds_name]) ? $sample_metrics[$ds_name] : 'U';
					$val_str .= ":" . $val;
				}
				$update_cmd .= ' ' . escapeshellarg($val_str);
				$update_count++;
			}

			// Execute update with all timestamped samples in one call
			cacti_log("gNMI: Executing RRD update with $update_count samples: " . substr($update_cmd, 0, 200) . "...", false, 'POLLER', POLLER_VERBOSITY_LOW);
			if (gnmi_update_rrd_with_recovery(
				$update_cmd,
				$rrd_path,
				(int)$local_data_id,
				$ds_label,
				$oldest_epoch
			)) {
				cacti_log("gNMI: Updated DS $local_data_id ($ds_label) with $update_count timestamped samples for device $device_id", false, 'POLLER', POLLER_VERBOSITY_LOW);
				$device_poll_success = true;
			}
		}

		// Update last_poll_time and last_poll_status for this device
		$poll_status = $device_poll_success ? 'success' : 'error';
		db_execute_prepared(
			'UPDATE plugin_gnmi_devices SET last_poll_time = NOW(), last_poll_status = ? WHERE id = ?',
			array($poll_status, $device_id)
		);
		cacti_log("gNMI: Updated poll status for device $device_id: $poll_status", false, 'POLLER', POLLER_VERBOSITY_LOW);
	}

	return true;
}

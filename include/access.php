<?php
/** Web authorization at explicit action and diagnostic boundaries. */
if (!defined('CACTI_VERSION')) { die('Access denied'); }

/** CLI lifecycle callers are trusted; harnesses can exercise web policy. */
function gnmi_web_authorization_required() {
	return PHP_SAPI !== 'cli' || !empty($GLOBALS['gnmi_enforce_web_auth_in_cli'])
		|| !empty($GLOBALS['gnmi_enforce_device_auth_in_cli']);
}

/** Mutations require an effective, authenticated non-guest identity. */
function gnmi_authenticated_operator() {
	if (empty($_SESSION['sess_user_id']) || !empty($_SESSION['sess_change_password'])
		|| !is_scalar($_SESSION['sess_user_id'])
		|| (int)$_SESSION['sess_user_id'] <= 0
		|| !function_exists('get_guest_account') || !function_exists('read_config_option')) {
		return false;
	}
	static $identities = array();
	$user_id = (int)$_SESSION['sess_user_id'];
	if (!array_key_exists($user_id, $identities)) {
		// Cacti caches configuration in the session. Re-read identity policy
		// once per request so changing guest/no-auth settings cannot preserve
		// an old mutation permission. Realm grants still use Cacti's API.
		$method = read_config_option('auth_method', true);
		read_config_option('guest_user', true);
		$identities[$user_id] = is_scalar($method) && (int)$method > 0
			&& $user_id !== (int)get_guest_account();
	}
	return $identities[$user_id];
}

/** Read policy follows Cacti for configured guest and no-auth installations. */
function gnmi_current_user_can_view_host($host_id, $reuse = true) {
	if (!gnmi_web_authorization_required()) { return true; }
	if (!is_scalar($host_id) || (int)$host_id <= 0 || !function_exists('is_device_allowed')
		|| !function_exists('read_config_option')) { return false; }
	if (empty($_SESSION['sess_user_id']) && (int)read_config_option('auth_method') !== 0) {
		return false;
	}
	// This cache lives only for this PHP request and includes effective identity.
	// Execution checks deliberately bypass it; Cacti owns session invalidation.
	static $decisions = array();
	$key = (int)($_SESSION['sess_user_id'] ?? 0) . ':' . (int)$host_id;
	if (!$reuse || !array_key_exists($key, $decisions)) {
		$decisions[$key] = (bool)is_device_allowed((int)$host_id);
	}
	return $decisions[$key];
}

/** Dedicated daemon grants never fall back to general device management. */
function gnmi_current_user_can_manage_daemons() {
	return gnmi_authenticated_operator() && function_exists('api_plugin_user_realm_auth')
		&& (bool)api_plugin_user_realm_auth('dashboard_actions.php');
}

/** Core Users/Groups realm is the installation maintenance authority. */
function gnmi_current_user_is_installation_admin() {
	return gnmi_authenticated_operator() && function_exists('is_realm_allowed')
		&& (bool)is_realm_allowed(1);
}

function gnmi_current_user_can_cleanup_orphans() {
	return gnmi_current_user_can_manage_daemons() && gnmi_current_user_is_installation_admin();
}

/** Same eligibility for dashboard and device-form explicit restart controls. */
function gnmi_current_user_can_restart_device($device, $reuse = true) {
	return is_array($device) && !empty($device['enabled'])
		&& gnmi_current_user_can_manage_daemons()
		&& gnmi_current_user_can_view_host($device['host_id'] ?? 0, $reuse);
}

/** Empty authorized results must not disclose hidden inventory. */
function gnmi_render_no_accessible_devices() {
	global $config;
	$html = '<p>No accessible gNMI devices are currently enabled.';
	if (function_exists('api_user_realm_auth') && api_user_realm_auth('host.php')) {
		$html .= ' <a href="' . html_escape($config['url_path']) . 'host.php">Add devices</a>';
	}
	return $html . '</p>';
}

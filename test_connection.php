<?php
/**
 * gNMI Connection Test — streaming endpoint.
 *
 * Accepts POST with current form values (including unsaved changes), writes a
 * temp config JSON, invokes gnmi_connection_test.py via proc_open, and streams
 * NDJSON output line-by-line so the browser receives each stage result as it
 * completes.
 *
 * Security:
 *  - Requires active Cacti session
 *  - If device_id provided, verifies it belongs to the posted host_id
 *  - Cert paths validated to be within plugin certs/ directory (no traversal)
 *  - Temp config file is chmod 0600 and unlinked after the script exits
 */

require_once(__DIR__ . '/../../include/global.php');
include_once($config['base_path'] . '/plugins/gnmi/include/functions.php');

// ── Streaming headers ────────────────────────────────────────────────────────
header('Content-Type: text/plain; charset=utf-8');
header('X-Accel-Buffering: no');   // disable nginx proxy buffering
header('Cache-Control: no-cache');
ini_set('output_buffering', 'Off');
ini_set('implicit_flush', true);
if (ob_get_level() > 0) {
    ob_end_flush();
}

// ── Auth and CSRF guards ─────────────────────────────────────────────────────
if (!gnmi_current_user_can_manage('test_connection.php')) {
    echo json_encode(['stage' => 'error', 'success' => false,
                      'message' => 'Permission denied', 'duration_ms' => 0]) . "\n";
    exit;
}

if (!gnmi_validate_csrf_request()) {
    cacti_log('gNMI: Connection test CSRF token validation failed', false, 'PLUGIN');
    echo json_encode(['stage' => 'error', 'success' => false,
                      'message' => 'Invalid request token', 'duration_ms' => 0]) . "\n";
    exit;
}

// ── Input validation ─────────────────────────────────────────────────────────
$host_id   = (int)($_POST['id']              ?? 0);
$device_id = (int)($_POST['gnmi_device_id']  ?? 0);

if ($device_id > 0) {
    $valid = db_fetch_cell_prepared(
        'SELECT COUNT(*) FROM plugin_gnmi_devices WHERE id = ? AND host_id = ?',
        [$device_id, $host_id]
    );
    if (!$valid) {
        echo json_encode(['stage' => 'error', 'success' => false,
                          'message' => 'Device not found or permission denied',
                          'duration_ms' => 0]) . "\n";
        ob_flush(); flush();
        exit;
    }
}

$hostname     = trim($_POST['gnmi_hostname']    ?? '');
$port         = max(1, min(65535, (int)($_POST['gnmi_port'] ?? 9339)));
$username     = (string)($_POST['gnmi_username']   ?? '');
$password     = (string)($_POST['gnmi_password']   ?? '');
$use_tls      = (($_POST['use_tls']      ?? '0') === '1');
$ca_cert      = trim($_POST['ca_cert_path']     ?? '');
$client_key   = trim($_POST['client_key_path']  ?? '');
$client_cert  = trim($_POST['client_cert_path'] ?? '');
$tls_override = trim($_POST['tls_override']     ?? '');
$skip_verify  = (($_POST['skip_verify']  ?? '0') === '1');
$compatibility_mode = (($_POST['compatibility_mode'] ?? 'standard') === 'ciena_saos10')
    ? 'ciena_saos10'
    : 'standard';

if ($hostname === '') {
    echo json_encode(['stage' => 'error', 'success' => false,
                      'message' => 'Hostname is required', 'duration_ms' => 0]) . "\n";
    ob_flush(); flush();
    exit;
}

// Certificate material must remain below the protected runtime certs directory.
if (!gnmi_certificate_path_is_allowed($ca_cert) ||
    !gnmi_certificate_path_is_allowed($client_key) ||
    !gnmi_certificate_path_is_allowed($client_cert)) {
    echo json_encode(['stage' => 'error', 'success' => false,
                      'message' => 'Invalid certificate path — must be within the gNMI runtime certs directory',
                      'duration_ms' => 0]) . "\n";
    ob_flush(); flush();
    exit;
}

// ── Write temp config JSON ────────────────────────────────────────────────────
$tmp = tempnam(sys_get_temp_dir(), 'gnmi_test_');
if ($tmp === false) {
    echo json_encode(['stage' => 'error', 'success' => false,
                      'message' => 'Cannot create temp file', 'duration_ms' => 0]) . "\n";
    ob_flush(); flush();
    exit;
}
chmod($tmp, 0600);   // protect credentials before writing

file_put_contents($tmp, json_encode([
    'hostname'          => $hostname,
    'port'              => $port,
    'username'          => $username,
    'password'          => $password,
    'use_tls'           => $use_tls,
    'ca_cert_path'      => $ca_cert,
    'client_key_path'   => $client_key,
    'client_cert_path'  => $client_cert,
    'tls_override'      => $tls_override,
    'skip_verify'       => $skip_verify,
    'compatibility_mode'=> $compatibility_mode,
]));

// ── Resolve paths ─────────────────────────────────────────────────────────────
$python = $config['base_path'] . '/plugins/gnmi/venv/bin/python3';
$script = $config['base_path'] . '/plugins/gnmi/scripts/gnmi_connection_test.py';
$cwd    = $config['base_path'] . '/plugins/gnmi/scripts';

if (!file_exists($python)) {
    unlink($tmp);
    echo json_encode(['stage' => 'error', 'success' => false,
                      'message' => 'Python virtual environment not found — install plugin dependencies first',
                      'duration_ms' => 0]) . "\n";
    ob_flush(); flush();
    exit;
}

// ── Launch Python script and stream stdout ────────────────────────────────────
// Use popen (simpler than proc_open — avoids multi-pipe descriptor management issues).
// Redirect stderr to /dev/null; Python uses flush=True so stdout arrives line by line.
$cmd = escapeshellarg($python) . ' -u ' .
       escapeshellarg($script) . ' ' .
       escapeshellarg($tmp) . ' 2>/dev/null';

$handle = popen($cmd, 'r');

if ($handle === false) {
    unlink($tmp);
    echo json_encode(['stage' => 'error', 'success' => false,
                      'message' => 'Failed to launch test script', 'duration_ms' => 0]) . "\n";
    ob_flush(); flush();
    exit;
}

while (!feof($handle)) {
    $line = fgets($handle);
    if ($line === false) break;   // guard: fgets returns false on read error
    if (trim($line) !== '') {
        echo $line;    // already ends with \n from Python print()
        ob_flush();
        flush();
    }
}

pclose($handle);
unlink($tmp);

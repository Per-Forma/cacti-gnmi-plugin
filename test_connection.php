<?php
/**
 * gNMI Connection Test — streaming endpoint.
 *
 * Accepts POST with current form values (including unsaved changes), writes a
 * temp config JSON, invokes gnmi_connection_test.py via popen, and streams
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
$tls_cipher_policy = (($_POST['tls_cipher_policy'] ?? 'default') === 'legacy_compatibility')
    ? 'legacy_compatibility'
    : 'default';
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
    'tls_cipher_policy' => $tls_cipher_policy,
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
$stderr_tmp = tempnam(sys_get_temp_dir(), 'gnmi_test_stderr_');
if ($stderr_tmp === false) {
    unlink($tmp);
    echo json_encode(['stage' => 'error', 'success' => false,
                      'message' => 'Cannot create diagnostic temp file', 'duration_ms' => 0]) . "\n";
    ob_flush(); flush();
    exit;
}
chmod($stderr_tmp, 0600);

// Keep native gRPC diagnostics out of the streamed NDJSON response, but retain
// a bounded, redacted copy in the Cacti log when the helper emits stderr.
$cmd = escapeshellarg($python) . ' -u ' .
       escapeshellarg($script) . ' ' .
       escapeshellarg($tmp) . ' 2>' . escapeshellarg($stderr_tmp);

$handle = popen($cmd, 'r');

if ($handle === false) {
    unlink($tmp);
    unlink($stderr_tmp);
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

$exit_code = pclose($handle);
$native_stderr = file_get_contents($stderr_tmp);
if ($exit_code !== 0 && is_string($native_stderr) && trim($native_stderr) !== '') {
    foreach ([$password, $username, $tmp, $stderr_tmp] as $sensitive_value) {
        if ($sensitive_value !== '') {
            $native_stderr = str_replace($sensitive_value, '[redacted]', $native_stderr);
        }
    }
    $native_stderr = str_replace(["\r", "\n", "\t"], ' ', $native_stderr);
    $native_stderr = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $native_stderr);
    $native_stderr = substr(trim($native_stderr), 0, 4000);
    cacti_log(
        'gNMI: Connection test native diagnostics (exit ' . (int)$exit_code . '): ' . $native_stderr,
        false,
        'PLUGIN'
    );
}
unlink($tmp);
unlink($stderr_tmp);

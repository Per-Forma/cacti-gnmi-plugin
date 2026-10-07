<?php
/** Internal subprocess protocol. Evaluates trusted Cacti config; never an HTTP endpoint. */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit(1); }
if (!function_exists('stream_isatty') || stream_isatty(STDOUT)) { exit(1); }
ini_set('display_errors', '0');
ini_set('log_errors', '0');
require_once __DIR__ . '/../include/database_config.php';
// Contain output even when included configuration exits before returning.
register_shutdown_function(function () { while (ob_get_level() > 0) @ob_end_clean(); });
set_error_handler(function () { throw new GnmiDatabaseConfigException(); });
try {
    $path = $argv[1] ?? '';
    if ($argc !== 2 || !is_file($path) || !is_readable($path)) throw new GnmiDatabaseConfigException('permissions');
    $path = realpath($path);
    if ($path === false || !chdir(dirname($path))) throw new GnmiDatabaseConfigException('permissions');
    $database_type='mysql'; $database_hostname='localhost'; $database_port='3306';
    $database_default='cacti'; $database_username='cactiuser'; $database_password='cactiuser';
    $database_ssl=false; $database_ssl_ca=''; $database_ssl_cert=''; $database_ssl_key='';
    $poller_id=1; $config=[];
    $emitted = false;
    ob_start(function ($output) use (&$emitted) { if ($output !== '') $emitted=true; return ''; }, 8192);
    include $path;
    if (ob_get_length() > 0) $emitted=true;
    ob_end_clean();
    if ($emitted) throw new GnmiDatabaseConfigException();
    if ((int)$poller_id > 1 || (int)($config['poller_id'] ?? 1) > 1 ||
        !empty($rdatabase_hostname) || !empty($rdatabase_default) || !empty($rdatabase_username)) {
        throw new GnmiDatabaseConfigException('routing');
    }
    $payload = gnmi_database_payload(compact('database_type','database_hostname','database_port','database_default',
        'database_username','database_password','database_ssl','database_ssl_ca','database_ssl_cert','database_ssl_key'));
    fwrite(STDOUT, json_encode($payload, JSON_THROW_ON_ERROR));
} catch (Throwable $error) {
    while (ob_get_level() > 0) @ob_end_clean();
    $category = $error instanceof GnmiDatabaseConfigException ? $error->category : 'configuration';
    fwrite(STDERR, 'GNMI_BRIDGE_ERROR '.$category." resolver\n");
    exit(1);
}

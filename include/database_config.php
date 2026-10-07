<?php
/** Private in-memory protocol shared by the poller and trusted CLI resolver. */
class GnmiDatabaseConfigException extends RuntimeException {
    public $category;
    public function __construct($category = 'configuration') {
        $this->category = $category;
        parent::__construct('Database configuration could not be exported safely');
    }
}

function gnmi_database_payload(array $metadata, $default_socket = null) {
    foreach (['database_type','database_hostname','database_default','database_username','database_password'] as $field) {
        if (!isset($metadata[$field]) || !is_string($metadata[$field]) ||
            ($field !== 'database_password' && strpos($metadata[$field], "\0") !== false)) {
            throw new GnmiDatabaseConfigException();
        }
    }
    $port = $metadata['database_port'] ?? null;
    if ((!is_int($port) && (!is_string($port) || !preg_match('/^[0-9]{1,5}$/D', $port))) || (int)$port < 1 || (int)$port > 65535) {
        throw new GnmiDatabaseConfigException();
    }
    $port = (int)$port;
    $tls = $metadata['database_ssl'] ?? null;
    if ($metadata['database_type'] !== 'mysql' || !is_bool($tls) || $metadata['database_hostname'] === '' || $metadata['database_default'] === '') {
        throw new GnmiDatabaseConfigException();
    }
    $host = $metadata['database_hostname'];
    $socket = null;
    if (strpos($host, '/') !== false) {
        if ($host[0] !== '/' || @filetype($host) !== 'socket') {
            throw new GnmiDatabaseConfigException('socket_resolution');
        }
        $socket = $host;
        $host = 'localhost';
    } elseif ($host === 'localhost') {
        if ($port !== 3306) {
            $host = '127.0.0.1';
        } else {
            $socket = $default_socket ?? ini_get('pdo_mysql.default_socket');
            if (!is_string($socket) || $socket === '' || $socket[0] !== '/' || strpos($socket, "\0") !== false) {
                throw new GnmiDatabaseConfigException('socket_resolution');
            }
        }
    }
    $payload = ['version'=>1, 'type'=>'mysql', 'host'=>$host, 'port'=>$port,
        'database'=>$metadata['database_default'], 'username'=>$metadata['database_username'],
        'password_b64'=>base64_encode($metadata['database_password']), 'tls'=>$tls];
    if ($socket !== null) $payload['unix_socket'] = $socket;
    if ($tls) {
        foreach (['ca','cert','key'] as $field) {
            $value = $metadata['database_ssl_'.$field] ?? null;
            if (!is_string($value) || strpos($value, "\0") !== false) throw new GnmiDatabaseConfigException();
            if ($value === '') continue;
            $path = realpath($value); // Relative to the effective caller's working directory, as PDO uses.
            if ($path === false || !is_file($path) || !is_readable($path)) {
                throw new GnmiDatabaseConfigException($field === 'key' || $field === 'cert' ? 'tls_client' : 'permissions');
            }
            $payload[$field] = $path;
        }
        if (isset($payload['cert']) !== isset($payload['key'])) throw new GnmiDatabaseConfigException('tls_client');
    }
    $json = json_encode($payload, JSON_THROW_ON_ERROR);
    if (strlen($json) > 65536) throw new GnmiDatabaseConfigException();
    return $payload;
}

function gnmi_export_database_config() {
    global $database_hostname, $database_port, $database_default, $database_sessions, $database_details;
    if (!is_string($database_hostname) || !is_string($database_default) || (!is_int($database_port) && !is_string($database_port))) {
        throw new GnmiDatabaseConfigException('routing');
    }
    $key = $database_hostname . ':' . $database_port . ':' . $database_default;
    $connection = $database_sessions[$key] ?? null;
    if (!($connection instanceof PDO)) throw new GnmiDatabaseConfigException('routing');
    $metadata = $database_details[spl_object_hash($connection)] ?? null;
    $expected_host = $database_hostname === 'localhost' && (int)$database_port !== 3306 ? '127.0.0.1' : $database_hostname;
    if (!is_array($metadata) || ($metadata['database_conn'] ?? null) !== $connection ||
        ($metadata['database_hostname'] ?? null) !== $expected_host ||
        ($metadata['database_default'] ?? null) !== $database_default ||
        (string)($metadata['database_port'] ?? '') !== (string)$database_port) {
        throw new GnmiDatabaseConfigException('routing');
    }
    return gnmi_database_payload($metadata);
}

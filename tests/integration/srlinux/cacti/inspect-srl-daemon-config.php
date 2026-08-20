<?php

declare(strict_types=1);

/**
 * Report daemon-config drift without printing credentials or certificate keys.
 */

chdir('/var/www/html/cacti');
require '/var/www/html/cacti/include/cli_check.php';
require_once '/var/www/html/cacti/plugins/gnmi/include/functions.php';

$device_id = (int)(getenv('GNMI_DEVICE_ID') ?: '999');
$storage_dir = gnmi_get_storage_dir();
$config_file = "{$storage_dir}/device_{$device_id}_config.json";
$disk_config = is_file($config_file)
	? json_decode((string)file_get_contents($config_file), true)
	: null;
$db_config = gnmi_build_daemon_config($device_id);

if (!is_array($disk_config) || !is_array($db_config)) {
	fwrite(STDERR, "ERROR: database or on-disk daemon config is unavailable\n");
	exit(1);
}

$differences = [];
$bool_fields = ['use_tls', 'skip_verify'];
$fields = [
	'collection_interval', 'hostname', 'port', 'username', 'use_tls',
	'tls_override', 'skip_verify', 'encoding', 'compatibility_mode',
	'tls_cipher_policy',
];

foreach ($fields as $field) {
	if (in_array($field, $bool_fields, true)) {
		$disk_value = gnmi_coerce_daemon_config_bool($disk_config[$field] ?? null);
		$db_value = gnmi_coerce_daemon_config_bool($db_config[$field] ?? null);
	} else {
		$disk_value = (string)($disk_config[$field] ?? '');
		$db_value = (string)($db_config[$field] ?? '');
	}
	if ($disk_value !== $db_value) {
		$differences[$field] = ['disk' => $disk_value, 'database' => $db_value];
	}
}

$disk_subscriptions = $disk_config['subscriptions'] ?? [];
$db_subscriptions = $db_config['subscriptions'] ?? [];
if (json_encode($disk_subscriptions) !== json_encode($db_subscriptions)) {
	$differences['subscriptions'] = [
		'disk_sha256' => hash('sha256', (string)json_encode($disk_subscriptions)),
		'database_sha256' => hash('sha256', (string)json_encode($db_subscriptions)),
		'disk_count' => count($disk_subscriptions),
		'database_count' => count($db_subscriptions),
	];
}

$result = [
	'device_id' => $device_id,
	'config_changed' => gnmi_check_subscription_config_changed($device_id),
	'differences' => $differences,
];

fwrite(STDOUT, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
exit(empty($differences) ? 0 : 1);

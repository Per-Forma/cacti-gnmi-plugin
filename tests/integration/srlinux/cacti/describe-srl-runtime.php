<?php

declare(strict_types=1);

/** Resolve dynamic Cacti/plugin/RRD identifiers for soak validation. */

chdir('/var/www/html/cacti');
require '/var/www/html/cacti/include/cli_check.php';

$host_id = (int)db_fetch_cell_prepared(
	'SELECT id FROM host WHERE external_id = ? LIMIT 1',
	['gnmi-srl-beta-srl1']
);
$device_id = (int)db_fetch_cell_prepared(
	'SELECT id FROM plugin_gnmi_devices WHERE host_id = ? LIMIT 1',
	[$host_id]
);
$local_data_ids = array_map(
	'intval',
	array_column(db_fetch_assoc_prepared(
		'SELECT DISTINCT m.local_data_id
		 FROM plugin_gnmi_metrics m
		 JOIN plugin_gnmi_subscriptions s ON s.id = m.subscription_id
		 WHERE s.device_id = ? AND s.enabled = 1 AND m.local_data_id IS NOT NULL
		 ORDER BY m.local_data_id',
		[$device_id]
	), 'local_data_id')
);

if ($host_id === 0 || $device_id === 0 || count($local_data_ids) !== 2) {
	fwrite(STDERR, "Unable to resolve the two-interface SR Linux runtime contract\n");
	exit(1);
}

$rrd_files = [];
foreach ($local_data_ids as $local_data_id) {
	$path = "/var/www/html/cacti/rra/{$host_id}/{$local_data_id}.rrd";
	if (!is_file($path)) {
		fwrite(STDERR, "Expected RRD does not exist: {$path}\n");
		exit(1);
	}
	$rrd_files[] = $path;
}

fwrite(STDOUT, json_encode([
	'host_id' => $host_id,
	'device_id' => $device_id,
	'local_data_ids' => $local_data_ids,
	'rrd_files' => $rrd_files,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

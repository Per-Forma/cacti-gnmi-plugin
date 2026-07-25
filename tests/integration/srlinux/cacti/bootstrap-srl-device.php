<?php

declare(strict_types=1);

/**
 * Idempotently provision the SR Linux integration target in Cacti.
 *
 * This script deliberately uses Cacti's device API and the gNMI plugin's
 * public form/subscription/data-source/graph functions.  It can be rerun after
 * container or target restarts without creating duplicate objects.
 */

chdir('/var/www/html/cacti');
require '/var/www/html/cacti/include/cli_check.php';
require_once '/var/www/html/cacti/lib/api_device.php';
require_once '/var/www/html/cacti/lib/plugins.php';
require_once '/var/www/html/cacti/plugins/gnmi/setup.php';
require_once '/var/www/html/cacti/plugins/gnmi/include/functions.php';
require_once '/var/www/html/cacti/plugins/gnmi/include/form_functions.php';
require_once '/var/www/html/cacti/plugins/gnmi/include/subscription_functions.php';

function env_string(string $name, string $default = ''): string {
	$value = getenv($name);
	return $value === false || $value === '' ? $default : $value;
}

function fail(string $message): void {
	fwrite(STDERR, "ERROR: {$message}\n");
	exit(1);
}

$description = env_string('GNMI_CACTI_DESCRIPTION', 'SR Linux Beta Target');
$target      = env_string('GNMI_TARGET', 'clab-cacti-gnmi-srl-srl1');
$port        = (int)env_string('GNMI_PORT', '57400');
$username    = env_string('GNMI_USERNAME', 'admin');
$password    = env_string('GNMI_PASSWORD', 'NokiaSrl1!');
$external_id = env_string('GNMI_CACTI_EXTERNAL_ID', 'gnmi-srl-beta-srl1');
$use_tls     = env_string('GNMI_USE_TLS', '1') === '1';
$skip_verify = env_string('GNMI_SKIP_VERIFY', '1') === '1';
$ca_cert     = env_string('GNMI_CA_CERT');
$tls_override = env_string('GNMI_TLS_OVERRIDE');

if ($port < 1 || $port > 65535) {
	fail("GNMI_PORT must be between 1 and 65535 (received {$port})");
}
if (!api_plugin_installed('gnmi') || !api_plugin_is_enabled('gnmi')) {
	fail('the gNMI plugin must be installed and enabled before provisioning');
}
if (!gnmi_runtime_directories_ready()) {
	fail('the gNMI runtime directories are not ready or writable');
}

// The integration RRD profile and daemon buffer are designed for 10-second
// samples. Cacti's one-minute cron invocation runs the required sub-polls.
set_config_option('poller_interval', '10');
set_config_option('cron_interval', '60');
gnmi_get_poller_interval(true);
gnmi_ensure_data_source_profile();

// Prefer the stable external ID; fall back to the target hostname for an
// environment created before this bootstrap script existed.
$host_id = (int)db_fetch_cell_prepared(
	'SELECT id FROM host WHERE external_id = ? LIMIT 1',
	[$external_id]
);
if ($host_id === 0) {
	$host_id = (int)db_fetch_cell_prepared(
		'SELECT id FROM host WHERE hostname = ? LIMIT 1',
		[$target]
	);
}

if ($host_id === 0) {
	// SNMP is intentionally disabled. gNMI owns reachability and collection for
	// this test device, so the Cacti availability method is "none" (0).
	$host_id = (int)api_device_save(
		0, 0, $description, $target,
		'', 0, '', '', 161, 500, '',
		0, 0, 0, 500, 1,
		'SR Linux Containerlab beta interoperability target',
		'', '', '', '', '',
		5, 1, 1, 1, $external_id, 'Containerlab', -1
	);
	if ($host_id === 0 || is_error_message()) {
		fail('Cacti api_device_save() could not create the SR Linux host');
	}
} else {
	$ok = db_execute_prepared(
		'UPDATE host SET description = ?, hostname = ?, external_id = ?, notes = ?, location = ? WHERE id = ?',
		[$description, $target, $external_id, 'SR Linux Containerlab beta interoperability target', 'Containerlab', $host_id]
	);
	if (!$ok) {
		fail("could not update existing Cacti host {$host_id}");
	}
}

// Exercise the same validated plugin save path used by the Cacti device form.
$_POST = [
	'gnmi_enabled'         => '1',
	'gnmi_hostname_source' => 'custom',
	'gnmi_hostname'        => $target,
	'gnmi_port'            => (string)$port,
	'gnmi_username'        => $username,
	'gnmi_password'        => $password,
	'collection_interval'  => '10',
	'compatibility_mode'   => 'standard',
	'ca_cert_path'         => $ca_cert,
	'client_key_path'      => '',
	'client_cert_path'     => '',
	'tls_override'         => $tls_override,
];
if ($use_tls) {
	$_POST['use_tls'] = '1';
}
if ($skip_verify) {
	$_POST['skip_verify'] = '1';
}

if (!gnmi_process_device_save($host_id)) {
	fail("gNMI device configuration failed for Cacti host {$host_id}");
}

$device = db_fetch_row_prepared(
	'SELECT * FROM plugin_gnmi_devices WHERE host_id = ?',
	[$host_id]
);
if (empty($device)) {
	fail("gNMI device row was not created for Cacti host {$host_id}");
}
$device_id = (int)$device['id'];

$interfaces = ['ethernet-1/1', 'ethernet-1/2'];
$metric_fields = [
	'in-octets' => 'in_octets',
	'out-octets' => 'out_octets',
	'in-unicast-packets' => 'in_pkts',
	'out-unicast-packets' => 'out_pkts',
	'in-error-packets' => 'in_errors',
	'out-error-packets' => 'out_errors',
];
$subscription_ids = [];
$metric_ids = [];
$local_data_ids = [];

foreach ($interfaces as $interface) {
	$path = "/interface[name={$interface}]/statistics";
	$subscription = db_fetch_row_prepared(
		'SELECT * FROM plugin_gnmi_subscriptions WHERE device_id = ? AND subscription_path = ? LIMIT 1',
		[$device_id, $path]
	);
	if (empty($subscription)) {
		$subscription_id = (int)gnmi_create_subscription($device_id, $path, $interface, [
			'enabled' => true,
			'notes' => 'SR Linux physical interface counters',
			'auto_create_datasources' => false,
			'auto_create_graphs' => true,
		]);
		if ($subscription_id === 0) {
			fail("could not create subscription for {$interface}");
		}
	} else {
		$subscription_id = (int)$subscription['id'];
		db_execute_prepared(
			'UPDATE plugin_gnmi_subscriptions SET instance_identifier = ?, enabled = 1, auto_create_datasources = 0, auto_create_graphs = 1, notes = ? WHERE id = ?',
			[$interface, 'SR Linux physical interface counters', $subscription_id]
		);
	}

	$subscription_ids[] = $subscription_id;
	foreach ($metric_fields as $metric_name => $cacti_field_name) {
		$metric = db_fetch_row_prepared(
			'SELECT * FROM plugin_gnmi_metrics WHERE subscription_id = ? AND metric_name = ? LIMIT 1',
			[$subscription_id, $metric_name]
		);
		$metric_id = empty($metric) ? 0 : (int)$metric['id'];
		if ($metric_id === 0) {
			$metric_id = (int)gnmi_add_metric_to_subscription(
				$subscription_id,
				$metric_name,
				'COUNTER',
				['rrd_heartbeat' => 40, 'rrd_min' => '0', 'rrd_max' => 'U', 'enabled' => true]
			);
			if ($metric_id === 0) {
				fail("could not create metric {$metric_name} for {$interface}");
			}
			$metric = db_fetch_row_prepared(
				'SELECT * FROM plugin_gnmi_metrics WHERE id = ?',
				[$metric_id]
			);
		} else {
			gnmi_update_metric($metric_id, [
				'rrd_type' => 'COUNTER',
				'rrd_heartbeat' => 40,
				'rrd_min' => '0',
				'rrd_max' => 'U',
				'enabled' => true,
			]);
		}

		// The checked-in interoperability contract uses concise, stable Cacti
		// aliases for packet/error leaves. Apply aliases before the physical RRD
		// exists. A pre-existing RRD cannot be renamed safely in place.
		if ($metric['cacti_field_name'] !== $cacti_field_name) {
			$local_data_id = (int)($metric['local_data_id'] ?? 0);
			$rrd_file = $local_data_id > 0
				? "/var/www/html/cacti/rra/{$host_id}/{$local_data_id}.rrd"
				: '';
			if ($rrd_file !== '' && file_exists($rrd_file)) {
				fail("cannot rename {$metric_name} to {$cacti_field_name}: RRD already exists at {$rrd_file}");
			}
			if ($local_data_id > 0) {
				db_execute_prepared(
					'UPDATE data_template_rrd SET data_source_name = ? WHERE local_data_id = ? AND data_source_name = ?',
					[$cacti_field_name, $local_data_id, $metric['cacti_field_name']]
				);
			}
			$ok = db_execute_prepared(
				'UPDATE plugin_gnmi_metrics SET cacti_field_name = ? WHERE id = ?',
				[$cacti_field_name, $metric_id]
			);
			if (!$ok) {
				fail("could not assign Cacti field {$cacti_field_name} to {$metric_name}");
			}
		}
		$metric_ids[] = $metric_id;
	}

	$local_data_id = (int)gnmi_create_data_sources_for_subscription($subscription_id);
	if ($local_data_id === 0) {
		fail("could not create or resolve the Cacti data source for {$interface}");
	}
	$local_data_ids[] = $local_data_id;
	db_execute_prepared(
		'UPDATE plugin_gnmi_subscriptions SET auto_create_datasources = 1 WHERE id = ?',
		[$subscription_id]
	);
}

// Paired graphs may defer until both directions have data sources. Two passes
// are cheap and make graph creation deterministic for a newly provisioned lab.
for ($pass = 0; $pass < 2; $pass++) {
	foreach ($metric_ids as $metric_id) {
		gnmi_create_graph_for_metric($metric_id);
	}
}

$device = db_fetch_row_prepared(
	'SELECT * FROM plugin_gnmi_devices WHERE id = ?',
	[$device_id]
);
if (!gnmi_restart_daemon($device)) {
	fail("could not start/restart the gNMI daemon for device {$device_id}");
}

$graph_ids = array_map(
	'intval',
	array_column(db_fetch_assoc_prepared(
		'SELECT DISTINCT graph_local_id FROM plugin_gnmi_metrics WHERE subscription_id IN (?, ?) AND graph_local_id IS NOT NULL ORDER BY graph_local_id',
		$subscription_ids
	), 'graph_local_id')
);

$summary = [
	'host_id' => $host_id,
	'device_id' => $device_id,
	'target' => "{$target}:{$port}",
	'tls' => $use_tls,
	'skip_verify' => $skip_verify,
	'compatibility_mode' => 'standard',
	'encoding' => 'JSON_IETF',
	'subscription_ids' => $subscription_ids,
	'metric_count' => count($metric_ids),
	'local_data_ids' => array_values(array_unique($local_data_ids)),
	'graph_local_ids' => $graph_ids,
];

fwrite(STDOUT, json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

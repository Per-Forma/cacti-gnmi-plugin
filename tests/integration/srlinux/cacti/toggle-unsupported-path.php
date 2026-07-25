<?php

declare(strict_types=1);

/** Add or remove one intentionally unsupported path for isolation testing. */

chdir('/var/www/html/cacti');
require '/var/www/html/cacti/include/cli_check.php';
require_once '/var/www/html/cacti/plugins/gnmi/include/functions.php';
require_once '/var/www/html/cacti/plugins/gnmi/include/subscription_functions.php';

$action = getenv('GNMI_UNSUPPORTED_ACTION') ?: 'status';
$device_id = (int)(getenv('GNMI_DEVICE_ID') ?: '999');
$path = '/interface[name=definitely-not-a-real-interface]/statistics';
$instance = 'unsupported-beta-test';

$subscription = db_fetch_row_prepared(
	'SELECT * FROM plugin_gnmi_subscriptions WHERE device_id = ? AND instance_identifier = ? LIMIT 1',
	[$device_id, $instance]
);

if ($action === 'add') {
	if (empty($subscription)) {
		$subscription_id = (int)gnmi_create_subscription($device_id, $path, $instance, [
			'enabled' => true,
			'notes' => 'Intentional unsupported-path interoperability test',
			'auto_create_datasources' => false,
			'auto_create_graphs' => false,
		]);
		if ($subscription_id === 0) {
			fwrite(STDERR, "Unable to create unsupported test subscription\n");
			exit(1);
		}
		$metric_id = (int)gnmi_add_metric_to_subscription(
			$subscription_id,
			'missing-leaf',
			'GAUGE',
			['enabled' => true]
		);
		if ($metric_id === 0) {
			fwrite(STDERR, "Unable to create unsupported test metric\n");
			exit(1);
		}
	} else {
		$subscription_id = (int)$subscription['id'];
		db_execute_prepared(
			'UPDATE plugin_gnmi_subscriptions SET enabled = 1 WHERE id = ?',
			[$subscription_id]
		);
	}
} elseif ($action === 'remove') {
	if (!empty($subscription) && !gnmi_delete_subscription((int)$subscription['id'])) {
		fwrite(STDERR, "Unable to delete unsupported test subscription\n");
		exit(1);
	}
	$subscription_id = 0;
} elseif ($action === 'status') {
	$subscription_id = empty($subscription) ? 0 : (int)$subscription['id'];
} else {
	fwrite(STDERR, "GNMI_UNSUPPORTED_ACTION must be add, remove, or status\n");
	exit(2);
}

if ($action !== 'status') {
	$device = db_fetch_row_prepared(
		'SELECT * FROM plugin_gnmi_devices WHERE id = ?',
		[$device_id]
	);
	if (empty($device) || !gnmi_restart_daemon($device)) {
		fwrite(STDERR, "Unable to restart daemon after unsupported-path {$action}\n");
		exit(1);
	}
}

fwrite(STDOUT, json_encode([
	'action' => $action,
	'device_id' => $device_id,
	'subscription_id' => $subscription_id,
	'path' => $path,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

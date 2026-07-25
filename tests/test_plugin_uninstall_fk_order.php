<?php
/**
 * Static check: uninstall must DROP child tables before parents (InnoDB FKs).
 *
 * Phase 3.3 chain: plugin_gnmi_metrics.subscription_id -> plugin_gnmi_subscriptions,
 * plugin_gnmi_subscriptions.device_id -> plugin_gnmi_devices.
 *
 * Run from repo root:
 *   php plugins/gnmi/tests/test_plugin_uninstall_fk_order.php
 */

$setup_path = dirname(__DIR__) . '/setup.php';
$setup = file_get_contents($setup_path);
if ($setup === false) {
	fwrite(STDERR, "FAIL: could not read {$setup_path}\n");
	exit(1);
}

$start = strpos($setup, 'function plugin_gnmi_uninstall()');
$end   = strpos($setup, 'function plugin_gnmi_upgrade()', $start);
if ($start === false || $end === false || $end <= $start) {
	fwrite(STDERR, "FAIL: could not isolate plugin_gnmi_uninstall() in setup.php\n");
	exit(1);
}

$chunk = substr($setup, $start, $end - $start);

function drop_position_in_uninstall($chunk, $table) {
	$needle = 'DROP TABLE IF EXISTS `' . $table . '`';
	$pos = strpos($chunk, $needle);
	return $pos === false ? null : $pos;
}

$required_order = array(
	'plugin_gnmi_device_metrics',
	'plugin_gnmi_events',
	'plugin_gnmi_metrics',
	'plugin_gnmi_subscriptions',
	'plugin_gnmi_device_settings',
	'plugin_gnmi_devices',
);

$last = -1;
$failed = false;
foreach ($required_order as $table) {
	$pos = drop_position_in_uninstall($chunk, $table);
	if ($pos === null) {
		echo "FAIL: missing DROP for `{$table}` in plugin_gnmi_uninstall()\n";
		$failed = true;
		continue;
	}
	if ($pos <= $last) {
		echo "FAIL: `{$table}` DROP must appear after prior table in uninstall order\n";
		$failed = true;
	}
	$last = $pos;
}

if ($failed) {
	exit(1);
}

echo "OK: plugin_gnmi_uninstall() DROP order covers FK chain (including subscriptions).\n";
exit(0);

<?php

declare(strict_types=1);

/**
 * Repair the test table's AUTO_INCREMENT after legacy lifecycle harnesses used
 * very large explicit IDs. This helper is intentionally limited to the
 * disposable integration environment and uses Cacti's database interface.
 */

chdir('/var/www/html/cacti');
require '/var/www/html/cacti/include/cli_check.php';

$table = 'plugin_gnmi_devices';
$before = (int)db_fetch_cell_prepared(
	'SELECT AUTO_INCREMENT
	 FROM information_schema.TABLES
	 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
	[$table]
);
$maximum = (int)db_fetch_cell("SELECT COALESCE(MAX(id), 0) FROM {$table}");
$next = $maximum + 1;

if ($before > $next) {
	if (!db_execute("ALTER TABLE {$table} AUTO_INCREMENT = {$next}")) {
		fwrite(STDERR, "Unable to repair {$table} AUTO_INCREMENT\n");
		exit(1);
	}
}

$after = (int)db_fetch_cell_prepared(
	'SELECT AUTO_INCREMENT
	 FROM information_schema.TABLES
	 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
	[$table]
);

if ($after > $next) {
	fwrite(STDERR, "AUTO_INCREMENT remains above the expected next ID\n");
	exit(1);
}

fwrite(STDOUT, json_encode([
	'table' => $table,
	'maximum_id' => $maximum,
	'before' => $before,
	'after' => $after,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

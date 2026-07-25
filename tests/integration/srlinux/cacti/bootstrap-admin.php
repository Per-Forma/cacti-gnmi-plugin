<?php

declare(strict_types=1);

chdir('/var/www/html/cacti');
require '/var/www/html/cacti/include/cli_check.php';
require_once '/var/www/html/cacti/lib/auth.php';

$username = getenv('CACTI_ADMIN_USER') ?: 'admin';
$password = getenv('CACTI_ADMIN_PASSWORD');
if ($password === false || strlen($password) < 8) {
	fwrite(STDERR, "CACTI_ADMIN_PASSWORD must contain at least 8 characters.\n");
	exit(2);
}

$exists = db_fetch_cell_prepared(
	'SELECT COUNT(*) FROM user_auth WHERE username = ?',
	[$username]
);
if (!$exists) {
	fwrite(STDERR, "Cacti user does not exist: {$username}\n");
	exit(1);
}

$ok = db_execute_prepared(
	"UPDATE user_auth
	 SET password = ?, must_change_password = '', password_change = '', enabled = 'on'
	 WHERE username = ?",
	[compat_password_hash($password, PASSWORD_DEFAULT), $username]
);
if (!$ok) {
	fwrite(STDERR, "Unable to update the Cacti administrative credential.\n");
	exit(1);
}

fwrite(STDOUT, "Cacti administrative credential initialized for {$username}.\n");

<?php

declare(strict_types=1);

function required_env(string $name): string {
	$value = getenv($name);
	if ($value === false || $value === '') {
		fwrite(STDERR, "Required environment variable is empty: {$name}\n");
		exit(2);
	}
	return $value;
}

$urlPath = getenv('CACTI_URL_PATH') ?: '/cacti/';
if ($urlPath[0] !== '/' || substr($urlPath, -1) !== '/') {
	fwrite(STDERR, "CACTI_URL_PATH must start and end with '/'.\n");
	exit(2);
}

$values = [
	'database_type'     => 'mysql',
	'database_default'  => required_env('DB_NAME'),
	'database_hostname' => required_env('DB_HOST'),
	'database_username' => required_env('DB_USER'),
	'database_password' => required_env('DB_PASS'),
	'database_port'     => getenv('DB_PORT') ?: '3306',
];

$output = "<?php\n";
foreach ($values as $name => $value) {
	$output .= sprintf('$%s = %s;' . "\n", $name, var_export($value, true));
}
$output .= "\$database_retries = 30;\n";
$output .= "\$database_ssl = false;\n";
$output .= "\$database_persist = false;\n";
$output .= "\$poller_id = 1;\n";
$output .= '$url_path = ' . var_export($urlPath, true) . ";\n";
$output .= "\$cacti_session_name = 'CactiGnmiSrlBeta';\n";

$target = '/var/www/html/cacti/include/config.php';
$temporary = $target . '.tmp';
if (file_put_contents($temporary, $output, LOCK_EX) === false ||
	!rename($temporary, $target)) {
	fwrite(STDERR, "Unable to write {$target}\n");
	exit(1);
}
chmod($target, 0640);

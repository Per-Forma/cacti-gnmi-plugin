<?php
/** Exercise real dependency diagnosis with a missing writable venv. */
define('CACTI_VERSION', 'test');
define('POLLER_VERBOSITY_LOW', 1);
define('POLLER_VERBOSITY_MEDIUM', 3);
require_once __DIR__ . '/../../include/functions.php';
function cacti_log(...$args) {}
function db_execute_prepared(...$args) { return true; }
$config = array('base_path'=>sys_get_temp_dir() . '/gnmi-deps-' . getmypid());
mkdir($config['base_path'] . '/plugins/gnmi',0700,true);
try {
    $state = gnmi_check_all_dependencies(false);
    if (!$state['venv_module']) {
        fwrite(STDERR, "This regression requires working system Python venv support\n"); exit(1);
    }
    if ($state['all_ok'] || is_dir($config['base_path'] . '/plugins/gnmi/venv')) {
        fwrite(STDERR, "Diagnostic dependency check must not create a venv\n"); exit(1);
    }
    echo "PASS: diagnostic false flag leaves missing writable venv untouched\n";
} finally {
    rmdir($config['base_path'] . '/plugins/gnmi'); rmdir($config['base_path'] . '/plugins'); rmdir($config['base_path']);
}

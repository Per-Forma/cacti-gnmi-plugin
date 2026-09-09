<?php
/** CLI-only fixtures and assertions for a disposable packaged installation. */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit(1); }
chdir('/var/www/html/cacti');
require './include/cli_check.php';
require_once './lib/auth.php';

function package_require($condition, $message) {
    if (!$condition) { fwrite(STDERR, $message . "\n"); exit(1); }
}

$mode = getenv('PACKAGE_FIXTURE_MODE');
if ($mode === 'prepare') {
    $password = getenv('CACTI_ADMIN_PASSWORD');
    package_require(strlen($password ?: '') >= 16, 'A disposable password is required');
    db_execute_prepared("UPDATE user_auth SET password=?, must_change_password='', password_change='', enabled='on' WHERE username='admin'",
        array(compat_password_hash($password, PASSWORD_DEFAULT)));
    $user = db_fetch_row("SELECT * FROM user_auth WHERE username='admin' AND realm=0");
    package_require(!empty($user), 'Missing administrative fixture');
    $user['id'] = 0;
    $user['username'] = 'package-denied';
    $denied_id = sql_save($user, 'user_auth');
    package_require($denied_id > 0, 'Cannot create restricted user');
    db_execute_prepared('DELETE FROM user_auth_realm WHERE user_id=?', array($denied_id));
    // A user needs one Cacti realm to log in; grant only the console, not gNMI.
    db_execute_prepared('INSERT INTO user_auth_realm (realm_id,user_id) VALUES (8,?)', array($denied_id));
    $host_id = (int)db_fetch_cell('SELECT id FROM host ORDER BY id LIMIT 1');
    package_require($host_id > 0, 'Missing disposable host');
    package_require((int)db_fetch_cell('SELECT COUNT(*) FROM plugin_gnmi_devices') === 0,
        'Package acceptance requires a fresh plugin database');
    $device_id = sql_save(array('id'=>0, 'host_id'=>$host_id, 'enabled'=>1,
        'hostname'=>'127.0.0.1', 'hostname_source'=>'custom', 'port'=>9,
        'username'=>'package-test', 'password'=>'package-test', 'use_tls'=>0,
        'compatibility_mode'=>'standard', 'skip_verify'=>0,
        'tls_cipher_policy'=>'default', 'collection_interval'=>10, 'encoding'=>'JSON_IETF'),
        'plugin_gnmi_devices');
    package_require($device_id > 0, 'Cannot create device fixture');
    echo json_encode(array('host_id'=>$host_id, 'device_id'=>(int)$device_id));
} elseif ($mode === 'snapshot') {
    require_once './plugins/gnmi/setup.php';
    require_once './plugins/gnmi/include/functions.php';
    $resources = gnmi_uninstall_collect_resources();
    package_require(!empty($resources['data_source_ids']) && !empty($resources['graph_ids']),
        'Lifecycle acceptance requires populated data sources and graphs');
    foreach ($resources['data_source_ids'] as $id) {
        $path = db_fetch_cell_prepared('SELECT data_source_path FROM data_template_data WHERE local_data_id=?', array($id));
        $path = str_replace('<path_rra>', $config['rra_path'] ?: $config['base_path'] . '/rra', $path);
        package_require(gnmi_prepare_rrd_file($path, $id, 'package acceptance', time() - 60),
            'Cannot create a valid RRD for retention acceptance');
    }
    $resources = gnmi_uninstall_collect_resources();
    package_require(count($resources['rrd_files']) === count($resources['data_source_ids']),
        'Every test data source must have a physical RRD before uninstall');
    echo json_encode($resources);
} elseif ($mode === 'uninstalled') {
    $snapshot = json_decode(file_get_contents('/tmp/package-resources.json'), true);
    package_require(is_array($snapshot), 'Missing resource snapshot');
    package_require(count(db_fetch_assoc("SHOW TABLES LIKE 'plugin_gnmi_%'")) === 0, 'Plugin tables remain');
    package_require((int)db_fetch_cell("SELECT COUNT(*) FROM plugin_hooks WHERE name='gnmi'") === 0, 'Plugin hooks remain');
    foreach (array('graph_ids'=>'graph_local', 'data_source_ids'=>'data_local') as $key=>$table) {
        foreach ($snapshot[$key] as $id) {
            package_require((int)db_fetch_cell_prepared("SELECT COUNT(*) FROM $table WHERE id=?", array($id)) === 0,
                'Owned Cacti metadata remains');
        }
    }
    foreach ($snapshot['rrd_files'] as $path) {
        package_require(is_file($path), 'Uninstall removed a physical RRD');
    }
    echo "PASS: uninstall removed plugin tables, hooks and owned metadata; retained RRD files\n";
} elseif ($mode === 'installed') {
    package_require(count(db_fetch_assoc("SHOW TABLES LIKE 'plugin_gnmi_%'")) === 4, 'Expected four plugin tables');
    package_require((int)db_fetch_cell("SELECT COUNT(*) FROM plugin_config WHERE directory='gnmi' AND status=1") === 1,
        'Plugin is not enabled');
    echo "PASS: plugin installed and enabled\n";
} else {
    package_require(false, 'Unknown fixture mode');
}

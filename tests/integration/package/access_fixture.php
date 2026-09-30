<?php
/** Disposable CLI fixtures for the real Cacti dashboard permission matrix. */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit(1); }
chdir('/var/www/html/cacti');
require './include/cli_check.php';
require_once './lib/auth.php';
require_once './plugins/gnmi/include/functions.php';
require_once './plugins/gnmi/include/status_functions.php';
require_once './plugins/gnmi/include/subscription_functions.php';
function access_require($ok, $message) {
    if (!$ok) { fwrite(STDERR, $message . "\n"); exit(1); }
}
function access_realm($file) {
    $id = db_fetch_cell_prepared('SELECT id FROM plugin_realms WHERE plugin=? AND file=?', array('gnmi', $file));
    access_require($id > 0, 'Missing fixture realm');
    return (int)$id + 100;
}
function access_device($name) {
    return db_fetch_row_prepared('SELECT * FROM plugin_gnmi_devices WHERE username=?', array($name));
}
$_SESSION['sess_user_id'] = (int)db_fetch_cell("SELECT id FROM user_auth WHERE username='admin' AND realm=0");
$mode = getenv('ACCESS_FIXTURE_MODE');
if ($mode === 'setup') {
    $password = getenv('CACTI_ADMIN_PASSWORD');
    access_require(strlen($password ?: '') >= 16, 'Disposable password required');
    $template = db_fetch_row("SELECT * FROM user_auth WHERE username='admin' AND realm=0");
    $host = db_fetch_row('SELECT * FROM host ORDER BY id LIMIT 1');
    access_require($template && $host, 'Missing baseline fixtures');
    db_execute("DELETE FROM host WHERE description LIKE 'ACCESS-%'");
    db_execute("DELETE FROM user_auth_realm WHERE user_id IN (SELECT id FROM user_auth WHERE username LIKE 'access-%')");
    db_execute("DELETE FROM user_auth_perms WHERE user_id IN (SELECT id FROM user_auth WHERE username LIKE 'access-%')");
    db_execute("DELETE FROM user_auth_group_members WHERE user_id IN (SELECT id FROM user_auth WHERE username LIKE 'access-%')");
    db_execute("DELETE FROM user_auth WHERE username LIKE 'access-%'");
    db_execute("DELETE FROM user_auth_group WHERE name LIKE 'access-%'");
    $devices = array();
    foreach (array('A', 'B', 'disabled', 'deleted') as $name) {
        $h = $host;
        $h['id'] = 0;
        $h['description'] = 'ACCESS-' . $name;
        $h['hostname'] = '127.0.0.1';
        $h['disabled'] = '';
        $h['deleted'] = $name === 'deleted' ? 'on' : '';
        $host_id = sql_save($h, 'host');
        $id = sql_save(array('id'=>0, 'host_id'=>$host_id, 'enabled'=>$name !== 'disabled' ? 1 : 0,
            'hostname'=>'127.0.0.1', 'hostname_source'=>'custom', 'port'=>9, 'username'=>'ACCESS-' . $name,
            'password'=>'disposable-test', 'use_tls'=>0, 'compatibility_mode'=>'standard', 'skip_verify'=>0,
            'tls_cipher_policy'=>'default', 'collection_interval'=>10, 'encoding'=>'JSON_IETF',
            'last_error_message'=>'ERROR-MARKER-' . $name, 'last_poll_status'=>'error'), 'plugin_gnmi_devices');
        access_require($id > 0, 'Device creation failed');
        if (in_array($name, array('A', 'B'))) {
            $subscription = gnmi_create_subscription($id, '/interfaces/interface/state/counters', 'access-test', array('auto_create_datasources'=>false));
            access_require($subscription > 0, 'Subscription fixture failed');
            access_require(gnmi_add_metric_to_subscription($subscription, 'in-octets', 'COUNTER') > 0, 'Metric fixture failed');
        }
        gnmi_log_event($id, 'error', array('message'=>'EVENT-MARKER-' . $name));
        $devices[$name] = array('id'=>(int)$id, 'host_id'=>(int)$host_id);
    }
    // Explicit effective realms, including the dedicated realm for admin.
    foreach (array('index.php', 'status.php', 'ajax_handler.php', 'dashboard_actions.php') as $file) {
        db_execute_prepared('REPLACE INTO user_auth_realm (realm_id,user_id) VALUES (?,?)', array(access_realm($file), $template['id']));
    }
    db_execute_prepared('REPLACE INTO user_auth_realm (realm_id,user_id) VALUES (1,?)', array($template['id']));
    $users = array();
    foreach (array('viewer', 'general', 'operator', 'installer', 'admin', 'group', 'disabled-group', 'empty', 'index-only') as $name) {
        $u = $template;
        $u['id'] = 0;
        $u['username'] = 'access-' . $name;
        $u['password'] = compat_password_hash($password, PASSWORD_DEFAULT);
        $u['policy_hosts'] = $u['policy_graphs'] = $u['policy_graph_templates'] = $u['policy_trees'] = 2;
        $u['must_change_password'] = $u['password_change'] = '';
        $u['enabled'] = 'on';
        $u['login_opts'] = 1;
        $id = sql_save($u, 'user_auth');
        access_require($id > 0, 'User creation failed');
        $users[$name] = (int)$id;
        $files = $name === 'index-only' ? array('index.php') : array('index.php', 'status.php', 'ajax_handler.php');
        $realms = array(8);
        foreach ($files as $file) { $realms[] = access_realm($file); }
        if ($name === 'general') { $realms[] = $user_auth_realm_filenames['host.php']; }
        if (in_array($name, array('operator', 'admin'))) { $realms[] = access_realm('dashboard_actions.php'); }
        if (in_array($name, array('installer', 'admin'))) { $realms[] = 1; }
        foreach ($realms as $realm) {
            db_execute_prepared('INSERT INTO user_auth_realm (realm_id,user_id) VALUES (?,?)', array($realm, $id));
        }
        if ($name !== 'empty' && $name !== 'group') {
            db_execute_prepared('INSERT INTO user_auth_perms (user_id,item_id,type) VALUES (?,?,3)', array($id, $devices['A']['host_id']));
        }
    }
    foreach (array('group'=>'on', 'disabled-group'=>'') as $name=>$enabled) {
        $group_id = sql_save(array('id'=>0, 'name'=>'access-' . $name, 'enabled'=>$enabled,
            'graph_settings'=>'on', 'policy_hosts'=>2, 'policy_graphs'=>2, 'policy_graph_templates'=>2, 'policy_trees'=>2), 'user_auth_group');
        db_execute_prepared('INSERT INTO user_auth_group_members (group_id,user_id) VALUES (?,?)', array($group_id,$users[$name]));
        db_execute_prepared('INSERT INTO user_auth_group_realm (group_id,realm_id) VALUES (?,?)', array($group_id,access_realm('dashboard_actions.php')));
        db_execute_prepared('INSERT INTO user_auth_group_perms (group_id,item_id,type) VALUES (?,?,3)', array($group_id,$devices['A']['host_id']));
    }
    db_execute("REPLACE INTO settings (name,value) VALUES ('graph_auth_method','3')");
    // Validate fixture expectations through Cacti itself, not plugin mocks.
    foreach ($users as $name=>$id) {
        access_require(is_device_allowed($devices['A']['host_id'], $id) === ($name !== 'empty'), 'Unexpected effective A policy: ' . $name);
        access_require(!is_device_allowed($devices['B']['host_id'], $id), 'B must be denied: ' . $name);
    }
    echo json_encode(array('devices'=>$devices, 'users'=>$users, 'cacti'=>$config['cacti_db_version'], 'php'=>PHP_VERSION));
} elseif ($mode === 'state') {
    $state = array();
    foreach (array('A','B','disabled','deleted') as $name) {
        $d = access_device('ACCESS-' . $name);
        $pid = gnmi_get_storage_dir() . '/device_' . $d['id'] . '.pid';
        $state[$name] = array('pid'=>is_file($pid) ? file_get_contents($pid) : null,
            'restarts'=>(int)db_fetch_cell_prepared("SELECT COUNT(*) FROM plugin_gnmi_events WHERE device_id=? AND event_type='daemon_restart'", array($d['id'])));
    }
    $state['global_cleanup_events'] = (int)db_fetch_cell("SELECT COUNT(*) FROM plugin_gnmi_events WHERE event_type='orphan_cleanup'");
    $audit = file_get_contents($config['base_path'] . '/log/cacti.log');
    $state['manual_audit_entries'] = substr_count($audit, 'gNMI: Manual orphan cleanup actor=');
    $state['orphan_exists'] = is_file(gnmi_get_storage_dir() . '/device_999999.pid');
    $state['auth_method'] = (int)read_config_option('auth_method', true);
    $state['venv_exists'] = is_file($config['base_path'] . '/plugins/gnmi/venv/bin/python3');
    echo json_encode($state);
} elseif ($mode === 'stale') {
    $d = access_device('ACCESS-A');
    gnmi_stop_daemon($d['id']);
    file_put_contents(gnmi_get_storage_dir() . '/device_' . $d['id'] . '.pid', '999999');
    file_put_contents(gnmi_get_storage_dir() . '/device_999999.pid', '999999');
} elseif ($mode === 'missing-venv') {
    access_require(rename('./plugins/gnmi/venv', './plugins/gnmi/venv.saved'), 'Cannot save fixture venv');
} elseif ($mode === 'restore-venv') {
    if (is_dir('./plugins/gnmi/venv')) { exec('rm -rf ./plugins/gnmi/venv'); }
    access_require(rename('./plugins/gnmi/venv.saved', './plugins/gnmi/venv'), 'Cannot restore fixture venv');
} elseif (strpos($mode, 'policy-') === 0) {
    $a = access_device('ACCESS-A');
    $b = access_device('ACCESS-B');
    $viewer = (int)db_fetch_cell("SELECT id FROM user_auth WHERE username='access-viewer'");
    db_execute_prepared("UPDATE host SET disabled='' WHERE id=?", array($a['host_id']));
    db_execute_prepared("REPLACE INTO settings_user (user_id,name,value) VALUES (?,'hide_disabled','')", array($viewer));
    db_execute_prepared('DELETE FROM user_auth_perms WHERE user_id=? AND type=1', array($viewer));
    $method = in_array($mode, array('policy-1','policy-2','policy-3','policy-4')) ? substr($mode,-1) : '3';
    db_execute_prepared("REPLACE INTO settings (name,value) VALUES ('graph_auth_method',?)", array($method));
    if ($mode === 'policy-graph') {
        $graph = sql_save(array('id'=>0,'host_id'=>$b['host_id'],'graph_template_id'=>1,'snmp_query_id'=>0,'snmp_index'=>''), 'graph_local');
        db_execute_prepared('INSERT INTO user_auth_perms (user_id,item_id,type) VALUES (?,?,1)', array($viewer,$graph));
    } elseif ($mode === 'policy-hide-disabled') {
        db_execute_prepared("UPDATE host SET disabled='on' WHERE id=?", array($a['host_id']));
        db_execute_prepared("REPLACE INTO settings_user (user_id,name,value) VALUES (?,'hide_disabled','on')", array($viewer));
    }
    // Force fresh config reads after changes in this CLI process.
    unset($_SESSION['sess_config_array'], $_SESSION['sess_user_config_array']);
    $GLOBALS['config']['config_options_array'] = array();
    $_SESSION['sess_user_id'] = $viewer;
    $GLOBALS['gnmi_enforce_web_auth_in_cli'] = true;
    $expected = array();
    foreach (array('A'=>$a,'B'=>$b) as $name=>$device) {
        if (is_device_allowed($device['host_id'])) { $expected[] = $name; }
    }
    $actual = array_column(gnmi_get_dashboard_summary(), 'device_id');
    foreach (array('A'=>$a,'B'=>$b) as $name=>$device) {
        access_require(in_array((int)$device['id'], array_map('intval',$actual)) === in_array($name,$expected), 'Plugin disagrees with effective Cacti policy');
    }
    echo json_encode($expected);
} elseif ($mode === 'guest') {
    db_execute("REPLACE INTO settings (name,value) VALUES ('guest_user','access-viewer'),('auth_method','1')");
    $id = db_fetch_cell("SELECT id FROM user_auth WHERE username='access-viewer'");
    foreach (array(1, access_realm('dashboard_actions.php')) as $realm) {
        db_execute_prepared('REPLACE INTO user_auth_realm (realm_id,user_id) VALUES (?,?)', array($realm,$id));
    }
} elseif ($mode === 'no-auth') {
    db_execute("REPLACE INTO settings (name,value) VALUES ('auth_method','0')");
} elseif ($mode === 'restore-auth') {
    db_execute("REPLACE INTO settings (name,value) VALUES ('auth_method','1'),('guest_user','guest')");
} elseif ($mode === 'revoke') {
    $id = db_fetch_cell("SELECT id FROM user_auth WHERE username='access-operator'");
    db_execute_prepared('DELETE FROM user_auth_realm WHERE user_id=? AND realm_id=?', array($id,access_realm('dashboard_actions.php')));
    reset_user_perms($id);
} else { access_require(false, 'Unknown access fixture mode'); }

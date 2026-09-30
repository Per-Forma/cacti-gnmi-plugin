#!/usr/bin/env python3
"""Real PDO/helper/PyMySQL parity on a disposable TLS DB; emits only booleans."""
import base64
import importlib.metadata
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import socket
import select
import threading

PLUGIN = Path('/var/www/html/cacti/plugins/gnmi')
sys.path.insert(0, str(PLUGIN/'scripts'))
import gnmi_poller_bridge as bridge
import pymysql


def main():
    # A real local TCP endpoint proves Cacti's nondefault localhost normalization.
    def forward(peer):
        upstream = socket.create_connection(('bridge-db', 4406))
        try:
            while True:
                for source in select.select([peer, upstream], [], [], 2)[0]:
                    data=source.recv(65536)
                    if not data:return
                    (upstream if source is peer else peer).sendall(data)
        finally:peer.close();upstream.close()
    def serve(listener):
        while True:
            peer,_=listener.accept()
            threading.Thread(target=forward,args=(peer,),daemon=True).start()
    listener=socket.socket();listener.setsockopt(socket.SOL_SOCKET,socket.SO_REUSEADDR,1)
    try:
        listener.bind(('127.0.0.1',4406));listener.listen()
        threading.Thread(target=serve,args=(listener,),daemon=True).start()
    except OSError:
        listener.close() # Gate A's disposable proxy may already occupy this endpoint.
    results = []
    root = pymysql.connect(unix_socket='/sock/mysql.sock', user='root', password=b'disposable-bridge-root', ssl_disabled=True)
    with root.cursor() as cur:
        cur.execute('CREATE DATABASE IF NOT EXISTS bridge_test')
        cur.execute('CREATE TABLE IF NOT EXISTS bridge_test.identity (marker INTEGER)')
        cur.execute('DELETE FROM bridge_test.identity')
        cur.execute('INSERT INTO bridge_test.identity VALUES (4406)')
        for name, password, requirement in [
            ('bridge_quotes', " '\"\\ snowman \u2603 $() `command` ", ''),
            ('bridge_empty', '', ''),
            ('bridge_tls', 'synthetic', 'REQUIRE SSL'),
            ('bridge_mtls', 'synthetic', "REQUIRE SUBJECT '/CN=bridge-client'"),
            ('bridge_socket', 'synthetic', '')]:
            cur.execute('DROP USER IF EXISTS %s@%s', (name, '%'))
            cur.execute('CREATE USER %s@%s IDENTIFIED BY %s '+requirement, (name, '%', password))
            cur.execute('GRANT ALL ON bridge_test.* TO %s@%s', (name, '%'))
    root.commit()
    root.close()

    with tempfile.TemporaryDirectory(prefix='gnmi-db-test-') as tmp:
        config = Path(tmp)/'config.php'
        php = Path(tmp)/'php-with-socket'
        php.write_text('#!/bin/sh\nexec php -d pdo_mysql.default_socket=/sock/mysql.sock "$@"\n')
        php.chmod(0o700)
        def resolved(password='synthetic', user='bridge_tls', host='bridge-db', port=4406, tls=True, ca='/tls/ca.crt', cert='', key=''):
            # Test configs are trusted synthetic fixtures, removed on exit.
            fields = dict(database_type='mysql', database_hostname=host, database_port=str(port),
                          database_default='bridge_test', database_username=user,
                          database_ssl=tls, database_ssl_ca=ca, database_ssl_cert=cert, database_ssl_key=key)
            # Exercise getenv without persisting even synthetic credentials in a fixture file.
            # This test-only environment expression is separate from the poller's private stdin.
            text = '<?php\n$database_password=base64_decode(getenv("GNMI_TEST_PASSWORD_B64"));\n'
            for field, value in fields.items():
                literal = 'true' if value is True else 'false' if value is False else 'base64_decode('+json.dumps(base64.b64encode(value.encode()).decode())+')'
                text += '$'+field+'='+literal+';\n'
            config.write_text(text)
            previous = os.environ.get('GNMI_TEST_PASSWORD_B64')
            os.environ['GNMI_TEST_PASSWORD_B64'] = base64.b64encode(password.encode()).decode()
            try:
                return bridge.parse_cacti_config(str(config), php_binary=str(php))
            finally:
                if previous is None:
                    del os.environ['GNMI_TEST_PASSWORD_B64']
                else:
                    os.environ['GNMI_TEST_PASSWORD_B64'] = previous

        def success(name, cfg, encrypted, pdo=True):
            # Exercise the actual export contract against a selected PDO object too.
            if pdo:
                probe = Path(tmp)/'parent.php'
                probe.write_text('''<?php
include __DIR__.'/config.php';
require '/var/www/html/cacti/plugins/gnmi/include/database_config.php';
$host=$database_hostname; if($host==='localhost' && $database_port!='3306')$host='127.0.0.1';
$flags=[PDO::ATTR_TIMEOUT=>2];
if($database_ssl){if($database_ssl_ca!=='')$flags[PDO::MYSQL_ATTR_SSL_CA]=$database_ssl_ca;
if($database_ssl_cert!==''){$flags[PDO::MYSQL_ATTR_SSL_CERT]=$database_ssl_cert;$flags[PDO::MYSQL_ATTR_SSL_KEY]=$database_ssl_key;}}
$dsn=$host[0]==='/'?'mysql:unix_socket='.$host.';dbname='.$database_default:'mysql:host='.$host.';port='.$database_port.';dbname='.$database_default;
try{$selected=new PDO($dsn,$database_username,$database_password,$flags);
$database_sessions=["$database_hostname:$database_port:$database_default"=>$selected];
$database_details=[spl_object_hash($selected)=>['database_conn'=>$selected]+compact('database_type','database_hostname','database_port','database_default','database_username','database_password','database_ssl','database_ssl_ca','database_ssl_cert','database_ssl_key')];
$database_details[spl_object_hash($selected)]['database_hostname']=$host;
$payload=gnmi_export_database_config();
if($selected->query('SELECT marker FROM identity')->fetchColumn()!=4406)exit(1);
fwrite(STDOUT,json_encode($payload));}catch(Throwable $e){exit(1);}
''')
                output = subprocess.run(['php', '-d', 'pdo_mysql.default_socket=/sock/mysql.sock', str(probe)],
                                        capture_output=True, timeout=3, cwd=tmp,
                                        env={**os.environ, 'GNMI_TEST_PASSWORD_B64':base64.b64encode(cfg['password']).decode()})
                assert output.returncode == 0 and not output.stderr, name+' PDO selection'
                cfg = bridge.validate_database_config(output.stdout)
            conn = bridge.connect_to_database(database_config=cfg)
            try:
                with conn.cursor() as cur:
                    cur.execute('SELECT marker FROM identity')
                    assert cur.fetchone()['marker'] == 4406, name+' exact database marker'
                    cur.execute("SHOW SESSION STATUS LIKE 'Ssl_cipher'")
                    assert bool(cur.fetchone()['Value']) == encrypted, name+' negotiated TLS'
            finally:
                conn.close()
            results.append(dict(case=name, passed=True, encrypted=encrypted, pdo_parity=pdo))

        def failure(name, cfg, categories):
            try:
                bridge.connect_to_database(database_config=cfg)
            except bridge.DatabaseConfigError as err:
                assert err.category in categories, name+' safe category '+err.category
                assert 'synthetic' not in str(err)
                results.append(dict(case=name, passed=True, category=err.category))
            else:
                raise AssertionError(name+' unexpectedly connected')

        for password, user, name in [(" '\"\\ snowman \u2603 $() `command` ", 'bridge_quotes', 'quoted_unicode_bytes'),
                                     ('', 'bridge_empty', 'empty_password')]:
            success(name, resolved(password, user, tls=False), False)
        success('nondefault_localhost', resolved(user='bridge_quotes',password=" '\"\\ snowman \u2603 $() `command` ",host='localhost',tls=False),False)
        success('verified_tls', resolved(), True)
        success('encryption_only', resolved(ca=''), True, pdo=False) # PDO has no material to request TLS in this mode.
        success('mutual_tls', resolved(user='bridge_mtls', cert='/tls/client.crt', key='/tls/client.key'), True)
        failure('tls_explicitly_disabled', resolved(tls=False), {'authentication'})
        failure('hostname_mismatch', resolved(host=os.environ.get('GNMI_TLS_MISMATCH_HOST','gnmi_bridge_lab_db')), {'tls_identity'})
        failure('unknown_ca', resolved(ca='/tls/unknown-ca.crt'), {'tls_identity'})
        failure('expired_ca', resolved(ca='/tls/expired-ca.crt'), {'tls_identity'})
        failure('missing_client_identity', resolved(user='bridge_mtls'), {'authentication'})
        failure('invalid_client_identity', resolved(user='bridge_mtls', cert='/tls/server.crt', key='/tls/server.key'), {'tls','authentication','tls_client'})
        for host in ['/sock/mysql.sock', 'localhost']:
            cfg=resolved(user='bridge_socket', host=host, port=3306, tls=False)
            success('socket_'+host, cfg, False)
        # An explicit socket with TLS remains encrypted in Python; PDO parity is unsupported in this lab.
        success('socket_required_tls', resolved(user='bridge_socket', host='/sock/mysql.sock', tls=True), True, pdo=False)
        for bad in [dict(ca='/missing'), dict(cert='/tls/client.crt'), dict(cert='/tls/client.crt', key='/missing'), dict(cert='/tls/client.crt',key='/tls/encrypted-client.key')]:
            try:
                bad_cfg=resolved(**bad)
                bridge.connect_to_database(database_config=bad_cfg)
            except bridge.DatabaseConfigError:
                results.append(dict(case='unreadable_or_partial_identity', passed=True))
            else:
                raise AssertionError('configured TLS paths silently ignored')
        unreadable=Path(tmp)/'unreadable-ca';unreadable.write_text('SYNTHETIC_MATERIAL_CANARY');unreadable.chmod(0)
        try:
            try:resolved(ca=str(unreadable))
            except bridge.DatabaseConfigError as err:assert err.category=='permissions'
            else:raise AssertionError('unreadable CA ignored')
            results.append(dict(case='service_account_unreadable_ca',passed=True))
        finally:unreadable.chmod(0o600)
        # TCP-disabled MariaDB with only local socket grants.
        if Path('/sock/only.sock').exists():
            db=pymysql.connect(unix_socket='/sock/only.sock',user='root',password=b'disposable-bridge-root',ssl_disabled=True)
            with db.cursor() as c:
                c.execute('CREATE DATABASE IF NOT EXISTS bridge_test')
                c.execute('CREATE TABLE IF NOT EXISTS bridge_test.identity(marker INTEGER)')
                c.execute('DELETE FROM bridge_test.identity');c.execute('INSERT INTO bridge_test.identity VALUES(4406)')
                c.execute("CREATE USER IF NOT EXISTS 'bridge_socket'@'localhost' IDENTIFIED BY 'synthetic'")
                c.execute("GRANT ALL ON bridge_test.* TO 'bridge_socket'@'localhost'")
            db.commit();db.close()
            success('socket_only_server_local_grants',resolved(user='bridge_socket',host='/sock/only.sock',tls=False),False)
        else:raise AssertionError('Socket-only server must be present')
        # Certificate paths outside runtime and relative paths use config-directory CWD.
        os.symlink('/tls/ca.crt',Path(tmp)/'relative-ca.crt')
        success('relative_certificate', resolved(ca='relative-ca.crt'),True)
    print(json.dumps(dict(driver=importlib.metadata.version('PyMySQL'), cases=results, result='passed')))

if __name__ == '__main__':
    main()

"""Regression contracts for private database configuration and safe diagnostics."""
import base64
import json
import ssl
from types import SimpleNamespace
from unittest.mock import Mock
import pytest
from scripts import gnmi_poller_bridge as bridge


def payload(**overrides):
    values = dict(version=1, type='mysql', host='db.example.test', port=4406,
                  database='cacti', username='synthetic', password_b64=base64.b64encode(b'').decode(), tls=False)
    values.update(overrides)
    return values


@pytest.mark.parametrize('password', [b'', b''' '\\" $() `echo synthetic` ''', ' snowman \u2603 '.encode(), b'\xff\x80'])
def test_protocol_preserves_password_bytes(password):
    result = bridge.validate_database_config(json.dumps(payload(password_b64=base64.b64encode(password).decode())).encode())
    assert result['password'] == password
    assert result['port'] == 4406


@pytest.mark.parametrize('change', [dict(version=2), dict(version=True), dict(port=True), dict(port='4406'),
    dict(port=0), dict(port=65536), dict(tls='false'), dict(type='sqlite'), dict(password_b64='!'),
    dict(username=None), dict(password_b64='\u2603'), dict(unix_socket='relative.sock'), dict(extra='x'), dict(cert='/tmp/client')])
def test_protocol_rejects_invalid_configuration(change):
    with pytest.raises(bridge.DatabaseConfigError):
        bridge.validate_database_config(json.dumps(payload(**change)).encode())


@pytest.mark.parametrize('raw', [b'{}', b'{', b'[]', b'x'*65537, b'{"version":1,"version":1}'],
                         ids=['missing', 'truncated', 'array', 'oversized', 'duplicate'])
def test_protocol_bounds_and_validates_input(raw):
    with pytest.raises(bridge.DatabaseConfigError):
        bridge.validate_database_config(raw)


def test_adapter_honors_port_bytes_and_disables_implicit_tls(monkeypatch):
    connect = Mock()
    monkeypatch.setattr(bridge, 'pymysql', SimpleNamespace(connect=connect, cursors=SimpleNamespace(DictCursor=object())))
    cfg = bridge.validate_database_config(json.dumps(payload()).encode())
    bridge.connect_to_database(database_config=cfg)
    kw = connect.call_args.kwargs
    assert kw['password'] == b''
    assert kw['port'] == 4406
    assert kw['ssl_disabled'] is True
    assert kw['connect_timeout'] <= 2
    assert kw['read_timeout'] <= 2
    assert kw['write_timeout'] <= 2


def test_encryption_only_context_requires_tls(monkeypatch):
    connect = Mock()
    monkeypatch.setattr(bridge, 'pymysql', SimpleNamespace(connect=connect, cursors=SimpleNamespace(DictCursor=object())))
    bridge.connect_to_database(database_config=bridge.validate_database_config(json.dumps(payload(tls=True)).encode()))
    context = connect.call_args.kwargs['ssl']
    assert isinstance(context, ssl.SSLContext)
    assert context.verify_mode == ssl.CERT_NONE
    assert context.check_hostname is False


def test_driver_exception_is_categorized_without_secret(monkeypatch, caplog):
    connect = Mock(side_effect=RuntimeError('SYNTHETIC_PASSWORD_CANARY'))
    monkeypatch.setattr(bridge, 'pymysql', SimpleNamespace(connect=connect, cursors=SimpleNamespace(DictCursor=object())))
    with pytest.raises(bridge.DatabaseConfigError) as caught:
        bridge.connect_to_database(database_config=bridge.validate_database_config(json.dumps(payload()).encode()))
    assert 'CANARY' not in str(caught.value)
    assert 'CANARY' not in caplog.text


def test_required_tls_rejects_server_before_authentication():
    """Exercise pinned driver on a real socket, observing its first client packet."""
    import socket
    import struct
    import threading
    from pymysql.constants import CLIENT
    listener = socket.socket()
    listener.bind(('127.0.0.1', 0))
    listener.listen(1)
    port = listener.getsockname()[1]
    received = []
    flags = CLIENT.PROTOCOL_41 | CLIENT.SECURE_CONNECTION | CLIENT.PLUGIN_AUTH
    greeting = (b'\x0a8.0.0\0' + struct.pack('<I', 1) + b'12345678\0' + struct.pack('<H', flags & 65535)
                + b'\x2d\x02\x00' + struct.pack('<H', flags >> 16) + b'\x15' + b'\0'*10
                + b'123456789012\0mysql_native_password\0')
    def serve():
        with listener:
            peer, _ = listener.accept()
            with peer:
                peer.settimeout(2)
                peer.sendall(struct.pack('<I', len(greeting))[:3] + b'\0' + greeting)
                received.append(peer.recv(1024))
    worker = threading.Thread(target=serve)
    worker.start()
    try:
        cfg = bridge.validate_database_config(json.dumps(payload(host='127.0.0.1', port=port, tls=True)).encode())
        with pytest.raises(bridge.DatabaseConfigError) as caught:
            bridge.connect_to_database(database_config=cfg)
        assert caught.value.category == 'tls_required'
    finally:
        worker.join(timeout=3)
    assert received == [b'']


def test_resolver_timeout_and_unbounded_output_are_contained(monkeypatch, tmp_path):
    import subprocess
    import sys
    import time
    original = subprocess.Popen
    for code, category in [('import time;time.sleep(5)', 'timeout'), ('print("SECRET_CANARY"*10000)', 'configuration'),
                           ('import sys;sys.stderr.write("SECRET_CANARY");sys.exit(1)', 'configuration')]:
        monkeypatch.setattr(bridge.subprocess, 'Popen', lambda command, **kwargs: original([sys.executable, '-c', code], **kwargs))
        started = time.monotonic()
        with pytest.raises(bridge.DatabaseConfigError) as caught:
            bridge.parse_cacti_config(str(tmp_path/'config.php'), deadline=started+.6)
        assert caught.value.category == category
        assert 'CANARY' not in str(caught.value)
        assert time.monotonic()-started < .9


def test_resolver_accepts_only_private_protocol(monkeypatch, tmp_path):
    import subprocess
    import sys
    original = subprocess.Popen
    commands = []
    raw = json.dumps(payload())
    def launch(command, **kwargs):
        commands.append(command)
        return original([sys.executable, '-c', 'import sys;sys.stdout.write('+repr(raw)+')'], **kwargs)
    monkeypatch.setattr(bridge.subprocess, 'Popen', launch)
    cfg = bridge.parse_cacti_config(str(tmp_path/'config.php'), php_binary='chosen-php')
    assert cfg['password'] == b''
    assert commands[0][0] == 'chosen-php'
    assert commands[0][1].endswith('gnmi_database_config.php')
    assert len(commands[0]) == 3


def test_non_numeric_latest_output_is_not_success_or_a_debug_leak(capsys, caplog):
    import logging
    with caplog.at_level(logging.DEBUG, logger=bridge.logger.name):
        assert bridge.output_cacti_format({'field': 'SECRET_CANARY'}) is False
    assert capsys.readouterr().out == ''
    assert 'SECRET_CANARY' not in caplog.text


def test_cli_rejects_conflicting_sources_before_connect(monkeypatch, capsys):
    import sys
    connect = Mock()
    monkeypatch.setattr(bridge, 'connect_to_database', connect)
    monkeypatch.setattr(sys, 'argv', ['bridge', '--device-id', '1', '--local-data-id', '1',
                                    '--database-config-stdin', '--config-path', 'config.php'])
    with pytest.raises(SystemExit) as caught:
        bridge.main()
    assert caught.value.code == 1
    connect.assert_not_called()
    output=capsys.readouterr()
    assert output.out == ''
    assert output.err == ('GNMI_BRIDGE_ERROR configuration configuration\n'
                          'Database configuration is invalid or incomplete\n')

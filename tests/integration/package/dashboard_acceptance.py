#!/usr/bin/env python3
"""Verify dashboard permissions using real sessions, CSRF and collector state."""
import argparse
import json
import os
import secrets
import subprocess
from http_acceptance import Session


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('container')
    parser.add_argument('url')
    parser.add_argument('--reproduce', action='store_true')
    parser.add_argument('--protected-installation', action='store_true')
    args = parser.parse_args()
    password = secrets.token_urlsafe(32)

    def fixture(mode):
        user = 'root' if args.protected_installation and mode in ('missing-venv','restore-venv') else 'www-data'
        result = subprocess.run(['docker', 'exec', '-u', user,
            '-e', 'CACTI_ADMIN_PASSWORD', '-e', 'ACCESS_FIXTURE_MODE=' + mode,
            args.container, 'php', '/tmp/access-fixture.php'],
            env={**os.environ, 'CACTI_ADMIN_PASSWORD': password},
            check=True, capture_output=True, text=True)
        return json.loads(result.stdout) if mode in ('setup', 'state') or mode.startswith('policy-') else None

    data = fixture('setup')
    devices = data['devices']
    print('Environment:', data['cacti'], 'PHP', data['php'])
    sessions = {}
    for name in data['users']:
        s = Session(args.url)
        s.login('access-' + name, password)
        sessions[name] = s
    if args.reproduce:
        for page in ('index.php', 'status.php'):
            _, html, _ = sessions['viewer'].request('plugins/gnmi/' + page)
            print('REPRODUCED:', page, 'hidden B details:', 'ERROR-MARKER-B' in html,
                'viewer restart:', 'value="restart"' in html, 'global orphans:', 'Orphaned Daemons' in html)
        before = fixture('state')
        sessions['general'].request('plugins/gnmi/status.php', {
            '__csrf_magic':sessions['general'].token, 'action':'restart', 'device_id':devices['A']['id']})
        sessions['operator'].request('plugins/gnmi/status.php', {
            '__csrf_magic':sessions['operator'].token, 'action':'restart', 'device_id':devices['B']['id']})
        after = fixture('state')
        print('REPRODUCED: general manager A restart dispatched:', after['A']['restarts'] > before['A']['restarts'])
        print('REPRODUCED: scoped operator B restart dispatched:', after['B']['restarts'] > before['B']['restarts'])
        fixture('stale')
        sessions['viewer'].request('plugins/gnmi/status.php')
        print('REPRODUCED: GET removed stale A PID:', fixture('state')['A']['pid'] is None)
        sessions['general'].request('plugins/gnmi/status.php', {
            '__csrf_magic':sessions['general'].token, 'action':'cleanup_orphans'})
        print('REPRODUCED: general manager removed global orphan:', not fixture('state')['orphan_exists'])
        fixture('missing-venv')
        try:
            sessions['viewer'].request('plugins/gnmi/index.php')
            print('REPRODUCED: viewer GET created venv:', fixture('state')['venv_exists'])
        finally:
            fixture('restore-venv')
        return

    for name, s in sessions.items():
        for page in ('index.php', 'status.php'):
            _, html, _ = s.request('plugins/gnmi/' + page)
            if name == 'index-only' and page == 'status.php':
                assert 'ERROR-MARKER-A' not in html, 'Page realms must remain independent'
                continue
            assert 'ERROR-MARKER-B' not in html and 'EVENT-MARKER-B' not in html, name + ': hidden B details leaked'
            assert 'ERROR-MARKER-deleted' not in html, 'Deleted host leaked'
            assert 'ERROR-MARKER-disabled' not in html, 'Disabled plugin leaked'
            if name == 'empty':
                assert 'No accessible gNMI devices are currently enabled.' in html
                assert 'host.php">Add devices' not in html
            else:
                assert 'ERROR-MARKER-A' in html and 'EVENT-MARKER-A' in html, name + ': allowed details missing'
            can_restart = name in ('operator', 'admin', 'group')
            assert ('value="restart"' in html) == can_restart, name + ': wrong restart control'
            assert ('Orphaned Daemons' in html) == (name in ('installer', 'admin')), name + ': wrong orphan panel'
        print('PASS: visibility and controls:', name)

    for page in ('index.php', 'status.php'):
        path = 'plugins/gnmi/' + page
        for name in ('viewer', 'general', 'installer', 'disabled-group', 'operator', 'group', 'admin'):
            s = sessions[name]
            for target in (devices['B']['id'], devices['disabled']['id'], devices['deleted']['id'], 999999, '0', 'bad'):
                before = fixture('state')
                _, html, _ = s.request(path, {'__csrf_magic':s.token, 'action':'restart', 'device_id':target})
                assert before == fixture('state'), name + ': denied restart changed lifecycle state'
                assert 'Daemon restarted successfully' not in html
            if name not in ('operator', 'group', 'admin'):
                before = fixture('state')
                s.request(path, {'__csrf_magic':s.token, 'action':'restart', 'device_id':devices['A']['id']})
                assert before == fixture('state'), name + ': missing realm allowed restart'
        before = fixture('state')
        s = sessions['operator']
        s.request(path + '?action=restart&device_id=' + str(devices['A']['id']))
        s.request(path, {'__csrf_magic':'invalid', 'action':'restart', 'device_id':devices['A']['id']})
        s.request(path, {'__csrf_magic':s.token, 'action[]':'restart', 'device_id[]':'1'})
        s.request(path, {'__csrf_magic':s.token, 'action':'unknown'})
        assert before == fixture('state'), 'Invalid request mutated runtime'
        s.request(path, {'__csrf_magic':s.token, 'action':'restart', 'device_id':devices['A']['id']})
        after = fixture('state')
        assert after['A']['restarts'] > before['A']['restarts'] and before['B'] == after['B']
        print('PASS: dashboard POST target and CSRF matrix:', page)

    for name in ('viewer', 'general', 'installer', 'disabled-group'):
        before = fixture('state')
        sessions[name].action('restart_daemon', 403, 'permission_denied', host_id=devices['A']['host_id'])
        assert fixture('state') == before
    for name in ('operator', 'group', 'admin'):
        for target in ('B', 'disabled', 'deleted'):
            before = fixture('state')
            sessions[name].action('restart_daemon', 404, 'target_unavailable', host_id=devices[target]['host_id'])
            assert fixture('state') == before
        sessions[name].action('restart_daemon', 200, 'daemon_restarted', host_id=devices['A']['host_id'])

    fixture('stale')
    for name in ('viewer', 'general', 'operator', 'installer', 'group', 'disabled-group'):
        s = sessions[name]
        before = fixture('state')
        s.request('plugins/gnmi/index.php')
        s.request('plugins/gnmi/status.php')
        s.request('plugins/gnmi/status.php', {'__csrf_magic':s.token, 'action':'cleanup_orphans'})
        assert fixture('state') == before, name + ': read/denied cleanup altered PID files'
    s = sessions['admin']
    _, html, _ = s.request('plugins/gnmi/status.php')
    assert 'Clean Up Orphans Now' in html
    before = fixture('state')
    s.request('plugins/gnmi/status.php', {'__csrf_magic':s.token, 'action':'cleanup_orphans'})
    after = fixture('state')
    assert not after['orphan_exists'], 'Authorized cleanup failed'
    assert after['manual_audit_entries'] == before['manual_audit_entries'] + 1
    assert after['global_cleanup_events'] == before['global_cleanup_events'], 'Manual maintenance attached to a device'
    print('PASS: read-only PID inspection and global maintenance matrix')

    fixture('missing-venv')
    try:
        for name in ('viewer', 'general', 'operator', 'installer'):
            sessions[name].request('plugins/gnmi/index.php')
            assert not fixture('state')['venv_exists'], name + ': GET created venv'
        sessions['admin'].request('plugins/gnmi/index.php')
        if args.protected_installation:
            _, html, _ = sessions['admin'].request('plugins/gnmi/index.php')
            assert not fixture('state')['venv_exists'], 'Protected installation created a venv'
            assert 'Administrator shell repair required' in html
        else:
            assert fixture('state')['venv_exists'], 'Authorized writable recovery did not create a venv'
    finally:
        fixture('restore-venv')
    if args.protected_installation:
        _, html, _ = sessions['admin'].request('plugins/gnmi/index.php')
        assert 'Administrator shell repair required' not in html, 'Dependency banner survived administrator repair'
        print('PASS: protected GET/recovery creates no venv; administrator restore clears banner')
    else:
        print('PASS: viewer diagnosis creates no venv; authorized writable recovery succeeds')
    # Authorized recovery may still run on a writable installation. Protected
    # code/venv must instead give useful shell remediation without downloads.
    fixture('missing-venv')
    plugin = '/var/www/html/cacti/plugins/gnmi'
    # Use a native container directory: Desktop bind mounts can report chown
    # while still granting write access through the host filesystem's ACLs.
    protected = '/tmp/gnmi-access-protected-venv'
    subprocess.run(['docker','exec','-u','root',args.container,'mkdir','-p',protected + '/bin',protected + '/lib'], check=True, capture_output=True)
    subprocess.run(['docker','exec','-u','root',args.container,'chmod','755',protected], check=True, capture_output=True)
    subprocess.run(['docker','exec','-u','root',args.container,'ln','-s',protected,plugin + '/venv'], check=True, capture_output=True)
    try:
        _, html, _ = sessions['admin'].request('plugins/gnmi/index.php')
        assert not fixture('state')['venv_exists'], 'Protected installation created a venv'
        assert 'Administrator shell repair required' in html
    finally:
        subprocess.run(['docker','exec','-u','root',args.container,'rm',plugin + '/venv'], check=True, capture_output=True)
        fixture('restore-venv')
    print('PASS: protected code/venv requires administrator shell repair')
    for case in ('policy-1','policy-2','policy-3','policy-4','policy-graph','policy-hide-disabled','policy-3'):
        allowed = fixture(case)
        # New sessions intentionally exercise cold Cacti policy decisions.
        viewer = Session(args.url)
        viewer.login('access-viewer', password)
        for page in ('index.php','status.php'):
            _, html, _ = viewer.request('plugins/gnmi/' + page)
            for name in ('A','B'):
                assert ('ERROR-MARKER-' + name in html) == (name in allowed), case + ': HTTP disagrees with Cacti API'
        print('PASS: effective device/graph policy:', case, 'visible', len(allowed))
    fixture('guest')
    try:
        guest = Session(args.url)
        # Cacti permits a configured guest only on pages allowed by its own
        # guest policy. Check both pages; page denial must never expose data.
        for page in ('index.php','status.php'):
            _, html, _ = guest.request('plugins/gnmi/' + page)
            assert 'ERROR-MARKER-B' not in html and 'value="restart"' not in html and 'Orphaned Daemons' not in html
        guest.action('restart_daemon',403,'permission_denied',host_id=devices['A']['host_id'])
        # The configured guest already has a real session plus both maintenance
        # grants, so denial proves identity enforcement, not a missing realm.
        sessions['viewer'].action('restart_daemon',403,'permission_denied',host_id=devices['A']['host_id'])
        fixture('no-auth')
        sessions['admin'].action('restart_daemon',403,'permission_denied',host_id=devices['A']['host_id'])
        no_auth = Session(args.url)
        for page in ('index.php','status.php'):
            _, html, _ = no_auth.request('plugins/gnmi/' + page)
            # Both supported Cacti releases reset no-auth to local authentication
            # at include/auth.php and redirect to password repair. Preserve it.
            assert 'ERROR-MARKER-A' not in html and 'ERROR-MARKER-B' not in html
            assert fixture('state')['auth_method'] == 1, 'Cacti no-auth reset policy lost'
            assert 'value="restart"' not in html and 'Orphaned Daemons' not in html
        no_auth.action('restart_daemon',403,'permission_denied',host_id=devices['A']['host_id'])
    finally:
        fixture('restore-auth')
    print('PASS: configured guest denial and Cacti no-auth reset introduce no mutation bypass')
    fixture('revoke')
    before = fixture('state')
    s = sessions['operator']
    s.request('plugins/gnmi/status.php', {'__csrf_magic':s.token, 'action':'restart', 'device_id':devices['A']['id']})
    assert before == fixture('state'), 'Stale session grant allowed restart'
    # Cacti may respond with cactiRedirect on permission invalidation; next request must deny.
    s.action('restart_daemon', 403, 'permission_denied', host_id=devices['A']['host_id'])
    print('PASS: revoked permissions rechecked in existing session')


if __name__ == '__main__':
    main()

#!/usr/bin/env python3
"""Assert actual service access and native ownership, including after recreation."""

import argparse
import json
import subprocess
import time

from install_guide import require_disposable_container


def verify(container):
    code = '''
import json, os, pathlib, stat
p=pathlib.Path('/var/www/html/cacti/plugins/gnmi')
assert os.geteuid()==33
for parent in (p, p/'include', p/'scripts', p/'venv'):
    assert parent.stat().st_uid==0 and not os.access(parent,os.W_OK), str(parent)
for parent in (p/'include',p/'scripts',p/'venv'):
    for item in parent.rglob('*'):
        if not item.is_symlink():
            assert item.stat().st_uid==0 and not os.access(item,os.W_OK), str(item)
for part in ('','storage','certs','logs'):
    path=p/'runtime'/part
    assert path.stat().st_uid==os.geteuid() and os.access(path,os.W_OK), str(path)
    assert stat.S_IMODE(path.stat().st_mode)==0o750, str(path)
    assert (path/'.htaccess').is_file()
print(json.dumps({'service_uid':os.geteuid(),'code_owner':0,'venv_owner':0,'service_can_write_code':False,'runtime_mode':'0750','result':'passed'}))
'''
    result = subprocess.run(['docker', 'exec', '-u', 'www-data', container,
        '/var/www/html/cacti/plugins/gnmi/venv/bin/python3', '-c', code],
        capture_output=True, text=True, check=True)
    return json.loads(result.stdout)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('container')
    parser.add_argument('--after-recreation', action='store_true')
    args = parser.parse_args()
    require_disposable_container(args.container)
    if args.after_recreation:
        for _ in range(90):
            ready = subprocess.run(['docker', 'inspect', '--format',
                '{{.State.Health.Status}}', args.container], capture_output=True, text=True, check=True)
            if ready.stdout.strip() == 'healthy':
                break
            time.sleep(2)
        else:
            raise RuntimeError('Recreated Cacti did not become healthy')
        subprocess.run(['docker','exec','-u','root',args.container,'service','cron','stop'],
                       check=True, capture_output=True)
    print(json.dumps(verify(args.container)), flush=True)
    if args.after_recreation:
        # Reinstall leaves a fresh plugin database; prove graph writes still work.
        result = subprocess.run(['docker','exec','-u','www-data',args.container,'php',
            '/var/www/html/cacti/plugins/gnmi/tests/integration/cacti_compat/test_bridge_database_real.php'],
            capture_output=True, text=True, check=True)
        samples = json.loads(result.stdout)
        assert samples['result']=='passed' and samples['exact_rrd_samples']==12
        print(json.dumps(samples))


if __name__ == '__main__':
    main()

#!/usr/bin/env python3
"""Execute archive-shipped commands; fixtures are added only after preparation."""

import argparse
import os
from pathlib import Path
import subprocess

from install_guide import command_block, require_disposable_container


def verify_docker_guards(document, env):
    container = env['GNMI_CONTAINER']
    work = subprocess.check_output(['docker','exec','-u','root',container,
        'mktemp','-d','/tmp/gnmi-install-guard.XXXXXX'], text=True).strip()
    try:
        for state in ('occupied','symlink','dangling','file'):
            destination = work + '/' + state
            setup = '''set -eu
case "$2" in
 occupied) mkdir "$1"; echo keep >"$1/sentinel";;
 symlink) mkdir "$1-target"; echo keep >"$1-target/sentinel"; ln -s "$1-target" "$1";;
 dangling) ln -s "$1-absent" "$1";;
 file) echo keep >"$1";;
esac'''
            subprocess.run(['docker','exec','-u','root',container,'sh','-c',setup,
                            'sh',destination,state], check=True)
            result = subprocess.run(['bash','-c',command_block(document,'docker-copy')],
                env={**env,'GNMI_PLUGIN_DIR':destination}, capture_output=True, text=True)
            if result.returncode == 0:
                raise RuntimeError('Archive guide accepted unsafe Docker destination: ' + state)
        print('PASS: Docker occupied/file/symlink destination guards', flush=True)
    finally:
        subprocess.run(['docker','exec','-u','root',container,'rm','-rf','--',work], check=True)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('package', type=Path)
    parser.add_argument('container')
    parser.add_argument('--mode', choices=('native', 'docker'), default='docker')
    args = parser.parse_args()
    require_disposable_container(args.container)
    package = args.package.resolve()
    document = package / 'docs/install.md'
    env = {**os.environ, 'GNMI_PACKAGE_DIR': str(package),
           'GNMI_CONTAINER': args.container,
           'GNMI_PLUGIN_DIR': '/var/www/html/cacti/plugins/gnmi',
           'GNMI_SERVICE_USER': 'www-data', 'GNMI_SERVICE_GROUP': 'www-data'}
    if args.mode == 'docker':
        verify_docker_guards(document, env)
        for step in ('docker-copy', 'docker-dependencies', 'docker-runtime'):
            subprocess.run(['bash', '-c', command_block(document, step)],
                           env=env, check=True)
    else:
        staged = '/tmp/gnmi-verified-install-package'
        subprocess.run(['docker', 'cp', str(package), args.container + ':' + staged], check=True)
        env['GNMI_PACKAGE_DIR'] = staged
        for step in ('destination', 'native-copy', 'native-dependencies', 'native-runtime'):
            command = ['docker', 'exec', '-i', '-u', 'root']
            for name in ('GNMI_PACKAGE_DIR', 'GNMI_PLUGIN_DIR', 'GNMI_SERVICE_USER', 'GNMI_SERVICE_GROUP'):
                command += ['-e', name]
            command += [args.container, 'bash', '-s']
            subprocess.run(command, input=command_block(document, step), env=env,
                           text=True, check=True)
    print('PASS: archive-only ' + args.mode + ' guide commands')


if __name__ == '__main__':
    main()

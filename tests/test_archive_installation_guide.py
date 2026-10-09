"""Exercise the shipped commands before any privileged copy or plugin enable."""

import hashlib
import io
import os
from pathlib import Path
import subprocess
import tarfile

import pytest

from tests.integration.package.install_guide import command_block

ROOT = Path(__file__).resolve().parents[1]
GUIDE = ROOT / 'docs/install.md'


def package(tmp_path, *, omit=None, corrupt=False):
    version = '1.0.0-beta.3'
    name = 'cacti-gnmi-plugin-' + version
    files = {p: b'fixture' for p in (
        'gnmi/setup.php', 'gnmi/INFO', 'gnmi/scripts/requirements.txt',
        'gnmi/scripts/gnmi_database_config.php', 'gnmi/include/database_config.php',
        'gnmi/include/poller_bridge.php', 'MANIFEST.md') if p != omit}
    sums = ''.join(hashlib.sha256(data).hexdigest() + '  ./' + path + '\n'
                   for path, data in files.items()).encode()
    files['CHECKSUMS.txt'] = sums
    if corrupt:
        files['gnmi/setup.php'] = b'changed'
    archive = tmp_path / (name + '.tar.gz')
    with tarfile.open(archive, 'w:gz') as tar:
        for path, data in files.items():
            member = tarfile.TarInfo(name + '/' + path)
            member.size = len(data)
            tar.addfile(member, io.BytesIO(data))
    (tmp_path / (archive.name + '.sha256')).write_text(
        hashlib.sha256(archive.read_bytes()).hexdigest() + '  ' + archive.name + '\n')
    return {'GNMI_RELEASE_VERSION': version, 'GNMI_PLUGIN_DIR': str(tmp_path / 'plugin')}


def execute(name, tmp_path, env):
    return subprocess.run(['bash', '-c', command_block(GUIDE, name)], cwd=tmp_path,
                          env={**os.environ, **env}, capture_output=True, text=True)


def test_verify_download_with_spaces(tmp_path):
    work = tmp_path / 'download with spaces'
    work.mkdir()
    result = execute('verify', work, package(work))
    assert result.returncode == 0, result.stderr


@pytest.mark.parametrize('failure', ['missing_checksum', 'wrong_checksum',
                                    'internal_corruption', 'missing_helper'])
def test_verification_failure_stops_before_deployment(tmp_path, failure):
    env = package(tmp_path,
                  omit='gnmi/scripts/gnmi_database_config.php' if failure == 'missing_helper' else None,
                  corrupt=failure == 'internal_corruption')
    checksum = next(tmp_path.glob('*.sha256'))
    if failure == 'missing_checksum':
        checksum.unlink()
    elif failure == 'wrong_checksum':
        checksum.write_text('0' * 64 + '  ' + checksum.name[:-7] + '\n')
    result = execute('verify', tmp_path, env)
    assert result.returncode != 0
    assert not Path(env['GNMI_PLUGIN_DIR']).exists()


@pytest.mark.parametrize('state', ['populated', 'symlink', 'dangling_symlink', 'file'])
def test_destination_guard_rejects_existing_state(tmp_path, state):
    target = tmp_path / 'target with spaces'
    target.mkdir()
    sentinel = target / 'keep'
    sentinel.write_text('unchanged')
    destination = tmp_path / 'plugin'
    if state == 'populated':
        destination.mkdir()
        (destination / 'keep').write_text('unchanged')
    elif state in ('symlink', 'dangling_symlink'):
        destination.symlink_to(target if state == 'symlink' else tmp_path / 'absent')
    else:
        destination.write_text('unchanged')
    result = execute('destination', tmp_path, {'GNMI_PLUGIN_DIR': str(destination)})
    assert result.returncode != 0
    assert sentinel.read_text() == 'unchanged'
    if state == 'populated':
        assert (destination / 'keep').read_text() == 'unchanged'


def test_destination_guard_accepts_deliberately_empty_directory(tmp_path):
    destination = tmp_path / 'empty plugin'
    destination.mkdir()
    result = execute('destination', tmp_path, {'GNMI_PLUGIN_DIR': str(destination)})
    assert result.returncode == 0, result.stderr


def test_archive_operator_flow_has_no_checkout_dependencies():
    guide = GUIDE.read_text()
    assert 'deploy_plugin.sh' not in guide
    assert 'requirements-dev.txt' not in guide
    for name in ('verify', 'destination', 'native-copy', 'native-dependencies',
                 'native-runtime', 'docker-copy', 'docker-dependencies', 'docker-runtime'):
        assert command_block(GUIDE, name).startswith('set -eu\n')


def test_contributor_deploy_contains_database_helper_without_planning_files(tmp_path):
    destination = tmp_path / 'deploy with spaces'
    subprocess.run(['bash', str(ROOT / 'deploy_plugin.sh'), str(destination)], check=True,
                   capture_output=True)
    assert (destination / 'scripts/gnmi_database_config.php').is_file()
    assert not list(destination.rglob('ITEM_*_SPEC.md'))
    assert not list(destination.rglob('BETA_TEST_READINESS_PLAN.md'))

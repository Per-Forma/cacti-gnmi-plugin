"""Acceptance must reject broken artifacts before touching a Cacti instance."""

import importlib.util
import io
from pathlib import Path
import tarfile

import pytest

SPEC = importlib.util.spec_from_file_location(
    'package_verifier', Path(__file__).parent / 'integration/package/verify_archive.py')
verifier = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(verifier)


def make_archive(tmp_path, files, extra=None):
    archive = tmp_path / 'package.tar.gz'
    entries = dict(files)
    entries['CHECKSUMS.txt'] = ''.join(
        verifier.hashlib.sha256(data).hexdigest() + '  ./' + name + '\n'
        for name, data in files.items()).encode()
    with tarfile.open(archive, 'w:gz') as tar:
        for name, data in entries.items():
            member = tarfile.TarInfo('package/' + name)
            member.size = len(data)
            tar.addfile(member, io.BytesIO(data))
        if extra:
            tar.addfile(extra)
    checksum = tmp_path / 'package.tar.gz.sha256'
    checksum.write_text(verifier.digest(archive) + '  package.tar.gz\n')
    return archive, checksum


def test_rejects_corrupt_archive_before_extraction(tmp_path):
    archive, checksum = make_archive(tmp_path, {'a': b'content'})
    archive.write_bytes(archive.read_bytes() + b'corrupt')
    with pytest.raises(ValueError, match='Archive checksum'):
        verifier.verify(archive, checksum, tmp_path / 'out')
    assert not (tmp_path / 'out').exists()


@pytest.mark.parametrize('name,kind', [('../escape', tarfile.REGTYPE),
                                      ('package/link', tarfile.SYMTYPE)])
def test_rejects_unsafe_members(tmp_path, name, kind):
    member = tarfile.TarInfo(name)
    member.type = kind
    member.linkname = '/etc/passwd' if kind == tarfile.SYMTYPE else ''
    archive, checksum = make_archive(tmp_path, {'a': b'content'}, member)
    with pytest.raises(ValueError, match='Unsafe'):
        verifier.verify(archive, checksum, tmp_path / 'out')
    assert not (tmp_path / 'out').exists()


def test_rejects_missing_runtime_file(tmp_path):
    archive, checksum = make_archive(tmp_path, {'MANIFEST.md': b'manifest'})
    with pytest.raises(ValueError, match='Missing required'):
        verifier.verify(archive, checksum, tmp_path / 'out')


def test_rejects_unlisted_file(tmp_path):
    extra = tarfile.TarInfo('package/unlisted.php')
    archive, checksum = make_archive(tmp_path, {'a': b'content'}, extra)
    with pytest.raises(ValueError, match='Internal checksum'):
        verifier.verify(archive, checksum, tmp_path / 'out')


def test_accepts_complete_package(tmp_path):
    names = ('MANIFEST.md', 'RELEASE_NOTES.md', 'gnmi/INFO', 'gnmi/setup.php',
             'gnmi/ajax_handler.php', 'gnmi/include/subscription_actions.php',
             'gnmi/scripts/requirements.txt', 'gnmi/scripts/gnmi_daemon.py')
    archive, checksum = make_archive(tmp_path, {name: b'fixture' for name in names})
    root = verifier.verify(archive, checksum, tmp_path / 'out')
    assert (root / 'gnmi/ajax_handler.php').read_bytes() == b'fixture'

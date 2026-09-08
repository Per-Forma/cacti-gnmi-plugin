#!/usr/bin/env python3
"""Verify and extract one package, without trusting tar paths or checksum paths."""

import argparse
import hashlib
from pathlib import Path, PurePosixPath
import re
import tarfile


def digest(path):
    with path.open('rb') as stream:
        return hashlib.file_digest(stream, 'sha256').hexdigest()


def checksums(text):
    result = {}
    for line in text.splitlines():
        match = re.fullmatch(r'([0-9a-f]{64}) [ *](.+)', line)
        if not match:
            raise ValueError('Malformed checksum entry')
        name = match[2]
        path = PurePosixPath(name)
        if path.is_absolute() or '..' in path.parts or str(path) in result:
            raise ValueError('Unsafe or duplicate checksum path')
        result[str(path)] = match[1]
    if not result:
        raise ValueError('Empty checksum manifest')
    return result


def verify(archive, checksum, destination):
    expected = checksums(checksum.read_text())
    if expected != {archive.name: digest(archive)}:
        raise ValueError('Archive checksum mismatch')
    if destination.exists():
        raise ValueError('Extraction destination must not exist')
    with tarfile.open(archive, 'r:gz') as tar:
        members = tar.getmembers()
        roots, names = set(), set()
        for member in members:
            path = PurePosixPath(member.name)
            if (path.is_absolute() or '..' in path.parts or not path.parts
                    or not (member.isdir() or member.isfile()) or str(path) in names):
                raise ValueError('Unsafe or duplicate archive member')
            roots.add(path.parts[0])
            names.add(str(path))
        if len(roots) != 1:
            raise ValueError('Expected one package root')
        # Members were checked above; links and special files are forbidden.
        destination.mkdir(parents=True)
        tar.extractall(destination, filter='data')
    root = destination / roots.pop()
    manifest = checksums((root / 'CHECKSUMS.txt').read_text())
    actual = {str(p.relative_to(root)): digest(p) for p in root.rglob('*')
              if p.is_file() and p != root / 'CHECKSUMS.txt'}
    if actual != manifest:
        raise ValueError('Internal checksum mismatch or unlisted files')
    for name in ('MANIFEST.md', 'RELEASE_NOTES.md', 'gnmi/INFO', 'gnmi/setup.php',
                 'gnmi/ajax_handler.php', 'gnmi/include/subscription_actions.php',
                 'gnmi/scripts/requirements.txt', 'gnmi/scripts/gnmi_daemon.py'):
        if name not in actual:
            raise ValueError('Missing required package file: ' + name)
    return root


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('archive', type=Path)
    parser.add_argument('checksum', type=Path)
    parser.add_argument('destination', type=Path)
    args = parser.parse_args()
    print(verify(args.archive, args.checksum, args.destination))

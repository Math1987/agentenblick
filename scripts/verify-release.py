#!/usr/bin/env python3
"""Inspect the distributable, never extract it or print credential matches."""
import argparse
import hashlib
import json
from pathlib import Path, PurePosixPath
import re
import stat
import zipfile


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('archive', type=Path)
    parser.add_argument('--secrets-env', type=Path, help='Optional local credentials file, read only for exact-value exclusion checks')
    arguments = parser.parse_args()
    payload = arguments.archive.read_bytes()
    forbidden = {'.git', '.github', '.local', '.tools', 'node_modules', 'vendor', 'dev', 'tests', 'scripts', 'handoffs', 'releases', '__pycache__'}
    with zipfile.ZipFile(arguments.archive) as archive:
        names = archive.namelist()
        assert len(names) == len(set(names)), 'Duplicate archive member'
        files = {}
        for info in archive.infolist():
            path = PurePosixPath(info.filename)
            assert not path.is_absolute() and '..' not in path.parts and '\\' not in info.filename, 'Unsafe member path'
            assert path.parts[0] == 'AithosAgentenblick', 'Unexpected package root'
            assert not forbidden.intersection(path.parts), 'Development-only content in package'
            assert not path.name.startswith('.env') and path.suffix not in {'.pem', '.key', '.pyc'}, 'Credential or development filename in package'
            assert not stat.S_ISLNK(info.external_attr >> 16), 'Symlink in package'
            if not info.is_dir():
                files[str(path.relative_to('AithosAgentenblick'))] = archive.read(info)
        assert archive.testzip() is None, 'Archive CRC mismatch'
    metadata = json.loads(files['composer.json'])
    assert metadata['extra']['shopware-plugin-class'] == 'Aithos\\Agentenblick\\AithosAgentenblick'
    assert metadata['license'] == 'MIT' and files['LICENSE']
    assert b'function executeComposerCommands' in files['src/AithosAgentenblick.php']
    assert all(name in files for name in ['README.md', 'README.de.md', 'SECURITY.md', 'CONTRIBUTING.md', 'CHANGELOG.md', 'docs/PRIVACY.en-GB.md', 'docs/PRIVACY.de-DE.md', 'src/Resources/config/plugin.png'])
    public = 'src/Resources/public/administration/'
    assert public + 'js/aithos-agentenblick.js' in files, 'Missing Shopware 6.6 assets'
    assert public + 'css/aithos-agentenblick.css' in files
    manifest = json.loads(files[public + '.vite/manifest.json'])
    for entry in manifest.values():
        for asset in [entry.get('file'), *entry.get('css', [])]:
            if asset:
                assert public + asset in files, 'Missing manifest asset'
    checksum = json.loads(files['checksum.json'])
    assert checksum['algorithm'] == 'xxh128'
    missing_hashes = set(files) - {'checksum.json'} - set(checksum['hashes'])
    assert not (set(checksum['hashes']) - set(files)), 'Checksum refers to a missing file'
    # shopware-cli excludes generated administration assets from checksum.json.
    # The release SHA-256 below covers the complete archive, including them.
    assert all(name.startswith(public) for name in missing_hashes), 'Source missing from checksum inventory'
    content = b'\n'.join(files.values())
    assert not re.search(rb'-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----', content), 'Private key in package'
    assert not re.search(rb'(?:github_pat_|gh[pousr]_)[A-Za-z0-9_]{20,}', content), 'GitHub credential in package'
    count = 0
    if arguments.secrets_env:
        raw = arguments.secrets_env.read_text()
        values = set(re.findall(r'(?:github_pat_|gh[pousr]_)[A-Za-z0-9_]+', raw))
        for block in re.findall(r'\{[^{}]+\}', raw, re.S):
            try:
                document = json.loads(block)
            except ValueError:
                continue
            for key in ['AccessKeyId', 'SecretAccessKey', 'SessionToken']:
                if isinstance(document.get(key), str) and len(document[key]) >= 16:
                    values.add(document[key])
        for value in values:
            assert value.encode() not in content, 'Local credential material in package'
        count = len(values)
    print(json.dumps({'passed': True, 'archive': arguments.archive.name, 'version': metadata['version'], 'bytes': len(payload), 'files': len(files), 'sha256': hashlib.sha256(payload).hexdigest(), 'legacyAndViteAssetsPresent': True, 'checksumSourceInventoryComplete': True, 'compiledAssetsOutsideCliChecksum': len(missing_hashes), 'developmentContentExcluded': True, 'exactLocalCredentialValuesExcluded': count}, indent=2))


if __name__ == '__main__':
    main()

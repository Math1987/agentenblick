#!/usr/bin/env python3
"""Compare a qualified plugin ZIP with a documentation-only rebuild, without extraction.

PHP, resources, administration source, dependency constraints, autoloading and
plugin identity must be identical. Compiled assets may be renamed only when
their bytes and their normalized manifest links remain identical. This checks
equivalence, not checksum.json validity; run verify-release.py separately.
"""
import argparse
from collections import Counter
import hashlib
import json
from pathlib import Path, PurePosixPath
import stat
import zipfile


PUBLIC = 'src/Resources/public/administration/'
MANIFESTS = {PUBLIC + '.vite/manifest.json', PUBLIC + '.vite/entrypoints.json'}
DOCUMENTS = {'README.md', 'README.de.md', 'CHANGELOG.md', 'CONTRIBUTING.md', 'SECURITY.md'}
COMPOSER_METADATA = {'description', 'authors', 'keywords', 'homepage', 'support'}
EXTRA_METADATA = {'label', 'description', 'manufacturerLink', 'supportLink'}


class ComparisonError(ValueError):
    pass


def require(condition, message):
    if not condition:
        raise ComparisonError(message)


def digest(content):
    return hashlib.sha256(content).hexdigest()


def read_archive(source):
    files = {}
    with zipfile.ZipFile(source) as archive:
        infos = archive.infolist()
        require(len(infos) <= 10000 and sum(item.file_size for item in infos) <= 100 * 1024 * 1024,
                'Archive exceeds comparison limits')
        require(len({item.filename for item in infos}) == len(infos), 'Duplicate archive member')
        for item in infos:
            path = PurePosixPath(item.filename)
            require(path.parts and not path.is_absolute() and '..' not in path.parts and '\\' not in item.filename,
                    'Unsafe archive member')
            require(path.parts[0] == 'AithosAgentenblick', 'Unexpected plugin root')
            require(not stat.S_ISLNK(item.external_attr >> 16), 'Archive symlinks are forbidden')
            if not item.is_dir():
                relative = str(path.relative_to('AithosAgentenblick'))
                require(relative not in files, 'Duplicate normalized archive member')
                files[relative] = archive.read(item)
    return files


def document(path):
    return path in DOCUMENTS or path.startswith('docs/')


def composer_runtime(content):
    data = json.loads(content)
    require(isinstance(data, dict), 'Invalid Composer manifest')
    for name in COMPOSER_METADATA:
        data.pop(name, None)
    extra = data.get('extra', {})
    require(isinstance(extra, dict), 'Invalid Composer extra metadata')
    for name in EXTRA_METADATA:
        extra.pop(name, None)
    if not extra:
        data.pop('extra', None)
    return data


def compiled(files):
    assets = {name[len(PUBLIC):]: content for name, content in files.items()
              if name.startswith(PUBLIC) and name not in MANIFESTS}
    require(assets and MANIFESTS.issubset(files), 'Missing compiled assets or manifests')
    referenced = set()

    def asset(path, base=None):
        require(isinstance(path, str), 'Manifest asset reference must be a string')
        relative = path[len(base):] if base and path.startswith(base) else path
        require(relative in assets, 'Manifest links to a missing compiled asset')
        referenced.add(relative)
        parsed = PurePosixPath(relative)
        # Retain asset directory/type while allowing a generated basename change.
        return '@asset:' + str(parsed.parent) + ':' + parsed.suffix + ':' + digest(assets[relative])

    manifest = json.loads(files[PUBLIC + '.vite/manifest.json'])
    require(isinstance(manifest, dict) and manifest, 'Invalid Vite manifest')
    entries = {}
    for name, entry in manifest.items():
        require(isinstance(entry, dict) and isinstance(entry.get('file'), str), 'Invalid Vite entry')
        entries[name] = asset(entry['file'])
    normalized_manifest = {}
    for name, source in manifest.items():
        entry = dict(source)
        entry['file'] = entries[name]
        for field in ['css', 'assets']:
            if field in entry:
                require(isinstance(entry[field], list), 'Invalid Vite asset list')
                entry[field] = [asset(path) for path in entry[field]]
        for field in ['imports', 'dynamicImports']:
            if field in entry:
                require(isinstance(entry[field], list), 'Invalid Vite import list')
                require(all(isinstance(key, str) and key in entries for key in entry[field]), 'Missing Vite import entry')
                entry[field] = [entries[key] if key.startswith('_') else key for key in entry[field]]
        # Vite names generated shared-chunk entries with an underscore prefix.
        key = entries[name] if name.startswith('_') else name
        require(key not in normalized_manifest, 'Ambiguous normalized Vite entry')
        normalized_manifest[key] = entry

    entrypoints = json.loads(files[PUBLIC + '.vite/entrypoints.json'])
    require(isinstance(entrypoints, dict) and isinstance(entrypoints.get('base'), str)
            and isinstance(entrypoints.get('entryPoints'), dict), 'Invalid entrypoints manifest')
    for entry in entrypoints['entryPoints'].values():
        require(isinstance(entry, dict), 'Invalid administration entrypoint')
        for field in ['css', 'js', 'dynamic', 'preload']:
            if field in entry:
                require(isinstance(entry[field], list), 'Invalid entrypoint asset list')
                entry[field] = [asset(path, entrypoints['base']) for path in entry[field]]

    inventory = Counter((str(PurePosixPath(name).parent), PurePosixPath(name).suffix, digest(value))
                        for name, value in assets.items())
    # Legacy entrypoints and any assets outside the manifests keep their exact
    # paths. They may be requested directly by Shopware or another asset.
    fixed = {name: digest(value) for name, value in assets.items() if name not in referenced}
    return {'inventory': inventory, 'fixed': fixed, 'manifest': normalized_manifest,
            'entrypoints': entrypoints, 'assets': assets}


def compare(qualified, candidate):
    errors = []
    allowed = []
    compared = 0
    all_paths = sorted(set(qualified) | set(candidate))
    for name in all_paths:
        if name == 'checksum.json' or document(name):
            if qualified.get(name) != candidate.get(name):
                allowed.append(name)
            continue
        if name == 'composer.json' or name.startswith(PUBLIC):
            continue
        compared += 1
        if qualified.get(name) != candidate.get(name):
            errors.append('Runtime file changed or missing: ' + name)
    require('composer.json' in qualified and 'composer.json' in candidate, 'Missing Composer manifest')
    if composer_runtime(qualified['composer.json']) != composer_runtime(candidate['composer.json']):
        errors.append('Composer runtime configuration changed')
    elif qualified['composer.json'] != candidate['composer.json']:
        allowed.append('composer.json (metadata/format only)')
    original = compiled(qualified)
    rebuilt = compiled(candidate)
    for field, description in [('inventory', 'Compiled asset contents/counts changed'),
                               ('fixed', 'Unmanifested/legacy asset paths changed'),
                               ('manifest', 'Normalized Vite manifest changed'),
                               ('entrypoints', 'Normalized entrypoints manifest changed')]:
        if original[field] != rebuilt[field]:
            errors.append(description)
    old_names = set(original['assets'])
    new_names = set(rebuilt['assets'])
    return {'passed': not errors, 'runtimeFilesCompared': compared,
            'compiledAssetFiles': len(original['assets']), 'normalizedManifestLinksEqual': original['manifest'] == rebuilt['manifest'] and original['entrypoints'] == rebuilt['entrypoints'],
            'allowedChanges': allowed, 'assetNamesRemoved': sorted(old_names - new_names),
            'assetNamesAdded': sorted(new_names - old_names), 'errors': errors}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('qualified', type=Path)
    parser.add_argument('candidate', type=Path)
    args = parser.parse_args()
    try:
        report = compare(read_archive(args.qualified), read_archive(args.candidate))
        report.update(qualifiedSha256=digest(args.qualified.read_bytes()), candidateSha256=digest(args.candidate.read_bytes()))
    except (ComparisonError, ValueError, OSError, KeyError, TypeError, zipfile.BadZipFile):
        # Never echo parsed metadata or arbitrary archive content on failure.
        report = {'passed': False, 'errors': ['Invalid or unsupported archive/manifest structure']}
    print(json.dumps(report, sort_keys=True, separators=(',', ':')))
    return 0 if report['passed'] else 1


if __name__ == '__main__':
    raise SystemExit(main())

#!/usr/bin/env python3
"""Explicit preparation/run/restore for the isolated native demo bench only."""
import argparse
import datetime
import fcntl
import hashlib
import json
import math
import os
from pathlib import Path
import pwd
import re
import secrets
import shutil
import signal
import statistics
import subprocess
import sys
import time

HERE = Path(__file__).resolve().parent
BASE = Path('/srv/agentenblick-demo')
STATE = Path('/var/lib/agentenblick-perf-native')
ORIGIN = 'https://demo-shop.aithos.app'
PLUGIN = 'custom/plugins/AithosAgentenblick'
POPULATIONS = [('human_or_unknown', 'Mozilla/5.0 AgentenblickNativeBench/1.0'),
               ('declared_agent', 'GPTBot/1.0')]
METHODS = {
    'public/index.php': {},
    f'{PLUGIN}/src/Capture/ObservationSubscriber.php': {
        **{name: 'pre' for name in ['begin', 'onRequest', 'complete', 'onResponse', 'offersSessionCookie']},
        'onTerminate': 'post', 'flush': 'post'},
    f'{PLUGIN}/src/Journey/EventSubscriber.php': {name: 'pre' for name in [
        'onSearch', 'onSuggest', 'onProduct', 'recordProduct', 'recordProductResponse', 'onCart',
        'onCheckout', 'onFinish', 'onOrder', 'onException', 'observe']},
    f'{PLUGIN}/src/Persistence/DailyKey.php': {'current': 'daily_key'},
    f'{PLUGIN}/src/Verification/Classifier.php': {'classify': 'classifier'},
    f'{PLUGIN}/src/Persistence/ObservationStore.php': {'record': 'store'},
    f'{PLUGIN}/src/Health/Observation.php': {'prepare': 'health_prepare', 'record': 'health_record'},
    f'{PLUGIN}/src/Persistence/ProxyDiagnostics.php': {'record': 'proxy'},
    f'{PLUGIN}/src/Verification/CatalogueProvider.php': {'load': 'catalogue'},
}


class Failure(RuntimeError):
    pass


def now():
    return datetime.datetime.now(datetime.timezone.utc).isoformat()


def digest(data):
    return hashlib.sha256(data).hexdigest()


def write_json(path, value):
    path.write_text(json.dumps(value, indent=2) + '\n')
    path.chmod(0o600)


def command(argv, *, cwd=None, user=False, timeout=180):
    if user:
        argv = ['runuser', '-u', 'www-data', '--', *map(str, argv)]
    try:
        result = subprocess.run(argv, cwd=cwd, capture_output=True, timeout=timeout, check=False)
    except (OSError, subprocess.TimeoutExpired):
        raise Failure('Command unavailable or timed out; inspect the bench privately.') from None
    if result.returncode:
        # Symfony/DB exceptions may contain credentials. Never copy their output.
        raise Failure(f'Command failed ({Path(str(argv[-1])).name if len(argv) == 1 else "exit " + str(result.returncode)}); inspect the bench privately.')
    return result.stdout


def resources(root):
    memory = {}
    for line in Path('/proc/meminfo').read_text().splitlines():
        key, value = line.split(':', 1)
        if key in ['MemAvailable', 'SwapTotal', 'SwapFree']:
            memory[key] = int(value.strip().split()[0]) * 1024
    result = {'diskFreeBytes': shutil.disk_usage(root).free, **memory, 'load1': os.getloadavg()[0]}
    if result['diskFreeBytes'] < 3 * 1024 ** 3 or result.get('MemAvailable', 0) < 256 * 1024 ** 2:
        raise Failure('Resource floor reached: require 3 GiB disk and 256 MiB available RAM.')
    return result


def app_root(value):
    root = Path(value).resolve(strict=True)
    if not root.is_relative_to(BASE.resolve()) or root == BASE.resolve():
        raise Failure('Only an explicitly isolated root below /srv/agentenblick-demo is allowed.')
    if not (root / 'bin/console').is_file() or not (root / 'public/index.php').is_file():
        raise Failure('Expected native Shopware root is missing.')
    return root


def bridge(root, database, action='preflight'):
    bridge_path = HERE / 'bridge.php'
    readable = subprocess.run(['runuser', '-u', 'www-data', '--', 'test', '-r', str(bridge_path)],
                              stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    if readable.returncode != 0:
        raise Failure('www-data cannot read the native bridge; make the kit parent directories traversable and bridge.php readable before preparation.')
    raw = command(['php', bridge_path, root, database, action], cwd=root, user=True)
    return json.loads(raw)


def preflight(root, database):
    if not re.fullmatch(r'[A-Za-z0-9_]{1,64}', database):
        raise Failure('An explicit expected local database name is required.')
    result = bridge(root, database)
    if result['environment'] != 'prod' or result['debug'] or result['testCatalogueConfigured']:
        raise Failure('Require APP_ENV=prod, APP_DEBUG=0 and the unchanged production catalogue.')
    if not result['pluginActive'] or not result['offlineInDatabase'] or result['heuristicsEnabled'] or result['benchmarkEnabled'] or result['pendingWithdrawal']:
        raise Failure('Require active plugin, offline explicitly stored, heuristics/benchmark off and no pending withdrawal.')
    if not result['phpCli'].startswith('8.3.') or not result['mysql'].startswith('8.') or result['productionCdnRanges'] < 1:
        raise Failure('This protocol expects PHP 8.3, MySQL 8 and a nonempty production CDN catalogue.')
    result['resources'] = resources(root)
    locked = json.loads((root / 'composer.lock').read_text())
    result['shopwareLockVersion'] = next((item['version'] for item in locked.get('packages', []) if item['name'] == 'shopware/core'), 'unknown')
    result['catalogueSha256'] = digest((root / PLUGIN / 'src/Resources/catalog/agents.json').read_bytes())
    result['target'] = ORIGIN
    result['connection'] = 'TLS hostname fixed; TCP connection forced to 127.0.0.1; no redirect'
    return result


def target_path(root, relative):
    if relative not in METHODS:
        raise Failure('Manifest target is not allowlisted.')
    root = root.resolve(strict=True)
    target = root / relative
    if relative == 'public/index.php':
        expected = root / relative
    else:
        # Atomic deployments share precisely this plugin directory between releases.
        # Do not extend this exception to arbitrary shared files or symlink targets.
        expected = BASE.resolve() / 'shared' / PLUGIN / Path(relative).relative_to(PLUGIN)
    resolved = target.resolve(strict=True)
    if resolved != expected or target.is_symlink():
        raise Failure('Instrumentation target does not match its exact release/shared allowlist.')
    return resolved


def assert_layout(manifest, root):
    root = root.resolve(strict=True)
    if Path(manifest['appPath']).resolve(strict=True) != root:
        raise Failure('The application deployment changed; refusing to modify or measure another release.')
    for target in manifest['targets']:
        if target_path(root, target['relative']) != Path(target['path']):
            raise Failure('The resolved instrumentation target changed since preparation.')


def atomic_write(path, content, mode, uid, gid):
    temporary = path.with_name(path.name + '.native-perf-' + secrets.token_hex(6))
    try:
        with temporary.open('xb') as stream:
            stream.write(content)
            stream.flush()
            os.fsync(stream.fileno())
        os.chmod(temporary, mode)
        os.chown(temporary, uid, gid)
        os.replace(temporary, path)
    finally:
        temporary.unlink(missing_ok=True)


def prepare(args):
    root = app_root(args.app_root)
    info = preflight(root, args.database)
    group = pwd.getpwnam('www-data').pw_gid
    directory = STATE / (datetime.datetime.now().strftime('%Y%m%dT%H%M%S') + '-' + secrets.token_hex(5))
    directory.mkdir(mode=0o710)
    os.chown(directory, 0, group)
    directory.chmod(0o710)
    (directory / 'backups').mkdir(mode=0o700)
    (directory / 'prepared').mkdir(mode=0o700)
    token, probe_path = secrets.token_hex(32), secrets.token_hex(16)
    output = directory / 'samples.jsonl'
    output.touch(mode=0o600)
    os.chown(output, pwd.getpwnam('www-data').pw_uid, group)
    helper = HERE.joinpath('probe.php').read_text().replace('__TOKEN__', token).replace('__PATH__', probe_path).replace('__OUTPUT__', str(output)).replace('__PHASE__', args.phase)
    (directory / 'probe.php').write_text(helper)
    os.chmod(directory / 'probe.php', 0o640)
    os.chown(directory / 'probe.php', 0, group)
    if args.phase == 'terminate':
        selected = {'public/index.php': {}}
    elif args.phase == 'kernel-terminate':
        selected = {'public/index.php': {}, f'{PLUGIN}/src/Capture/ObservationSubscriber.php': {'onTerminate': 'post'}}
    else:
        selected = METHODS
    targets = []
    for index, (relative, methods) in enumerate(selected.items()):
        path = target_path(root, relative)
        content, stat = path.read_bytes(), path.stat()
        if b'AgentenblickNativeProbe' in content or b'AgentenblickPhaseProbe' in content:
            raise Failure('Existing instrumentation detected; restore it before preparation.')
        backup = directory / 'backups' / f'{index}.php'
        backup.write_bytes(content)
        backup.chmod(0o600)
        targets.append({'relative': relative, 'path': str(path), 'methods': methods,
                        'backup': str(backup), 'prepared': str(directory / 'prepared' / f'{index}.php'),
                        'originalSha256': digest(content), 'mode': stat.st_mode & 0o777, 'uid': stat.st_uid, 'gid': stat.st_gid})
    write_json(directory / 'instrument-input.json', {'helper': str(directory / 'probe.php'), 'targets': targets})
    command(['php', '-l', directory / 'probe.php'])
    command(['php', HERE / 'instrument.php', directory / 'instrument-input.json'])
    for target in targets:
        candidate = Path(target['prepared'])
        candidate.chmod(0o600)
        command(['php', '-l', candidate])
        target['instrumentedSha256'] = digest(candidate.read_bytes())
    manifest = {'version': 2, 'phase': args.phase, 'preparedAt': now(), 'state': 'prepared',
                'appRoot': str(root), 'appPath': str(Path(args.app_root).absolute()),
                'database': args.database, 'token': token, 'probePath': probe_path,
                'originalPluginActive': True, 'preflight': info, 'targets': targets}
    write_json(directory / 'manifest.json', manifest)
    print(json.dumps({'prepared': str(directory / 'manifest.json'), 'runtimeModified': False, 'phase': args.phase}))


def load_manifest(value):
    path = Path(value).resolve(strict=True)
    if path.name != 'manifest.json' or path.parent.parent != STATE or path.stat().st_uid != 0 or path.stat().st_mode & 0o077:
        raise Failure('Require a root-owned private manifest from this kit.')
    data = json.loads(path.read_text())
    root = app_root(data['appRoot'])
    if data.get('version') != 2 or data.get('phase') not in ['terminate', 'kernel-terminate', 'diagnostic', 'abab']:
        raise Failure('Unsupported manifest.')
    assert_layout(data, root)
    for target in data['targets']:
        if str(target_path(root, target['relative'])) != target['path']:
            raise Failure('Manifest target differs from the allowed native root.')
        for key in ['backup', 'prepared']:
            if not Path(target[key]).resolve().is_relative_to(path.parent):
                raise Failure('Manifest material is outside its private directory.')
    return path, data, root


def reload_fpm():
    command(['systemctl', 'reload', 'php8.3-fpm'], timeout=60)
    time.sleep(1)


def plugin_state(root, active):
    command(['php', 'bin/console', 'plugin:activate' if active else 'plugin:deactivate', 'AithosAgentenblick', '--no-interaction', '--no-ansi'], cwd=root, user=True)
    command(['php', 'bin/console', 'cache:clear', '--no-interaction', '--no-ansi'], cwd=root, user=True)
    reload_fpm()


def restore(path, manifest, root):
    errors = []
    try:
        assert_layout(manifest, root)
    except (Failure, OSError):
        manifest['state'] = 'restoration_incomplete'
        manifest['restoredAt'] = now()
        write_json(path, manifest)
        return {'sourceHashesMatch': False, 'pluginActiveRestored': False,
                'errors': ['Deployment paths changed; restore the saved exact targets manually after reconciliation.']}
    for target in manifest['targets']:
        try:
            current = Path(target['path'])
            content = Path(target['backup']).read_bytes()
            if digest(content) != target['originalSha256']:
                raise Failure('Backup checksum mismatch.')
            if digest(current.read_bytes()) not in [target['originalSha256'], target['instrumentedSha256']]:
                raise Failure('Target changed externally; manual reconciliation required.')
            atomic_write(current, content, target['mode'], target['uid'], target['gid'])
        except Exception:
            errors.append('Could not safely restore ' + target['relative'])
    if not errors:
        try:
            current = bridge(root, manifest['database'])
            if current['pluginActive'] != manifest['originalPluginActive']:
                plugin_state(root, manifest['originalPluginActive'])
            else:
                reload_fpm()
        except Exception:
            errors.append('Plugin activation or PHP-FPM reload not confirmed.')
    try:
        matched = all(digest(Path(t['path']).read_bytes()) == t['originalSha256'] for t in manifest['targets'])
    except OSError:
        matched = False
        errors.append('Source restoration checksums could not be verified.')
    manifest['state'] = 'restored' if matched and not errors else 'restoration_incomplete'
    manifest['restoredAt'] = now()
    write_json(path, manifest)
    return {'sourceHashesMatch': matched, 'pluginActiveRestored': not errors, 'errors': errors}


def request(manifest, stage, temperature, population, sample, delay=None, kernel=False):
    suffix = '/' if delay is None or kernel else '/_agentenblick-fpm-' + manifest['probePath']
    marker = '\nAGENTENBLICK-METRICS-' + secrets.token_hex(8) + ' '
    headers = [f'X-Agentenblick-Native-Run: {manifest["token"]}',
               f'X-Agentenblick-Native-Sample: {stage}:{temperature}:{population}:{sample}',
               'Accept: text/html', 'Accept-Language: en-GB']
    if delay is not None:
        headers.append('X-Agentenblick-Native-Delay: ' + str(delay))
    argv = ['curl', '--silent', '--show-error', '--noproxy', '*', '--resolve',
            'demo-shop.aithos.app:443:127.0.0.1', '--proto', '=https', '--max-redirs', '0',
            '--connect-timeout', '3', '--max-time', '15', '--max-filesize', '2097152',
            '--user-agent', POPULATIONS[population][1]]
    for header in headers:
        argv += ['--header', header]
    argv += ['--write-out', marker + '%{http_code} %{time_total}', ORIGIN + suffix]
    raw = command(argv, timeout=20)
    body, separator, metadata = raw.rpartition(marker.encode())
    if not separator:
        raise Failure('Missing bounded client timing.')
    status, seconds = metadata.decode().strip().split()
    result = {'status': int(status), 'clientMs': float(seconds) * 1000, 'bodyBytes': len(body), 'bodySha256': digest(body)}
    if delay is not None and not kernel:
        proof = json.loads(body)
        if proof != {'sample': sample, 'delayMs': delay, 'fpm': True}:
            raise Failure('FPM flush endpoint did not return the expected numeric fixture proof.')
    return result


def shutdown_row(directory, sample):
    deadline = time.monotonic() + 10
    while time.monotonic() < deadline:
        content = (directory / 'samples.jsonl').read_text()
        complete = content[:content.rfind('\n') + 1]
        rows = [json.loads(line) for line in complete.splitlines() if line]
        found = [row for row in rows if row['sample'] == sample]
        if len(found) == 1:
            if any(type(value) is not int for value in found[0].values()) or found[0]['open_timers'] or found[0]['fpm'] != 1 or not 80300 <= found[0].get('php_version_id', 0) < 80400:
                raise Failure('Non-numeric, unclosed or non-FPM instrumentation record.')
            return found[0]
        if len(found) > 1:
            raise Failure('Duplicate shutdown record.')
        time.sleep(.02)
    raise Failure('No completed FPM shutdown record; no timing conclusion is valid.')


def stats(values):
    if not values:
        return None
    values = sorted(values)
    return {'count': len(values), 'medianMs': statistics.median(values), 'p95Ms': values[math.ceil(.95 * len(values)) - 1], 'maxMs': values[-1]}


def summarize(rows):
    if not rows:
        return None
    labels = ['pre', 'post', 'daily_key', 'classifier', 'store', 'health_prepare', 'health_record', 'proxy', 'catalogue']
    return {**{label: stats([row[label + '_ns'] / 1e6 for row in rows]) for label in labels},
            'client': stats([row['clientMs'] for row in rows])}


def measure(path, manifest, root):
    if manifest['state'] != 'prepared':
        raise Failure('Each prepared manifest can run only once; prepare a new experiment.')
    info = preflight(root, manifest['database'])
    report = {'startedAt': now(), 'phase': manifest['phase'], 'preflight': info, 'stages': [], 'errors': [],
              'populations': [name for name, _ua in POPULATIONS], 'qualifiedProductionBudgets': False,
              'sourceSha256': {target['relative']: target['originalSha256'] for target in manifest['targets']},
              'limitations': ['Only human/unknown and declared-agent synthetic populations; no verified IP, signature, rDNS or cold directory cost.',
                              'Pre timers exclude dependency injection, service construction, autoload and core event dispatch: the complete 0.5 ms target is not measured.',
                              'Post timers cover selected onTerminate/flush work, inclusive child timers must not be added together.',
                              'Local HTTPS homepage, sequential warmed sessions; not capacity, checkout or real-shop evidence.',
                              'Synthetic observations are retained in this disposable database; no implicit reset or SQL cleanup occurs.',
                              'The terminate phase checks fastcgi_finish_request only; kernel-terminate injects its delay inside the real plugin onTerminate method.']}
    directory = path.parent
    def interrupted(_signum, _frame):
        raise Failure('Interrupted; restoring targeted sources and original activation.')
    signal.signal(signal.SIGINT, interrupted)
    signal.signal(signal.SIGTERM, interrupted)
    mutated = False
    try:
        assert_layout(manifest, root)
        for target in manifest['targets']:
            if digest(Path(target['path']).read_bytes()) != target['originalSha256'] or digest(Path(target['prepared']).read_bytes()) != target['instrumentedSha256']:
                raise Failure('Source changed since preparation; refusing to install instrumentation.')
        manifest['state'] = 'running'
        write_json(path, manifest)
        mutated = True
        for target in manifest['targets']:
            atomic_write(Path(target['path']), Path(target['prepared']).read_bytes(), target['mode'], target['uid'], target['gid'])
        reload_fpm()
        if manifest['phase'] in ['terminate', 'kernel-terminate']:
            kernel = manifest['phase'] == 'kernel-terminate'
            before = bridge(root, manifest['database'], 'counters')
            warmups = []
            if kernel:
                for sample in range(4):
                    assert_layout(manifest, root)
                    client = request(manifest, 0, 1, 0, sample, 0, kernel=True)
                    row = shutdown_row(directory, sample)
                    if client['status'] != 200 or row['post_calls'] < 1:
                        raise Failure('The real Shopware termination callback was not observed during warmup.')
                    warmups.append({**row, **client})
            report['warmups'] = warmups
            samples = []
            for sample, delay in enumerate([0, 2000] * 5, start=len(warmups)):
                assert_layout(manifest, root)
                client = request(manifest, 0, 2, 0, sample, delay, kernel=kernel)
                row = shutdown_row(directory, sample)
                samples.append({**row, **client, 'delayMs': delay})
            delayed = [row for row in samples if row['delayMs'] == 2000]
            report['samples'] = samples
            delay_field = 'kernel_delay_ns' if kernel else 'flush_delay_ns'
            passed = all(row['status'] == 200 and row['clientMs'] < 1000 and row.get(delay_field, 0) >= 1900000000 for row in delayed)
            passed = passed and all(row['status'] == 200 and row['clientMs'] < 1000 for row in samples)
            if kernel:
                bodies = {(row['bodyBytes'], row['bodySha256']) for row in samples}
                passed = passed and len(bodies) == 1 and all(row['post_calls'] >= 1 for row in samples)
                passed = passed and all(row['post_ns'] >= row['kernel_delay_ns'] >= 1900000000 for row in delayed)
                passed = passed and all(row['kernel_delay_ns'] == 0 for row in samples if row['delayMs'] == 0)
                report['kernelTerminateReleasedClient'] = passed
                report['identicalMeasuredStatusBody'] = len(bodies) == 1
            else:
                report['flushReleasedClient'] = passed
            after = bridge(root, manifest['database'], 'counters')
            changes = {key: after.get(key, 0) - before.get(key, 0) for key in before.keys() | after.keys()}
            changes = {key: value for key, value in changes.items() if value}
            if changes != ({'none': 14} if kernel else {}):
                raise Failure('Unexpected traffic during the response-release check.')
            report['observedCounts'] = changes
            if not passed:
                raise Failure('Client release before the two-second worker delay was not established.')
        else:
            active_states = [True] if manifest['phase'] == 'diagnostic' else [False, True, False, True]
            count = 40 if manifest['phase'] == 'diagnostic' else 1000
            sample = 0
            for stage, active in enumerate(active_states):
                assert_layout(manifest, root)
                if manifest['phase'] == 'abab':
                    plugin_state(root, active)
                before = bridge(root, manifest['database'], 'counters')
                rows = []
                for index in range(count + 24):
                    assert_layout(manifest, root)
                    if index % 10 == 0:
                        report['lastResourceCheck'] = resources(root)
                    temperature = 0 if index < 4 else (1 if index < 24 else 2)
                    population = index % len(POPULATIONS)
                    client = request(manifest, stage, temperature, population, sample)
                    row = {**shutdown_row(directory, sample), **client}
                    if row['status'] != 200 or bool(row['pre_calls']) != active or bool(row['post_calls']) != active:
                        raise Failure('HTTP response or active/inactive instrumentation does not match the stage.')
                    rows.append(row)
                    sample += 1
                after = bridge(root, manifest['database'], 'counters')
                changes = {key: after.get(key, 0) - before.get(key, 0) for key in before.keys() | after.keys()}
                changes = {key: value for key, value in changes.items() if value}
                expected = {'none': (count + 24) // 2, 'declared_unverified': (count + 24) // 2} if active else {}
                if changes != expected:
                    raise Failure('Unexpected evidence totals: concurrent traffic or fixture mismatch invalidates the stage.')
                measured = [row for row in rows if row['temperature'] == 2]
                stage_report = {'stage': stage, 'active': active, 'metrics': summarize(measured), 'observedCounts': changes,
                                'populations': {name: summarize([row for row in measured if row['population'] == i]) for i, (name, _ua) in enumerate(POPULATIONS)},
                                'samples': rows}
                report['stages'].append(stage_report)
                write_json(directory / 'report.json', report)
                print(json.dumps({'stage': stage, 'active': active, 'post': stage_report['metrics']['post']}), flush=True)
            bodies = {(row['status'], row['bodyBytes'], row['bodySha256']) for stage in report['stages'] for row in stage['samples'] if row['temperature'] == 2}
            report['identicalMeasuredStatusBody'] = len(bodies) == 1
            if len(bodies) != 1:
                raise Failure('Homepage response varied; matched-response comparison was not established.')
            report['selectedPostMethodsBelow5MsForEachPopulation'] = all(metrics['post']['p95Ms'] < 5 for stage in report['stages'] if stage['active'] for metrics in stage['populations'].values())
    except BaseException as error:
        report['errors'].append(str(error) if isinstance(error, Failure) else type(error).__name__)
    finally:
        signal.signal(signal.SIGINT, signal.SIG_IGN)
        signal.signal(signal.SIGTERM, signal.SIG_IGN)
        if mutated:
            # Any controlled request has at most two seconds of artificial delay.
            time.sleep(2.2)
            try:
                report['restoration'] = restore(path, manifest, root)
                report['errors'] += report['restoration']['errors']
            except Exception:
                report['restoration'] = {'sourceHashesMatch': False, 'pluginActiveRestored': False}
                report['errors'].append('Restoration could not finish; use the private manifest for explicit recovery.')
        else:
            report['restoration'] = {'runtimeWasNotModified': True}
        report['finishedAt'] = now()
        report['experimentComplete'] = not report['errors']
        write_json(directory / 'report.json', report)
        print(json.dumps({'report': str(directory / 'report.json'), 'experimentComplete': report['experimentComplete'],
                          'qualifiedProductionBudgets': False, 'restoration': report['restoration']}), flush=True)
    return 0 if report['experimentComplete'] else 1


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('action', choices=['preflight', 'prepare', 'run', 'restore'])
    parser.add_argument('--app-root', default='/srv/agentenblick-demo/current')
    parser.add_argument('--database')
    parser.add_argument('--phase', choices=['terminate', 'kernel-terminate', 'diagnostic', 'abab'])
    parser.add_argument('--manifest')
    parser.add_argument('--ack-isolated-bench', action='store_true')
    args = parser.parse_args()
    if sys.platform != 'linux' or os.geteuid() != 0:
        raise Failure('Run on the isolated Linux bench as root; no Docker or SSH is invoked by this kit.')
    if args.action in ['preflight', 'prepare'] and not args.database:
        parser.error('--database is required')
    if args.action == 'preflight':
        print(json.dumps(preflight(app_root(args.app_root), args.database), indent=2))
        return 0
    if not args.ack_isolated_bench:
        parser.error('Mutating commands require --ack-isolated-bench for the dedicated demo host.')
    if args.action == 'prepare' and not args.phase:
        parser.error('prepare requires --phase')
    if args.action in ['run', 'restore'] and not args.manifest:
        parser.error('run/restore require --manifest')
    STATE.mkdir(mode=0o711, parents=True, exist_ok=True)
    if STATE.is_symlink() or STATE.stat().st_uid != 0 or STATE.stat().st_mode & 0o022:
        raise Failure('Private state directory must be root-owned and not writable by group/others.')
    STATE.chmod(0o711)
    with (STATE / 'active.lock').open('a') as lock:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        if args.action == 'prepare':
            prepare(args)
            return 0
        path, manifest, root = load_manifest(args.manifest)
        if args.action == 'restore':
            result = restore(path, manifest, root)
            print(json.dumps(result))
            return 0 if not result['errors'] else 1
        return measure(path, manifest, root)


if __name__ == '__main__':
    try:
        sys.exit(main())
    except Exception as error:
        print(json.dumps({'error': str(error) if isinstance(error, Failure) else type(error).__name__}), file=sys.stderr)
        sys.exit(1)

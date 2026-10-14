#!/usr/bin/env python3
"""Measure selected plugin pre-response/terminate work on agentenblick-shop only.

Run manually on an idle, exclusively reserved bench after code and UI checks:
    python3 scripts/measure-phases.py
Never imported by the plugin. Temporary instrumentation touches container copies
only, is linted before installation, and is restored/hashed in finally. The
public entrypoint records shutdown even while the plugin is disabled. It stores
numeric phase/correlation fields only. This is a cached-page latency experiment,
not a load test or a claim about cold remote key-directory lookups.
"""
import base64
import collections
import datetime
import hashlib
import json
import math
from pathlib import Path
import secrets
import shutil
import signal
import statistics
import subprocess
import sys
import time

ROOT = Path(__file__).resolve().parents[1]
CONTAINER = 'agentenblick-shop'
PLUGIN = 'AithosAgentenblick'
BASE = '/var/www/html'
PREFIX = BASE + '/custom/plugins/' + PLUGIN
CATALOGUE = PREFIX + '/tests/fixtures/catalog.json'
PRODUCT_CATALOGUE = PREFIX + '/src/Resources/catalog/agents.json'
TARGETS = [BASE + '/public/index.php', PREFIX + '/src/Capture/ObservationSubscriber.php', PREFIX + '/src/Journey/EventSubscriber.php', CATALOGUE]
ORIGIN = 'http://127.0.0.1:18081'
POPULATIONS = ['human', 'declared_agent', 'verified_fixture_ip', 'verified_signature_cached_key']
OUTPUT = ROOT / '.local' / 'phases.json'


class MeasurementError(RuntimeError):
    pass


def command(args, label, *, timeout=240, data=None):
    try:
        result = subprocess.run(args, input=data, capture_output=True, timeout=timeout, check=False)
    except (OSError, subprocess.TimeoutExpired) as error:
        raise MeasurementError(f'{label}: {type(error).__name__}') from None
    if result.returncode:
        # Environment and framework exception output can contain secrets.
        raise MeasurementError(f'{label}: exit {result.returncode}')
    return result.stdout


def execute(*args, user='root', timeout=240):
    return command(['docker', 'exec', '-u', user, '-w', BASE, CONTAINER, *args], str(args[0]), timeout=timeout)


def console(*args):
    return execute('php', 'bin/console', *args, '--no-interaction', '--no-ansi', user='www-data').decode().strip()


def copy_into(path, destination):
    command(['docker', 'cp', str(path), CONTAINER + ':' + destination], 'copy disposable file')


def sql(statement):
    return command(['docker', 'exec', '-u', 'root', '-e', 'MYSQL_PWD=root', CONTAINER,
                    'mysql', '--protocol=TCP', '-h127.0.0.1', '-uroot', '--batch', '--raw', '--skip-column-names', 'shopware', '-e', statement], 'disposable database').decode().strip()


def now():
    return datetime.datetime.now(datetime.timezone.utc).isoformat()


def percentile(values):
    ordered = sorted(values)
    return {'count': len(ordered), 'median_ms': statistics.median(ordered), 'p95_ms': ordered[math.ceil(len(ordered) * .95) - 1], 'min_ms': ordered[0], 'max_ms': ordered[-1]}


def summarize(rows):
    if not rows:
        return None
    return {**{name: percentile([row[field] / divisor for row in rows]) for name, field, divisor in [('pre', 'pre_ns', 1e6), ('post', 'post_ns', 1e6), ('client', 'clientMs', 1)]},
            'http_status_counts': dict(collections.Counter(str(row['status']) for row in rows)),
            'response_combinations': [{'status': status, 'body_bytes': size, 'sha256': digest, 'requests': count} for (status, size, digest), count in collections.Counter((row['status'], row['bytes'], row['bodySha256']) for row in rows).items()]}


def counters():
    result = {}
    for row in sql('SELECT evidence, SUM(requests) FROM agentenblick_daily GROUP BY evidence').splitlines():
        if row:
            name, count = row.split('\t')
            result[name] = int(count)
    return result


def directory_calls():
    return int(execute('php', '-r', 'echo is_file("/tmp/agentenblick-directory-calls.log") ? substr_count(file_get_contents("/tmp/agentenblick-directory-calls.log"), "\\n") : 0;').decode())


def state(active):
    console('plugin:activate' if active else 'plugin:deactivate', PLUGIN)
    console('cache:clear')
    execute('apache2ctl', 'graceful')
    time.sleep(1)


def main():
    def interrupted(_signal, _frame):
        raise MeasurementError('Measurement interrupted; restoring disposable bench')
    signal.signal(signal.SIGINT, interrupted)
    signal.signal(signal.SIGTERM, interrupted)
    minimum_free = shutil.disk_usage('/').free
    def disk_guard(_signal, _frame):
        nonlocal minimum_free
        minimum_free = min(minimum_free, shutil.disk_usage('/').free)
        if minimum_free < 3 * 1024 ** 3:
            raise MeasurementError('Host disk below 3 GiB; restoring disposable bench')
    signal.signal(signal.SIGALRM, disk_guard)
    signal.setitimer(signal.ITIMER_REAL, 5, 5)
    run = secrets.token_hex(8)
    temporary = '/tmp/agentenblick-phase-probe-' + run
    backup = ROOT / '.local' / 'phase-probe-backups' / run
    backup.mkdir(parents=True, exist_ok=False)
    report = {'check': 'cached_plugin_phase_cost', 'startedAt': now(), 'run': run, 'container': CONTAINER,
              'stages': [], 'errors': [], 'restoration': {'source_hashes_match': False, 'test_catalogue_restored': False, 'key_cache_restored': False, 'plugin_active': False},
              'method': {'stage_order': ['off-1', 'on-1', 'off-2', 'on-2'], 'measured_requests_per_stage': 1000,
                         'populations': POPULATIONS, 'requests_per_population_per_stage': 250, 'first_requests_per_stage': 4,
                         'warmups_per_stage': 20, 'concurrency': 1, 'p95': 'nearest rank ceil(0.95 * n)',
                         'threshold_ms': {'pre_p95': .5, 'post_p95': 5.0},
                         'measurement': 'hrtime union of nested instrumented plugin methods by phase; JSONL appended at shutdown outside the timers. Client completion is measured separately. Every next request waits for the previous shutdown record, outside client timing.',
                         'instrumented_pre': ['capture begin/onRequest/complete/onResponse/offersSessionCookie', 'Journey callbacks and observe; nested calls counted once'],
                         'instrumented_post': ['capture onTerminate/flush; nested and duplicate no-op calls counted without overlapping time'],
                         'limitations': ['The first four requests after cache clear and Apache graceful reload are labelled first-after-clear, not a guaranteed cold OS/MySQL cache.',
                                         'Warmed homepage only; no capacity claim or checkout/product-route benchmark.',
                                         'Synthetic fixture identities and test-mode catalogue; no genuine provider traffic.',
                                         'Signed requests use a preseeded public key cache; no remote directory, rDNS or catalogue refresh cost is represented.',
                                         'Instrumentation overhead is included, not subtracted; core event dispatch and outer decorator bookkeeping are not included in the selected plugin phase timers.',
                                         'Dependency injection, service construction and autoload before the first instrumented method are outside the phase timers; the client off/on comparison includes their effect.',
                                         'Health token checks inside capture are included; public probes are not triggered.',
                                         'Client off/on differences include ordinary local scheduling variance and cannot alone establish a causal regression.']}}
    originals = []
    old_cache = None
    cache_saved = False
    initialized = False
    try:
        disk_guard(None, None)
        info = json.loads(command(['docker', 'inspect', CONTAINER], 'inspect fixed disposable container'))[0]
        if not info['State']['Running'] or info['Config']['Image'] != 'dockware/shopware:6.7.15.0':
            raise MeasurementError('Expected running dockware/shopware:6.7.15.0 disposable container')
        for mount in info['Mounts']:
            if mount['Type'] == 'bind' and any(target == mount['Destination'] or target.startswith(mount['Destination'].rstrip('/') + '/') for target in TARGETS):
                raise MeasurementError('Refusing to instrument source files in a host bind mount')
        catalogue = json.loads(execute('cat', CATALOGUE))
        if catalogue.get('testOnly') is not True or not any(agent.get('origin') == ORIGIN for agent in catalogue['agents']):
            raise MeasurementError('Test-only signed catalogue fixture is required')
        product_bytes = execute('cat', PRODUCT_CATALOGUE)
        product = json.loads(product_bytes)
        production_cdn_ranges = sum(len(provider.get('ranges', [])) for provider in product.get('cdnRanges', []))
        if production_cdn_ranges == 0 or product.get('testOnly') is True:
            raise MeasurementError('A nonempty production CDN catalogue is required')
        configured = json.loads(execute('php', '-r', 'require "/var/www/html/vendor/autoload.php"; (new Symfony\\Component\\Dotenv\\Dotenv())->bootEnv("/var/www/html/.env"); echo json_encode(["environment"=>$_SERVER["APP_ENV"]??$_ENV["APP_ENV"]??"", "catalogue"=>$_SERVER["AGENTENBLICK_TEST_CATALOG"]??$_ENV["AGENTENBLICK_TEST_CATALOG"]??""]);'))
        if configured != {'environment': 'test', 'catalogue': CATALOGUE}:
            raise MeasurementError('The active test environment must use the expected disposable catalogue path')
        about = console('about')
        if 'Environment          test' not in about:
            raise MeasurementError('APP_ENV=test is required')
        # These two flags must already be off; measurement never changes consent/settings.
        heuristic_status = json.loads(console('agentenblick:heuristics', 'status'))
        benchmark_status = json.loads(console('agentenblick:benchmark', 'status'))
        if heuristic_status.get('enabled') is not False or benchmark_status.get('enabled') is not False:
            raise MeasurementError('Heuristics and benchmark participation must already be disabled')
        report['versions'] = {'shopware': console('--version'), 'php_cli': execute('php', '-r', 'echo PHP_VERSION;').decode(), 'source_commit': command(['git', '-C', str(ROOT), 'rev-parse', 'HEAD'], 'source revision').decode().strip(), 'image_id': info['Image']}
        for index, target in enumerate(TARGETS):
            content = execute('cat', target)
            local = backup / (str(index) + Path(target).suffix)
            local.write_bytes(content)
            local.chmod(0o600)
            metadata = execute('stat', '-c', '%a:%u:%g', target).decode().strip().split(':')
            originals.append({'path': target, 'backup': str(local), 'sha256': hashlib.sha256(content).hexdigest(), 'mode': metadata[0], 'uid': metadata[1], 'gid': metadata[2]})
        (backup / 'manifest.json').write_text(json.dumps(originals, indent=2) + '\n')
        fixture_cdn_ranges = sum(len(provider.get('ranges', [])) for provider in catalogue.get('cdnRanges', []))
        catalogue['cdnRanges'] = product['cdnRanges'] + catalogue.get('cdnRanges', [])
        merged_catalogue = (json.dumps(catalogue, separators=(',', ':')) + '\n').encode()
        prepared_catalogue = backup / 'catalogue-with-production-cdn.json'
        prepared_catalogue.write_bytes(merged_catalogue)
        report['catalogue'] = {'production_cdn_ranges': production_cdn_ranges, 'fixture_cdn_ranges': fixture_cdn_ranges,
                               'measured_cdn_ranges': production_cdn_ranges + fixture_cdn_ranges,
                               'production_sha256': hashlib.sha256(product_bytes).hexdigest(),
                               'measured_test_catalogue_sha256': hashlib.sha256(merged_catalogue).hexdigest(),
                               'test_agents': len(catalogue['agents'])}
        old = sql("SELECT JSON_OBJECT('document',document_json,'fetchedAt',DATE_FORMAT(fetched_at,'%Y-%m-%d %H:%i:%s')) FROM agentenblick_key_cache WHERE origin='" + ORIGIN + "'")
        old_cache = json.loads(old) if old else None
        (backup / 'key-cache.json').write_text(json.dumps(old_cache) + '\n')
        cache_saved = True
        execute('mkdir', '-m', '700', temporary)
        initialized = True
        copy_into(prepared_catalogue, CATALOGUE)
        if hashlib.sha256(execute('cat', CATALOGUE)).hexdigest() != report['catalogue']['measured_test_catalogue_sha256']:
            raise MeasurementError('Disposable catalogue did not match the prepared production CDN fixture')
        helper = (ROOT / 'dev/phase-probe/probe.php').read_text().replace('__RUN__', run).replace('__OUTPUT__', temporary + '/phases.jsonl')
        helper_file = backup / 'probe.php'
        helper_file.write_text(helper)
        copy_into(helper_file, temporary + '/probe.php')
        for name in ['instrument.php', 'traffic.mjs']:
            copy_into(ROOT / 'dev/phase-probe' / name, temporary + '/' + name)
        execute('chown', '-R', 'www-data:www-data', temporary)
        execute('php', '-l', temporary + '/probe.php')
        execute('php', '-l', temporary + '/instrument.php')
        instrumented = execute('php', temporary + '/instrument.php', run).decode()
        report['instrumentation'] = [json.loads(line) for line in instrumented.splitlines() if line]
        execute('node', temporary + '/traffic.mjs', 'setup', temporary, run, user='www-data')
        document = execute('cat', temporary + '/jwks.json')
        encoded = base64.b64encode(document).decode()
        sql("INSERT INTO agentenblick_key_cache(origin,document_json,fetched_at) VALUES ('" + ORIGIN + "',FROM_BASE64('" + encoded + "'),UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE document_json=VALUES(document_json),fetched_at=VALUES(fetched_at)")
        network_before = directory_calls()
        for index, active in enumerate([False, True, False, True]):
            print(json.dumps({'stage': index, 'active': active, 'status': 'clearing cache and reloading workers'}), flush=True)
            state(active)
            execute('truncate', '-s', '0', temporary + '/phases.jsonl', user='www-data')
            before = counters()
            execute('node', temporary + '/traffic.mjs', 'run', temporary, run, str(index), user='www-data', timeout=1800)
            payload = json.loads(execute('cat', temporary + f'/stage-{index}.json'))
            evidence = execute('cat', temporary + '/phases.jsonl')
            rows = payload['results']
            (backup / f'stage-{index}.jsonl').write_bytes(evidence)
            raw_rows = [json.loads(line) for line in evidence.splitlines() if line]
            if len(rows) != 1024 or len(raw_rows) != 1024 or len({row['sample'] for row in raw_rows}) != 1024:
                raise MeasurementError('Every first/warm/measured request needs exactly one shutdown row')
            if any(not isinstance(value, int) for row in raw_rows for value in row.values()):
                raise MeasurementError('Shutdown records must contain numeric fields only')
            if any(row['open_timers'] != 0 or row['status'] != 200 for row in rows):
                raise MeasurementError('An unclosed timer or non-200 storefront response invalidates the stage')
            if active and any(row['pre_calls'] < 1 or row['post_calls'] < 1 for row in rows):
                raise MeasurementError('Active plugin phase instrumentation was not reached')
            if not active and any(row['pre_calls'] or row['post_calls'] for row in rows):
                raise MeasurementError('Inactive plugin still executed instrumented methods')
            after = counters()
            observed = {key: after.get(key, 0) - before.get(key, 0) for key in set(before) | set(after)}
            observed = {key: count for key, count in observed.items() if count}
            expected = {'none': 256, 'declared_unverified': 256, 'verified_ip': 256, 'verified_signature': 256} if active else {}
            if observed != expected:
                report['validation_failure'] = {'stage': index, 'expected': expected, 'observed': observed}
                raise MeasurementError('Unexpected classification totals: fixture, concurrent traffic or managed settings differ')
            measured = [row for row in rows if row['temperature'] == 2]
            stage = {'index': index, 'plugin_active': active, 'classification_counts_including_warmup': observed,
                     'metrics': summarize(measured), 'first_after_clear': summarize([row for row in rows if row['temperature'] == 0]),
                     'populations': {name: summarize([row for row in measured if row['population'] == i]) for i, name in enumerate(POPULATIONS)}, 'samples': rows}
            report['stages'].append(stage)
            (backup / f'stage-{index}.jsonl').write_bytes(evidence)
            print(json.dumps({'stage': index, 'status': 'measured', 'pre': stage['metrics']['pre'], 'post': stage['metrics']['post']}), flush=True)
        report['key_directory_requests_during_experiment'] = directory_calls() - network_before
        if report['key_directory_requests_during_experiment'] != 0:
            raise MeasurementError('Unexpected local key-directory network call during cached-key experiment')
    except BaseException as error:
        report['errors'].append(str(error) if isinstance(error, MeasurementError) else type(error).__name__)
    finally:
        # Finish restoration even if the controlling shell is interrupted again.
        signal.setitimer(signal.ITIMER_REAL, 0)
        signal.signal(signal.SIGINT, signal.SIG_IGN)
        signal.signal(signal.SIGTERM, signal.SIG_IGN)
        if initialized:
            try:
                execute('php', '-r', '''$file=$argv[1]."/traffic.pid"; if(!is_file($file))exit; $pid=(int)file_get_contents($file); if($pid<2)exit; $cmd=@file_get_contents("/proc/".$pid."/cmdline"); if($cmd===false)exit; if(!str_contains($cmd,$argv[1]."/traffic.mjs"))throw new RuntimeException("PID no longer belongs to this measurement"); if(!function_exists("posix_kill"))throw new RuntimeException("Cannot stop measurement runner"); posix_kill($pid,15); for($i=0;$i<20&&is_dir("/proc/".$pid);++$i){usleep(100000);clearstatcache();} if(is_dir("/proc/".$pid))posix_kill($pid,9);''', temporary)
            except Exception:
                report['errors'].append('could not stop the disposable traffic runner before restoration')
        for original in originals:
            try:
                copy_into(original['backup'], original['path'])
                execute('chmod', original['mode'], original['path'])
                execute('chown', original['uid'] + ':' + original['gid'], original['path'])
            except Exception as error:
                report['errors'].append('source restoration: ' + (str(error) if isinstance(error, MeasurementError) else type(error).__name__))
        if originals:
            try:
                report['restoration']['source_hashes_match'] = all(hashlib.sha256(execute('cat', item['path'])).hexdigest() == item['sha256'] for item in originals)
                original_catalogue = next((item for item in originals if item['path'] == CATALOGUE), None)
                report['restoration']['test_catalogue_restored'] = original_catalogue is not None and hashlib.sha256(execute('cat', CATALOGUE)).hexdigest() == original_catalogue['sha256']
            except Exception:
                report['errors'].append('could not verify source restoration')
        if cache_saved:
            try:
                if old_cache is None:
                    sql("DELETE FROM agentenblick_key_cache WHERE origin='" + ORIGIN + "'")
                else:
                    document = base64.b64encode(old_cache['document'].encode()).decode()
                    stamp = old_cache['fetchedAt']
                    if not isinstance(stamp, str) or len(stamp) != 19 or any(char not in '0123456789- :' for char in stamp):
                        raise MeasurementError('Invalid cached timestamp during restoration')
                    sql("INSERT INTO agentenblick_key_cache(origin,document_json,fetched_at) VALUES ('" + ORIGIN + "',FROM_BASE64('" + document + "'),'" + stamp + "') ON DUPLICATE KEY UPDATE document_json=VALUES(document_json),fetched_at=VALUES(fetched_at)")
                restored_cache = sql("SELECT JSON_OBJECT('document',document_json,'fetchedAt',DATE_FORMAT(fetched_at,'%Y-%m-%d %H:%i:%s')) FROM agentenblick_key_cache WHERE origin='" + ORIGIN + "'")
                report['restoration']['key_cache_restored'] = (json.loads(restored_cache) if restored_cache else None) == old_cache
                if not report['restoration']['key_cache_restored']:
                    report['errors'].append('public key cache restoration did not match original')
            except Exception:
                report['errors'].append('public key cache restoration failed')
        if initialized:
            try:
                state(True)
                report['restoration']['plugin_active'] = True
            except Exception:
                report['errors'].append('plugin activation or worker reload failed')
            if report['restoration']['source_hashes_match']:
                try:
                    execute('rm', '-rf', temporary)
                except Exception:
                    report['errors'].append('temporary instrumentation cleanup failed')
        measured_active = [row for stage in report['stages'] if stage['plugin_active'] for row in stage['samples'] if row['temperature'] == 2]
        measured_off = [row for stage in report['stages'] if not stage['plugin_active'] for row in stage['samples'] if row['temperature'] == 2]
        report['web_runtime'] = [{'php_version_id': version, 'sapi_code': sapi} for version, sapi in sorted({(row['php_version_id'], row['sapi_code']) for row in measured_active + measured_off})]
        report['sapi_codes'] = {'1': 'apache2handler', '2': 'fpm-fcgi', '3': 'cgi-fcgi', '0': 'other'}
        report['active'] = summarize(measured_active)
        report['inactive'] = summarize(measured_off)
        report['active_by_population'] = {name: summarize([row for row in measured_active if row['population'] == index]) for index, name in enumerate(POPULATIONS)}
        if report['active'] and report['inactive']:
            report['client_delta_ms'] = {name: report['active']['client'][name] - report['inactive']['client'][name] for name in ['median_ms', 'p95_ms']}
            report['identical_measured_status_body'] = len({(row['status'], row['bytes'], row['bodySha256']) for row in measured_active + measured_off}) == 1
        report['finishedAt'] = now()
        report['minimum_host_free_bytes'] = minimum_free
        report['host_disk_stop_threshold_bytes'] = 3 * 1024 ** 3
        report['measurement_complete'] = len(report['stages']) == 4 and not report['errors'] and all(report['restoration'].values())
        report['phase_thresholds_pass'] = report['measurement_complete'] and len(measured_active) == 2000 and len(measured_off) == 2000 and all(metrics is not None and metrics['pre']['count'] == 500 and metrics['pre']['p95_ms'] < .5 and metrics['post']['p95_ms'] < 5 for metrics in report['active_by_population'].values())
        OUTPUT.parent.mkdir(parents=True, exist_ok=True)
        OUTPUT.write_text(json.dumps(report, indent=2) + '\n')
        print(json.dumps({'output': str(OUTPUT), 'measurement_complete': report['measurement_complete'], 'phase_thresholds_pass': report['phase_thresholds_pass'], 'restoration': report['restoration'], 'errors': report['errors']}), flush=True)
    return 0 if report['measurement_complete'] else 1


if __name__ == '__main__':
    sys.exit(main())

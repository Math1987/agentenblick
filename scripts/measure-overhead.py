#!/usr/bin/env python3
"""Initial client-latency measurement on the disposable Agentenblick bench only.

Run on the host: python3 scripts/measure-overhead.py
The four stages are plugin off/on/off/on. Each clears the Shopware cache,
warms the same storefront URL ten times, then measures fifty serial requests.
This is a small warmed-page comparison, not a capacity or load test. Every
path through main() attempts to leave AithosAgentenblick activated again.
"""

import collections
import datetime
import hashlib
import json
import math
import pathlib
import platform
import statistics
import subprocess
import sys
import time
import urllib.error
import urllib.request


ROOT = pathlib.Path(__file__).resolve().parents[1]
OUTPUT = ROOT / '.local' / 'overhead.json'
CONTAINER = 'agentenblick-shop'
PLUGIN = 'AithosAgentenblick'
URL = 'http://127.0.0.1:18080/'
WARMUPS = 10
SAMPLES = 50
HTTP_TIMEOUT_SECONDS = 20
COMMAND_TIMEOUT_SECONDS = 180
USER_AGENT = 'GPTBot/1.0'


class BenchmarkError(RuntimeError):
    """A bounded diagnostic which never embeds command output or secrets."""


class NoRedirects(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, request, response, code, message, headers, new_url):
        return None


# Do not inherit HTTP proxy settings or follow redirects away from the bench.
HTTP = urllib.request.build_opener(urllib.request.ProxyHandler({}), NoRedirects())


def command(arguments, label, timeout=COMMAND_TIMEOUT_SECONDS):
    try:
        completed = subprocess.run(
            arguments, check=False, capture_output=True, text=True, timeout=timeout,
        )
    except (OSError, subprocess.TimeoutExpired) as error:
        raise BenchmarkError(f'{label}: {type(error).__name__}') from None
    if completed.returncode != 0:
        # Shell/Shopware diagnostics can include environment details: omit them.
        raise BenchmarkError(f'{label}: exit {completed.returncode}')
    return completed.stdout.strip()


def console(*arguments):
    return command([
        'docker', 'exec', '--user', 'www-data', '--workdir', '/var/www/html',
        CONTAINER, 'php', 'bin/console', *arguments, '--no-interaction', '--no-ansi',
    ], arguments[0])


def set_plugin(active):
    console('plugin:activate' if active else 'plugin:deactivate', PLUGIN)
    console('cache:clear')


def versions():
    image = json.loads(command([
        'docker', 'inspect', '--format',
        '{"image":{{json .Config.Image}},"image_id":{{json .Image}},"running":{{json .State.Running}}}',
        CONTAINER,
    ], 'inspect disposable bench', timeout=20))
    if image['running'] is not True:
        raise BenchmarkError('disposable bench is not running')
    runtime = json.loads(command([
        'docker', 'exec', '--user', 'www-data', '--workdir', '/var/www/html',
        CONTAINER, 'php', '-r',
        'echo json_encode(["php_cli" => PHP_VERSION, '
        '"plugin" => json_decode(file_get_contents('
        '"custom/plugins/AithosAgentenblick/composer.json"), true)["version"]]);',
    ], 'read installed runtime versions', timeout=20))
    return {
        **image,
        **runtime,
        'shopware': console('--version'),
        'docker_cli': command(['docker', '--version'], 'read Docker CLI version', timeout=20),
        'python': platform.python_version(),
        'source_commit': command(['git', '-C', str(ROOT), 'rev-parse', 'HEAD'], 'read source commit', timeout=20),
    }


def request():
    started = time.perf_counter()
    outgoing = urllib.request.Request(URL, headers={
        'User-Agent': USER_AGENT,
        'Accept-Encoding': 'identity',
        'Connection': 'close',
    })
    try:
        response = HTTP.open(outgoing, timeout=HTTP_TIMEOUT_SECONDS)
    except urllib.error.HTTPError as error:
        response = error
    except (OSError, urllib.error.URLError) as error:
        raise BenchmarkError(f'HTTP request: {type(error).__name__}') from None
    digest = hashlib.sha256()
    size = 0
    with response:
        while True:
            chunk = response.read(65536)
            if not chunk:
                break
            digest.update(chunk)
            size += len(chunk)
        elapsed = (time.perf_counter() - started) * 1000
        # Only cache diagnostics are retained; never persist cookies or response bodies.
        cache_headers = {
            name.lower(): response.headers[name][:256]
            for name in ['Age', 'Cache-Control', 'X-Symfony-Cache', 'X-Cache']
            if name in response.headers
        }
        return {
            'client_ms': elapsed,
            'http_status': response.status,
            'body_bytes': size,
            'body_sha256': digest.hexdigest(),
            'cache_headers': cache_headers,
        }


def metrics(samples):
    times = sorted(sample['client_ms'] for sample in samples)
    if not times:
        return None
    return {
        'requests': len(times),
        'median_ms': statistics.median(times),
        'p95_ms': times[math.ceil(0.95 * len(times)) - 1],
        'min_ms': times[0],
        'max_ms': times[-1],
        'http_status_counts': dict(collections.Counter(str(s['http_status']) for s in samples)),
        'body_sha256_counts': dict(collections.Counter(s['body_sha256'] for s in samples)),
    }


def delta(baseline, active):
    return {
        'median_ms': active['median_ms'] - baseline['median_ms'],
        'median_percent': 100 * (active['median_ms'] / baseline['median_ms'] - 1) if baseline['median_ms'] else None,
        'p95_ms': active['p95_ms'] - baseline['p95_ms'],
        'p95_percent': 100 * (active['p95_ms'] / baseline['p95_ms'] - 1) if baseline['p95_ms'] else None,
    }


def summarize(report):
    for stage in report['stages']:
        stage['metrics'] = metrics(stage['samples'])
    baseline_samples = [sample for stage in report['stages'] if not stage['plugin_active'] for sample in stage['samples']]
    active_samples = [sample for stage in report['stages'] if stage['plugin_active'] for sample in stage['samples']]
    baseline = metrics(baseline_samples)
    active = metrics(active_samples)
    report['baseline'] = baseline
    report['active'] = active
    report['active_minus_baseline'] = delta(baseline, active) if baseline and active else None
    report['paired_deltas'] = []
    for index in (0, 2):
        if len(report['stages']) > index + 1:
            before, after = report['stages'][index:index + 2]
            if before['metrics'] and after['metrics']:
                report['paired_deltas'].append({
                    'baseline_stage': before['name'], 'active_stage': after['name'],
                    **delta(before['metrics'], after['metrics']),
                })
    samples = baseline_samples + active_samples
    signatures = {(s['http_status'], s['body_sha256'], s['body_bytes']) for s in samples}
    report['response_comparison'] = {
        'all_measured_statuses_200': bool(samples) and all(s['http_status'] == 200 for s in samples),
        'identical_status_body_digest_and_size': bool(samples) and len(signatures) == 1,
        'different_responses_observed': len(signatures) > 1,
        'unique_status_body_combinations': [
            {'http_status': status, 'body_sha256': digest, 'body_bytes': size}
            for status, digest, size in sorted(signatures)
        ],
        'interpretation': 'Response differences are reported separately; a difference alone does not establish that the plugin changed the response.',
    }


def main():
    report = {
        'check': 'initial_warmed_storefront_client_overhead',
        'started_at': datetime.datetime.now(datetime.timezone.utc).isoformat(),
        'container': CONTAINER,
        'url': URL,
        'method': {
            'stage_order': ['off-1', 'on-1', 'off-2', 'on-2'],
            'cache_clear_each_stage': True,
            'warmups_per_stage': WARMUPS,
            'measured_requests_per_stage': SAMPLES,
            'client_concurrency': 1,
            'user_agent': USER_AGENT,
            'measurement': 'Client elapsed time through complete response-body read; new connection for each request.',
            'p95': 'Nearest rank, ceil(0.95 * n).',
            'limitations': [
                'Initial local warmed-page comparison, not a capacity or sustained-load test.',
                'Client completion does not measure all work performed after the response is flushed.',
                'Ten warmups target cached traffic; cache diagnostics are retained when the server supplies them.',
                'Local scheduling, Docker and database activity can affect small latency differences.',
                'No latency pass threshold is inferred from this measurement.',
            ],
        },
        'stages': [],
        'errors': [],
        'plugin_reactivated_in_finally': False,
    }
    try:
        report['versions'] = versions()
        for index, active in enumerate([False, True, False, True]):
            name = f'{"on" if active else "off"}-{index // 2 + 1}'
            print(json.dumps({'stage': name, 'status': 'changing plugin state and clearing cache'}), flush=True)
            set_plugin(active)
            stage = {'name': name, 'plugin_active': active, 'warmups': [], 'samples': []}
            report['stages'].append(stage)
            for _ in range(WARMUPS):
                stage['warmups'].append(request())
            for _ in range(SAMPLES):
                stage['samples'].append(request())
            print(json.dumps({'stage': name, 'status': 'measured', **metrics(stage['samples'])}), flush=True)
    except Exception as error:
        report['errors'].append(str(error) if isinstance(error, BenchmarkError) else type(error).__name__)
    finally:
        # Activation is intentional even when the bench initially had the plugin disabled.
        print(json.dumps({'status': 'restoring active plugin and clearing cache'}), flush=True)
        try:
            set_plugin(True)
            report['plugin_reactivated_in_finally'] = True
        except Exception as error:
            report['errors'].append('restoration: ' + (str(error) if isinstance(error, BenchmarkError) else type(error).__name__))
        summarize(report)
        report['finished_at'] = datetime.datetime.now(datetime.timezone.utc).isoformat()
        report['measurement_complete'] = (
            len(report['stages']) == 4
            and all(len(stage['samples']) == SAMPLES for stage in report['stages'])
            and not report['errors']
            and report['response_comparison']['all_measured_statuses_200']
        )
        OUTPUT.parent.mkdir(parents=True, exist_ok=True)
        OUTPUT.write_text(json.dumps(report, indent=2) + '\n')
        print(json.dumps({
            'output': str(OUTPUT),
            'measurement_complete': report['measurement_complete'],
            'plugin_reactivated_in_finally': report['plugin_reactivated_in_finally'],
            'baseline': report['baseline'], 'active': report['active'],
            'active_minus_baseline': report['active_minus_baseline'],
            'response_comparison': report['response_comparison'], 'errors': report['errors'],
        }, indent=2), flush=True)
    return 0 if report['measurement_complete'] else 1


if __name__ == '__main__':
    sys.exit(main())

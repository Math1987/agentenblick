#!/usr/bin/env python3
"""Measure the real Shopware kernel through its HTTP server (local bench only)."""
import json
import pathlib
import statistics
import subprocess
import time
import urllib.request

ROOT = pathlib.Path(__file__).resolve().parents[1]
URL = 'http://127.0.0.1:18080/_agentenblick/terminate-probe'


def request(delay):
    started = time.perf_counter()
    with urllib.request.urlopen(f'{URL}?delay_ms={delay}', timeout=20) as response:
        body = json.load(response)
        status = response.status
    return {**body, 'http_status': status, 'client_ms': (time.perf_counter() - started) * 1000}


# Warm the container without a deferred delay before measuring.
request(0)
request(0)
samples = []
for delay in [0, 2000, 0, 2000, 0, 2000]:
    samples.append(request(delay))
    if delay:
        time.sleep(2.2)

log = subprocess.run(
    ['docker', 'exec', 'agentenblick-shop', 'cat', '/tmp/agentenblick-terminate-probe.jsonl'],
    check=True, text=True, capture_output=True,
).stdout
events = {row['id']: row for row in map(json.loads, log.splitlines())}
for sample in samples:
    event = events.get(sample['id'])
    if event:
        sample['terminate_ms'] = (event['terminate_finished'] - event['terminate_started']) * 1000

baseline = statistics.median(s['client_ms'] for s in samples if not s['delay_ms'])
delayed = statistics.median(s['client_ms'] for s in samples if s['delay_ms'])
passed = (
    delayed - baseline < 500
    and all(s.get('terminate_ms', 0) >= 1900 for s in samples if s['delay_ms'])
    and all(s['fastcgi_finish_request'] and s['sapi'] == 'fpm-fcgi' for s in samples)
)
report = {
    'check': 'real_shopware_kernel_terminate_early_response',
    'baseline_client_median_ms': baseline,
    'delayed_client_median_ms': delayed,
    'added_client_median_ms': delayed - baseline,
    'passed': passed,
    'samples': samples,
}
output = ROOT / '.local' / 'terminate-measurement.json'
output.parent.mkdir(exist_ok=True)
output.write_text(json.dumps(report, indent=2) + '\n')
print(json.dumps(report, indent=2))
raise SystemExit(0 if passed else 1)

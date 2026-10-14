#!/usr/bin/env python3
"""Destructive tests ONLY for the disposable agentenblick-shop demo container.

Resets Agentenblick test observations, never Shopware business tables.
Run from the repository on the host after provisioning the test catalogue/proxy.
"""
import concurrent.futures
import hashlib
import json
from pathlib import Path
import subprocess
import time
import urllib.request

ROOT = Path(__file__).resolve().parents[1]
PLUGIN = '/var/www/html/custom/plugins/AithosAgentenblick'
CONTAINER = 'agentenblick-shop'
OUT = ROOT / '.local' / 'acceptance.json'
results = {}


def run(*args):
    return subprocess.run(args, check=True, text=True, capture_output=True).stdout.strip()


def execute(*args, user='www-data'):
    return run('docker', 'exec', '-u', user, CONTAINER, *args)


def console(*args):
    return execute('php', '/var/www/html/bin/console', *args, '--no-ansi')


def sql(statement):
    return run('docker', 'exec', '-u', 'root', '-e', 'MYSQL_PWD=root', CONTAINER,
               'mysql', '--protocol=TCP', '-h127.0.0.1', '-u', 'root', '--batch', '--skip-column-names', 'shopware', '-e', statement)


def scalar(statement):
    return int(sql(statement))


def wait_count(expected):
    for _ in range(100):
        actual = scalar('SELECT COUNT(*) FROM agentenblick_request')
        if actual == expected:
            return
        time.sleep(.1)
    raise AssertionError(f'request count: expected {expected}, got {actual}')


def get(context='failure-test'):
    request = urllib.request.Request('http://127.0.0.1:18080/', headers={'User-Agent': 'GPTBot/1.0', 'sw-context-token': context})
    with urllib.request.build_opener(urllib.request.ProxyHandler({})).open(request, timeout=20) as response:
        body = response.read()
        return {'status': response.status, 'bytes': len(body), 'sha256': hashlib.sha256(body).hexdigest()}


def traffic(*args):
    return json.loads(execute('bash', '-lc', 'node ' + PLUGIN + '/scripts/agentenblick-traffic.mjs ' + ' '.join(args)))


def main():
    assert json.loads(execute('cat', PLUGIN + '/tests/fixtures/catalog.json'))['testOnly'] is True
    assert 'Environment          test' in console('about')
    sql('TRUNCATE agentenblick_request; TRUNCATE agentenblick_session; TRUNCATE agentenblick_daily; TRUNCATE agentenblick_key_cache; DELETE FROM agentenblick_state WHERE name LIKE "proxy_%";')
    results['identity_traffic'] = traffic()
    wait_count(10)
    report = json.loads(console('agentenblick:report', '--json'))
    assert sum(row['requests'] for row in report['agents']) == 11
    assert report['sessions'] == 9
    assert scalar("SELECT COUNT(*) FROM agentenblick_request WHERE evidence = 'verified_signature'") == 2
    assert scalar("SELECT COUNT(*) FROM agentenblick_request WHERE evidence = 'verified_ip' AND agent='ExampleTestAgent'") == 1
    assert scalar("SELECT COUNT(*) FROM agentenblick_request WHERE agent='ExampleTestAgent' AND evidence='declared_unverified'") == 2
    assert scalar("SELECT COUNT(*) FROM agentenblick_request WHERE sales_channel_id='unassigned'") == 0
    assert scalar("SELECT COUNT(*) FROM agentenblick_request WHERE kind='human_or_unknown'") == 0
    assert report['human_browsers'][0]['browser_family'] == 'chrome'
    results['report'] = report

    # Identical session token, overlapping workers: no dropped observations.
    with concurrent.futures.ThreadPoolExecutor(max_workers=8) as pool:
        responses = list(pool.map(lambda _: get('concurrency-test-marker'), range(30)))
    wait_count(40)
    assert scalar("SELECT COUNT(*) FROM agentenblick_session WHERE requests=30 AND agent='GPTBot'") == 1
    assert len({row['sha256'] for row in responses}) == 1
    results['concurrency'] = {'requests': 30, 'sessions': 1, 'workers': 8, 'response': responses[0]}

    sql('TRUNCATE agentenblick_key_cache')
    results['directory_outage'] = traffic('--pending')
    wait_count(42)
    assert scalar('SELECT COUNT(*) FROM agentenblick_request WHERE pending_context IS NOT NULL') == 1
    assert scalar("SELECT COUNT(*) FROM agentenblick_request WHERE reason='directory_unreachable_privacy'") == 1
    before = sql('SELECT SUM(requests), SUM(sessions) FROM agentenblick_daily')
    sql('UPDATE agentenblick_request SET verify_after=UTC_TIMESTAMP() WHERE pending_context IS NOT NULL')
    results['reverification'] = json.loads(console('agentenblick:reverify'))
    assert results['reverification']['verified'] == 1
    assert sql('SELECT SUM(requests), SUM(sessions) FROM agentenblick_daily') == before
    assert json.loads(console('agentenblick:reverify'))['checked'] == 0
    results['reverification']['idempotent'] = True

    # Search every text column of plugin tables for known sensitive test markers.
    columns = sql("SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='shopware' AND LEFT(TABLE_NAME,13)='agentenblick_' AND DATA_TYPE IN ('varchar','char','text','mediumtext')")
    matches = 0
    for line in columns.splitlines():
        table, column = line.split('\t')
        matches += scalar(f"SELECT COUNT(*) FROM `{table}` WHERE `{column}` REGEXP 'sensitive-(query|cookie|context)-marker|person(%40|@)example[.]test|198[.]51[.]100[.]20|172[.]29[.]240[.]42|concurrency-test-marker'")
    assert matches == 0
    results['minimization'] = {'scanned_text_columns': len(columns.splitlines()), 'sensitive_matches': matches}

    # A plugin SQL failure must not modify the storefront's response.
    baseline = get()
    time.sleep(.3)
    sql('RENAME TABLE agentenblick_request TO agentenblick_request_acceptance_backup')
    try:
        failed_db = get()
        time.sleep(.3)
    finally:
        sql('RENAME TABLE agentenblick_request_acceptance_backup TO agentenblick_request')
    assert failed_db == baseline
    catalogue = PLUGIN + '/tests/fixtures/catalog.json'
    execute('cp', catalogue, catalogue + '.acceptance-backup', user='root')
    try:
        execute('sh', '-c', "printf '{' > " + catalogue, user='root')
        failed_catalogue = get()
        time.sleep(.3)
    finally:
        execute('mv', catalogue + '.acceptance-backup', catalogue, user='root')
    assert failed_catalogue == baseline
    results['fail_open'] = {'baseline': baseline, 'database_unavailable': failed_db, 'catalogue_corrupt': failed_catalogue}

    # Resolved Docker/CDN-range addresses trigger the warning; a clean new
    # observation window through the trusted proxy does not.
    sql('DELETE FROM agentenblick_state WHERE name LIKE "proxy_%"')
    for _ in range(12):
        get('proxy-diagnostic')
    time.sleep(.5)
    assert json.loads(console('agentenblick:report', '--json'))['proxy_diagnostics']['warning'] is True
    sql('DELETE FROM agentenblick_state WHERE name LIKE "proxy_%"')
    sql('TRUNCATE agentenblick_key_cache')
    traffic()
    assert json.loads(console('agentenblick:report', '--json'))['proxy_diagnostics']['warning'] is False
    results['proxy_diagnostic'] = {'cdn_majority_warned': True, 'clean_window_cleared': True}
    results['selftest'] = json.loads(console('agentenblick:selftest'))
    results['ok'] = True


if __name__ == '__main__':
    OUT.parent.mkdir(exist_ok=True)
    try:
        main()
    finally:
        OUT.write_text(json.dumps(results, indent=2) + '\n')
    print(json.dumps({'ok': results['ok'], 'evidence': str(OUT)}, indent=2))

#!/usr/bin/env python3
"""Native plugin -> HTTPS benchmark acceptance, ONLY on agentenblick-shop.

Run manually after the lot gate. No work happens on import. A pre-existing
consent/registration is never replaced. The prior week's aggregates and three
configuration keys are backed up and restored; only this run's drafts are erased.
Cloud traffic is always demo=true. No plaintext secret is read or printed.
"""
import hashlib
import importlib.util
import json
from pathlib import Path
import re
import subprocess
import time
from datetime import datetime, timedelta, timezone
import uuid
from threading import Event, Thread
from acceptance import CONTAINER, ROOT, console, execute, sql

SPEC = importlib.util.spec_from_file_location('agentenblick_admin_acceptance', ROOT / 'scripts/acceptance-admin.py')
admin = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(admin)
OUT = ROOT / '.local/benchmark-acceptance.json'
PREFIX = '_action/agentenblick/benchmark/'
CONFIG_KEYS = ['AithosAgentenblick.internal.benchmark', 'AithosAgentenblick.config.benchmarkDemo', 'AithosAgentenblick.config.offline']
FILTER = '(udp port 53 or tcp port 53) or (tcp and (dst port 80 or dst port 443) and not net 127.0.0.0/8 and not host ::1)'


def require(condition, message):
    if not condition:
        raise AssertionError(message)


def literal(value):
    return "CONVERT(X'" + str(value).encode().hex() + "' USING utf8mb4) COLLATE utf8mb4_unicode_ci"


def cli(action, *options):
    return json.loads(console('agentenblick:benchmark', action, *options))


def capture(action):
    """Only packet counts are returned. Stop this capture PID, never all tcpdump."""
    marker = uuid.uuid4().hex
    base = '/tmp/agentenblick-benchmark-' + marker
    pidfile, pcap = base + '.pid', base + '.pcap'
    start = 'import os,sys; open(sys.argv[1],"w").write(str(os.getpid())); os.execvp("tcpdump",["tcpdump","-ni","any","-Q","out","-U","-w",sys.argv[2],sys.argv[3]])'
    process = subprocess.Popen(['docker', 'exec', '-u', 'root', CONTAINER, 'python3', '-c', start, pidfile, pcap, FILTER], stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
    try:
        ready = Event()
        def read_startup():
            for line in process.stderr:
                if 'listening on' in line:
                    ready.set()
                    return
        Thread(target=read_startup, daemon=True).start()
        require(ready.wait(12), 'tcpdump did not attach; cannot claim zero network')
        result = action()
        time.sleep(0.5)
    finally:
        stop = 'import os,signal,sys; from pathlib import Path; p=Path(sys.argv[1]); os.kill(int(p.read_text()),signal.SIGINT) if p.exists() else None'
        try:
            execute('python3', '-c', stop, pidfile, user='root')
        finally:
            process.communicate(timeout=12)
    try:
        packets = execute('tcpdump', '-nn', '-r', pcap, user='root').splitlines()
        return len(packets), result
    finally:
        execute('rm', '-f', pidfile, pcap, user='root')


def main():
    evidence = {'startedAt': datetime.now(timezone.utc).isoformat(), 'demo': True, 'checks': {}, 'cleanup': {}}
    marker = uuid.uuid4().hex[:12]
    daily_backup = 'agentenblick_accept_daily_' + marker
    config_backup = 'agentenblick_accept_config_' + marker
    config_filter = 'configuration_key IN (' + ','.join(literal(key) for key in CONFIG_KEYS) + ')'
    now = datetime.now(timezone.utc)
    end = (now - timedelta(days=now.weekday())).date()
    start = end - timedelta(days=7)
    period = start.strftime('%G-W%V')
    period_filter = 'day >= ' + literal(start) + ' AND day < ' + literal(end)
    installation = None
    daily_saved = config_saved = mutated = daily_mutated = consent_attempted = False
    original_rows = None
    try:
        require(CONTAINER == 'agentenblick-shop', 'Only the disposable agentenblick-shop bench is allowed')
        require(re.search(r'Environment\s+test\b', console('about')) is not None, 'APP_ENV=test is required')
        original = cli('status')
        require(not any(original.get(key) for key in ['enabled', 'registered', 'pendingWithdrawal', 'consentAt']), 'Existing participation or consent must be preserved; acceptance refused')
        evidence['checks']['environment'] = 'test'

        # API uses the same authenticated request helper as acceptance-admin.py.
        status, _, raw = admin.request('oauth/token', body=json.dumps({'grant_type': 'password', 'client_id': 'administration', 'scopes': 'write', 'username': 'admin', 'password': 'shopware'}).encode())
        require(status == 200, 'Disposable admin authentication failed')
        token = json.loads(raw)['access_token']
        def api(action, payload=None):
            status, headers, raw = admin.request(PREFIX + action, token, None if payload is None else json.dumps(payload).encode())
            require(status == 200, 'Benchmark admin endpoint failed: ' + action.split('?')[0])
            require('no-store' in headers.get('Cache-Control', ''), 'Benchmark response permits caching')
            return json.loads(raw)
        require(admin.request(PREFIX + 'status')[0] == 401, 'Anonymous benchmark admin access was accepted')

        # Backups are durable until successful restoration, including on interruption.
        sql('CREATE TABLE ' + config_backup + ' LIKE system_config')
        config_saved = True
        sql('INSERT INTO ' + config_backup + ' SELECT * FROM system_config WHERE ' + config_filter)
        sql('CREATE TABLE ' + daily_backup + ' LIKE agentenblick_daily')
        daily_saved = True
        sql('INSERT INTO ' + daily_backup + ' SELECT * FROM agentenblick_daily WHERE ' + period_filter)
        original_rows = sql('SELECT COUNT(*) FROM ' + daily_backup)
        sql('DELETE FROM system_config WHERE configuration_key = ' + literal(CONFIG_KEYS[0]))
        mutated = True
        console('system:config:set', '--json', CONFIG_KEYS[1], 'true')
        console('system:config:set', '--json', CONFIG_KEYS[2], 'false')
        console('cache:clear')
        initial = cli('status')
        require(initial['enabled'] is False and initial['registered'] is False and initial['consentAt'] is None, 'Benchmark is not off before consent')
        require(initial['demo'] is True and initial['offline'] is False, 'Demo mode must be fixed before preview; live transport must be available')
        evidence['checks']['offBeforeConsent'] = True
        evidence['checks']['demoConfiguredBeforeIdentity'] = True
        daily_mutated = True
        sql('DELETE FROM agentenblick_daily WHERE ' + period_filter)
        channels = [uuid.uuid4().hex for _ in range(3)]
        for channel, humans, agents in zip(channels, [90, 9, 11], [10, 0, 9]):
            for count, kind, proof, operator, agent in [
                (humans, 'human_or_unknown', 'none', 'unknown', 'unknown'),
                (agents, 'training_crawler', 'verified_ip', 'openai', 'AcceptanceSynthetic'),
            ]:
                if not count:
                    continue
                values = [start, channel, operator, agent, kind, proof, '', count, count]
                sql('INSERT INTO agentenblick_daily (day,sales_channel_id,operator,agent,kind,evidence,browser_family,requests,sessions) VALUES (' + ','.join(literal(value) for value in values) + ')')

        positive, _ = capture(lambda: execute('curl', '--silent', '--show-error', '--fail', '--max-time', '10', '--output', '/dev/null', 'https://shopware.aithos.app/v1/health'))
        require(positive > 0, 'Packet capture positive control failed')
        def local_only():
            require(cli('send')['status'] == 'disabled', 'Sending without consent was accepted')
            require(cli('comparison')['available'] is False, 'Comparison connected without consent')
            return cli('preview', '--period=' + period)
        packets, preview = capture(local_only)
        require(packets == 0 and preview['networkSent'] is False, 'Preview or disabled action emitted network traffic')
        installation = cli('status')['installationId']
        require(len(preview['items']) == 3, 'Expected exactly three isolated synthetic channels')
        require(preview['items'][0]['payload']['installationId'] == installation, 'Preview and status identities differ')
        require(re.fullmatch(r'[a-f0-9-]{36}', installation) is not None, 'Invalid generated installation identity')
        evidence['checks']['network'] = {'positiveControlPackets': positive, 'beforeConsentPackets': packets, 'filter': FILTER, 'direction': 'out'}
        evidence['period'] = period

        snapshots = {}
        for item in preview['items']:
            text = item['json']
            require(item['sha256'] == hashlib.sha256(text.encode()).hexdigest(), 'Preview SHA256 differs from exact UTF-8 bytes')
            require(item['payload']['demo'] is True, 'A real-data document would be sent')
            require(all(channel not in text for channel in channels), 'Shopware channel identity leaked into payload')
            snapshots[item['channelId']] = (item['sha256'], text)
        payloads = [item['payload'] for item in preview['items']]
        eligible = next(value for value in payloads if value['denominators']['agentSessions'] == 'at_least_10')
        require(eligible['shares']['aiSessionsPct'] == 10 and eligible['shares']['byEvidence']['verified_ip'] == 100, 'Ten-agent threshold or aggregation incorrect')
        low_total = next(value for value in payloads if value['denominators']['sessions'] == 'below_10')
        require(all(value == 'suppressed' for value in low_total['shares'].values()), 'Below-ten total exposed a metric')
        mixed = next(value for value in payloads if value['shares']['aiSessionsPct'] == 45)
        require(all(mixed['shares'][key] == 'suppressed' for key in ['byKind', 'byEvidence', 'byOperator']), 'Below-ten agents exposed distributions')
        api_preview = api('preview?period=' + period)
        require({row['channelId']: (row['sha256'], row['json']) for row in api_preview['items']} == snapshots, 'CLI and API previews differ')
        evidence['checks']['k10AndByteHash'] = {'channels': 3, 'fullyEligible': 1, 'fullySuppressed': 1, 'agentDistributionsSuppressed': 1, 'cliApiBytesEqual': True}

        invalid_status, _, _ = admin.request(PREFIX + 'enable', token, json.dumps({'consent': False, 'consentVersion': '1', 'locale': 'de-DE'}).encode())
        require(invalid_status == 400, 'Missing explicit consent was accepted')
        consent_attempted = True
        enabled = cli('enable', '--accept-consent=1', '--locale=de-DE')
        require(enabled['enabled'] and enabled['registered'] and enabled['demo'] and enabled['locale'] == 'de-DE' and enabled['consentVersion'] == '1', 'Explicit German consent did not register the demo installation')
        require(enabled['installationId'] == installation, 'Registration changed the reviewed installation ID')
        require(api('status')['registered'] is True, 'API and CLI registration states disagree')
        evidence['checks']['explicitConsent'] = {'locale': 'de-DE', 'version': '1', 'registered': True, 'missingConsentStatus': 400}
        for _ in range(3):
            result = cli('send')
            require(result['status'] == 'sent' and result['resultCode'] == 'accepted', 'Cloud rejected the native plugin submission')
        require(cli('send')['status'] == 'no_pending_documents', 'Completed documents were queued again')
        history = [row for row in api('history')['items'] if row['payload']['installationId'] == installation]
        require(len(history) == 3 and all(row['status'] == 'sent' for row in history), 'Native send history incomplete')
        require({row['channelId']: (row['sha256'], row['json']) for row in history} == snapshots, 'Sent bytes differ from reviewed bytes')
        # Simulate a lost local acknowledgement; same frozen bytes must remain accepted.
        retry_channel = history[0]['channelId']
        sql("UPDATE agentenblick_benchmark_draft SET status='failed', result_code='acceptance_retry' WHERE installation_id=" + literal(installation) + ' AND channel_id=' + literal(retry_channel) + ' AND period=' + literal(period))
        require(api('send', {})['status'] == 'sent', 'Idempotent retry of exact bytes failed')
        require(cli('send')['status'] == 'no_pending_documents', 'Idempotent retry left a pending document')
        evidence['checks']['nativeCloudSend'] = {'acceptedDocuments': 3, 'identicalRetryAccepted': True, 'historyHashesMatchPreview': True}
        disabled = api('disable', {})
        require(not disabled['enabled'] and not disabled['registered'] and not disabled['pendingWithdrawal'] and disabled['installationId'] is None, 'Cloud withdrawal not confirmed by the native client')
        # Manager clears these fields only after the cloud returned HTTP 204.
        evidence['checks']['withdrawal'] = {'confirmedByNativeClient': True, 'requiredCloudStatus': 204, 'sendingStopped': cli('send')['status'] == 'disabled'}
        evidence['passed'] = True
    except Exception as error:
        evidence['passed'] = False
        evidence['failure'] = str(error) if isinstance(error, AssertionError) else type(error).__name__
        raise
    finally:
        clean_cloud = not consent_attempted
        if consent_attempted:
            for _ in range(3):
                try:
                    result = cli('disable')
                    clean_cloud = not result['pendingWithdrawal'] and not result['registered'] and not result['enabled']
                    if clean_cloud:
                        break
                except Exception:
                    pass
                time.sleep(1)
        evidence['cleanup']['cloudWithdrawalConfirmed'] = clean_cloud
        if mutated and installation is None:
            try:
                installation = cli('status')['installationId']
            except Exception:
                pass
        try:
            if daily_saved:
                if daily_mutated:
                    sql('START TRANSACTION; DELETE FROM agentenblick_daily WHERE ' + period_filter + '; INSERT INTO agentenblick_daily SELECT * FROM ' + daily_backup + '; COMMIT')
                    require(sql('SELECT COUNT(*) FROM agentenblick_daily WHERE ' + period_filter) == original_rows, 'Restored aggregate count differs')
                sql('DROP TABLE ' + daily_backup)
                evidence['cleanup']['dailyRestored'] = True
            if installation and clean_cloud:
                sql('DELETE FROM agentenblick_benchmark_draft WHERE installation_id=' + literal(installation))
                evidence['cleanup']['ownDraftsDeleted'] = True
            if config_saved and clean_cloud:
                if mutated:
                    sql('START TRANSACTION; DELETE FROM system_config WHERE ' + config_filter + '; INSERT INTO system_config SELECT * FROM ' + config_backup + '; COMMIT')
                    console('cache:clear')
                sql('DROP TABLE ' + config_backup)
                evidence['cleanup']['configurationRestored'] = True
            elif config_saved:
                # Keep the current encrypted secret for retrying withdrawal, together
                # with the original untouched settings; never erase recovery material.
                evidence['cleanup']['configurationBackupRetained'] = config_backup
        except Exception:
            evidence['cleanup']['restoreFailed'] = True
            evidence['cleanup']['dailyBackupIfPresent'] = daily_backup
            evidence['cleanup']['configurationBackupIfPresent'] = config_backup
        evidence['finishedAt'] = datetime.now(timezone.utc).isoformat()
        evidence['cleanup']['complete'] = clean_cloud and not evidence['cleanup'].get('restoreFailed', False) and (not config_saved or evidence['cleanup'].get('configurationRestored', False))
        if not evidence['cleanup']['complete']:
            evidence['passed'] = False
        OUT.parent.mkdir(exist_ok=True)
        OUT.write_text(json.dumps(evidence, indent=2) + '\n')
        print(json.dumps(evidence, indent=2))
    require(evidence.get('passed') is True, 'Acceptance or restoration is incomplete; inspect safe evidence')


if __name__ == '__main__':
    main()

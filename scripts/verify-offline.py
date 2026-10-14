#!/usr/bin/env python3
"""Network proof for the disposable local test bench; requires tcpdump there."""
import json
from pathlib import Path
import subprocess
import time
from acceptance import CONTAINER, PLUGIN, ROOT, execute, console, sql, traffic

FILTER = '(udp port 53 or tcp port 53) or (tcp and (dst port 80 or dst port 443) and not net 127.0.0.0/8 and not host ::1)'


def capture(path, action):
    process = subprocess.Popen(['docker', 'exec', '-u', 'root', CONTAINER, 'tcpdump', '-ni', 'any', '-U', '-w', path, FILTER], stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
    try:
        # Wait for tcpdump to have attached before generating traffic.
        while True:
            line = process.stderr.readline()
            if 'listening on' in line:
                break
            if not line and process.poll() is not None:
                raise RuntimeError('tcpdump failed to start')
        result = action()
        time.sleep(1)
    finally:
        execute('pkill', '-INT', '-x', 'tcpdump', user='root')
        process.communicate(timeout=10)
    packets = execute('tcpdump', '-nn', '-r', path, user='root').splitlines()
    return len(packets), result


def activity():
    dataset = traffic()
    # Force the reverse-DNS candidate path as well as both signature formats.
    execute('bash', '-lc', "node -e \"require('http').get('http://127.0.0.1:18081/',{headers:{'user-agent':'Googlebot/2.1'}},r=>r.resume())\"")
    return {'http_requests': dataset['requests'] + 1, 'ranges': console('agentenblick:ranges:refresh'), 'reverify': console('agentenblick:reverify')}


if __name__ == '__main__':
    assert 'Environment          test' in console('about')
    control, _ = capture('/tmp/agentenblick-control.pcap', lambda: execute('curl', '--silent', '--show-error', '--max-time', '10', '--output', '/dev/null', 'https://openai.com/gptbot.json'))
    assert control > 0, 'Capture filter did not see the explicit network control'
    console('system:config:set', '--json', 'AithosAgentenblick.config.offline', 'true')
    console('cache:clear')
    sql("TRUNCATE agentenblick_key_cache; DELETE FROM agentenblick_state WHERE name LIKE 'rdns:%'")
    calls = execute('sh', '-c', 'wc -l < /tmp/agentenblick-directory-calls.log')
    try:
        packets, activity_result = capture('/tmp/agentenblick-offline.pcap', activity)
        calls_after = execute('sh', '-c', 'wc -l < /tmp/agentenblick-directory-calls.log')
        assert packets == 0, f'Unexpected network packets: {packets}'
        assert calls == calls_after, 'Offline mode fetched the local test directory'
    finally:
        console('system:config:set', '--json', 'AithosAgentenblick.config.offline', 'false')
        console('cache:clear')
    output = {'ok': True, 'filter': FILTER, 'positive_control_packets': control, 'offline_packets': packets, 'local_directory_calls': 0, 'activity': activity_result}
    path = ROOT / '.local' / 'offline.json'
    path.write_text(json.dumps(output, indent=2) + '\n')
    print(json.dumps(output, indent=2))

#!/usr/bin/env python3
"""Maintainer-only build tool: obtain public IP snapshots; never runs in a shop request."""
import concurrent.futures
import ipaddress
import json
import pathlib
import urllib.request
from datetime import datetime, timezone

ROOT = pathlib.Path(__file__).resolve().parents[1]
PATH = ROOT / 'src/Resources/catalog/agents.json'
document = json.loads(PATH.read_text())


def fetch(url):
    with urllib.request.urlopen(url, timeout=15) as response:
        data = response.read(8 * 1024 * 1024 + 1)
    if len(data) > 8 * 1024 * 1024:
        raise ValueError('oversized source')
    return json.loads(data)


def ranges(value):
    result = set()
    if isinstance(value, dict):
        for item in value.values():
            result.update(ranges(item))
    elif isinstance(value, list):
        for item in value:
            result.update(ranges(item))
    elif isinstance(value, str) and '/' in value:
        try:
            network = ipaddress.ip_network(value, strict=True)
            if network.prefixlen and network.is_global:
                result.add(str(network))
        except ValueError:
            pass
    return result


def update(agent):
    if not agent.get('rangesUrl'):
        return agent
    try:
        snapshot = sorted(ranges(fetch(agent['rangesUrl'])))
        if not 1 <= len(snapshot) <= 50000:
            raise ValueError('implausible range count')
        agent['ranges'] = snapshot
        agent['rangesRetrievedAt'] = datetime.now(timezone.utc).isoformat()
        print(agent['id'], len(snapshot), flush=True)
    except Exception as error:
        print(agent['id'], 'kept previous snapshot:', type(error).__name__, flush=True)
    return agent


with concurrent.futures.ThreadPoolExecutor(max_workers=4) as pool:
    document['agents'] = list(pool.map(update, document['agents']))

document['cdnRanges'] = []
for provider, url in [
    ('cloudflare', 'https://api.cloudflare.com/client/v4/ips'),
    ('cloudfront', 'https://ip-ranges.amazonaws.com/ip-ranges.json'),
    ('fastly', 'https://api.fastly.com/public-ip-list'),
]:
    data = fetch(url)
    if provider == 'cloudfront':
        data = [p for p in data.get('prefixes', []) + data.get('ipv6_prefixes', []) if p.get('service') == 'CLOUDFRONT']
    snapshot = sorted(ranges(data))
    if not snapshot:
        raise ValueError('empty CDN source: ' + provider)
    document['cdnRanges'].append({'provider': provider, 'source': url, 'ranges': snapshot})
    print(provider, len(snapshot), flush=True)

PATH.write_text(json.dumps(document, indent=2) + '\n')
test_path = ROOT / 'tests/fixtures/catalog.json'
test = json.loads(test_path.read_text())
real_ids = {agent['id'] for agent in document['agents']}
test_agents = [agent for agent in test['agents'] if agent['id'] not in real_ids]
test = {**document, 'testOnly': True, 'agents': document['agents'] + test_agents}
test['cdnRanges'] = [{'provider': 'test-cdn', 'source': 'isolated test fixture', 'ranges': ['172.18.0.0/16']}]
test_path.write_text(json.dumps(test, indent=2) + '\n')

#!/usr/bin/env python3
"""Read-only acceptance of the isolated bench's real authenticated Admin API."""
import csv
import io
import json
import urllib.error
import urllib.request
from acceptance import ROOT, console

BASE = 'http://127.0.0.1:18080/api/'


def request(path, token=None, body=None):
    headers = {'Accept': 'application/json', 'Content-Type': 'application/json'}
    if token:
        headers['Authorization'] = 'Bearer ' + token
    req = urllib.request.Request(BASE + path, data=body, headers=headers)
    try:
        response = urllib.request.urlopen(req, timeout=15)
    except urllib.error.HTTPError as error:
        response = error
    with response:
        return response.status, response.headers, response.read()


if __name__ == '__main__':
    status, _, body = request('oauth/token', body=json.dumps({'grant_type': 'password', 'client_id': 'administration', 'scopes': 'write', 'username': 'admin', 'password': 'shopware'}).encode())
    assert status == 200, (status, body[:500])
    token = json.loads(body)['access_token']
    prefix = '_action/agentenblick/'
    assert request(prefix + 'summary')[0] == 401
    assert request(prefix + 'summary?days=999', token)[0] == 400
    status, headers, body = request(prefix + 'summary?days=7', token)
    assert status == 200 and 'no-store' in headers['Cache-Control']
    summary = json.loads(body)
    report = json.loads(console('agentenblick:report', '--days=7', '--json'))
    assert summary['totals']['requests'] == sum(x['requests'] for x in report['agents'])
    assert summary['totals']['sessions'] == report['sessions']
    assert sum(x['requests'] for x in summary['daily']) == sum(x['requests'] for x in report['agents'])
    status, _, body = request(prefix + 'agents?days=7', token)
    assert status == 200
    agents = json.loads(body)
    assert sum(x['requests'] for x in agents['items']) == summary['totals']['agentRequests']
    status, headers, body = request(prefix + 'export?days=7', token)
    assert status == 200 and headers['Content-Type'].startswith('text/csv')
    csv_rows = list(csv.reader(io.StringIO(body.decode('utf-8-sig'))))
    assert len(csv_rows) == agents['total'] + 1
    assert request(prefix + 'ranges/pending', token)[0] == 200
    result = {'totals': summary['totals'], 'agentRows': agents['total'], 'csvRows': len(csv_rows) - 1, 'days': len(summary['daily']), 'anonymous': 401, 'invalidFilter': 400, 'cliMatches': True, 'noStore': True}
    (ROOT / '.local/admin-acceptance.json').write_text(json.dumps(result, indent=2) + '\n')
    print(json.dumps(result, indent=2))

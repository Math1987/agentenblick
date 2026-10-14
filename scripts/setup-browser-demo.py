#!/usr/bin/env python3
"""Create/clean one local browser demo in agentenblick-shop only.

The fixture vhost labels CUA browser requests as a fictitious, IP-verified agent.
No browser interaction happens here. Email delivery and heuristic classification
stay disabled until cleanup; only fixture-owned Shopware entities are deleted.
"""
import argparse
import importlib.util
import json
import os
import re
import subprocess
import uuid
import urllib.error
import urllib.request
from pathlib import Path
from acceptance import ROOT, CONTAINER, PLUGIN, console, execute, run, sql

SPEC = importlib.util.spec_from_file_location('browser_admin', ROOT / 'scripts/acceptance-admin.py')
admin = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(admin)
STATE = ROOT / '.local/browser-demo-state.json'
HOST = 'agentenblick-browser.localhost:18080'
URL = 'http://' + HOST
VHOST = '/etc/apache2/sites-available/agentenblick-browser.conf'
PROXY_CONFIG = '/var/www/html/config/packages/agentenblick-test-proxy.yaml'
KEYS = ['core.mailerSettings.disableDelivery', 'core.cart.showTosCheckbox', 'AithosAgentenblick.config.heuristicsEnabled']


def require(value, message):
    if not value:
        raise AssertionError(message)


def literal(value):
    return "CONVERT(X'" + str(value).encode().hex() + "' USING utf8mb4) COLLATE utf8mb4_unicode_ci"


def save(state):
    STATE.parent.mkdir(exist_ok=True)
    fd = os.open(STATE, os.O_WRONLY | os.O_CREAT | os.O_TRUNC, 0o600)
    with os.fdopen(fd, 'w') as stream:
        json.dump(state, stream, indent=2)
        stream.write('\n')
    os.chmod(STATE, 0o600)


def write(path, content):
    execute('python3', '-c', 'import sys;from pathlib import Path;p=Path(sys.argv[1]);p.parent.mkdir(parents=True,exist_ok=True);p.write_text(sys.argv[2])', path, content, user='root')


def authentication():
    status, _, raw = admin.request('oauth/token', body=json.dumps({'grant_type': 'password', 'client_id': 'administration', 'scopes': 'write', 'username': 'admin', 'password': 'shopware'}).encode())
    require(status == 200, 'Disposable admin authentication failed')
    return json.loads(raw)['access_token']


def call(token, entity, body=None, method=None):
    request = urllib.request.Request(admin.BASE + entity, data=None if body is None else json.dumps(body).encode(), method=method, headers={'Authorization': 'Bearer ' + token, 'Content-Type': 'application/json', 'Accept': 'application/json'})
    try:
        response = urllib.request.urlopen(request, timeout=40)
    except urllib.error.HTTPError as error:
        response = error
    with response:
        raw = response.read()
        return response.status, json.loads(raw) if raw else {}


def restart_proxy():
    run('docker', 'cp', str(ROOT / 'scripts/test-proxy.mjs'), CONTAINER + ':' + PLUGIN + '/scripts/test-proxy.mjs')
    subprocess.run(['docker', 'exec', '-u', 'root', CONTAINER, 'pkill', '-f', '^node ' + PLUGIN + '/scripts/test-proxy.mjs$'], check=False, capture_output=True)
    run('docker', 'exec', '-d', '-u', 'www-data', CONTAINER, 'node', PLUGIN + '/scripts/test-proxy.mjs')


def native_browser_sessions(state):
    # Shopware's test factory is static memory with save() empty: unsuitable for
    # separate FPM browser requests. Keep the test catalogue, use real sessions.
    state['sessionDirectory'] = '/var/www/html/var/agentenblick-browser-sessions-' + state['marker']
    save(state)
    execute('mkdir', '-p', state['sessionDirectory'], user='root')
    execute('chown', 'www-data:www-data', state['sessionDirectory'], user='root')
    execute('chmod', '700', state['sessionDirectory'], user='root')
    write(PROXY_CONFIG, "when@test:\n  framework:\n    trusted_proxies: '127.0.0.1'\n    trusted_headers: ['x-forwarded-for', 'x-forwarded-host']\n    session:\n      storage_factory_id: session.storage.factory.native\n      save_path: '" + state['sessionDirectory'] + "'\n")


def ensure_bench():
    require(CONTAINER == 'agentenblick-shop' and re.search(r'Environment\s+test\b', console('about')), 'Only agentenblick-shop APP_ENV=test is permitted')


def summary(state):
    return {'url': URL, 'products': state.get('products', []), 'syntheticGuestEmail': state['guestEmail'], 'heuristicsEnabled': False, 'emailDeliveryEnabled': False, 'termsCheckboxRequired': True, 'cleanup': 'python3 scripts/setup-browser-demo.py cleanup', 'fixtureState': str(STATE)}


def setup():
    ensure_bench()
    if STATE.exists():
        state = json.loads(STATE.read_text())
        require(state.get('complete'), 'An unfinished owned fixture exists; clean it before retrying')
        return summary(state)
    require(sql('SELECT COUNT(*) FROM sales_channel_domain WHERE url=' + literal(URL)) == '0', 'A pre-existing browser demo domain must be preserved')
    require(execute('python3', '-c', 'from pathlib import Path;print(Path("' + VHOST + '").exists())', user='root') == 'False', 'A pre-existing vhost must be preserved')
    token = authentication()
    row = json.loads(sql("SELECT JSON_OBJECT('channel',LOWER(HEX(sc.id)),'currency',LOWER(HEX(sc.currency_id)),'navigation',LOWER(HEX(sc.navigation_category_id)),'language',LOWER(HEX(d.language_id)),'snippet',LOWER(HEX(d.snippet_set_id))) FROM sales_channel sc JOIN sales_channel_domain d ON d.sales_channel_id=sc.id WHERE d.url='http://127.0.0.1:18080' LIMIT 1"))
    tax = json.loads(sql("SELECT JSON_OBJECT('id',LOWER(HEX(id)),'rate',tax_rate) FROM tax ORDER BY tax_rate DESC LIMIT 1"))
    marker = uuid.uuid4().hex[:12]
    state = {'container': CONTAINER, 'marker': marker, 'guestEmail': 'browser-' + marker + '@example.invalid', 'domain': uuid.uuid4().hex, 'category': uuid.uuid4().hex, 'products': [], 'configBackup': 'agentenblick_browser_config_' + marker, 'proxyConfigBefore': execute('cat', PROXY_CONFIG), 'configSaved': False, 'configMutated': False, 'complete': False}
    save(state)
    config_filter = 'configuration_key IN (' + ','.join(literal(key) for key in KEYS) + ')'
    sql('CREATE TABLE ' + state['configBackup'] + ' LIKE system_config')
    state['configSaved'] = True
    save(state)
    sql('INSERT INTO ' + state['configBackup'] + ' SELECT * FROM system_config WHERE ' + config_filter)
    state['configMutated'] = True
    save(state)
    sql('DELETE FROM system_config WHERE ' + config_filter)
    for key, value in zip(KEYS, ['true', 'true', 'false']):
        console('system:config:set', '--json', key, value)
    status, _ = call(token, 'sales-channel-domain', {'id': state['domain'], 'url': URL, 'salesChannelId': row['channel'], 'languageId': row['language'], 'currencyId': row['currency'], 'snippetSetId': row['snippet']})
    require(status in [200, 204], 'Browser sales channel domain creation failed')
    status, _ = call(token, 'category', {'id': state['category'], 'parentId': row['navigation'], 'name': 'Clothing · Agentenblick Demo', 'active': True, 'type': 'page', 'productAssignmentType': 'product'})
    require(status in [200, 204], 'Demo clothing category creation failed')
    for gross, label in [(24, 'T-shirt coton'), (39, 'T-shirt premium')]:
        product = {'id': uuid.uuid4().hex, 'name': 'Agentenblick Demo · ' + label, 'grossEur': gross}
        state['products'].append(product)
        save(state)
        payload = {'id': product['id'], 'name': product['name'], 'description': '<p>Produit synthétique pour démonstration locale Agentenblick. Aucun achat réel ni paiement externe.</p>', 'productNumber': 'BROWSER-' + marker + '-' + str(gross), 'active': True, 'stock': 20, 'isCloseout': True, 'minPurchase': 1, 'taxId': tax['id'], 'price': [{'currencyId': row['currency'], 'gross': gross, 'net': round(gross / (1 + tax['rate'] / 100), 6), 'linked': True}], 'visibilities': [{'salesChannelId': row['channel'], 'visibility': 30}], 'categories': [{'id': state['category']}]}
        status, _ = call(token, 'product', payload)
        require(status in [200, 204], 'Synthetic shirt creation failed')
        product['detailUrl'] = URL + '/detail/' + product['id']
        save(state)
    vhost = '''<VirtualHost *:80>
    ServerName agentenblick-browser.localhost
    ProxyPreserveHost On
    ProxyAddHeaders Off
    RequestHeader set User-Agent "ExampleTestAgent/1.0 BrowserDemo"
    RequestHeader set X-Agentenblick-Test-Ip "172.29.240.43"
    ProxyPass / http://127.0.0.1:18081/ connectiontimeout=5 timeout=60
    ProxyPassReverse / http://127.0.0.1:18081/
    ErrorLog /var/log/apache2/agentenblick-browser-error.log
</VirtualHost>
'''
    write(VHOST, vhost)
    native_browser_sessions(state)
    execute('a2enmod', 'headers', 'proxy', 'proxy_http', user='root')
    execute('a2ensite', 'agentenblick-browser', user='root')
    execute('apache2ctl', 'configtest', user='root')
    restart_proxy()
    console('cache:clear')
    execute('apache2ctl', 'graceful', user='root')
    # Local read-only smoke; use a fixed host header, never browser automation.
    request = urllib.request.Request('http://127.0.0.1:18080/', headers={'Host': HOST})
    with urllib.request.urlopen(request, timeout=40) as response:
        require(response.status == 200, 'Browser fixture vhost failed its HTTP smoke')
        text = response.read().decode(errors='replace')
        require('Clothing' in text, 'Demo category did not appear in storefront navigation')
    state['complete'] = True
    state['categoryUrl'] = URL + '/navigation/' + state['category']
    save(state)
    return summary(state)


def cleanup():
    ensure_bench()
    require(STATE.exists(), 'No owned browser fixture state exists')
    state = json.loads(STATE.read_text())
    require(state.get('container') == CONTAINER and re.fullmatch('[a-f0-9]{12}', state.get('marker', '')), 'Invalid owned fixture state')
    token = authentication()
    customer_ids = sql('SELECT LOWER(HEX(id)) FROM customer WHERE email=' + literal(state['guestEmail'])).splitlines()
    order_ids = []
    for customer in customer_ids:
        require(re.fullmatch('[a-f0-9]{32}', customer), 'Invalid owned customer identity')
        order_ids.extend(sql('SELECT LOWER(HEX(order_id)) FROM order_customer WHERE customer_id=UNHEX(' + literal(customer) + ')').splitlines())
    for entity, ids in [('order', order_ids), ('customer', customer_ids), ('product', [item['id'] for item in state['products']]), ('category', [state['category']]), ('sales-channel-domain', [state['domain']])]:
        for entity_id in ids:
            require(re.fullmatch('[a-f0-9]{32}', entity_id), 'Invalid owned entity identity')
            status, _ = call(token, entity + '/' + entity_id, method='DELETE')
            require(status in [200, 204, 404], 'Owned fixture cleanup failed for ' + entity)
    execute('a2dissite', 'agentenblick-browser', user='root')
    execute('rm', '-f', VHOST, user='root')
    write(PROXY_CONFIG, state['proxyConfigBefore'])
    if state.get('sessionDirectory'):
        require(state['sessionDirectory'] == '/var/www/html/var/agentenblick-browser-sessions-' + state['marker'], 'Invalid owned session directory')
        execute('python3', '-c', 'import shutil,sys;shutil.rmtree(sys.argv[1],ignore_errors=True)', state['sessionDirectory'], user='root')
    if state['configSaved']:
        config_filter = 'configuration_key IN (' + ','.join(literal(key) for key in KEYS) + ')'
        require(re.fullmatch('agentenblick_browser_config_[a-f0-9]{12}', state['configBackup']), 'Invalid config backup identity')
        if state.get('configMutated'):
            sql('START TRANSACTION; DELETE FROM system_config WHERE ' + config_filter + '; INSERT INTO system_config SELECT * FROM ' + state['configBackup'] + '; COMMIT')
            console('system:config:set', '--json', KEYS[2], 'false')
        sql('DROP TABLE ' + state['configBackup'])
    console('cache:clear')
    execute('apache2ctl', 'configtest', user='root')
    execute('apache2ctl', 'graceful', user='root')
    STATE.unlink()
    return {'cleanedOwnedFixture': True, 'products': len(state['products']), 'orders': len(order_ids), 'customers': len(customer_ids), 'configurationRestored': True, 'heuristicsEnabled': False}


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('action', choices=['setup', 'status', 'cleanup'], nargs='?', default='setup')
    arguments = parser.parse_args()
    try:
        result = cleanup() if arguments.action == 'cleanup' else (summary(json.loads(STATE.read_text())) if arguments.action == 'status' else setup())
        print(json.dumps(result, indent=2))
    except Exception as error:
        print(json.dumps({'ready': False, 'failure': str(error) if isinstance(error, AssertionError) else type(error).__name__, 'recoveryState': str(STATE)}))
        raise SystemExit(1)

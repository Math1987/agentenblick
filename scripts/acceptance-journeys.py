#!/usr/bin/env python3
"""Real HTTP journey acceptance, restricted to the disposable agentenblick-shop.

Creates two synthetic products and one guest/order, then deletes only those IDs.
Only its own sessions are aged by 31 minutes to exercise the inactivity closer.
No external payment route is called; delivery of emails is disabled temporarily.
Run explicitly after the plugin has been copied and migrated by the coordinator.
"""
import argparse
import hashlib
import importlib.util
import json
import re
import time
import urllib.error
import urllib.request
import uuid
from datetime import datetime, timezone
from acceptance import CONTAINER, ROOT, console, execute, sql

SPEC = importlib.util.spec_from_file_location('agentenblick_admin_acceptance', ROOT / 'scripts/acceptance-admin.py')
admin = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(admin)
OUT = ROOT / '.local/journey-acceptance.json'
CONFIG_KEYS = ['core.listing.hideCloseoutProductsWhenOutOfStock', 'core.mailerSettings.disableDelivery']
TRANSPORT = r'''
import json,sys,urllib.request,urllib.error
p=json.loads(sys.argv[1]); req=urllib.request.Request('http://127.0.0.1:18081'+p['path'],method=p['method'],headers=p['headers'],data=None if p['body'] is None else p['body'].encode())
try: response=urllib.request.build_opener(urllib.request.ProxyHandler({})).open(req,timeout=40)
except urllib.error.HTTPError as e: response=e
with response:
 raw=response.read(); print(json.dumps({'status':response.status,'headers':dict(response.headers),'body':raw.decode(errors='replace')}))
'''


def require(value, message):
    if not value:
        raise AssertionError(message)


def literal(value):
    return "CONVERT(X'" + str(value).encode().hex() + "' USING utf8mb4) COLLATE utf8mb4_unicode_ci"


def require_id(value):
    require(re.fullmatch('[a-f0-9]{32}', value or '') is not None, 'Invalid synthetic entity identity')
    return value


def json_rows(statement):
    value = sql(statement)
    return [json.loads(line) for line in value.splitlines()] if value else []


def admin_call(path, token, payload=None, method=None):
    req = urllib.request.Request(admin.BASE + path, method=method, data=None if payload is None else json.dumps(payload).encode(), headers={'Authorization': 'Bearer ' + token, 'Accept': 'application/json', 'Content-Type': 'application/json'})
    try:
        response = urllib.request.urlopen(req, timeout=40)
    except urllib.error.HTTPError as error:
        response = error
    with response:
        raw = response.read()
        return response.status, json.loads(raw) if raw else {}


class Store:
    def __init__(self, access_key, user_agent, ip='172.29.240.42'):
        self.key, self.ua, self.ip = access_key, user_agent, ip
        self.context = uuid.uuid4().hex

    def call(self, path, payload=None, method=None):
        args = {'path': path, 'method': method or ('POST' if payload is not None else 'GET'), 'body': None if payload is None else json.dumps(payload), 'headers': {'Host': '127.0.0.1:18080', 'Accept': 'application/json', 'Content-Type': 'application/json', 'User-Agent': self.ua, 'sw-access-key': self.key, 'sw-context-token': self.context, 'x-agentenblick-test-ip': self.ip}}
        result = json.loads(execute('python3', '-c', TRANSPORT, json.dumps(args)))
        for key, value in result['headers'].items():
            if key.lower() == 'sw-context-token':
                self.context = value
        try:
            body = json.loads(result['body'])
        except json.JSONDecodeError:
            body = {}
        return result['status'], body


def wait_requests(user_agent, minimum):
    predicate = 'user_agent=' + literal(user_agent)
    for _ in range(80):
        if int(sql('SELECT COUNT(*) FROM agentenblick_request WHERE ' + predicate)) >= minimum:
            return json_rows("SELECT JSON_OBJECT('id',LOWER(HEX(id)),'session',LOWER(HEX(session_id)),'stage',stage,'failures',failure_mask,'known',known_mask,'status',status,'proof',evidence,'order',LOWER(HEX(order_id))) FROM agentenblick_request WHERE " + predicate + ' ORDER BY observed_at,id')
        time.sleep(.1)
    raise AssertionError('Asynchronous journey capture did not complete')


def main(keep_fixtures=False):
    marker = uuid.uuid4().hex[:12]
    evidence = {'startedAt': datetime.now(timezone.utc).isoformat(), 'checks': {}, 'cleanup': {}, 'passed': False}
    backup = 'agentenblick_accept_journey_' + marker
    config_filter = 'configuration_key IN (' + ','.join(literal(key) for key in CONFIG_KEYS) + ')'
    config_saved = config_mutated = False
    token = None
    products, customer, order = [], None, None
    email = 'journey-' + marker + '@example.invalid'
    privacy_term = 'empty-private-search-' + marker
    try:
        require(CONTAINER == 'agentenblick-shop', 'Only the disposable Agentenblick bench is allowed')
        require(re.search(r'Environment\s+test\b', console('about')) is not None, 'APP_ENV=test required')
        status, _, raw = admin.request('oauth/token', body=json.dumps({'grant_type': 'password', 'client_id': 'administration', 'scopes': 'write', 'username': 'admin', 'password': 'shopware'}).encode())
        require(status == 200, 'Disposable admin authentication failed')
        token = json.loads(raw)['access_token']
        require(admin.request('_action/agentenblick/journey/summary')[0] == 401, 'Journey API allows anonymous access')
        # Resolve only known bench settings; no keys/tokens are included in evidence.
        shop = json_rows("SELECT JSON_OBJECT('id',LOWER(HEX(sc.id)),'accessKey',sc.access_key,'currency',LOWER(HEX(sc.currency_id)),'shipping',LOWER(HEX(sc.shipping_method_id)),'country',LOWER(HEX(sc.country_id)),'url',d.url) FROM sales_channel sc JOIN sales_channel_domain d ON d.sales_channel_id=sc.id WHERE d.url='http://127.0.0.1:18080' LIMIT 1")
        require(len(shop) == 1, 'Expected isolated demo storefront domain')
        shop = shop[0]
        tax = require_id(sql('SELECT LOWER(HEX(id)) FROM tax ORDER BY tax_rate DESC LIMIT 1'))
        payment = require_id(sql("SELECT LOWER(HEX(pm.id)) FROM payment_method pm JOIN sales_channel_payment_method scm ON scm.payment_method_id=pm.id WHERE scm.sales_channel_id=UNHEX(" + literal(shop['id']) + ") AND pm.active=1 AND pm.technical_name IN ('payment_invoicepayment','payment_cashpayment') ORDER BY pm.technical_name LIMIT 1"))
        sql('CREATE TABLE ' + backup + ' LIKE system_config')
        config_saved = True
        sql('INSERT INTO ' + backup + ' SELECT * FROM system_config WHERE ' + config_filter)
        config_mutated = True
        # Clear scoped overrides only for these two backed-up keys.
        sql('DELETE FROM system_config WHERE ' + config_filter)
        console('system:config:set', '--json', CONFIG_KEYS[0], 'false')
        console('system:config:set', '--json', CONFIG_KEYS[1], 'true')
        console('cache:clear')
        evidence['checks']['environment'] = {'test': True, 'emailDeliveryDisabled': True, 'payment': 'local_manual', 'externalPaymentCalls': 0}

        for stock in [20, 0]:
            product = uuid.uuid4().hex
            products.append(product)
            status, _ = admin_call('product', token, {'id': product, 'name': 'Agentenblick synthetic ' + marker, 'productNumber': 'AGB-' + marker + '-' + str(stock), 'active': True, 'stock': stock, 'isCloseout': True, 'minPurchase': 1, 'taxId': tax, 'price': [{'currencyId': shop['currency'], 'gross': 12, 'net': 10, 'linked': False}], 'visibilities': [{'salesChannelId': shop['id'], 'visibility': 30}]})
            require(status in [200, 204], 'Could not create the owned synthetic product')
        available, depleted = products
        store = Store(shop['accessKey'], 'Agentenblick acceptance setup')
        require(store.call('/store-api/context')[0] == 200, 'Could not open Store API context')
        require(store.call('/store-api/context', {'paymentMethodId': payment, 'shippingMethodId': shop['shipping'], 'countryId': shop['country']}, 'PATCH')[0] == 200, 'Could not select local manual checkout methods')
        status, body = store.call('/store-api/account/register', {'guest': True, 'firstName': 'Synthetic', 'lastName': 'Acceptance', 'email': email, 'storefrontUrl': shop['url'], 'acceptedDataProtection': True, 'billingAddress': {'firstName': 'Synthetic', 'lastName': 'Acceptance', 'street': 'Synthetic Street 1', 'zipcode': '10115', 'city': 'Berlin', 'countryId': shop['country']}})
        require(status == 200, 'Synthetic guest registration failed')
        customer = require_id(body.get('id'))
        # Registration can rotate the context; start the measured journey after it.
        main_ua = 'ExampleTestAgent/1.0 JourneyAcceptance/' + marker + '/ordered'
        store.ua = main_ua
        require(store.call('/store-api/search', {'search': privacy_term, 'limit': 1})[0] == 200, 'Search request failed')
        require(store.call('/store-api/product/' + depleted, {})[0] == 200, 'Out-of-stock detail is not visible on the isolated fixture')
        require(store.call('/store-api/product/' + available, {})[0] == 200, 'Available product detail failed')
        status, cart = store.call('/store-api/checkout/cart/line-item', {'items': [{'id': available, 'referencedId': available, 'type': 'product', 'quantity': 1}]})
        require(status == 200 and len(cart.get('lineItems', [])) == 1, 'Synthetic product was not added to cart')
        status, body = store.call('/store-api/checkout/order', {})
        require(status == 200, 'Synthetic Store API order failed')
        order = require_id(body.get('id'))
        rows = wait_requests(main_ua, 5)
        sessions = {row['session'] for row in rows}
        require(len(sessions) == 1, 'Stable post-registration Store API token did not link one session')
        main_session = require_id(next(iter(sessions)))
        require(all(row['proof'] == 'verified_ip' for row in rows), 'Test proxy did not produce verified IP identity')
        require(any(row['stage'] == 'search' and row['known'] & 1 and row['failures'] & 1 for row in rows), 'Zero-result search event was not captured')
        require(any(row['stage'] == 'product' and row['known'] & 2 and row['failures'] & 4 for row in rows), 'Out-of-stock product event was not captured')
        require(any(row['stage'] == 'cart' for row in rows), 'Cart step was not captured')
        require(any(row['stage'] == 'order' and row['order'] == order for row in rows), 'Shopware order UUID is not linked to the agent session')
        require(all(row['known'] & 64 == 0 for row in rows), 'Order creation incorrectly claims payment processing was observed')
        evidence['checks']['orderedJourney'] = {'sessionId': main_session, 'requests': len(rows), 'proof': 'verified_ip', 'steps': [row['stage'] for row in rows], 'zeroResults': True, 'outOfStock': True, 'orderLinked': True, 'orderIdSha256': hashlib.sha256(order.encode()).hexdigest(), 'paymentOutcome': 'unknown'}

        # A separate declared agent ends at cart; a third reaches checkout without an order.
        scenario_ids = {'order': main_session}
        for label in ['cart', 'checkout']:
            ua = 'GPTBot/1.0 JourneyAcceptance/' + marker + '/' + label
            anonymous = Store(shop['accessKey'], ua, '198.51.100.20')
            require(anonymous.call('/store-api/checkout/cart/line-item', {'items': [{'id': available, 'referencedId': available, 'type': 'product', 'quantity': 1}]})[0] == 200, 'Abandonment fixture cart failed')
            if label == 'checkout':
                status, _ = anonymous.call('/store-api/checkout/order', {})
                require(400 <= status < 500, 'Anonymous checkout unexpectedly created an order')
            rows = wait_requests(ua, 1 if label == 'cart' else 2)
            require(all(row['proof'] == 'declared_unverified' for row in rows), 'Unverified agent was promoted')
            ids = {row['session'] for row in rows}
            require(len(ids) == 1, 'Abandonment context unexpectedly changed session')
            scenario_ids[label] = require_id(next(iter(ids)))
        scoped_ids = ','.join('UNHEX(' + literal(value) + ')' for value in scenario_ids.values())
        # Change only timestamps on this run's observations, never another session.
        sql('START TRANSACTION; UPDATE agentenblick_request SET observed_at=DATE_SUB(observed_at,INTERVAL 31 MINUTE) WHERE session_id IN (' + scoped_ids + '); UPDATE agentenblick_session SET first_seen=DATE_SUB(first_seen,INTERVAL 31 MINUTE),last_seen=DATE_SUB(last_seen,INTERVAL 31 MINUTE),last_stage_at=DATE_SUB(last_stage_at,INTERVAL 31 MINUTE) WHERE id IN (' + scoped_ids + '); COMMIT')
        console('agentenblick:journey', 'close')
        for label, session_id in scenario_ids.items():
            records = json_rows("SELECT JSON_OBJECT('closed',closed_at IS NOT NULL,'contribution',contribution) FROM agentenblick_session WHERE id=UNHEX(" + literal(session_id) + ')')
            require(len(records) == 1 and records[0]['closed'] == 1, 'Inactive owned session was not closed')
            contribution = records[0]['contribution']
            if isinstance(contribution, str):
                contribution = json.loads(contribution)
            metrics = contribution['metrics']
            if label == 'order':
                require('failure.abandoned_cart' not in metrics and 'failure.abandoned_checkout' not in metrics, 'An ordered session was counted as abandoned')
            else:
                require('failure.abandoned_' + label in metrics, 'Observed final step did not produce the expected abandonment')
        before = sql('SELECT SHA2(GROUP_CONCAT(HEX(id),contribution ORDER BY id),256) FROM agentenblick_session WHERE id IN (' + scoped_ids + ')')
        console('agentenblick:journey', 'close')
        require(before == sql('SELECT SHA2(GROUP_CONCAT(HEX(id),contribution ORDER BY id),256) FROM agentenblick_session WHERE id IN (' + scoped_ids + ')'), 'Repeated closure changed an owned contribution')
        evidence['checks']['closure'] = {'agedOnlyOwnSessionsMinutes': 31, 'sessions': 3, 'cartAbandoned': True, 'checkoutAbandoned': True, 'orderedNotAbandoned': True, 'idempotent': True}
        status, detail = admin_call('_action/agentenblick/journey/sessions/' + main_session + '?days=7', token)
        require(status == 200, 'Authenticated journey detail failed')
        text = json.dumps(detail)
        require(email not in text and privacy_term not in text and store.context not in text and '198.51.100.20' not in text, 'Forbidden request data leaked through journey API')
        require(any(item.get('orderId') == order for item in detail['items']), 'Journey API omitted the permitted order linkage')
        columns = sql("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='shopware' AND TABLE_NAME='agentenblick_request' AND DATA_TYPE IN ('varchar','char','text','mediumtext','json')").splitlines()
        leaks = 0
        for column in columns:
            leaks += int(sql('SELECT COUNT(*) FROM agentenblick_request WHERE session_id IN (' + scoped_ids + ') AND (`' + column + '` LIKE ' + literal('%' + email + '%') + ' OR `' + column + '` LIKE ' + literal('%' + privacy_term + '%') + ' OR `' + column + '` LIKE ' + literal('%' + store.context + '%') + ')'))
        require(leaks == 0, 'Forbidden marker retained in request storage')
        evidence['checks']['privacy'] = {'textColumnsScanned': len(columns), 'forbiddenMatches': leaks, 'apiOnlyMinimizedFields': True, 'queryAndBodyTermsAbsent': True}
        evidence['passed'] = True
    except Exception as error:
        evidence['failure'] = str(error) if isinstance(error, AssertionError) else type(error).__name__
        raise
    finally:
        cleanup_errors = []
        # Recover only this run's guest and orders if a response was interrupted.
        if token and not keep_fixtures:
            order_ids = []
            try:
                if customer is None:
                    result = sql('SELECT LOWER(HEX(id)) FROM customer WHERE email=' + literal(email))
                    customer = result if re.fullmatch('[a-f0-9]{32}', result or '') else None
                if customer:
                    result = sql('SELECT LOWER(HEX(order_id)) FROM order_customer WHERE customer_id=UNHEX(' + literal(customer) + ')')
                    order_ids = [require_id(value) for value in result.splitlines()]
            except Exception:
                cleanup_errors.append('fixture_discovery')
            if order and order not in order_ids:
                order_ids.append(order)
            for entity, ids in [('order', order_ids), ('customer', [customer] if customer else []), ('product', products)]:
                for entity_id in ids:
                    try:
                        status, _ = admin_call(entity + '/' + entity_id, token, method='DELETE')
                        if status not in [200, 204, 404]:
                            cleanup_errors.append(entity)
                    except Exception:
                        cleanup_errors.append(entity)
            evidence['cleanup']['ownBusinessFixturesDeleted'] = not cleanup_errors
        elif keep_fixtures:
            evidence['cleanup']['fixturesRetainedExplicitly'] = True
            evidence['cleanup']['fixtureIds'] = {'products': products, 'customer': customer, 'order': order}
        if config_saved:
            try:
                if config_mutated:
                    sql('START TRANSACTION; DELETE FROM system_config WHERE ' + config_filter + '; INSERT INTO system_config SELECT * FROM ' + backup + '; COMMIT')
                    console('cache:clear')
                sql('DROP TABLE ' + backup)
                evidence['cleanup']['configurationRestored'] = True
            except Exception:
                evidence['cleanup']['configurationBackupRetained'] = backup
                cleanup_errors.append('configuration')
        if cleanup_errors:
            evidence['passed'] = False
            evidence['cleanup']['failedEntities'] = cleanup_errors
        evidence['finishedAt'] = datetime.now(timezone.utc).isoformat()
        OUT.parent.mkdir(exist_ok=True)
        OUT.write_text(json.dumps(evidence, indent=2) + '\n')
    require(evidence['passed'], 'Acceptance cleanup failed; inspect the safe evidence file')
    return evidence


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--keep-fixtures', action='store_true', help='Explicitly retain only the synthetic fixtures for a later local demonstration')
    arguments = parser.parse_args()
    try:
        result = main(arguments.keep_fixtures)
        print(json.dumps({'passed': result['passed'], 'evidence': str(OUT)}))
    except Exception:
        print(json.dumps({'passed': False, 'evidence': str(OUT)}))
        raise SystemExit(1)

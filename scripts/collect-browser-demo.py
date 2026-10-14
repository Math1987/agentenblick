#!/usr/bin/env python3
"""Collect redacted proof for the active browser fixture; SQL reads only.

Evidence contains counts, route names, status codes and bounded journey labels.
No entity/session ID, cookie, context, search term or form value is exported.
"""
import json
import re
from datetime import datetime, timezone
from acceptance import ROOT, CONTAINER, console, sql

STATE = ROOT / '.local/browser-demo-state.json'
OUT = ROOT / '.local/browser-demo-acceptance.json'
UA = 'ExampleTestAgent/1.0 BrowserDemo'


def literal(value):
    return "CONVERT(X'" + str(value).encode().hex() + "' USING utf8mb4) COLLATE utf8mb4_unicode_ci"


def rows(statement):
    return [json.loads(line) for line in sql(statement).splitlines()]


def main():
    if CONTAINER != 'agentenblick-shop' or re.search(r'Environment\s+test\b', console('about')) is None:
        raise AssertionError('Only the disposable test bench is allowed')
    state = json.loads(STATE.read_text())
    if not state.get('complete') or state.get('container') != CONTAINER:
        raise AssertionError('An active owned browser fixture is required')
    where = 'user_agent=' + literal(UA)
    summary = rows("SELECT JSON_OBJECT('route',route,'method',method,'status',status,'stage',stage,'evidence',evidence,'requests',COUNT(*)) FROM agentenblick_request WHERE " + where + ' GROUP BY route,method,status,stage,evidence ORDER BY route,status')
    recent = rows("SELECT JSON_OBJECT('route',route,'method',method,'status',status,'stage',stage,'evidence',evidence,'knownMask',known_mask,'failureMask',failure_mask,'orderLinked',order_id IS NOT NULL) FROM agentenblick_request WHERE " + where + ' ORDER BY observed_at DESC,id DESC LIMIT 30')
    # IDs only select groups inside this process; never copy them into evidence.
    session_ids = sql('SELECT DISTINCT LOWER(HEX(session_id)) FROM agentenblick_request WHERE ' + where).splitlines()
    journeys = []
    for ordinal, session_id in enumerate(session_ids, 1):
        if not re.fullmatch('[a-f0-9]{32}', session_id):
            raise AssertionError('Invalid session grouping')
        journey = rows("SELECT JSON_OBJECT('route',route,'method',method,'status',status,'stage',stage,'evidence',evidence,'knownMask',known_mask,'failureMask',failure_mask,'orderLinked',order_id IS NOT NULL) FROM agentenblick_request WHERE session_id=UNHEX(" + literal(session_id) + ') AND ' + where + ' ORDER BY observed_at,id')
        journeys.append({'ordinal': ordinal, 'requests': len(journey), 'sequence': journey})
    # Exact synthetic email plus common personal-body markers. Count all textual
    # analytics columns, including heuristic state, without exporting any match.
    markers = [state['guestEmail'], '172.29.240.43', 'Synthetic Street', 'Teststrasse']
    columns = sql("SELECT TABLE_NAME,COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='shopware' AND TABLE_NAME LIKE 'agentenblick\\_%' AND TABLE_NAME NOT LIKE 'agentenblick\\_browser\\_%' AND DATA_TYPE IN ('varchar','char','text','mediumtext','json')").splitlines()
    matches = 0
    for line in columns:
        table, column = line.split('\t')
        if not re.fullmatch('[a-zA-Z0-9_]+', table + column):
            raise AssertionError('Unexpected SQL metadata identifier')
        predicate = ' OR '.join('CAST(`' + column + '` AS CHAR) LIKE ' + literal('%' + marker + '%') for marker in markers)
        matches += int(sql('SELECT COUNT(*) FROM `' + table + '` WHERE ' + predicate))
    orders = int(sql('SELECT COUNT(*) FROM order_customer WHERE email=' + literal(state['guestEmail'])))
    payload = {'collectedAt': datetime.now(timezone.utc).isoformat(), 'container': CONTAINER, 'fixedFictitiousAgent': True, 'requestCount': sum(row['requests'] for row in summary), 'sessionCount': len(journeys), 'byRoute': summary, 'recentRequestsNewestFirst': recent, 'journeys': journeys, 'syntheticOrdersPlaced': orders, 'privacy': {'textColumnsScanned': len(columns), 'forbiddenMarkerMatches': matches, 'noIdentifiersExported': True}, 'lastSeoNavigationRecognized': any(row['route'] == 'frontend.navigation.page' and row['stage'] == 'browse' for row in recent), 'lastProductRecognized': any(row['route'] == 'frontend.detail.page' and row['stage'] == 'product' for row in recent), 'checkoutFormFailureObserved': any(row['failureMask'] & 128 for row in recent)}
    OUT.write_text(json.dumps(payload, indent=2) + '\n')
    print(json.dumps({'evidence': str(OUT), 'requests': payload['requestCount'], 'sessions': payload['sessionCount'], 'seoNavigation': payload['lastSeoNavigationRecognized'], 'product': payload['lastProductRecognized'], 'checkoutFormFailure': payload['checkoutFormFailureObserved'], 'syntheticOrders': orders, 'forbiddenMarkerMatches': matches}))


if __name__ == '__main__':
    main()

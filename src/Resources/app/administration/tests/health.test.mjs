import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';

let definition;
const source = readFileSync(new URL('../src/module/agentenblick/component/agentenblick-health/index.js', import.meta.url), 'utf8').replace(/^import .*;\n/gm, '');
runInNewContext(source, { template: '', Shopware: { Component: { register: (_name, value) => { definition = value; } } } });
const domain = { id: 'a'.repeat(32), salesChannelId: 'b'.repeat(32), url: 'https://shop.test/en', eligible: true };
const second = { ...domain, id: 'c'.repeat(32), url: 'https://second.test' };
const stored = () => ({ offline: false, domains: [domain, second], selectedDomainId: domain.id, snapshot: null, findings: [], afterLlms: null });

function component({ viewer = true, editor = true } = {}) {
    const calls = [];
    const events = [];
    const context = {
        ...definition.data(), filters: { days: 7 },
        acl: { can: (name) => name === 'agentenblick.viewer' ? viewer : editor },
        $i18n: { locale: 'en-GB' }, $t: (key) => key, $te: () => true,
        $emit: (...args) => events.push(args),
        agentenblickApiService: {
            getHealth: async (params) => { calls.push(['get', params]); return stored(); },
            probeHealth: async (body) => { calls.push(['post', body]); return { completed: true }; },
        },
    };
    for (const [name, method] of Object.entries(definition.methods)) context[name] = method.bind(context);
    for (const [name, getter] of Object.entries(definition.computed)) Object.defineProperty(context, name, { get: () => getter.call(context) });
    return { context, calls, events };
}

test('opening, refreshing and changing a domain only read stored local results', async () => {
    const { context, calls } = component();
    await definition.created.call(context);
    await context.load();
    context.changeDomain(second.id);
    await Promise.resolve();
    assert.ok(calls.every(([verb]) => verb === 'get'));
    assert.equal(calls.length, 3);
    assert.equal(context.autoTest, false);
    assert.equal(calls[2][1].domainId, second.id);
});

test('viewer, editor, offline and configured-domain guards apply to public probes', async () => {
    for (const options of [{ viewer: false }, { editor: false }, {}]) {
        const { context, calls } = component(options);
        await context.load();
        if (!options.viewer && options.viewer !== undefined) assert.equal(calls.length, 0);
        if (!Object.keys(options).length) context.health.offline = true;
        await context.probe();
        assert.ok(calls.every(([verb]) => verb !== 'post'));
    }
    const { context, calls } = component();
    await context.load();
    context.selectedDomainId = 'https://private.invalid/';
    await context.probe();
    assert.ok(calls.every(([verb]) => verb !== 'post'));
});

test('only explicit probe sends a selected domain ID and an explicit auto-test boolean', async () => {
    const { context, calls, events } = component();
    await context.load();
    context.autoTest = true;
    assert.ok(calls.every(([verb]) => verb === 'get'));
    await context.probe();
    assert.equal(calls[1][0], 'post');
    assert.deepEqual(Object.keys(calls[1][1]).sort(), ['autoTest', 'domainId']);
    assert.equal(calls[1][1].autoTest, true);
    assert.equal(calls[1][1].domainId, domain.id);
    assert.equal(calls[2][0], 'get');
    assert.deepEqual(events, [['busy', true], ['busy', false]]);
});

test('a pending probe cannot run twice or be replaced by a filter or domain refresh', async () => {
    const { context, calls } = component();
    await context.load();
    let finish;
    context.agentenblickApiService.probeHealth = (body) => { calls.push(['post', body]); return new Promise((resolve) => { finish = resolve; }); };
    const pending = context.probe();
    await context.probe();
    await context.load();
    context.changeDomain(second.id);
    definition.watch.filters.handler.call(context);
    assert.equal(calls.length, 2);
    assert.equal(context.selectedDomainId, domain.id);
    finish({ completed: true });
    await pending;
    assert.equal(calls.length, 3);
});

test('failed public probes preserve the previous snapshot without claiming success', async () => {
    const { context } = component();
    await context.load();
    context.health.snapshot = { checkedAt: '2026-10-14T09:00:00Z', resources: [] };
    context.agentenblickApiService.probeHealth = async () => { throw new Error('offline changed'); };
    await context.probe();
    assert.equal(context.probeFailed, true);
    assert.equal(context.snapshot.checkedAt, '2026-10-14T09:00:00Z');
    assert.equal(context.probing, false);
});

test('late local responses and responses after unmount cannot replace current results', async () => {
    const { context } = component();
    let finish;
    context.agentenblickApiService.getHealth = () => new Promise((resolve) => { finish = resolve; });
    const first = context.load();
    context.agentenblickApiService.getHealth = async () => ({ ...stored(), marker: 'current' });
    await context.load();
    finish({ ...stored(), marker: 'old' });
    await first;
    assert.equal(context.health.marker, 'current');
    definition.beforeUnmount.call(context);
    await context.load();
    assert.equal(context.health.marker, 'current');
});

test('unknown availability, policy and missing counts never become success or permission', async () => {
    const { context } = component();
    assert.equal(context.state(null), 'unknown');
    assert.equal(context.state({ state: 'unrecognized', statusCode: 200 }), 'unknown');
    assert.equal(context.decision({}), 'unknown');
    assert.equal(context.decision({ decision: 'allow_all' }), 'unknown');
    assert.equal(context.count(undefined), '—');
    assert.equal(context.count(0), '0');
    assert.equal(context.statusCode(null), '—');
    assert.equal(context.statusCode(404), '404');
    context.agentenblickApiService.getHealth = async () => ({ ...stored(), offline: undefined });
    await context.load();
    assert.equal(context.loadFailed, true);
    assert.equal(context.canProbe, false);
});

test('DE and EN expose the same caveats and declared User-Agent tests never prove identity', () => {
    const en = JSON.parse(readFileSync(new URL('../src/module/agentenblick/snippet/en-GB.json', import.meta.url))).agentenblick.health;
    const de = JSON.parse(readFileSync(new URL('../src/module/agentenblick/snippet/de-DE.json', import.meta.url))).agentenblick.health;
    const keys = (value, prefix = '') => Object.entries(value).flatMap(([key, item]) => typeof item === 'object' ? keys(item, `${prefix}${key}.`) : [`${prefix}${key}`]).sort();
    assert.deepEqual(keys(en), keys(de));
    assert.match(en.serverIpNotice, /not the operators/);
    assert.match(de.serverIpNotice, /nicht von den IP-Adressen der Betreiber/);
    assert.match(en.resourceHint, /does not validate/);
    assert.match(en.robotsHint, /only to its displayed path/);
    assert.match(en.noFindings, /does not establish/);
});

test('an incomplete acknowledgement never advertises a completed public check', async () => {
    const { context, calls } = component();
    await context.load();
    context.agentenblickApiService.probeHealth = async () => ({ completed: false });
    await context.probe();
    assert.equal(context.probeFailed, true);
    assert.equal(calls.length, 1);
    assert.equal(context.probing, false);
    assert.equal(context.probeMethod(undefined), '—');
    assert.equal(context.probeMethod('OPTIONS'), 'OPTIONS');
    assert.equal(context.findingVariant('error'), 'critical');
});

test('the parent keeps the domain and filters stable while an explicit public probe is pending', () => {
    let parent;
    const code = readFileSync(new URL('../src/module/agentenblick/page/agentenblick-index/index.js', import.meta.url), 'utf8').replace(/^import .*;\n/gm, '');
    runInNewContext(code, { template: '', Shopware: { Component: { register: (_name, value) => { parent = value; } }, Mixin: { getByName: () => ({}) } } });
    let reads = 0;
    const value = { ...parent.data(), healthProbing: true, activeTab: 'health', tabs: ['overview', 'health'], loadData: () => { reads += 1; } };
    parent.methods.onDaysChange.call(value, 30);
    parent.methods.onChannelChange.call(value, 'a'.repeat(32));
    parent.methods.selectTab.call(value, 'overview');
    parent.methods.refreshVisible.call(value);
    assert.equal(value.days, 7);
    assert.equal(value.salesChannelId, '');
    assert.equal(value.activeTab, 'health');
    assert.equal(reads, 0);
    value.healthProbing = false;
    value.$refs = { health: { load: () => { reads += 1; } } };
    parent.methods.refreshVisible.call(value);
    assert.equal(reads, 1);
});


test('configured HTTP or local domains remain visible but cannot trigger a public probe', async () => {
    const { context, calls } = component();
    context.agentenblickApiService.getHealth = async () => ({ ...stored(), domains: [{ ...domain, url: 'http://localhost', eligible: false, reasonCode: 'public_https_required' }] });
    await context.load();
    assert.equal(context.domains.length, 1);
    assert.equal(context.selectedDomain.url, 'http://localhost');
    assert.equal(context.canProbe, false);
    await context.probe();
    assert.ok(calls.every(([verb]) => verb !== 'post'));
    context.health.domains[0].eligible = undefined;
    assert.equal(context.canProbe, false);
});


test('public rule groups are normalized text in one escaped pre block, separate from path decisions', async () => {
    const { context } = component();
    await context.load();
    context.health.snapshot = { robots: { groups: [{ agents: ['gptbot', 'claudebot'], rules: [{ allow: false, pattern: '/<script>alert(1)</script>' }, { allow: true, pattern: '/public' }] }] } };
    assert.equal(context.publicRules, 'User-agent: gptbot\nUser-agent: claudebot\nDisallow: /<script>alert(1)</script>\nAllow: /public');
    const template = readFileSync(new URL('../src/module/agentenblick/component/agentenblick-health/agentenblick-health.html.twig', import.meta.url), 'utf8');
    assert.match(template, /<pre>{{ publicRules }}<\/pre>/);
    assert.doesNotMatch(template, /v-html/);
    assert.equal(context.rules.length, 0);
});

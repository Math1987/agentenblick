import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';

let definition;
const source = readFileSync(new URL('../src/module/agentenblick/component/agentenblick-benchmark/index.js', import.meta.url), 'utf8')
    .replace(/^import .*;\n/gm, '');
runInNewContext(source, {
    template: '',
    Shopware: { Component: { register: (_name, value) => { definition = value; } }, Mixin: { getByName: () => ({}) } },
});

function component({ editor = true, enabled = false, offline = false, pendingWithdrawal = false, reasonCode = 'disabled' } = {}) {
    const calls = [];
    const status = { enabled, offline, pendingWithdrawal, reasonCode, consentVersion: '2' };
    const context = {
        ...definition.data(),
        acl: { can: () => editor },
        $i18n: { locale: 'en-GB' },
        $t: (key) => key,
        $te: () => true,
        createNotificationSuccess: () => {},
        createNotificationError: () => {},
        agentenblickApiService: {
            getBenchmarkStatus: async () => { calls.push('status'); return status; },
            getBenchmarkPreview: async () => { calls.push('preview'); return { items: [], networkSent: false }; },
            getBenchmarkHistory: async () => { calls.push('history'); return { items: [] }; },
            getBenchmarkComparison: async () => { calls.push('comparison'); return { available: false }; },
            updateBenchmark: async (action, body) => { calls.push({ action, body }); },
        },
    };
    for (const [key, method] of Object.entries(definition.methods)) context[key] = method.bind(context);
    for (const [key, getter] of Object.entries(definition.computed)) Object.defineProperty(context, key, { get: () => getter.call(context) });
    context.status = status;
    return { context, calls };
}

test('opening the panel loads only local endpoints and never submits or compares', async () => {
    const { context, calls } = component();
    await context.load();
    assert.deepEqual(calls.sort(), ['history', 'preview', 'status']);
    assert.equal(context.consent, false);
    assert.equal(context.canTransmit, false);
});

test('enable needs editor privilege and explicit consent and is blocked offline or during withdrawal', async () => {
    for (const options of [{ editor: false }, { offline: true }, { pendingWithdrawal: true }]) {
        const { context, calls } = component(options);
        context.consent = true;
        await context.update('enable');
        assert.deepEqual(calls, []);
    }
    const { context, calls } = component();
    await context.update('enable');
    assert.deepEqual(calls, []);
    context.consent = true;
    context.$i18n.locale = 'de-DE';
    await context.update('enable');
    assert.deepEqual(JSON.parse(JSON.stringify(calls[0])), { action: 'enable', body: { consent: true, consentVersion: '2', locale: 'de-DE' } });
    assert.equal(context.consent, false);
});

test('send and comparison are blocked before opt-in, while offline or during withdrawal', async () => {
    for (const options of [{ enabled: false }, { enabled: true, offline: true }, { enabled: true, pendingWithdrawal: true }]) {
        const { context, calls } = component(options);
        await context.update('send');
        await context.compare();
        assert.deepEqual(calls, []);
    }
});

test('viewer can fetch an opted-in comparison but cannot send or withdraw', async () => {
    const { context, calls } = component({ editor: false, enabled: true });
    await context.update('send');
    await context.update('disable');
    assert.deepEqual(calls, []);
    await context.compare();
    assert.deepEqual(calls, ['comparison']);
});

test('editor withdrawal remains available while offline', async () => {
    const { context, calls } = component({ enabled: true, offline: true });
    await context.update('disable');
    assert.equal(calls[0].action, 'disable');
});

test('comparison refuses below-threshold, malformed and unordered published metrics', () => {
    const { context } = component();
    const valid = { installations: 5, p25: 2, median: 3, p75: 4 };
    assert.equal(context.published(valid), true);
    for (const invalid of ['suppressed', { ...valid, installations: 4 }, { ...valid, median: 101 }, { ...valid, p25: 5 }]) {
        assert.equal(Boolean(context.published(invalid)), false);
    }
    context.comparison = { report: { cohorts: [], cells: [
        { cohortId: 'safe', metrics: { aiSessionsPct: valid } },
        { cohortId: 'unsafe', metrics: { aiSessionsPct: { ...valid, installations: 2 } } },
    ] } };
    assert.equal(context.comparisonCells.length, 1);
    assert.equal(context.comparisonCells[0].cohortId, 'safe');
});

test('terminal refusals never promise an automatic retry in the journal', () => {
    const { context } = component();
    for (const code of ['http_400', 'http_409', 'http_410', 'http_413', 'http_422']) {
        assert.equal(context.rowStatus('failed', code), 'agentenblick.benchmark.submission.rejected');
    }
    assert.equal(context.rowStatus('failed', 'http_503'), 'agentenblick.benchmark.submission.failed');
    assert.equal(context.rowStatus('failed', 'transport_failed'), 'agentenblick.benchmark.submission.failed');
    assert.equal(context.rowStatus('rejected', 'weekly_channel_limit'), 'agentenblick.benchmark.submission.rejected');
    assert.equal(context.rowStatus('expired', 'period_expired'), 'agentenblick.benchmark.submission.expired');
    assert.equal(context.rowStatus('withdrawn', 'withdrawn'), 'agentenblick.benchmark.submission.withdrawn');
});

test('a failed network submission is not reported as a successful send', async () => {
    const { context } = component({ enabled: true });
    const notices = [];
    context.createNotificationSuccess = () => notices.push('success');
    context.createNotificationError = () => notices.push('error');
    context.agentenblickApiService.updateBenchmark = async () => ({ status: 'failed' });
    await context.update('send');
    assert.deepEqual(notices, ['error']);
});

test('a cloned real identity in test shows an explicit state and never previews, sends, enables or compares', async () => {
    const { context, calls } = component({ enabled: true, reasonCode: 'test_identity_conflict' });
    await context.load();
    assert.deepEqual(calls.sort(), ['history', 'status']);
    assert.equal(context.stateLabel, 'agentenblick.benchmark.state.test_identity_conflict');
    context.consent = true;
    calls.length = 0;
    await context.update('enable');
    await context.update('send');
    await context.compare();
    assert.deepEqual(calls, []);
});

test('pending registration does not show a connected state', () => {
    const { context } = component({ enabled: true, reasonCode: 'registration_pending' });
    assert.equal(context.stateLabel, 'agentenblick.benchmark.state.registration_pending');
});

test('previous consent pauses submissions and comparison until version 2 is explicitly checked', async () => {
    const { context, calls } = component({ enabled: true, reasonCode: 'consent_update_required' });
    context.status.consentVersion = '1';
    context.status.requiredConsentVersion = '2';
    assert.equal(context.needsConsentUpdate, true);
    assert.equal(context.stateLabel, 'agentenblick.benchmark.state.consent_update_required');
    await context.update('send');
    await context.compare();
    await context.update('enable');
    assert.equal(calls.length, 0);
    context.consent = true;
    await context.update('enable');
    assert.equal(calls[0].action, 'enable');
    assert.equal(calls[0].body.consentVersion, '2');
});

test('withdrawal stays available with outdated consent and an unsupported future consent cannot be accepted', async () => {
    const { context, calls } = component({ enabled: true, reasonCode: 'consent_update_required' });
    context.status.consentVersion = '1';
    context.status.requiredConsentVersion = '3';
    context.consent = true;
    await context.update('enable');
    await context.update('send');
    assert.equal(calls.length, 0);
    await context.update('disable');
    assert.equal(calls[0].action, 'disable');
});

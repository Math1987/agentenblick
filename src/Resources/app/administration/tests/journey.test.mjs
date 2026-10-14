import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';

let definition;
const source = readFileSync(new URL('../src/module/agentenblick/component/agentenblick-journey/index.js', import.meta.url), 'utf8').replace(/^import .*;\n/gm, '');
runInNewContext(source, { template: '', Shopware: { Component: { register: (_name, value) => { definition = value; } } } });
const agent = { id: 'a'.repeat(32), kind: 'browsing_agent', evidence: 'verified_signature', lastStage: 'browse', maxStage: 'cart' };

function component({ mode = 'funnel', viewer = true } = {}) {
    const calls = [];
    const context = {
        ...definition.data(), mode, filters: { days: 7, salesChannelId: 'channel' },
        acl: { can: (privilege) => viewer && privilege === 'agentenblick.viewer' },
        $i18n: { locale: 'en-GB' }, $t: (key) => key, $te: () => true,
        agentenblickApiService: {
            getJourneySummary: async (filters) => { calls.push(['summary', filters]); return { funnel: [], failures: [] }; },
            getJourneySessions: async (filters) => { calls.push(['sessions', filters]); return { items: [agent], total: 1 }; },
            getJourneySession: async (id, filters) => { calls.push(['detail', id, filters]); return { session: agent, items: [] }; },
        },
    };
    for (const [key, method] of Object.entries(definition.methods)) context[key] = method.bind(context);
    for (const [key, getter] of Object.entries(definition.computed)) Object.defineProperty(context, key, { get: () => getter.call(context) });
    return { context, calls };
}

test('opening a tab reads only its local API and preserves channel/period filters', async () => {
    for (const mode of ['funnel', 'failures', 'journeys']) {
        const { context, calls } = component({ mode });
        await context.load();
        assert.equal(calls.length, 1);
        assert.equal(calls[0][0], mode === 'journeys' ? 'sessions' : 'summary');
        assert.equal(calls[0][1].salesChannelId, 'channel');
        assert.equal(calls[0][1].days, 7);
    }
});

test('viewer privilege is required for both summaries and session details', async () => {
    const { context, calls } = component({ viewer: false });
    await context.load();
    await context.openDetail(agent);
    assert.equal(calls.length, 0);
    assert.equal(context.selectedId, null);
});

test('human and unknown session kinds never appear in journeys or detail', async () => {
    const { context, calls } = component({ mode: 'journeys' });
    const human = { ...agent, kind: 'human_or_unknown' };
    context.agentenblickApiService.getJourneySessions = async () => ({ items: [human, { ...agent, kind: undefined }, agent], total: 3 });
    await context.load();
    assert.equal(context.sessions.length, 1);
    assert.equal(context.sessions[0].kind, 'browsing_agent');
    await context.openDetail(human);
    assert.equal(calls.length, 0);
    context.agentenblickApiService.getJourneySession = async () => ({ session: human, items: [{ route: 'secret' }] });
    await context.openDetail(agent);
    assert.equal(context.detail, null);
    assert.equal(context.detailFailed, true);
});

test('unknown outcomes and invalid denominators never become a zero-percent success', () => {
    const { context } = component();
    for (const [value, denominator] of [[0, 0], [0, null], [null, 10], [11, 10], [-1, 10]]) {
        assert.equal(context.percent(value, denominator), '—');
        assert.equal(context.barWidth(value, denominator), 0);
    }
    assert.equal(context.percent(0, 10), '0%');
    assert.equal(context.percent(1, 4), '25%');
    assert.equal(context.stage(null), 'agentenblick.journey.unknown');
    assert.equal(context.stage('unrecognized'), 'agentenblick.journey.unknown');
    assert.equal(context.count(null), '—');
});

test('independently observed steps retain last/max difference and do not infer skipped steps', async () => {
    const { context } = component({ mode: 'journeys' });
    await context.openDetail(agent);
    assert.equal(context.detail.session.lastStage, 'browse');
    assert.equal(context.detail.session.maxStage, 'cart');
    assert.deepEqual(Array.from(context.stepNames(['discover', 'cart'])), ['agentenblick.journey.stage.discover', 'agentenblick.journey.stage.cart']);
    context.summary = { funnel: [
        { evidence: 'verified_signature', sessions: 3, steps: { cart: 1 } },
        { evidence: 'declared_unverified', sessions: 4, steps: { cart: 1 } },
    ] };
    assert.deepEqual(Array.from(context.proofGroups, (group) => group.key), ['verified', 'declared']);
    assert.equal(context.proofGroups[0].rows[0].steps.browse, undefined);
});

test('an older filter response cannot overwrite a newer response', async () => {
    const { context } = component();
    let firstResolve;
    context.agentenblickApiService.getJourneySummary = () => new Promise((resolve) => { firstResolve = resolve; });
    const first = context.load();
    context.agentenblickApiService.getJourneySummary = async () => ({ marker: 'new' });
    await context.load();
    firstResolve({ marker: 'old' });
    await first;
    assert.equal(context.summary.marker, 'new');
});

test('closing detail or changing filters prevents a late response from reopening it', async () => {
    const { context } = component();
    let resolveDetail;
    context.agentenblickApiService.getJourneySession = () => new Promise((resolve) => { resolveDetail = resolve; });
    const request = context.openDetail(agent);
    context.closeDetail();
    resolveDetail({ session: agent, items: [{ route: 'known' }] });
    await request;
    assert.equal(context.selectedId, null);
    assert.equal(context.detail, null);
});

test('missing failure outcomes remain separately inspectable and zero known failures remain visible', () => {
    const { context } = component({ mode: 'failures' });
    context.summary = { failures: [
        { code: 'search_empty', evidence: 'verified_ip', sessions: 0, eligibleSessions: 0 },
        { code: 'search_empty', evidence: 'declared_unverified', sessions: 0, eligibleSessions: 2 },
    ] };
    assert.equal(context.failures.length, 1);
    assert.equal(context.failures[0].eligibleSessions, 2);
    assert.equal(context.unknownFailures.length, 1);
});

test('DE and EN snippets have identical keys and preserve the no-success inference', () => {
    const root = '../src/module/agentenblick/snippet/';
    const en = JSON.parse(readFileSync(new URL(`${root}en-GB.json`, import.meta.url)));
    const de = JSON.parse(readFileSync(new URL(`${root}de-DE.json`, import.meta.url)));
    const keys = (object, prefix = '') => Object.entries(object).flatMap(([key, value]) => typeof value === 'object' ? keys(value, `${prefix}${key}.`) : [`${prefix}${key}`]).sort();
    assert.deepEqual(keys(en), keys(de));
    assert.match(en.agentenblick.journey.failuresHint, /never means success/);
    assert.match(de.agentenblick.journey.failuresHint, /niemals Erfolg/);
});

test('suspected automation has its own group and can never be presented as verified identity', () => {
    const { context } = component();
    context.summary = { funnel: [
        { evidence: 'verified_ip', sessions: 2 },
        { evidence: 'declared_unverified', sessions: 3 },
        { evidence: 'suspected', sessions: 5 },
        { evidence: 'none', sessions: 1 },
    ] };
    assert.deepEqual(Array.from(context.proofGroups, (group) => group.key), ['verified', 'declared', 'suspected', 'other']);
    assert.equal(context.proofGroups[0].rows.length, 1);
    assert.equal(context.proofClass('suspected'), 'agentenblick-proof--suspected');
    assert.equal(context.hasSuspected, true);
    assert.equal(context.agentName({ evidence: 'suspected', operator: 'unknown', agent: 'unknown' }), 'agentenblick.heuristics.unidentifiedAutomation');
});

test('heuristic score and flags are bounded diagnostics, never defaulted to a probability', () => {
    const { context } = component();
    assert.equal(context.heuristicScore({ score: 72 }), '72/100');
    for (const value of [null, {}, { score: null }, { score: -1 }, { score: 101 }, { score: 60.5 }]) assert.equal(context.heuristicScore(value), '—');
    assert.equal(context.heuristicVersion({ version: '1' }), '1');
    assert.equal(context.heuristicVersion({ version: '<script>' }), 'agentenblick.journey.unknown');
    assert.equal(context.heuristicThreshold({ threshold: 60 }), 60);
    assert.equal(context.heuristicThreshold({ threshold: 0 }), '—');
    context.$te = (key) => key === 'agentenblick.heuristics.flag.tool_user_agent';
    assert.deepEqual(Array.from(context.heuristicFlags({ flags: ['tool_user_agent', 'tool_user_agent', 'unknown_flag', '<html>'] })), ['tool_user_agent']);
});

test('partial observation stays explicit without discarding observed steps or known failures', async () => {
    const { context } = component({ mode: 'journeys' });
    const partial = { ...agent, evidence: 'suspected', observationPartial: true, steps: ['cart'], failures: ['cart_error'] };
    context.agentenblickApiService.getJourneySession = async () => ({ session: partial, items: [] });
    await context.openDetail(partial);
    assert.equal(context.isObservationPartial(context.detail.session), true);
    assert.deepEqual(context.detail.session.steps, ['cart']);
    assert.deepEqual(context.detail.session.failures, ['cart_error']);
    for (const value of [null, {}, { observationPartial: false }, { observationPartial: 'true' }]) assert.equal(context.isObservationPartial(value), false);
    const en = JSON.parse(readFileSync(new URL('../src/module/agentenblick/snippet/en-GB.json', import.meta.url)));
    assert.match(en.agentenblick.journey.observationPartial, /abandonment are not inferred/);
});

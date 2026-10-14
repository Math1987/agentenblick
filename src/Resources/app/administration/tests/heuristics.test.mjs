import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';

let definition;
const source = readFileSync(new URL('../src/module/agentenblick/component/agentenblick-heuristics/index.js', import.meta.url), 'utf8').replace(/^import .*;\n/gm, '');
runInNewContext(source, { template: '', Shopware: {
    Component: { register: (_name, value) => { definition = value; } }, Mixin: { getByName: () => ({}) },
} });
const settings = { enabled: false, threshold: 60, minThreshold: 30, maxThreshold: 100, experimental: true, calibrated: false, version: '1', status: 'disabled' };

function component({ viewer = true, editor = true } = {}) {
    const calls = [];
    const notifications = [];
    const context = {
        ...definition.data(),
        acl: { can: (privilege) => privilege === 'agentenblick.viewer' ? viewer : editor },
        $t: (key) => key,
        $emit: (name, value) => notifications.push([name, value]),
        createNotificationSuccess: (value) => notifications.push(['success', value]),
        createNotificationError: (value) => notifications.push(['error', value]),
        agentenblickApiService: {
            getHeuristicSettings: async () => { calls.push(['get']); return { ...settings }; },
            updateHeuristicSettings: async (body) => { calls.push(['post', body]); return { ...settings, ...body }; },
        },
    };
    for (const [key, method] of Object.entries(definition.methods)) context[key] = method.bind(context);
    for (const [key, getter] of Object.entries(definition.computed)) Object.defineProperty(context, key, { get: () => getter.call(context) });
    return { context, calls, notifications };
}

test('opening settings reads local state only and keeps detection disabled by default', async () => {
    const { context, calls } = component();
    assert.equal(context.enabled, false);
    await context.load();
    assert.deepEqual(calls, [['get']]);
    assert.equal(context.enabled, false);
    assert.equal(context.settings.calibrated, false);
    assert.equal(context.dirty, false);
    assert.equal(Boolean(context.canSave), false);
});

test('viewer and editor permissions guard both reads and mutation methods', async () => {
    const denied = component({ viewer: false, editor: false });
    await denied.context.load();
    denied.context.changeEnabled(true);
    await denied.context.save();
    assert.equal(denied.calls.length, 0);
    const viewer = component({ editor: false });
    await viewer.context.load();
    viewer.context.changeEnabled(true);
    viewer.context.changeThreshold(30);
    await viewer.context.save();
    assert.equal(viewer.context.enabled, false);
    assert.equal(viewer.context.threshold, 60);
    assert.deepEqual(viewer.calls, [['get']]);
});

test('editing a checkbox or threshold never sends until explicit save', async () => {
    const { context, calls } = component();
    await context.load();
    context.changeEnabled(true);
    context.changeThreshold('75');
    assert.deepEqual(calls, [['get']]);
    assert.equal(context.settings.enabled, false);
    assert.equal(Boolean(context.canSave), true);
    await context.save();
    assert.equal(calls.length, 2);
    assert.deepEqual(JSON.parse(JSON.stringify(calls[1])), ['post', { enabled: true, threshold: 75 }]);
    assert.equal(context.settings.enabled, true);
    assert.equal(context.dirty, false);
});

test('invalid thresholds cannot be sent and zero or an empty field is not silently defaulted', async () => {
    const { context, calls } = component();
    await context.load();
    context.changeEnabled(true);
    for (const value of ['', null, 0, 29, 101, 60.5, 'not-a-number']) {
        context.changeThreshold(value);
        assert.equal(context.thresholdValid, false);
        await context.save();
    }
    assert.deepEqual(calls, [['get']]);
    for (const value of [30, 100]) { context.changeThreshold(value); assert.equal(context.thresholdValid, true); }
});

test('a failed save does not advertise activation as confirmed', async () => {
    const { context, notifications } = component();
    await context.load();
    context.changeEnabled(true);
    context.agentenblickApiService.updateHeuristicSettings = async () => { throw new Error('network failure'); };
    await context.save();
    assert.equal(context.settings.enabled, false);
    assert.equal(context.enabled, true);
    assert.equal(context.saving, false);
    assert.equal(notifications.some(([name]) => name === 'error'), true);
});

test('one pending save cannot send twice or be overwritten by a refresh', async () => {
    const { context, calls } = component();
    await context.load();
    context.changeEnabled(true);
    let resolve;
    context.agentenblickApiService.updateHeuristicSettings = (body) => {
        calls.push(['post', body]);
        return new Promise((done) => { resolve = done; });
    };
    const first = context.save();
    await context.save();
    await context.load();
    context.changeEnabled(false);
    assert.equal(context.enabled, true);
    assert.equal(calls.length, 2);
    resolve({ ...settings, enabled: true });
    await first;
    assert.equal(context.settings.enabled, true);
});

test('invalid or stale settings fail closed', async () => {
    const { context } = component();
    context.agentenblickApiService.getHeuristicSettings = async () => ({ ...settings, enabled: 'true' });
    await context.load();
    assert.equal(context.failed, true);
    assert.equal(context.settings, null);
    assert.equal(Boolean(context.canSave), false);
    let resolve;
    context.agentenblickApiService.getHeuristicSettings = () => new Promise((done) => { resolve = done; });
    const pending = context.load();
    definition.beforeUnmount.call(context);
    resolve({ ...settings, enabled: true });
    await pending;
    assert.equal(context.settings, null);
});

test('both languages explicitly disclose experimental status and all named signals', () => {
    for (const locale of ['en-GB', 'de-DE']) {
        const snippets = JSON.parse(readFileSync(new URL(`../src/module/agentenblick/snippet/${locale}.json`, import.meta.url))).agentenblick;
        assert.equal(Object.keys(snippets.heuristics.flag).length, 12);
        assert.match(snippets.heuristics.experimental, locale === 'en-GB' ? /not calibrated/i : /nicht kalibriert/i);
        assert.match(snippets.heuristics.notProof, locale === 'en-GB' ? /not proof/i : /kein Nachweis/i);
        assert.match(snippets.heuristics.timelineStartsAtDetection, locale === 'en-GB' ? /not reconstructed/ : /nicht rekonstruiert/);
    }
});

// Contract tests for our browser adapter, not evidence that an agent volunteers.
// User acceptance must use a real browser's WebMCP discovery and a normal task.
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import { setImmediate as nextTurn } from 'node:timers/promises';
import vm from 'node:vm';

const source = await readFile(new URL('../src/Resources/public/webmcp.js', import.meta.url), 'utf8');
const valid = { service: 'codex', intent: 'product_search' };
const acknowledgement = (input = valid) => ({
    status: 'declared', ...input, evidence: 'declared_unverified',
});

function harness(options = {}) {
    const fetches = [];
    const tools = [];
    const submit = {};
    const callbacks = {};
    const button = { disabled: true };
    const status = { hidden: true, textContent: '' };
    const values = { service: '', intent: '' };
    const form = {
        elements: { namedItem: name => ({ value: values[name] }) },
        querySelector: () => button,
        addEventListener: (name, callback) => { submit[name] = callback; },
    };
    const root = {
        dataset: {
            endpoint: options.endpoint || '/agentenblick/webmcp/declare',
            successMessage: 'Recorded, unverified.',
            errorMessage: 'Could not confirm.',
            invalidMessage: 'Choose available options.',
        },
        querySelector: selector => selector.includes('-form]') ? form : status,
    };
    const document = {
        readyState: options.loading ? 'loading' : 'complete',
        querySelector: () => options.noRoot ? null : root,
        addEventListener: (name, callback) => { callbacks[name] = callback; },
    };
    const nativeContext = {
        registerTool(tool) {
            assert.equal(this, nativeContext, 'native method must retain its receiver');
            if (options.registerThrows) throw new Error('policy');
            if (options.registerRejects) return Promise.reject(new Error('policy'));
            tools.push(tool);
            return options.syncRegistration ? undefined : Promise.resolve();
        },
    };
    if (!options.noApi) document.modelContext = nativeContext;
    if (options.apiGetterThrows) Object.defineProperty(document, 'modelContext', { get() { throw new Error('policy'); } });
    const window = { location: new URL(options.url || 'https://shop.example/product?private=discard') };
    window.top = window;
    window.self = window;
    if (options.iframe) window.top = {};
    const context = vm.createContext({
        document, window, URL, AbortController, setTimeout, clearTimeout,
        navigator: options.navigator || {},
        fetch: async (url, request) => {
            fetches.push({ url, request });
            if (options.fetch) return options.fetch(url, request);
            return { ok: true, json: async () => acknowledgement(JSON.parse(request.body)) };
        },
    });
    const run = () => vm.runInContext(source, context);
    run();
    return { fetches, tools, submit, callbacks, button, status, values, root, document, run };
}

test('loading a page registers a transparent write tool without declaring or fetching', async () => {
    const h = harness();
    await nextTurn();
    assert.equal(h.tools.length, 1);
    assert.equal(h.fetches.length, 0);
    assert.equal(h.status.hidden, true);
    assert.equal(h.root.dataset.webmcpState, 'registered');
    const tool = h.tools[0];
    assert.equal(tool.name, 'agentenblick_declare_visit');
    assert.equal(tool.annotations.readOnlyHint, false);
    assert.equal(tool.annotations.consequentialHint, false);
    assert.match(tool.description, /writes/);
    assert.match(tool.description, /unverified/);
    assert.match(tool.description, /Shopping does not require/);
    assert.equal(tool.inputSchema.additionalProperties, false);
});

test('an explicit call sends only the bounded declaration to the first-party endpoint', async () => {
    const h = harness();
    const result = await h.tools[0].execute(valid);
    assert.deepEqual(JSON.parse(JSON.stringify(result)), acknowledgement());
    assert.equal(h.fetches.length, 1);
    const { url, request } = h.fetches[0];
    assert.equal(url, 'https://shop.example/agentenblick/webmcp/declare');
    assert.deepEqual(JSON.parse(request.body), valid);
    assert.equal(request.method, 'POST');
    assert.equal(request.credentials, 'same-origin');
    assert.equal(request.mode, 'same-origin');
    assert.equal(request.cache, 'no-store');
    assert.equal(request.referrerPolicy, 'no-referrer');
    assert.equal(request.headers['Content-Type'], 'application/json');
    assert.equal(request.headers['X-Requested-With'], 'XMLHttpRequest');
    assert.equal(h.status.textContent, 'Recorded, unverified.');
    assert.equal(h.status.hidden, false);
    assert.equal(h.button.disabled, false);
});

test('unknown service is an explicit valid declaration, without invented attribution', async () => {
    const h = harness();
    const input = { service: 'unknown', intent: 'other' };
    await h.tools[0].execute(input);
    assert.deepEqual(JSON.parse(h.fetches[0].request.body), input);
});

test('rejects unsupported or extra fields before transmission, including private free text', async () => {
    const h = harness();
    const invalid = [
        null, [], {}, { service: 'codex' }, { intent: 'other' },
        { service: 'invented', intent: 'other' }, { service: 'codex', intent: 'private shopping detail' },
        { ...valid, prompt: 'private user message' }, { ...valid, url: 'https://private.example/' },
        { service: { toString: () => 'codex' }, intent: 'other' },
        Object.create(valid),
    ];
    for (const input of invalid) await assert.rejects(h.tools[0].execute(input));
    assert.equal(h.fetches.length, 0);
    assert.equal(h.status.textContent, 'Choose available options.');
});

test('ordinary form fallback remains available without the WebMCP API', async () => {
    const h = harness({ noApi: true });
    assert.equal(h.root.dataset.webmcpState, 'unavailable');
    assert.equal(h.button.disabled, false);
    assert.equal(h.tools.length, 0);
    assert.equal(h.fetches.length, 0);
    Object.assign(h.values, valid);
    let prevented = false;
    await h.submit.submit({ preventDefault: () => { prevented = true; } });
    assert.equal(prevented, true);
    assert.equal(h.fetches.length, 1);
    assert.equal(h.status.textContent, 'Recorded, unverified.');
});

test('invalid ordinary form values cannot transmit a declaration', async () => {
    const h = harness({ noApi: true });
    await h.submit.submit({ preventDefault() {} });
    assert.equal(h.fetches.length, 0);
    assert.equal(h.status.textContent, 'Choose available options.');
});

test('missing API is not replaced by an invented implementation or unrelated navigator API', () => {
    let deprecatedCalled = false;
    const h = harness({ noApi: true, navigator: { modelContext: { registerTool() { deprecatedCalled = true; } } } });
    assert.equal(h.document.modelContext, undefined);
    assert.equal(deprecatedCalled, false);
    assert.equal(h.tools.length, 0);
});

test('a rejected native registration leaves normal shopping and the form available', async () => {
    for (const option of ['registerThrows', 'registerRejects']) {
        const h = harness({ [option]: true });
        await nextTurn();
        assert.equal(h.root.dataset.webmcpState, 'unavailable');
        assert.equal(h.fetches.length, 0);
        assert.equal(h.button.disabled, false);
        Object.assign(h.values, valid);
        await h.submit.submit({ preventDefault() {} });
        assert.equal(h.fetches.length, 1);
    }
});

test('registration supports synchronous native implementations', async () => {
    const h = harness({ syncRegistration: true });
    await Promise.resolve();
    assert.equal(h.root.dataset.webmcpState, 'registered');
});

test('an inaccessible native API or iframe does not register a tool or break the form', () => {
    for (const options of [{ apiGetterThrows: true }, { iframe: true }]) {
        const h = harness(options);
        assert.equal(h.tools.length, 0);
        assert.equal(h.fetches.length, 0);
        assert.equal(h.root.dataset.webmcpState, 'unavailable');
        assert.equal(h.button.disabled, false);
    }
});

test('forbidden cross-origin or non-HTTPS endpoints never register or submit', () => {
    for (const endpoint of ['https://outside.example/declare', '//outside.example/declare',
        'http://shop.example/declare', 'javascript:alert(1)', '/declare?prompt=private',
        '/declare#fragment', 'https://user:password@shop.example/declare']) {
        const h = harness({ endpoint });
        assert.equal(h.tools.length, 0, endpoint);
        assert.equal(h.fetches.length, 0, endpoint);
        assert.equal(h.button.disabled, true, endpoint);
    }
    assert.equal(harness({ url: 'http://shop.example/' }).tools.length, 0);
});

test('missing opt-in markup is a complete no-op', () => {
    const h = harness({ noRoot: true });
    assert.equal(h.tools.length, 0);
    assert.equal(h.fetches.length, 0);
});

test('DOMContentLoaded binding and repeated script evaluation register only once', () => {
    const h = harness({ loading: true });
    assert.equal(h.tools.length, 0);
    h.callbacks.DOMContentLoaded();
    assert.equal(h.tools.length, 1);
    h.document.readyState = 'complete';
    h.run();
    assert.equal(h.tools.length, 1);
});

test('HTTP errors and malformed acknowledgements never claim successful detection', async () => {
    const responses = [
        { ok: false, json: async () => acknowledgement() },
        { ok: true, json: async () => { throw new Error('bad JSON'); } },
        { ok: true, json: async () => ({ ok: true }) },
        { ok: true, json: async () => ({ ...acknowledgement(), evidence: 'verified' }) },
        { ok: true, json: async () => ({ ...acknowledgement(), service: 'claude' }) },
    ];
    for (const response of responses) {
        const h = harness({ fetch: async () => response });
        await assert.rejects(h.tools[0].execute(valid), /could not be confirmed/);
        assert.equal(h.status.textContent, 'Could not confirm.');
        assert.equal(h.button.disabled, false);
    }
});

test('network failure preserves the form and permits a later explicit retry', async () => {
    let failed = false;
    const h = harness({ fetch: async () => {
        if (!failed) { failed = true; throw new Error('offline'); }
        return { ok: true, json: async () => acknowledgement() };
    } });
    await assert.rejects(h.tools[0].execute(valid));
    assert.equal(h.button.disabled, false);
    await h.tools[0].execute(valid);
    assert.equal(h.status.textContent, 'Recorded, unverified.');
});

test('retries only a transient session race, at most twice within the explicit action', async () => {
    let attempts = 0;
    const h = harness({ fetch: async () => {
        attempts++;
        if (attempts < 3) return {
            ok: false, status: 409, json: async () => ({ status: 'error', reason: 'session_busy' }),
        };
        return { ok: true, json: async () => acknowledgement() };
    } });
    await h.tools[0].execute(valid);
    assert.equal(attempts, 3);
    assert.equal(h.status.textContent, 'Recorded, unverified.');
});

test('persistent session race stops after three total requests and never claims success', async () => {
    const h = harness({ fetch: async () => ({
        ok: false, status: 409, json: async () => ({ status: 'error', reason: 'session_unavailable' }),
    }) });
    await assert.rejects(h.tools[0].execute(valid));
    assert.equal(h.fetches.length, 3);
    assert.equal(h.status.textContent, 'Could not confirm.');
});

test('other conflict responses and server errors do not trigger automatic retries', async () => {
    for (const status of [409, 500]) {
        const h = harness({ fetch: async () => ({
            ok: false, status, json: async () => ({ status: 'error', reason: 'session_cookie_required' }),
        }) });
        await assert.rejects(h.tools[0].execute(valid));
        assert.equal(h.fetches.length, 1);
    }
});

test('already-cancelled tool calls never transmit a declaration', async () => {
    const h = harness();
    const controller = new AbortController();
    controller.abort();
    await assert.rejects(h.tools[0].execute(valid, { signal: controller.signal }), /cancelled/);
    assert.equal(h.fetches.length, 0);
});

test('native cancellation reaches the in-flight fetch and clears the busy state', async () => {
    const h = harness({ fetch: async (_, request) => new Promise((_, reject) => {
        request.signal.addEventListener('abort', () => reject(new Error('aborted')), { once: true });
    }) });
    const controller = new AbortController();
    const pending = h.tools[0].execute(valid, { signal: controller.signal });
    assert.equal(h.button.disabled, true);
    await assert.rejects(h.tools[0].execute(valid), /already in progress/);
    controller.abort();
    await assert.rejects(pending, /could not be confirmed/);
    assert.equal(h.button.disabled, false);
    assert.equal(h.fetches.length, 1);
});

test('untrusted text in a successful response is neither rendered nor returned', async () => {
    const h = harness({ fetch: async () => ({ ok: true, json: async () => ({
        ...acknowledgement(), message: '<script>private instructions</script>',
    }) }) });
    const result = await h.tools[0].execute(valid);
    assert.deepEqual(JSON.parse(JSON.stringify(result)), acknowledgement());
    assert.equal(h.status.textContent, 'Recorded, unverified.');
});

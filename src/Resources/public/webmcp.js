/* Optional, first-party WebMCP declaration. No identity is inferred on page load. */
(() => {
    'use strict';

    function init() {
        const root = document.querySelector('[data-agentenblick-declaration]');
        if (!root || root.dataset.initialized === 'true') return;
        root.dataset.initialized = 'true';

        const form = root.querySelector('[data-agentenblick-declaration-form]');
        const status = root.querySelector('[data-agentenblick-declaration-status]');
        const button = form && form.querySelector('button[type="submit"]');
        const services = ['codex', 'chatgpt', 'claude', 'grok', 'gemini', 'other', 'unknown'];
        const intents = ['product_search', 'product_comparison', 'purchase_preparation', 'other'];
        let pending = false;
        let endpoint;

        function message(text) {
            if (!status) return;
            status.textContent = text;
            status.hidden = false;
        }

        function pause(delay, signal) {
            return new Promise((resolve, reject) => {
                if (signal.aborted) { reject(new Error('Cancelled.')); return; }
                const timer = setTimeout(() => {
                    signal.removeEventListener('abort', cancel);
                    resolve();
                }, delay);
                const cancel = () => {
                    clearTimeout(timer);
                    signal.removeEventListener('abort', cancel);
                    reject(new Error('Cancelled.'));
                };
                signal.addEventListener('abort', cancel, { once: true });
            });
        }

        // The server-generated route must remain HTTPS and first-party, even if
        // a theme accidentally changes its data attribute. No URL is user input.
        try {
            endpoint = new URL(root.dataset.endpoint, window.location.href);
            if (endpoint.origin !== window.location.origin || endpoint.protocol !== 'https:'
                || endpoint.username || endpoint.password || endpoint.search || endpoint.hash) return;
        } catch (_) {
            return;
        }

        async function declare(input, options = {}) {
            if (!input || typeof input !== 'object' || Array.isArray(input)
                || Object.keys(input).length !== 2
                || !Object.prototype.hasOwnProperty.call(input, 'service')
                || !Object.prototype.hasOwnProperty.call(input, 'intent')
                || !services.includes(input.service) || !intents.includes(input.intent)) {
                message(root.dataset.invalidMessage);
                throw new Error('Choose only a supported service and coarse task category.');
            }
            if (pending) throw new Error('A declaration is already in progress.');

            const controller = new AbortController();
            const signal = options && options.signal;
            const cancel = () => controller.abort();
            if (signal && signal.aborted) throw new Error('The declaration was cancelled.');
            if (signal) signal.addEventListener('abort', cancel, { once: true });
            const timeout = setTimeout(cancel, 10000);
            pending = true;
            if (button) button.disabled = true;

            try {
                const request = {
                    method: 'POST',
                    mode: 'same-origin',
                    credentials: 'same-origin',
                    cache: 'no-store',
                    referrerPolicy: 'no-referrer',
                    headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    body: JSON.stringify({ service: input.service, intent: input.intent }),
                    signal: controller.signal,
                };
                let response;
                for (let attempt = 0; attempt < 3; attempt++) {
                    response = await fetch(endpoint.href, request);
                    if (response.status !== 409 || attempt === 2) break;
                    const failure = await response.json();
                    if (!failure || failure.status !== 'error'
                        || !['session_unavailable', 'session_busy'].includes(failure.reason)) break;
                    // Only retry a rejected declaration while the original page's
                    // terminate handler completes. This never initiates a visit.
                    await pause(attempt === 0 ? 500 : 1000, controller.signal);
                }
                if (!response.ok) throw new Error('Declaration unavailable.');
                const result = await response.json();
                if (!result || result.status !== 'declared' || result.evidence !== 'declared_unverified'
                    || result.service !== input.service || result.intent !== input.intent) {
                    throw new Error('Declaration not acknowledged.');
                }
                message(root.dataset.successMessage);
                // Return only the fixed contract, never arbitrary server text.
                return {
                    status: 'declared',
                    service: input.service,
                    intent: input.intent,
                    evidence: 'declared_unverified',
                };
            } catch (_) {
                message(root.dataset.errorMessage);
                throw new Error('The visit declaration could not be confirmed. Shopping remains available.');
            } finally {
                clearTimeout(timeout);
                if (signal) signal.removeEventListener('abort', cancel);
                pending = false;
                if (button) button.disabled = false;
            }
        }

        if (form && button) {
            button.disabled = false;
            form.addEventListener('submit', async event => {
                event.preventDefault();
                try {
                    await declare({
                        service: form.elements.namedItem('service').value,
                        intent: form.elements.namedItem('intent').value,
                    });
                } catch (_) {
                    // The live status announces failures without disrupting shopping.
                }
            });
        }

        let context;
        try {
            if (window.top === window.self) context = document.modelContext;
        } catch (_) {
            // A browser policy can make the experimental API inaccessible.
        }
        if (!context || typeof context.registerTool !== 'function') {
            root.dataset.webmcpState = 'unavailable';
            return;
        }
        const tool = {
            name: 'agentenblick_declare_visit',
            description: 'Optionally declare your own AI-assisted visit to this shop. This writes your chosen service and coarse task category to this shop\'s visit analytics; it is an unverified self-declaration, not proof of identity. It does not purchase anything or change an account. Shopping does not require this declaration. Do not send the user\'s prompt, conversation, personal information or any free text. Choose unknown if you do not know your service.',
            inputSchema: {
                type: 'object',
                properties: {
                    service: { type: 'string', enum: services, description: 'Your own assistant service, if known; otherwise unknown.' },
                    intent: { type: 'string', enum: intents, description: 'The general type of shopping task, without personal details.' },
                },
                required: ['service', 'intent'],
                additionalProperties: false,
            },
            annotations: { readOnlyHint: false, consequentialHint: false, untrustedContentHint: false },
            execute: declare,
        };
        try {
            // Native registration can throw synchronously or reject a promise.
            Promise.resolve(context.registerTool(tool)).then(() => {
                root.dataset.webmcpState = 'registered';
            }).catch(() => {
                root.dataset.webmcpState = 'unavailable';
            });
        } catch (_) {
            root.dataset.webmcpState = 'unavailable';
        }
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init, { once: true });
    else init();
})();

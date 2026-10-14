import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync, readdirSync } from 'node:fs';
import { runInNewContext } from 'node:vm';

const directory = new URL('../src/module/agentenblick/component/agentenblick-ui/', import.meta.url);
const names = ['card', 'button', 'banner', 'select', 'checkbox'];
const source = readFileSync(new URL('index.js', directory), 'utf8').replace(/^import .*;\n/gm, '');
function definitions(version) {
    const result = {};
    const templates = Object.fromEntries(names.map((name) => [`${name}Template`, readFileSync(new URL(`${name}.html.twig`, directory), 'utf8')]));
    runInNewContext(source, { ...templates, Shopware: {
        Context: { app: { config: { version } } },
        Component: { register: (name, value) => { result[name] = value; } },
    } });
    return result;
}

function instance(name, props = {}) {
    const definition = definitions('6.6.10.29')[`agentenblick-${name}`];
    const events = [];
    const value = { ...Object.fromEntries(Object.entries(definition.props).map(([key, prop]) => [key, prop.default])), ...props, $emit: (...args) => events.push(args) };
    for (const [key, method] of Object.entries(definition.methods || {})) value[key] = method.bind(value);
    return { definition, value, events };
}

test('every supported 6.6 uses native legacy widgets and 6.7 uses Meteor', () => {
    for (const version of ['6.6.0.0', '6.6.10.29', '', undefined, 'unknown']) {
        const widgets = definitions(version);
        assert.equal(Object.keys(widgets).length, 5);
        for (const widget of Object.values(widgets)) assert.equal(widget.computed.meteorAvailable(), false);
    }
    for (const version of ['6.7.0.0-rc4', '6.7.15.0', '7.0.0.0']) {
        for (const widget of Object.values(definitions(version))) assert.equal(widget.computed.meteorAvailable(), true);
    }
});

test('warnings and errors preserve their meaning in native Shopware 6.6 alerts', () => {
    const { definition } = instance('banner');
    for (const [variant, expected] of [['attention', 'warning'], ['critical', 'error'], ['positive', 'success'], ['neutral', 'info']]) {
        assert.equal(definition.computed.legacyVariant.call({ variant }), expected);
    }
});

test('select events preserve typed periods, empty all-channel selection and channel IDs', () => {
    const { value, events } = instance('select');
    const channel = 'a'.repeat(32);
    for (const selected of [7, 30, '', channel]) value.updateValue(selected);
    assert.deepEqual(events, [7, 30, '', channel].map((selected) => ['update:modelValue', selected]));
    value.disabled = true;
    value.updateValue(7);
    assert.equal(events.length, 4);
});

test('disabled or loading buttons cannot emit actions twice through forwarded listeners', () => {
    const { definition, value, events } = instance('button');
    const event = { type: 'click' };
    value.clicked(event);
    value.disabled = true;
    value.clicked(event);
    value.disabled = false;
    value.isLoading = true;
    value.clicked(event);
    assert.deepEqual(events, [['click', event]]);
    assert.deepEqual(Array.from(definition.emits), ['click']);
    assert.equal(definition.inheritAttrs, false);
});

test('checkbox values remain booleans and a disabled consent or setting cannot be changed', () => {
    const { value, events } = instance('checkbox');
    value.updateValue(true);
    value.updateValue(false);
    value.updateValue('true');
    value.disabled = true;
    value.updateValue(true);
    assert.deepEqual(events, [['update:modelValue', true], ['update:modelValue', false]]);
});

test('legacy fields use value/update:value, while slots and attributes reach either native branch', () => {
    const widgets = definitions('6.6.10.29');
    for (const name of names) {
        const template = widgets[`agentenblick-${name}`].template;
        assert.equal((template.match(/v-bind="\$attrs"/g) || []).length, 2);
        if (['card', 'button', 'banner'].includes(name)) assert.equal((template.match(/<slot \/>/g) || []).length, 2);
        else {
            assert.match(template, /:value="modelValue"/);
            assert.match(template, /@update:value="updateValue"/);
            assert.match(template, /@update:model-value="updateValue"/);
        }
    }
    assert.match(widgets['agentenblick-select'].template, /:show-clearable-button="!hideClearableButton"/);
});

test('feature views cannot bypass compatibility wrappers with direct Meteor tags', () => {
    const module = new URL('../src/module/agentenblick/', import.meta.url);
    function visit(url) {
        for (const entry of readdirSync(url, { withFileTypes: true })) {
            const child = new URL(entry.name + (entry.isDirectory() ? '/' : ''), url);
            if (entry.isDirectory() && entry.name !== 'agentenblick-ui') visit(child);
            else if (entry.isFile() && entry.name.endsWith('.html.twig')) assert.doesNotMatch(readFileSync(child, 'utf8'), /<\/?mt-(card|button|banner|select|checkbox)\b/);
        }
    }
    visit(module);
});

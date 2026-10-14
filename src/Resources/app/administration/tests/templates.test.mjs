import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync, readdirSync } from 'node:fs';
import { compile } from '@vue/compiler-dom';

const root = new URL('../src/', import.meta.url);

function templates(directory) {
    return readdirSync(directory, { withFileTypes: true }).flatMap((entry) => {
        const path = new URL(entry.name + (entry.isDirectory() ? '/' : ''), directory);
        if (entry.isDirectory()) return templates(path);
        return entry.name.endsWith('.html.twig') ? [path] : [];
    });
}

function compileRender(template) {
    // Shopware resolves Twig inheritance blocks before Vue's runtime compiler.
    // These views use blocks only; Vue interpolation must remain untouched.
    const resolved = template.replace(/\{%\s*(?:block\s+[\w]+|endblock)\s*%\}/g, '');
    assert.doesNotMatch(resolved, /\{%/, 'Unexpected Twig logic needs a real resolver');
    const { code } = compile(resolved, { mode: 'function' });
    // compile() may produce invalid JavaScript for an event assignment. This is
    // the same final parsing step that Vue performs in compileToFunction.
    return new Function('Vue', code);
}

for (const path of templates(root)) {
    test(`Vue compiles a valid render function for ${path.pathname.slice(root.pathname.length)}`, () => {
        assert.equal(typeof compileRender(readFileSync(path, 'utf8')), 'function');
    });
}

test('runtime compilation catches invalid assignments in component event listeners', () => {
    assert.throws(() => compileRender('<agentenblick-heuristics @saving="heuristicSaving || healthProbing = $event" />'), SyntaxError);
    assert.equal(typeof compileRender('<agentenblick-heuristics @saving="heuristicSaving = $event" />'), 'function');
});

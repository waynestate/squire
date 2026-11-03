// kit.test.mjs: the docs kit's JavaScript, tested through the harness in tests/js/harness.mjs.
//
// Later tasks add their own files here; node --test runs every *.test.mjs in this folder.
import test from 'node:test';
import assert from 'node:assert/strict';
import { load } from './harness.mjs';

test('docs.js loads without throwing on an empty page', () => {
  const { document } = load('');
  // The script ran against the bare document jsdom builds for an empty string and survived.
  assert.ok(document.querySelector('body'));
  assert.equal(document.querySelectorAll('.docs-copy').length, 0);
});

test('docs.js does nothing to a page without the markup it enhances', () => {
  const html = [
    '<!doctype html>',
    '<html lang="en">',
    '<head><meta charset="utf-8"><title>Plain page</title></head>',
    '<body>',
    '<main class="docs-article"><h1>Plain</h1><p>No code blocks, no menu.</p></main>',
    '</body>',
    '</html>',
  ].join('\n');
  const { document } = load(html);
  // No .docs-article pre means no copy buttons, and no .docs-menu means no menu listener to see.
  assert.equal(document.querySelectorAll('.docs-copy').length, 0);
  assert.equal(document.querySelectorAll('.docs-copy__button').length, 0);
  assert.equal(document.querySelectorAll('button').length, 0);
});

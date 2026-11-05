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

const CODE_PAGE = '<!doctype html><html lang="en"><body><main class="docs-article"><pre><code>booster detect</code></pre></main></body></html>';

async function clickCopy(writeText) {
  const { window, document } = load(CODE_PAGE);
  Object.defineProperty(window.navigator, 'clipboard', { value: { writeText }, configurable: true });
  // docs.js may wait for DOMContentLoaded before it adds the buttons.
  await new Promise((resolve) => window.setTimeout(resolve, 0));
  const button = document.querySelector('.docs-copy__button');
  button.click();
  // Let the clipboard promise and its handlers settle.
  await new Promise((resolve) => window.setTimeout(resolve, 0));
  return { button, status: document.querySelector('.docs-copy__status') };
}

test('copy says Copied when the clipboard write succeeds (writeText resolves with undefined)', async () => {
  let copied = null;
  const { button, status } = await clickCopy((text) => {
    copied = text;
    return Promise.resolve();
  });
  assert.equal(copied, 'booster detect');
  assert.equal(button.textContent, 'Copied');
  assert.equal(status.textContent, 'Code copied.');
});

test('a code block that scrolls sideways joins the tab order, and leaves it once it fits', async () => {
  const { window, document } = load(CODE_PAGE);
  await new Promise((resolve) => window.setTimeout(resolve, 0));
  const pre = document.querySelector('.docs-article pre');
  // jsdom has no layout, so both widths start at 0: the block fits and stays out of the tab order.
  assert.equal(pre.hasAttribute('tabindex'), false);
  Object.defineProperty(pre, 'scrollWidth', { value: 900, configurable: true });
  Object.defineProperty(pre, 'clientWidth', { value: 300, configurable: true });
  window.dispatchEvent(new window.Event('resize'));
  assert.equal(pre.getAttribute('tabindex'), '0');
  Object.defineProperty(pre, 'scrollWidth', { value: 300, configurable: true });
  window.dispatchEvent(new window.Event('resize'));
  assert.equal(pre.hasAttribute('tabindex'), false);
});

test('copy says Copy failed when the clipboard write is refused', async () => {
  const { button, status } = await clickCopy(() => Promise.reject(new Error('denied')));
  assert.equal(button.textContent, 'Copy failed');
  assert.equal(status.textContent, 'Could not copy the code.');
});

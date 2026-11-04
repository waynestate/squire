// tabs.test.mjs: the code-tab behavior in kit/docs/docs.js, tested through the harness in tests/js/harness.mjs.
//
// The markup mirrors what bin/docs-site.php writes: one .docs-tabs per run of tab="..." fences, one labelled
// panel per fence, every panel visible until the script turns the set into a tablist.
import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { JSDOM } from 'jsdom';
import { load } from './harness.mjs';

const DOCS_JS = readFileSync(new URL('../../kit/docs/docs.js', import.meta.url), 'utf8');

// A stable https origin so window.localStorage exists: jsdom withholds storage on an opaque origin.
const ORIGIN = 'https://docs.squire.test/';

/** One tab set the way the builder writes it: a labelled panel per label, all visible. */
function setMarkup(key, labels) {
  const panels = labels
    .map(
      (label) =>
        `<div class="docs-tabs__panel" data-tab-label="${label}">` +
        `<p class="docs-tabs__label">${label}</p>` +
        `<pre><code>echo ${label}</code></pre></div>`,
    )
    .join('');
  return `<div class="docs-tabs" data-tabs="${key}">${panels}</div>`;
}

function pageWith(bodies) {
  return ['<!doctype html>', '<html lang="en">', '<head><meta charset="utf-8"><title>Tabs</title></head>', '<body>', bodies, '</body>', '</html>'].join('\n');
}

// The kit works on DOMContentLoaded; jsdom fires that on a later tick than the harness returns, so tests wait
// for it before asserting.
async function settled(window) {
  if (window.document.readyState === 'loading') {
    await new Promise((resolve) => {
      window.document.addEventListener('DOMContentLoaded', resolve, { once: true });
    });
  } else {
    await new Promise((resolve) => setTimeout(resolve, 0));
  }
}

test('tabs build a tablist with roles and one selected tab', async () => {
  const { window, document } = load(pageWith(setMarkup('composer|sail', ['composer', 'sail'])));
  await settled(window);

  const tablist = document.querySelector('[role="tablist"]');
  assert.ok(tablist, 'the set gains a tablist');
  assert.equal(tablist.getAttribute('aria-label'), 'Code examples');
  assert.equal(tablist.parentElement.getAttribute('data-tabs'), 'composer|sail');

  const tabs = Array.from(document.querySelectorAll('[role="tab"]'));
  assert.deepEqual(tabs.map((tab) => tab.textContent), ['composer', 'sail']);
  tabs.forEach((tab) => {
    assert.equal(tab.type, 'button');
    assert.ok(tab.id, 'every tab has an id');
    const panel = document.getElementById(tab.getAttribute('aria-controls'));
    assert.ok(panel, 'aria-controls names a real panel');
    assert.equal(panel.getAttribute('role'), 'tabpanel');
    assert.equal(panel.getAttribute('aria-labelledby'), tab.id);
    assert.equal(panel.getAttribute('tabindex'), '0');
  });

  const selected = tabs.filter((tab) => tab.getAttribute('aria-selected') === 'true');
  assert.equal(selected.length, 1, 'exactly one tab is selected');
  assert.equal(selected[0].textContent, 'composer', 'the first tab starts selected');
  assert.equal(selected[0].tabIndex, 0);
  const unselected = tabs.filter((tab) => tab.getAttribute('aria-selected') !== 'true');
  unselected.forEach((tab) => assert.equal(tab.tabIndex, -1));

  const panels = Array.from(document.querySelectorAll('.docs-tabs__panel'));
  assert.equal(panels[0].hidden, false);
  assert.equal(panels[1].hidden, true);

  // The static labels retire once the tablist carries the names.
  document.querySelectorAll('.docs-tabs__label').forEach((label) => assert.equal(label.hidden, true));
});

test('clicking a tab shows its panel and hides the others', async () => {
  const { window, document } = load(pageWith(setMarkup('composer|sail', ['composer', 'sail'])));
  await settled(window);
  const tabs = Array.from(document.querySelectorAll('[role="tab"]'));

  tabs[1].click();

  assert.equal(tabs[1].getAttribute('aria-selected'), 'true');
  assert.equal(tabs[0].getAttribute('aria-selected'), 'false');
  const panels = Array.from(document.querySelectorAll('.docs-tabs__panel'));
  assert.equal(panels[1].hidden, false);
  assert.equal(panels[0].hidden, true);
});

test('arrow keys move the selection', async () => {
  const { window, document } = load(pageWith(setMarkup('a|b|c', ['a', 'b', 'c'])));
  await settled(window);
  const tabs = Array.from(document.querySelectorAll('[role="tab"]'));
  const panels = Array.from(document.querySelectorAll('.docs-tabs__panel'));
  const press = (tab, key) => {
    tab.dispatchEvent(new window.KeyboardEvent('keydown', { key, bubbles: true }));
  };

  press(tabs[0], 'ArrowRight');
  assert.equal(tabs[1].getAttribute('aria-selected'), 'true');
  assert.equal(panels[1].hidden, false);
  assert.equal(document.activeElement, tabs[1], 'focus follows the selection');

  press(tabs[1], 'ArrowRight');
  press(tabs[2], 'ArrowRight');
  assert.equal(tabs[0].getAttribute('aria-selected'), 'true', 'ArrowRight wraps from the last tab');

  press(tabs[0], 'ArrowLeft');
  assert.equal(tabs[2].getAttribute('aria-selected'), 'true', 'ArrowLeft wraps from the first tab');

  press(tabs[2], 'Home');
  assert.equal(tabs[0].getAttribute('aria-selected'), 'true');
  press(tabs[0], 'End');
  assert.equal(tabs[2].getAttribute('aria-selected'), 'true');
});

test('choosing a tab syncs another set with the same key', async () => {
  const { window, document } = load(pageWith(setMarkup('composer|sail', ['composer', 'sail']) + setMarkup('composer|sail', ['composer', 'sail'])));
  await settled(window);
  const sets = Array.from(document.querySelectorAll('.docs-tabs'));
  assert.equal(sets.length, 2);
  const firstTabs = Array.from(sets[0].querySelectorAll('[role="tab"]'));
  const secondTabs = Array.from(sets[1].querySelectorAll('[role="tab"]'));
  const secondPanels = Array.from(sets[1].querySelectorAll('.docs-tabs__panel'));

  firstTabs[1].click();

  assert.equal(secondTabs[1].getAttribute('aria-selected'), 'true', 'the twin set follows');
  assert.equal(secondPanels[1].hidden, false);
  assert.equal(secondPanels[0].hidden, true);
});

test('the stored choice is applied on load', async () => {
  const { window, document } = load(pageWith(setMarkup('composer|sail', ['composer', 'sail'])), {
    storage: { 'squire-docs-tab:composer|sail': 'sail' },
  });
  await settled(window);
  const tabs = Array.from(document.querySelectorAll('[role="tab"]'));
  assert.equal(tabs[1].getAttribute('aria-selected'), 'true', 'the stored label wins over the first tab');

  tabs[0].click();
  assert.equal(window.localStorage.getItem('squire-docs-tab:composer|sail'), 'composer', 'a new choice overwrites the stored one');
});

test('tabs work when localStorage throws', async () => {
  const dom = new JSDOM(pageWith(setMarkup('composer|sail', ['composer', 'sail'])), {
    runScripts: 'outside-only',
    url: ORIGIN,
  });
  Object.defineProperty(dom.window, 'localStorage', {
    configurable: true,
    get() {
      throw new Error('Storage blocked');
    },
  });
  dom.window.eval(DOCS_JS);
  const window = dom.window;
  const document = window.document;
  await settled(window);

  const tabs = Array.from(document.querySelectorAll('[role="tab"]'));
  assert.equal(tabs.length, 2, 'the tablist is built without touching storage');
  assert.equal(tabs[0].getAttribute('aria-selected'), 'true');

  tabs[1].click();
  assert.equal(tabs[1].getAttribute('aria-selected'), 'true', 'selection still works when storage throws');
  assert.equal(document.querySelectorAll('.docs-tabs__panel')[1].hidden, false);
});

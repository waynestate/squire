// toc.test.mjs: the "On this page" marker in kit/docs/docs.js, tested through the harness in tests/js/harness.mjs.
//
// The markup mirrors what bin/docs-site.php writes: headings with ids in the article, then a nav.docs-toc whose
// links point at them. jsdom has no layout and no IntersectionObserver, so each test stubs the observer and says
// where every heading sits in the window. The script measures for itself, so a stub that only reports "something
// crossed" is all it needs. (No headings were harmed in the scrolling of this file.)
import test from 'node:test';
import assert from 'node:assert/strict';
import { load } from './harness.mjs';

const ORIGIN = 'https://docs.squire.test/';

const PAGE = [
  '<!doctype html>',
  '<html lang="en">',
  '<head><meta charset="utf-8"><title>Toc</title></head>',
  '<body>',
  '<div class="docs-layout docs-layout--toc">',
  '<main class="docs-content"><article class="docs-article">',
  '<h1 id="toc">Toc<a class="docs-heading-link" href="#toc">#</a></h1><p>Intro.</p>',
  '<h2 id="first">First<a class="docs-heading-link" href="#first">#</a></h2><p>Text.</p>',
  '<h3 id="deeper">Deeper<a class="docs-heading-link" href="#deeper">#</a></h3><p>Text.</p>',
  '<h2 id="second">Second<a class="docs-heading-link" href="#second">#</a></h2><p>Text.</p>',
  '</article></main>',
  '<nav class="docs-toc" aria-label="On this page"><h2 class="docs-toc__title">On this page</h2><ul class="docs-toc__list">',
  '<li class="docs-toc__item"><a href="#first">First</a></li>',
  '<li class="docs-toc__item docs-toc__item--sub"><a href="#deeper">Deeper</a></li>',
  '<li class="docs-toc__item"><a href="#second">Second</a></li>',
  '<li class="docs-toc__item"><a href="#nowhere">Gone</a></li>',
  '</ul></nav>',
  '</div>',
  '<footer class="docs-footer"><p>Footer.</p></footer>',
  '</body>',
  '</html>',
].join('\n');

/** Installs a stand-in IntersectionObserver and returns the list every new observer joins. */
function stubObserver(window) {
  const observers = [];
  window.IntersectionObserver = class {
    constructor(callback, options) {
      this.callback = callback;
      this.options = options || {};
      this.targets = [];
      observers.push(this);
    }

    observe(target) {
      this.targets.push(target);
    }

    unobserve() {}

    disconnect() {}
  };
  return observers;
}

/** Puts each heading's top edge at the given distance from the top of the window (jsdom's is 768px tall). */
function place(document, tops) {
  for (const [id, top] of Object.entries(tops)) {
    document.getElementById(id).getBoundingClientRect = () => ({ top, bottom: top + 30, left: 0, right: 600, width: 600, height: 30 });
  }
}

/** Scrolls the stand-in page to its very bottom: 2000px down a page 2768px tall. */
function toBottom(window) {
  Object.defineProperty(window, 'pageYOffset', { configurable: true, value: 2000 });
  Object.defineProperty(window.document.documentElement, 'scrollHeight', { configurable: true, get: () => 2768 });
}

function fire(observers) {
  observers.forEach((observer) => observer.callback([], observer));
}

function marked(document) {
  return Array.from(document.querySelectorAll('.docs-toc a'))
    .filter((link) => link.getAttribute('aria-current') === 'true' || link.classList.contains('is-current'))
    .map((link) => link.getAttribute('href'));
}

function assertOnly(document, href) {
  assert.deepEqual(marked(document), href === null ? [] : [href]);
  if (href !== null) {
    const link = document.querySelector(`.docs-toc a[href="${href}"]`);
    assert.equal(link.getAttribute('aria-current'), 'true', 'aria-current says it to screen readers');
    assert.ok(link.classList.contains('is-current'), 'is-current says it to themes');
  }
}

// The kit works on DOMContentLoaded; jsdom fires that on a later tick than the harness returns.
async function settled(window) {
  if (window.document.readyState === 'loading') {
    await new Promise((resolve) => {
      window.document.addEventListener('DOMContentLoaded', resolve, { once: true });
    });
  } else {
    await new Promise((resolve) => setTimeout(resolve, 0));
  }
}

/** Clicks a TOC link, checks it is marked at once, then waits for the hashchange the click queues (jsdom can deliver it a few ticks late). */
async function clickToc(window, document, href) {
  const changed = new Promise((resolve) => window.addEventListener('hashchange', resolve, { once: true }));
  document.querySelector(`.docs-toc a[href="${href}"]`).click();
  assertOnly(document, href);
  await changed;
}

async function page({ tops, url } = {}) {
  let observers = [];
  const { window, document } = load(PAGE, {
    url,
    before: (win) => {
      observers = stubObserver(win);
      place(win.document, tops || { first: 400, deeper: 600, second: 900 });
    },
  });
  await settled(window);
  return { window, document, observers };
}

test('without IntersectionObserver the list stays exactly as built', async () => {
  const { window, document } = load(PAGE);
  await settled(window);
  assert.equal(typeof window.IntersectionObserver, 'undefined', 'jsdom has no observer, like an old browser');
  assertOnly(document, null);
  assert.deepEqual(
    Array.from(document.querySelectorAll('.docs-toc a')).map((link) => link.getAttribute('href')),
    ['#first', '#deeper', '#second', '#nowhere'],
  );
  assert.equal(document.querySelectorAll('.docs-toc [aria-current], .docs-toc .is-current').length, 0);
});

test('the observers watch every TOC heading against a band at the top of the window, plus the footer', async () => {
  const { document, observers } = await page();
  assert.equal(observers.length, 2);
  const [crossing, footer] = observers;
  assert.deepEqual(crossing.targets.map((el) => el.id), ['first', 'deeper', 'second'], 'a link to a missing heading is skipped');
  assert.equal(crossing.options.rootMargin, '0px 0px -70% 0px');
  assert.deepEqual(footer.targets, [document.querySelector('.docs-footer')]);
});

test('nothing is marked while the reader is still above the first section', async () => {
  const { document, observers } = await page();
  fire(observers);
  assertOnly(document, null);
});

test('the last section whose heading passed the line is marked, and only that one', async () => {
  const { document, observers } = await page();
  place(document, { first: -100, deeper: 150, second: 500 });
  fire(observers);
  assertOnly(document, '#deeper');
});

test('the mark moves on as the reader scrolls, both ways, and the old link is cleared', async () => {
  const { document, observers } = await page();
  place(document, { first: 100, deeper: 400, second: 900 });
  fire(observers);
  assertOnly(document, '#first');

  place(document, { first: -600, deeper: -300, second: 200 });
  fire(observers);
  assertOnly(document, '#second');
  const first = document.querySelector('.docs-toc a[href="#first"]');
  assert.equal(first.hasAttribute('aria-current'), false);
  assert.equal(first.classList.contains('is-current'), false);

  place(document, { first: 100, deeper: 400, second: 900 });
  fire(observers);
  assertOnly(document, '#first');
});

test('at the bottom of the page the last heading in the window is marked, short as its section is', async () => {
  const { window, document, observers } = await page();
  toBottom(window);
  place(document, { first: -900, deeper: -500, second: 500 });
  fire(observers);
  assertOnly(document, '#second');
});

test('a TOC click marks its link at once, holds through a smooth jump, and holds while its heading leads', async () => {
  const { window, document, observers } = await page();
  await clickToc(window, document, '#second');

  // Mid-jump under smooth scrolling: the sections it passes do not flash by in the list.
  place(document, { first: -300, deeper: 100, second: 400 });
  fire(observers);
  assertOnly(document, '#second');

  // Back up to an h2 that has an h3 right under it: once landed, the h3 also sits above the line, but the reader
  // asked for the h2.
  await clickToc(window, document, '#first');
  place(document, { first: 24, deeper: 90, second: 900 });
  window.dispatchEvent(new window.Event('scrollend'));
  assertOnly(document, '#first');

  // Reading on: the h2 leaves the top, and the usual rule takes over.
  place(document, { first: -60, deeper: 10, second: 820 });
  fire(observers);
  assertOnly(document, '#deeper');
});

test('a picked section near the foot of a page wins over the last heading in the window', async () => {
  const { window, document } = await page();
  toBottom(window);
  await clickToc(window, document, '#deeper');
  place(document, { first: -400, deeper: 300, second: 500 });
  window.dispatchEvent(new window.Event('scrollend'));
  assertOnly(document, '#deeper');
});

test('a slow jump holds its pick however long it takes, and lets go the moment its heading lands', async () => {
  const { window, document, observers } = await page();
  await clickToc(window, document, '#second');
  for (const first of [300, 100, -100, -300]) {
    place(document, { first, deeper: first + 300, second: first + 600 });
    fire(observers);
    assertOnly(document, '#second');
  }
  // Landed, with no scrollend: the observer that saw it land lets go of the hold.
  place(document, { first: -500, deeper: -200, second: 24 });
  fire(observers);
  assertOnly(document, '#second');
  // Proof the hold is gone: scrolling back up moves the mark at once.
  place(document, { first: 24, deeper: 330, second: 630 });
  fire(observers);
  assertOnly(document, '#first');
});

test('a jump the reader cuts short lets go when scrolling ends, or after three seconds without scrollend', async () => {
  const { window, document, observers } = await page();
  await clickToc(window, document, '#second');
  place(document, { first: -100, deeper: 150, second: 500 });
  window.dispatchEvent(new window.Event('scrollend'));
  assertOnly(document, '#deeper');

  await clickToc(window, document, '#first');
  place(document, { first: -600, deeper: -300, second: 200 });
  fire(observers);
  assertOnly(document, '#first');
  await new Promise((resolve) => setTimeout(resolve, 3100));
  assertOnly(document, '#second');
});

test('a #hash in the address marks its section on load', async () => {
  const { document } = await page({ url: `${ORIGIN}#first`, tops: { first: 24, deeper: 90, second: 900 } });
  assertOnly(document, '#first');
});

test('a jump no observer saw is settled when scrolling ends', async () => {
  const { window, document, observers } = await page();
  fire(observers);
  assertOnly(document, null);
  place(document, { first: -2000, deeper: -1500, second: 100 });
  window.dispatchEvent(new window.Event('scrollend'));
  assertOnly(document, '#second');
});

test('it marks links only: no scrolling, no forced smooth scroll', async () => {
  let scrolls = 0;
  let observers = [];
  const { window, document } = load(PAGE, {
    before: (win) => {
      observers = stubObserver(win);
      place(win.document, { first: 400, deeper: 600, second: 900 });
      win.scrollTo = () => {
        scrolls += 1;
      };
      win.scrollBy = win.scrollTo;
      win.Element.prototype.scrollIntoView = win.scrollTo;
    },
  });
  await settled(window);
  document.querySelector('.docs-toc a[href="#second"]').click();
  place(document, { first: -600, deeper: -300, second: 24 });
  fire(observers);
  assertOnly(document, '#second');
  assert.equal(scrolls, 0, 'the browser does the jump; the script never scrolls');
  assert.equal(document.documentElement.style.scrollBehavior, '');
  assert.equal(document.body.style.scrollBehavior, '');
});

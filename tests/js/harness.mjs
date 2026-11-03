// harness.mjs: loads kit/docs/docs.js into a jsdom window the way the docs site loads it.
//
// The page markup is parsed first, then the kit script runs once against it, with no other
// scripts in the window. That is the whole contract the kit has to keep: the pages are
// complete without the script, and the script only enhances what is already there.
import { readFileSync } from 'node:fs';
import { JSDOM } from 'jsdom';

const DOCS_JS = readFileSync(new URL('../../kit/docs/docs.js', import.meta.url), 'utf8');

// A stable https origin so window.localStorage exists: jsdom withholds storage on an opaque origin.
// The .test domain is reserved for testing, so no real host is named.
const ORIGIN = 'https://docs.squire.test/';

/**
 * Load the given page and run kit/docs/docs.js against it.
 *
 * @param {string} [html] The page markup; empty by default, like a blank page.
 * @param {{ storage?: Record<string, string> }} [options] Optional localStorage values to set
 *   before the script runs.
 * @returns {{ window: Window, document: Document }} The finished window and its document.
 */
export function load(html, { storage } = {}) {
  const dom = new JSDOM(html === undefined ? '' : html, {
    runScripts: 'outside-only',
    url: ORIGIN,
  });
  if (storage) {
    for (const [key, value] of Object.entries(storage)) {
      dom.window.localStorage.setItem(key, String(value));
    }
  }
  dom.window.eval(DOCS_JS);
  return { window: dom.window, document: dom.window.document };
}

/* ============================================================================
   Tests for the forbidden-word filter (forbidden.mjs).

   The public-posture rule lets the art name the platform, Citadel, but not
   what points at the inside. Run with:

     npm --prefix scripts/readme-art test
   ========================================================================== */

import test from "node:test";
import assert from "node:assert/strict";
import { FORBIDDEN } from "../forbidden.mjs";

// The word Citadel, in the casings the site uses it, passes. Public WSU
// addresses pass too: they are not internal hosts.
const PASSES = [
  "Citadel",
  "the Citadel",
  "CITADEL",
  "Suits you up for the Citadel.",
  "WELD deploys to Citadel through it.",
  "Use Squire on its own to bring an app you already have onto Citadel.",
  "https://squire.apps.wayne.edu/",
  "https://sourdough.apps.wayne.edu/",
  "https://weld.apps.wayne.edu/",
];

// Internal tool names and hostname-shaped strings still fail.
const FAILS = [
  "nougat",
  "Nougat",
  "snu.gs",
  "dockerhost",
  "dockerhost.prod.wayne.edu",
  "dockerhost.cctest.wayne.edu",
  "app.prod.wayne.edu",
  "app.cctest.wayne.edu",
];

test("the word Citadel passes the filter", () => {
  for (const text of PASSES) {
    assert.equal(FORBIDDEN.test(text), false, `should pass: ${text}`);
  }
});

test("internal names and hostname-shaped strings still fail the filter", () => {
  for (const text of FAILS) {
    assert.equal(FORBIDDEN.test(text), true, `should fail: ${text}`);
  }
});

#!/usr/bin/env node
/**
 * Front page accessibility gate.
 *
 * Serves the site folder on a local port, opens the page in a real Chromium
 * browser and runs axe-core against it with the WCAG 2.2 AA tag set, in light
 * mode and in dark mode (colorScheme emulation) at a narrow (360 px) and a
 * wide (1280 px) viewport. The page is analyzed with reduced motion, which is
 * the page's own static, fully visible state, so scroll reveals never hide
 * content from the check.
 *
 * Usage:  node tools/a11y/check.mjs [--root=site] [--page=index.html]
 * Exit:   0 clean, 1 violations found, 2 tool error.
 */
import { createServer } from "node:http";
import { readFile } from "node:fs/promises";
import path from "node:path";
import process from "node:process";
import { fileURLToPath } from "node:url";

import { chromium } from "playwright";
import AxeBuilder from "@axe-core/playwright";

const TAGS = ["wcag2a", "wcag2aa", "wcag21a", "wcag21aa", "wcag22aa"];
const MODES = ["light", "dark"];
const WIDTHS = [360, 1280];
const HEIGHT = 900;

const MIME = {
  ".css": "text/css; charset=utf-8",
  ".gif": "image/gif",
  ".html": "text/html; charset=utf-8",
  ".ico": "image/x-icon",
  ".js": "text/javascript; charset=utf-8",
  ".json": "application/json; charset=utf-8",
  ".md": "text/markdown; charset=utf-8",
  ".mjs": "text/javascript; charset=utf-8",
  ".png": "image/png",
  ".svg": "image/svg+xml",
  ".txt": "text/plain; charset=utf-8",
  ".woff": "font/woff",
  ".woff2": "font/woff2",
  ".xml": "application/xml; charset=utf-8",
};

function option(name, fallback) {
  const prefix = `--${name}=`;
  const hit = process.argv.slice(2).find((arg) => arg.startsWith(prefix));
  return hit ? hit.slice(prefix.length) : fallback;
}

/** Serve rootDir over http on an ephemeral port; "/" maps to pageName. */
async function serve(rootDir, pageName) {
  const server = createServer((req, res) => {
    (async () => {
      try {
        const url = new URL(req.url, "http://127.0.0.1");
        let pathname = decodeURIComponent(url.pathname);
        if (pathname === "/") pathname = `/${pageName}`;
        const filePath = path.normalize(path.join(rootDir, pathname));
        if (filePath !== rootDir && !filePath.startsWith(rootDir + path.sep)) {
          throw new Error(`outside the site root: ${pathname}`);
        }
        const body = await readFile(filePath);
        const type = MIME[path.extname(filePath).toLowerCase()] || "application/octet-stream";
        res.writeHead(200, { "content-type": type });
        res.end(body);
      } catch {
        res.writeHead(404, { "content-type": "text/plain; charset=utf-8" });
        res.end("not found");
      }
    })();
  });
  await new Promise((resolve, reject) => {
    server.once("error", reject);
    server.listen(0, "127.0.0.1", resolve);
  });
  return { server, base: `http://127.0.0.1:${server.address().port}` };
}

async function main() {
  const repoRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..", "..");
  const rootDir = path.resolve(repoRoot, option("root", "site"));
  const pageName = option("page", "index.html");

  const { server, base } = await serve(rootDir, pageName);
  const browser = await chromium.launch();
  let total = 0;
  try {
    for (const scheme of MODES) {
      for (const width of WIDTHS) {
        const context = await browser.newContext({
          colorScheme: scheme,
          reducedMotion: "reduce",
          viewport: { width, height: HEIGHT },
        });
        const page = await context.newPage();
        await page.goto(`${base}/${pageName}`, { waitUntil: "load" });
        await page.evaluate(() => document.fonts.ready);
        const results = await new AxeBuilder({ page }).withTags(TAGS).analyze();
        total += results.violations.length;
        console.log(`${scheme} ${width}px: ${results.violations.length} violation${results.violations.length === 1 ? "" : "s"}`);
        for (const violation of results.violations) {
          console.log(`  ${violation.id} (${violation.impact}): ${violation.help}`);
          for (const node of violation.nodes) {
            console.log(`    ${node.target.join(" ")}`);
          }
        }
        await context.close();
      }
    }
  } finally {
    await browser.close();
    server.close();
  }
  console.log(total === 0 ? "0 violations" : `${total} violations`);
  process.exit(total === 0 ? 0 : 1);
}

main().catch((err) => {
  console.error(`tool error: ${err && err.message ? err.message : err}`);
  process.exit(2);
});

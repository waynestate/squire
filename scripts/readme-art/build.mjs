#!/usr/bin/env node
/* ============================================================================
   SQUIRE README art kit.

   Cuts the README images from this repo's own static front page (site/), its
   brand files and its source, and writes them under docs/readme/. Nothing is
   drawn freehand: the hero and the gallery are screenshots of the real page,
   the cast is the marks resized (WayneUI composed on its green tile), the
   badges read their facts from composer.json and the README, and the packing
   list is the site's own packing list, hung as luggage tags on a strap beside
   the satchel.

   Run from any folder (every path resolves from this file's own location):

     npm --prefix scripts/readme-art ci
     npx --prefix scripts/readme-art playwright install chromium
     node scripts/readme-art/build.mjs

   The page is opened as a file:// URL: no server, no network. A squire checks
   the bag twice and never phones out.
   ========================================================================== */

import { chromium } from "playwright";
import { makeBadge } from "badge-maker";
import sharp from "sharp";
import { existsSync, mkdirSync, readFileSync, rmSync, writeFileSync } from "node:fs";
import path from "node:path";
import { fileURLToPath, pathToFileURL } from "node:url";
import { FORBIDDEN } from "./forbidden.mjs";

/* ------------------------------------------------------------- paths, limits */

const scriptDir = path.dirname(fileURLToPath(import.meta.url));
const repoRoot = path.resolve(scriptDir, "..", "..");
const siteDir = path.join(repoRoot, "site");
const outDir = path.join(repoRoot, "docs", "readme");
const castDir = path.join(outDir, "cast");

const MAX_BYTES = 300 * 1024; // every file in docs/readme/ stays at or under this

// The site's own palette, from site/assets/squire.css (:root).
const C = {
  green: "#0C5449",
  greenDeep: "#073A32",
  gold: "#FFCC33",
  goldDeep: "#D9A514",
  cream: "#F4F1EA",
  canvas: "#EFE6D4",
  paper: "#FBF7EE",
  leather: "#8B5A2B",
  leatherDark: "#5A3A22",
  ink: "#2A2118",
  inkMuted: "#5A4A3A",
  termDim: "#B3A48E",
};

const outputs = [];

function record(rel, buf) {
  const abs = path.join(outDir, rel);
  mkdirSync(path.dirname(abs), { recursive: true });
  writeFileSync(abs, buf);
  outputs.push({ rel, bytes: buf.length });
  console.log(`  ${rel}  ${buf.length} bytes`);
}

function fail(msg) {
  console.error(`FAIL: ${msg}`);
  process.exit(1);
}

function read(rel) {
  const abs = path.join(repoRoot, rel);
  if (!existsSync(abs)) fail(`missing source ${rel}`);
  return readFileSync(abs, "utf8");
}

function esc(s) {
  return s.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;");
}

function assertCleanText(text, what) {
  if (FORBIDDEN.test(text)) fail(`forbidden text matched ${FORBIDDEN} in ${what}`);
}

/* ------------------------------------------------------------- 1. cast strip */

// One 96x96 PNG per stack part (shown at 48 px in the README). The marks are
// copied and resized only, never redrawn. WayneUI is composed instead: its
// reverse mark (white frame, gold tab) set on a Wayne State green tile, so it
// reads as a sibling of the other thirteen.
const CAST = [
  "creed",
  "proctor",
  "sesame",
  "helmet",
  "sourdough",
  "squire",
  "preflight",
  "pinstripe",
  "booster",
  "rollcall",
  "drawbridge",
  "advisor",
  "weld",
];

function castSource(name) {
  // SQUIRE's own mark is not in assets/marks/ (the site shows it from brand/).
  if (name === "squire") return path.join(siteDir, "brand", "squire-avatar-256.png");
  return path.join(siteDir, "assets", "marks", `${name}.png`);
}

// The WayneUI tile: the reverse mark, traced from WayneUI's approved artwork,
// set on a green rounded tile. The traced paths are placed unchanged: no edits
// to any d= value, fill, or the mark's own viewBox. The visible ink spans
// 1546 x 1551 of the 1980 x 1989 viewBox and is centred in it, so this scale
// makes the mark 352 px tall on the 512 tile (about 69%), matching the square
// marks of the rest of the stack.
function wayneuiTileSvg() {
  const raw = readFileSync(path.join(scriptDir, "src", "wayneui-mark-reverse.svg"), "utf8");
  const open = raw.indexOf("<svg");
  const openEnd = raw.indexOf(">", open) + 1;
  const close = raw.lastIndexOf("</svg>");
  if (open < 0 || openEnd < 2 || close < openEnd) fail("wayneui-mark-reverse.svg: no <svg> element");
  // Everything between the opening tag and the final </svg>, verbatim (the
  // file's own line endings included, so the paths stay byte-identical).
  const inner = raw.slice(openEnd, close).replace(/^\r?\n/, "");
  return `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512" width="512" height="512" role="img" aria-label="WayneUI">
  <rect width="512" height="512" rx="80" ry="80" fill="#0C5449"/>
  <svg x="31.3" y="30.3" width="449.4" height="451.4" viewBox="0 0 1980 1989">
${inner}</svg>
</svg>
`;
}

async function buildCast() {
  console.log("cast:");
  const tile = Buffer.from(wayneuiTileSvg(), "utf8");
  record("cast/wayneui.svg", tile);
  const tilePng = await sharp(tile, { density: 300 })
    .resize(96, 96, { fit: "contain", background: { r: 0, g: 0, b: 0, alpha: 0 } })
    .png({ palette: true, quality: 85, compressionLevel: 9 })
    .toBuffer();
  record("cast/wayneui.png", tilePng);
  const stale = path.join(castDir, "wayneui-dark.png");
  if (existsSync(stale)) rmSync(stale);
  for (const name of CAST) {
    const src = castSource(name);
    if (!existsSync(src)) fail(`cast needs ${path.relative(repoRoot, src)}`);
    const png = await sharp(readFileSync(src))
      .resize(96, 96, { fit: "contain", background: { r: 0, g: 0, b: 0, alpha: 0 } })
      .png({ palette: true, quality: 85, compressionLevel: 9 })
      .toBuffer();
    record(`cast/${name}.png`, png);
  }
}

/* ---------------------------------------------------------------- 2. badges */

// Facts are read, never typed: php and the licence from composer.json, the
// exit-code contract from the README's own "Exit codes:" paragraph. A badge
// that lies is a bag with the wrong tag on it.
function buildBadges() {
  console.log("badges:");
  const composer = JSON.parse(read("composer.json"));
  const para = read("README.md").match(/^Exit codes:.*(?:\r?\n(?!\r?\n).*)*/m);
  if (!para) fail("README.md has no 'Exit codes:' paragraph");
  const codes = [...para[0].matchAll(/`(\d)` [a-z]/g)].map((m) => m[1]);
  if (codes.length < 3) fail("could not read the exit codes from README.md");
  const defs = [
    { key: "php", label: "php", message: composer.require?.php },
    { key: "license", label: "licence", message: composer.license },
    { key: "exit-codes", label: "exit codes", message: codes.join(" · ") },
  ];
  for (const d of defs) {
    if (!d.message) fail(`no fact to badge for "${d.key}"`);
    const svg = makeBadge({
      label: d.label,
      message: d.message,
      labelColor: C.leatherDark,
      color: C.gold,
      style: "flat-square",
    });
    record(`badges/${d.key}.svg`, svg);
  }
}

/* ------------------------------------------------------------- 3. the page */

async function openPage(browser) {
  const context = await browser.newContext({
    viewport: { width: 1280, height: 900 },
    deviceScaleFactor: 2,
    reducedMotion: "reduce", // every tag already hung, the bag already buckled
  });
  // file:// only: refuse anything that tries to leave the machine.
  await context.route(/^(https?|wss?):/, (route) => route.abort());
  const page = await context.newPage();
  await page.goto(pathToFileURL(path.join(siteDir, "index.html")).href, { waitUntil: "load" });
  await page.evaluate(() => document.fonts.ready);
  return { context, page };
}

async function assertClean(page, selector, what) {
  assertCleanText(await page.locator(selector).innerText(), what);
}

/* ------------------------------------------------------------------ 4. hero */

// The hero's label, tagline and blurb are hidden for the shot (the title, the
// command, the stack note, the tags and the satchel stay), and the build
// checks that every word still visible is clean.
async function buildHero(page) {
  console.log("hero:");
  await page.addStyleTag({
    content: `.site-header, .toast { display: none !important; }
      .hero__copy .label, .hero__tagline, .hero__blurb { display: none !important; }`,
  });
  await assertClean(page, ".hero", "hero");
  const png = await page.locator(".hero").screenshot();
  const jpg = await sharp(png).resize({ width: 1280 }).jpeg({ quality: 82, mozjpeg: true }).toBuffer();
  record("hero.jpg", jpg);
}

/* --------------------------------------------------- 5. the packing list */

// What the packing list needs from the page: the heading, the command, every
// tag's file name (and count), the stamp, the bag label rows that are not the
// destination, the "5 of 5 packed" line and the last line of the real run.
async function readPackList(page) {
  return page.evaluate(() => {
    const txt = (el) => (el ? el.textContent.replace(/\s+/g, " ").trim() : "");
    const items = [...document.querySelectorAll("#kit .packlist .item")].map((li) => {
      const name = li.querySelector(".item__name");
      const count = name.querySelector(".item__count");
      const base = [...name.childNodes]
        .filter((n) => n.nodeType === 3)
        .map((n) => n.textContent)
        .join("")
        .trim();
      return { name: base, count: count ? txt(count) : "", stamp: txt(li.querySelector(".stamp")) };
    });
    const rows = [...document.querySelectorAll("#kit .bag__label-row")].map((r) => ({
      k: txt(r.querySelector("b")),
      v: txt(r).slice(txt(r.querySelector("b")).length).trim(),
    }));
    const runLines = [...document.querySelectorAll("#kit [data-term-out] p")].map(txt);
    return {
      heading: txt(document.querySelector("#kit-title")),
      command: txt(document.querySelector("#kit .section__head .label")),
      items,
      rows,
      counter: txt(document.querySelector("#kit .bag__count")),
      lastLine: runLines[runLines.length - 1],
    };
  });
}

function splitName(full) {
  // "deployments/001-first-release.sh" -> dir "deployments/", base "001-first-release.sh".
  const i = full.lastIndexOf("/");
  return i < 0 ? { dir: "", base: full } : { dir: full.slice(0, i + 1), base: full.slice(i + 1) };
}

async function buildPackingList(page) {
  console.log("packing list:");
  const pl = await readPackList(page);
  if (pl.items.length < 4) fail("the packing list has fewer than four items");
  // A row that carries a forbidden word drops out; the rest are the bag's own.
  const rows = pl.rows.filter((r) => !FORBIDDEN.test(r.k + " " + r.v));
  const items = pl.items;
  assertCleanText(
    JSON.stringify({ items, rows, c: pl.counter, h: pl.heading, l: pl.lastLine, cmd: pl.command }),
    "packing list text",
  );

  const W = 1400;
  const H = 452;
  const mono = "ui-monospace, SFMono-Regular, Menlo, Consolas, 'Liberation Mono', monospace";
  const serif = "Arvo, Rockwell, 'Roboto Slab', Georgia, serif";

  const n = items.length;
  const tagW = 196;
  const gap = 14;
  const x0 = 48;
  const strapY = 98;
  const strapH = 16;
  const lens = [30, 54, 36, 58, 42]; // how far each tag hangs, like the hero's
  const rot = [-3, 2.5, -2, 3, -2.5];
  const tagH = 188;
  const T = 13; // seconds
  const step = 9; // percent between tags landing

  const parts = [];
  const css = [];
  items.forEach((it, i) => {
    const cx = x0 + tagW / 2 + i * (tagW + gap);
    const len = lens[i % lens.length];
    const r = rot[i % rot.length];
    const a = 4 + i * step; // % when this tag starts to land
    const { dir, base } = splitName(it.name);
    const nameSize = base.length > 18 ? 15 : 18;
    css.push(`    .t${i} { animation: t${i} ${T}s ease-out infinite; }
    @keyframes t${i} {
      0%, ${a}% { opacity: 0; transform: translateY(-16px) rotate(${-r * 2}deg); }
      ${a + 3}% { opacity: 1; transform: translateY(5px) rotate(${r * 1.4}deg); }
      ${a + 5}% { transform: translateY(0) rotate(${-r * 0.5}deg); }
      ${a + 7}%, 91% { opacity: 1; transform: translateY(0) rotate(0deg); }
      95%, 100% { opacity: 0; transform: translateY(0) rotate(0deg); }
    }
    .p${i} { animation: p${i} ${T}s ease-out infinite; }
    @keyframes p${i} {
      0%, ${a + 7}% { opacity: 0; transform: scale(1.7) rotate(-8deg); }
      ${a + 9}%, 91% { opacity: 1; transform: scale(1) rotate(-8deg); }
      95%, 100% { opacity: 0; transform: scale(1) rotate(-8deg); }
    }`);
    const stampY = len + tagH - 32;
    parts.push(`  <g transform="translate(${cx},${strapY + strapH / 2})">
    <g class="tag t${i}">
      <line class="string" x1="0" y1="0" x2="0" y2="${len + 16}"/>
      <path class="paper" d="M-${tagW / 2},${len + 22} L-${tagW / 2 - 22},${len} H${tagW / 2 - 22} L${tagW / 2},${len + 22} V${len + tagH} H-${tagW / 2} Z"/>
      <circle class="eyelet" cx="0" cy="${len + 16}" r="5"/>
      <text class="no" x="-${tagW / 2 - 14}" y="${len + 50}">${String(i + 1).padStart(2, "0")}</text>
      ${dir ? `<text class="dir" x="0" y="${len + 78}" text-anchor="middle">${esc(dir)}</text>` : ""}
      <text class="file" x="0" y="${len + (dir ? 102 : 96)}" text-anchor="middle" style="font-size:${nameSize}px">${esc(base)}</text>
      ${it.count ? `<text class="count" x="0" y="${len + 122}" text-anchor="middle">${esc(it.count)}</text>` : ""}
      <g transform="translate(0,${stampY})"><g class="stamp p${i}">
        <rect x="-44" y="-14" width="88" height="28" rx="4"/>
        <text x="0" y="5" text-anchor="middle">${esc(it.stamp.toUpperCase())}</text>
      </g></g>
    </g>
  </g>`);
  });

  // The satchel: the site's own mark, resized, never redrawn.
  const mark = await sharp(readFileSync(path.join(siteDir, "brand", "squire-avatar-256.png")))
    .resize(184, 184)
    .png({ palette: true, quality: 85, compressionLevel: 9 })
    .toBuffer();
  const markUri = `data:image/png;base64,${mark.toString("base64")}`;
  const bagX = 1112;
  const bagW = 240;
  const bagCx = bagX + bagW / 2;
  const labelRows = rows
    .map(
      (r, i) =>
        `      <text class="row" x="${bagX + 18}" y="${326 + i * 28}"><tspan class="rk">${esc(r.k)}</tspan><tspan dx="10">${esc(r.v)}</tspan></text>`,
    )
    .join("\n");

  const arvo = readFileSync(path.join(siteDir, "assets", "fonts", "arvo-latin-700-normal.woff2")).toString("base64");

  const lastStart = 4 + (n - 1) * step + 9;
  const desc =
    `A leather strap hangs a luggage tag for each file in SQUIRE's packing list, one after another: ` +
    `${items.map((it) => (it.count ? `${it.name} (${it.count})` : it.name)).join(", ")}. ` +
    `Each tag is stamped ${items[0].stamp}. Then the satchel's own tag reads ${rows.map((r) => `${r.k} ${r.v}`).join(", ")}, ` +
    `and the counter says ${pl.counter}.`;

  const svg = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${W} ${H}" role="img" aria-labelledby="pk-title pk-desc">
  <title id="pk-title">${esc(pl.heading)}</title>
  <desc id="pk-desc">${esc(desc)}</desc>
  <style>
    @font-face { font-family: "Arvo"; font-weight: 700; src: url(data:font/woff2;base64,${arvo}) format("woff2"); }
    .head { font-family: ${serif}; font-weight: 700; font-size: 30px; fill: ${C.gold}; }
    .cmd { font-family: ${mono}; font-size: 17px; fill: ${C.termDim}; }
    .strap { fill: ${C.leather}; stroke: ${C.leatherDark}; stroke-width: 2; }
    .stitch { stroke: ${C.canvas}; stroke-width: 2; stroke-dasharray: 7 6; opacity: .55; fill: none; }
    .string { stroke: ${C.canvas}; stroke-width: 2.5; opacity: .85; }
    .paper { fill: ${C.paper}; stroke: ${C.leatherDark}; stroke-width: 2.5; stroke-linejoin: round; }
    .eyelet { fill: ${C.green}; stroke: ${C.leatherDark}; stroke-width: 2.5; }
    .no { font-family: ${serif}; font-weight: 700; font-size: 17px; fill: ${C.leather}; }
    .dir { font-family: ${mono}; font-size: 14px; fill: ${C.inkMuted}; }
    .file { font-family: ${mono}; font-weight: 700; fill: ${C.ink}; }
    .count { font-family: ${mono}; font-size: 14px; fill: ${C.inkMuted}; }
    .stamp rect { fill: none; stroke: ${C.green}; stroke-width: 2.5; }
    .stamp text { font-family: ${serif}; font-weight: 700; font-size: 14px; letter-spacing: 1.5px; fill: ${C.green}; }
    .stamp { transform: rotate(-8deg); transform-box: fill-box; transform-origin: center; }
    .bagmark { stroke: ${C.gold}; stroke-width: 3; }
    .label { fill: ${C.paper}; stroke: ${C.leatherDark}; stroke-width: 2.5; stroke-linejoin: round; }
    .row { font-family: ${mono}; font-size: 17px; font-weight: 700; fill: ${C.ink}; }
    .rk { font-family: ${serif}; font-size: 14px; fill: ${C.leather}; }
    .count-line { font-family: ${serif}; font-weight: 700; font-size: 18px; fill: ${C.gold}; }
    .last { font-family: ${mono}; font-size: 16px; fill: ${C.termDim}; }

    /* The finished bag is the resting state: with no animation (or reduced
       motion) every tag is hung and stamped, and the satchel is buckled. */
    .lab { animation: lab ${T}s ease-out infinite; }
    @keyframes lab {
      0%, ${lastStart}% { opacity: 0; transform: translateY(10px); }
      ${lastStart + 5}%, 91% { opacity: 1; transform: translateY(0); }
      95%, 100% { opacity: 0; transform: translateY(0); }
    }
    .tally { animation: tally ${T}s linear infinite; }
    @keyframes tally { 0%, ${lastStart + 2}% { opacity: 0; } ${lastStart + 6}%, 91% { opacity: 1; } 95%, 100% { opacity: 0; } }
${css.join("\n")}

    @media (prefers-reduced-motion: reduce) {
      .tag, .lab, .tally, .stamp { animation: none !important; opacity: 1 !important; }
      .tag, .lab { transform: none !important; }
      .stamp { transform: rotate(-8deg) !important; }
    }
  </style>

  <rect width="${W}" height="${H}" rx="24" fill="${C.green}"/>
  <text class="head" x="48" y="58">${esc(pl.heading)}</text>
  <text class="cmd" x="${W - 48}" y="56" text-anchor="end">${esc(pl.command)}</text>

${parts.join("\n")}

  <rect class="strap" x="${x0 - 14}" y="${strapY}" width="${n * tagW + (n - 1) * gap + 28}" height="${strapH}" rx="8"/>
  <line class="stitch" x1="${x0 - 4}" y1="${strapY + strapH / 2}" x2="${x0 + n * tagW + (n - 1) * gap + 4}" y2="${strapY + strapH / 2}"/>

  <g>
    <image href="${markUri}" x="${bagCx - 92}" y="104" width="184" height="184"/>
    <rect class="bagmark" x="${bagCx - 92}" y="104" width="184" height="184" rx="30" fill="none"/>
    <g class="lab">
      <rect class="label" x="${bagX}" y="296" width="${bagW}" height="${rows.length * 28 + 22}" rx="10"/>
${labelRows}
    </g>
  </g>

  <g class="tally">
    <text class="count-line" x="48" y="${H - 30}">${esc(pl.counter)}</text>
    <text class="last" x="${W - 48}" y="${H - 30}" text-anchor="end">${esc(pl.lastLine)}</text>
  </g>
</svg>
`;
  assertCleanText(svg.replace(/data:[^)"]+/g, ""), "packing-list.svg");
  record("packing-list.svg", Buffer.from(svg, "utf8"));
}

/* -------------------------------------------------------------- 6. gallery */

// A contact sheet of two more cuts of the real front page: the party's
// riding order, and the inspection patches. Any patch that carries a
// forbidden word is left out of the shot, and the build checks every word
// that is in.
async function buildGallery(page) {
  console.log("gallery:");
  await page.evaluate((src) => {
    const re = new RegExp(src, "i");
    document.querySelectorAll("#doctor .patch").forEach((p) => {
      if (re.test(p.textContent)) p.remove();
    });
  }, FORBIDDEN.source);
  const shots = [
    { sel: "#party .rail", name: "rail" },
    { sel: "#doctor .patches", name: "patches" },
  ];
  const uris = [];
  for (const s of shots) {
    await assertClean(page, s.sel, s.name);
    const buf = await page.locator(s.sel).screenshot();
    uris.push(`data:image/png;base64,${buf.toString("base64")}`);
  }
  const sheet = await page.context().newPage();
  const html = `<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<style>
  html, body { margin: 0; padding: 0; }
  .sheet { width: 1200px; box-sizing: border-box; background: ${C.leatherDark}; padding: 36px; display: grid; gap: 28px; }
  figure { margin: 0; border-radius: 14px; overflow: hidden; border: 1px solid rgba(255, 204, 51, .5); background: ${C.canvas}; }
  img { display: block; width: 100%; }
</style>
</head>
<body>
<div class="sheet">
${uris.map((u) => `  <figure><img src="${u}" alt=""></figure>`).join("\n")}
</div>
</body>
</html>`;
  await sheet.setContent(html, { waitUntil: "load" });
  const png = await sheet.locator(".sheet").screenshot();
  await sheet.close();
  const jpg = await sharp(png).resize({ width: 1200 }).jpeg({ quality: 80, mozjpeg: true }).toBuffer();
  record("gallery.jpg", jpg);
}

/* ---------------------------------------------------------------- 7. board */

async function buildBoard() {
  console.log("board:");
  const src = path.join(siteDir, "brand", "squire-board.png");
  if (!existsSync(src)) fail("site/brand/squire-board.png is missing");
  let q = 82;
  let jpg;
  do {
    jpg = await sharp(src).resize({ width: 1400 }).jpeg({ quality: q, mozjpeg: true }).toBuffer();
    q -= 6;
  } while (jpg.length > MAX_BYTES && q >= 60);
  record("board.jpg", jpg);
}

/* -------------------------------------------------------------------- main */

async function main() {
  await buildCast();
  buildBadges();
  await buildBoard();

  const browser = await chromium.launch();
  try {
    const { context, page } = await openPage(browser);
    await buildPackingList(page);
    await buildGallery(page);
    await context.close();
    const hero = await openPage(browser);
    await buildHero(hero.page);
    await hero.context.close();
  } finally {
    await browser.close();
  }

  let over = false;
  for (const { rel, bytes } of outputs) {
    if (bytes > MAX_BYTES) {
      console.error(`FAIL: ${rel} is ${bytes} bytes (limit ${MAX_BYTES})`);
      over = true;
    }
  }
  if (over) process.exit(1);
  console.log(`done: ${outputs.length} files, all at or under ${MAX_BYTES} bytes`);
}

main().catch((err) => {
  console.error(err);
  process.exit(1);
});

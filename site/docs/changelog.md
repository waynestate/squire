---
title: "Changelog | Squire docs"
description: "All notable changes to this project."
url: https://squire.apps.wayne.edu/docs/changelog.html
llms: https://squire.apps.wayne.edu/llms.txt
generator: Squire agent-ready
---

# Changelog[#](https://squire.apps.wayne.edu/docs/changelog.html#changelog)

All notable changes to Squire are recorded here. The format follows Keep a Changelog; the project follows Semantic Versioning once 1.0 is out.

## Unreleased[#](https://squire.apps.wayne.edu/docs/changelog.html#unreleased)

- Added: the fallback base says less about itself (SQUIRE-9): the new parity file `.squire/apache-hardening.conf` sets `ServerTokens Prod`, `ServerSignature Off` and `TraceEnable Off`, and the base enables it as `zz-squire-hardening.conf`, after Debian's `security.conf` (which ships `ServerTokens OS` and `ServerSignature On`), so the `Server` header reads `Apache` and Apache's own error pages carry no version footer. The app image is unchanged, so a run on the real Citadel image still shows what that image says. New docs page: Guides, Image hardening.
- Changed: the release script asks PROCTOR's deploy gate, `proctor gate`, for a go or no-go instead of running `proctor check`, and runs it right after `composer audit`, before the frontend build and the migrations, so an enforced no-go stops the release before it touches the database. The verdict is written to `storage/squire/proctor-gate.json` (new setting `SQUIRE_PROCTOR_RECORD_DIR`, in the settings-file allow list and the `.env.squire.example` files); when the release goes on, the verdict becomes the baseline the next release is ratcheted against, so a failure that is already live is held and only a new one blocks. `SQUIRE_PROCTOR=auto` still only reports a no-go, and `enforce` stops on it. With a PROCTOR that has no `gate` command the step warns, names the PROCTOR version, and runs `proctor check` as before. Needs a PROCTOR release with `proctor gate` to take effect.
- Changed: `doctor` S14 passes when the release runs `proctor gate` as well as `proctor check`, and the S50 hand-off names `proctor gate`.
- Changed: Squire's own deploy layer (`deploy/`) is updated to the current templates with `squire update`.
- Changed: The canonical repository is git.wayne.edu (ADR-0021); docs, links and package metadata no longer point at the archived nougat copy.
- Changed: the Oracle images publish to the group's images project (`images/squire-oracle-sail`, `images/squire-oracle-parity`) because git.wayne.edu has no registry.
- Fixed: `squire update` writes the template files a newer release added to a group the kit records: a file the target release has that `.squire.json` does not list is written from the template, recorded, and printed as added, so one `update` converges the kit (a kit made before 0.4.0 gains `oracle-check.php` when it moves past it) instead of leaving the new files to `squire init`; a file the app already has is still left alone, and an edited file is still refused and shown as a diff.
- Fixed: `squire init` without `--only` defaults to the groups the `.squire.json` records instead of `release,parity`, so a bare `init` completes an existing kit without dropping its groups; with no manifest it still writes `release,parity`, and `--only` still wins.
- Added: the Proof page records its last run: a dated table of the checks run against Squire itself (`composer ci` and the local parity rig) with their results and durations, so a reader can see when the page was last verified and how to refresh it.
- Fixed: the docs builder turns an article image whose src leaves the site (an http:, https: or // address) into a text link, because the stack's CSPs block external images: inside an anchor the tag becomes its alt text (the last path segment of the src when the alt is empty) and the link stays, so a README badge reads as a link to its page, and a bare tag becomes a link to the image itself with rel="noopener"; relative, /-absolute and data: srcs are the site's own and stay put, for Markdown images and raw HTML `<img>` alike.
- Fixed: images in a docs page sit in rows again instead of stacking one per line (WayneUI's reset makes every image a block): the kit's docs.css puts article images back in the line, centered on the text, while an image alone in its paragraph (or followed only by a `<sub>` credit) stays a block; a paragraph or block of only images or linked images (badge rows, a README cast row) wraps in balanced lines with even spacing and keeps align="center"; a list whose every item is one image or linked image becomes a wrapping row of 4rem tiles with no bullets; and linked tiles show an accent hairline on hover, the kit's focus ring on keyboard focus, and a 2px lift only without a reduced-motion request.
- Fixed: the kit's docs.css and docs-site.php answer three findings the new axe-core gates raised on SESAME, HELMET and Booster: inline code in a table header cell now inherits the cell's own colors (color: inherit, background: none) so it keeps the header text's contrast in light and dark themes; every code block gets tabindex="0" from the builder, like the table wrappers, so a block that scrolls sideways on a narrow screen is keyboard reachable; and the level-3 "On this page" entries carry the docs-toc\_\_item base class as well as docs-toc\_\_item--sub so the kit's target-size rules reach them.
- Security: the front-page deploy layer moves to Symfony 7.4.23 (symfony/http-foundation and symfony/yaml advisories, including CVE-2026-118514).
- Fixed: GitHub CI's js-tests job installs git first, so checkout keeps package-lock.json.
- Added: a Proof page in the docs (Reference, Proof): Squire run against 82 Wayne State applications and 13 more repositories on 2026-11-05 (doctor, init, init --check, a second init, doctor again, update --dry-run, and a lint of every file init wrote), plus four of them booted on local parity; what it caught in Squire itself, what it does not prove, and how to run the same on your own app.
- Changed: `squire init` says so when the release it just wrote cannot pass its own first step: an app with no `composer.json` or no `composer.lock` gets a "Heads up" line naming the missing file and how to make it, instead of finding out from a failed first deploy. Found by the proof run (31 of the 82 apps have no lock file). New tests in ConsoleTest.
- Changed: Squire's docs theme tidies two rough edges the front page never shows: the phone Menu button keeps a clear line of canvas between itself and the page title, and the sidebar and terminal scrollbars are thin stitches in the theme's colors instead of the browser's grey rail.
- Fixed: a docs code block that overflows only once the web fonts load joins the tab order: the kit's docs.js re-measures every code block once document.fonts.ready settles (when the browser exposes the Font Loading API), so a block whose width grows with the final font gets tabindex="0" like one measured after a resize; browsers and pages without the API behave exactly as before.
- Fixed: the docs builder's changelog page writes its own `# Changelog` heading after the marker comment, so the built page has an h1 like every other docs page; the source's first heading is still dropped, whatever it said.
- Fixed: the agent-ready Markdown twin keeps the text of a label, which the docs builder wraps around every task-list checkbox and its item text: a list item that opens with a checkbox input (directly in the li, or in a label or paragraph that starts the item) renders as `- [ ] Item text`, or `- [x] Item text` when the input carries a checked attribute, ordered lists keep their number, and a label outside a list keeps its text inline.
- Changed: Squire's own docs pages wear the front page's look through the theme key: docs/\_header.html is the front page masthead (satchel, SQUIRE, the same links, the version tag, Documentation marked current) and docs/theme/squire.css maps the front page's canvas, leather, gold and Arvo onto the kit's --dk-\* tokens, with luggage tags for labels, the current page and the pager, stitched seams above sections, the hero's strap and buckle under each title, terminals with the gold Copy in the corner, code tabs styled like the front page's OS switcher, a derived dark set (prefers-color-scheme and data-theme), and motion only without a reduced-motion request. The kit's docs.css and docs.js are unchanged.
- Fixed: the front page footer's Documentation link was green on the deep green footer (contrast 1.43, axe color-contrast); it is gold now, like the footer's line.
- Added: dark mode in the docs kit: docs.css redefines its twelve --dk-\* color aliases with dark fallbacks in :root\[data-theme="dark"\] and in a prefers-color-scheme: dark query scoped to :root:not(\[data-theme="light"\]), in WayneUI's own resolution order (an explicit choice first, then the reader's system setting, else light), declares color-scheme in each theme so form controls and scrollbars follow, and keeps every text pair at WCAG AA contrast in both themes; the hex fallbacks only apply when WayneUI is absent.
- Fixed: code tab labels may contain spaces: the docs builder now finds the first tab="..." token anywhere in a fence's info string (the quoted label may hold spaces but no double quote, 1 to 40 characters after trimming) instead of only when it is its own whitespace-separated word, so a fence such as `bash tab="Linux / macOS"` joins its tab set with the full label; the token is still dropped from the kept info (the language is the first remaining word), an empty or over-long label still leaves the fence as a plain block, and the ids the builder derives from spaced labels stay valid (no spaces).
- Added: code tabs in the docs builder: consecutive fenced code blocks whose info string carries tab="LABEL" (double quotes, label up to 40 characters) render as one labeled tab set (a docs-tabs element keyed by the lowercased labels joined by |, each panel a docs-tabs\_\_panel with a visible label and the code escaped exactly like a plain fenced block), so every example reads without JavaScript; a lone tab="..." fence stays a plain code block with the tab="..." word dropped from its language class, a run broken by anything else becomes separate sets, and the kit's docs.js turns each set into an accessible tablist (click, arrow keys, Home and End), remembers the chosen label per key in localStorage, and keeps every set sharing a key in step, while docs.css styles the tabs from WayneUI tokens and print shows every panel.
- Added: Squire's own docs site (docs/ built to site/docs/ by its docs builder) and the composer scripts site:agent-ready, site:agent-ready-check; ci now runs both checks.
- Added: GitHub alert callouts in the docs builder: a blockquote whose first line is a marker alone (\[!NOTE\], \[!TIP\], \[!WARNING\] or \[!DANGER\], case-insensitive) renders as an aside with the classes docs-callout and docs-callout--note (one per kind), role="note", a visible label, and the body below it, and the marker line leaves the body; a blockquote without a marker, or with a marker that is not one of the four, stays a plain blockquote, and the kit's docs.css styles all four kinds from WayneUI tokens in light and dark themes.
- Fixed: the kit's agent-ready.js drops two unused variables in enhancePrompt, so eslint no-unused-vars passes.
- Fixed: the kit's agent-ready.js and stack-env.js use bindingless catch blocks, so a linter with no-unused-vars passes on them.
- Fixed: a docs code block that scrolls sideways is keyboard reachable: the kit's docs.js gives a block wider than its box tabindex="0" and takes it away again when a resize makes it fit, so axe's scrollable-region-focusable rule passes on pages with long commands.
- Fixed: a scrolling code block inside a code tab stays keyboard reachable: docs.js measures a block again when its tab shows, and a resize leaves a block in a hidden panel as it was (it has no box to measure), so axe's scrollable-region-focusable rule passes on whichever tab is open.
- Fixed: a GitHub task list checkbox in the docs has an accessible name: the builder wraps the checkbox and its item's own inline text in a label (class docs-task), stopping before a nested list or a second paragraph, so axe's label rule passes on pages with task lists.
- Fixed: sub-items in the docs "On this page" list carry docs-toc\_\_item as well as docs-toc\_\_item--sub, so the kit's item rules (muted color, padding, hover) and a theme's reach them; docs.css also matches a bare --sub item, so pages built before a resync still look right.
- Fixed: the docs builder ignores generated: markers inside fenced code blocks, so a page can show the marker syntax in an example without the builder filling or checking it.
- Fixed: the docs kit's copy button says Copied after a successful copy; clipboard.writeText resolves with undefined, which docs.js read as a failure, so every working copy said Copy failed, and a refused write now says Copy failed instead of staying silent.
- Added: the docs builder's theme hook: an optional theme key in docs/docs.json with css and header members (both optional and independent, both plain relative paths against docs/); css names a stylesheet copied byte for byte to site/docs/theme/ and linked after docs.css on every page, builder-owned like a copied image (--check compares it, reports it stale when it differs, and flags a copy under site/docs/theme/ as a leftover once the key goes away), and header names an HTML fragment whose single element replaces the generic docs header in place, its relative href, src and srcset URLs prefixed with the page depth (#, /, // and scheme URLs stay put), its first link to docs/index.html or docs/ marked aria-current="page" unless it already carries one, and its {{project}} and {{version}} placeholders filled (any other {{...}} fails the build, naming it); a non-object theme, a non-string member, an unknown key, an empty string or a path that does not exist is a tool error naming the key, and with no theme key, or an empty one, every generated byte matches the unthemed build.
- Changed: the docs builder's table cells strip Symfony console style tags (, , , , \<fg=...> and \</>) before escaping, so a symfony-commands help cell shows list instead of list; other angle text stays.
- Added: the docs builder's changelog page: when docs/nav.json lists a page whose path is changelog, a plain run writes docs/changelog.md from CHANGELOG.md before building (the usual front matter, a marker comment naming its source, the body without its top-level heading, every version heading kept as written), a relative link becomes a blob link under the nav.json source or loses its link keeping its label when there is none, --check reports docs/changelog.md (missing) or (out of date) instead of writing, and a nav entry without a CHANGELOG.md is a finding.
- Added: the docs builder's --examples mode runs the tagged command examples in the docs and fails when reality drifts from what they promise: a fenced block tagged run (bash, sh, shell or console, optionally exit=N) runs its lines in order in a fresh temp copy of the examples.cwd folder and compares the output with the following output block, every shell block must be tagged run or carry a docs-test: skip REASON line directly before it, skips are counted and grouped by reason in the summary and capped by examples.maxSkips, and a plain build or --check never runs examples.
- Added: the docs builder's two-way command and option coverage gate for a symfony-commands block: a code span or fenced line that starts with the CLI name must name a real command (help and list always pass) and may only use options of that command or the application options, every kept command and option must appear in the docs at least once (the generated block counts), and a fenced block whose info string contains docs-test: skip is not scanned.
- Changed: the front-page deploy layer pins HELMET ^0.3, PROCTOR ^0.4 and CREED ^0.1 (lock: HELMET 0.3.0, PROCTOR 0.4.0, CREED 0.1.6).
- Changed: CI pins Preflight 0.1.4 (GitLab component includes and the GitHub reusable workflow at its tag commit); the deploy layer sets minimum-stability to stable.
- Added: the docs builder's link gate for the built pages: every content link must reach a built page, a file under site/, or an external address, a fragment must name a real heading on the page it points at, and a relative link to a file the site does not serve (for example ../README.md) is a finding.
- Added: a GitHub release workflow (`.github/workflows/release.yml`) creates the release when a version tag arrives through the push mirror, using the CHANGELOG section for the notes and only the built-in `GITHUB_TOKEN`.
- Added: the docs builder's image gate for the built pages: every image a page references (a Markdown image or an HTML img) must name a file under docs/, resolved against the source page's folder, and is copied byte for byte to the same relative path under site/docs/ so the built page's relative src still resolves; a missing image is a finding, unreferenced files are never copied, and --check fails when a copy is missing or out of date.
- Added: the docs builder compares composer.json's version field with the docs stamp; a different version is a finding.
- Added: the docs builder's --require-release flag fails when CHANGELOG.md has no released heading yet, so the stamp cannot be unreleased in a checked repo.
- Added: the docs builder's --require-tag flag fails when the repo's latest version tag does not match the docs stamp (a tag such as v1.6.0 counts as 1.6.0); the flag is off by default.
- Added: a JavaScript test harness for the docs kit (tests/js, node:test with jsdom pinned exactly in package.json and package-lock.json) and a js-tests CI job on GitLab and GitHub that runs npm ci and npm test in a pinned multi-arch Node 22 image; composer js runs the same two commands locally and stays out of composer ci because the PHP image has no Node.
- Fixed: the oracle image jobs pin the Buildah image to the v1.43.5-immutable tag and its manifest list digest, because the previous digest was removed upstream.
- Fixed: a directory asked for without its trailing slash (for example `/docs`) now redirects to `/docs/`, so the page's relative images and links resolve.

## 0.5.0 (2026-11-03)[#](https://squire.apps.wayne.edu/docs/changelog.html#0-5-0-2026-11-03)

- GitHub CI now pins the PREFLIGHT reusable workflow to the 0.1.3 tag instead of a commit SHA.
- Added: an ADVISOR module (waynestate/advisor) with squire_doctor and squire_drift.
- Added: squire doctor oracle runs the Oracle connection check on demand, reading ORACLE\_\* settings from the environment and .env.
- Changed: the deploy layer's Squire kit is refreshed to 0.4.0 (the Oracle check script and the updated build and release scripts).
- Added: CI publishes the multi-arch Oracle images to the project's container registry when a version is tagged.
- Changed: the docs name the real image path instead of placeholders.
- Changed: Oracle image recipes use fully qualified `COPY --from` names so buildah can resolve them; the CI dry run shows amd64 builds need qemu binfmt on the runner (see OPEN_QUESTIONS).
- Docs: WSL 2 file-location advice and an engines and licenses note for local parity.
- Added: scripts/contract-image.sh runs release.sh inside the fallback image and asserts the smoke URL, health.php and the first-release log.
- Added: a contract-image CI job (GitHub) that runs scripts/contract-image.sh on amd64 and arm64.
- Changed: CI pulls its Docker Hub images through the GitLab Dependency Proxy (the group's CI_DEPENDENCY_PROXY_GROUP_IMAGE_PREFIX), so pipelines no longer hit Docker Hub's anonymous pull limit.
- Added: contract-parity-sh and contract-parity-pwsh CI jobs (GitHub) that bring the local parity stack up on the fallback image and check the proxy answers.
- Docs: the contract suite gap on GitLab and the real image is recorded as an open question.
- Fixed: the fallback base image builds when the host's uid or gid already exists in the image (GitHub runners use 1001).
- Fixed: the front page shows the current version.
- Fixed: the contract-image CI job installs PHP and the Composer dependencies before running scripts/contract-image.sh, so it no longer stops on the missing vendor/autoload.php.
- Fixed: the PowerShell parity script passes the host's uid and gid to the fallback base image build (1000 on Windows, the current user's ids on Linux and macOS) instead of hard-coded 1000, so the app container starts when the bind-mounted source is owned by another user.
- Added: a front page accessibility gate (tools/a11y: axe-core in Playwright, WCAG 2.2 AA) with scripts/a11y.sh and scripts/a11y.ps1 that run it in Docker; GitHub gets an a11y job, continue-on-error until it has been seen green once.
- Fixed: scripts/a11y.sh and scripts/a11y.ps1 create tools/a11y/node_modules on the host before docker run, so the named volume's mount point exists when the repo is mounted read-only.
- Changed: the PROCTOR deploy job is blocking now.
- Fixed: the contract-image job builds with the docker buildx driver so the app image finds the freshly built fallback base.
- Added: bin/docs-site.php, the shared docs builder, with the kit's docs.css and docs.js; it turns docs/ into a committed, WayneUI-styled site/docs/ and fails CI when the pages are stale or a finding is present.
- Changed: docs and package metadata name nougat as the canonical repository, with git.wayne.edu and GitHub as read-only mirrors, until git.wayne.edu has CI runners.
- Added: the docs builder renders a Source link in the header and footer, with a Mirrors list beside it, from the optional `source` and `mirrors` keys in docs/nav.json; a source or mirror URL that is not https is a finding.
- Added: the docs builder's generated blocks: docs/docs.json defines blocks that own marked regions in docs/\*\*/\*.md, the json-table type renders a Markdown table from a command's JSON output, and --check reports a stale block instead of writing.
- Added: the docs builder's symfony-commands block type runs list --format=json and renders the command reference (overview table, per-command headings with usage, argument and option tables, application options listed once), and its env block type scans the scan folders for prefixed environment variable names and renders them with describe meanings and defaults; a read variable without a describe entry, or a described variable no scanned file reads, is a finding.

## 0.4.0 (2026-11-01)[#](https://squire.apps.wayne.edu/docs/changelog.html#0-4-0-2026-11-01)

- Added: oracle-check.php, a plain-language Oracle connection check (settings, host, port, driver, login) shipped in the kit's deploy scripts. release.sh runs it after PROCTOR and before the smoke test; it is a report, never a gate, and stays silent when the app has no ORACLE\_\* settings.
- Added: opt-in Oracle-ready Docker images, a Laravel Sail flavour (squire/oracle-sail) and a parity flavour (squire/oracle-parity), with scripts/oracle-image-build.sh and scripts/oracle-image-test.sh. Oracle Instant Client 21.7 on amd64 and 19.32 on arm64 is downloaded at build time and checksum-pinned; nothing from Oracle is committed.
- Changed: the site's agent-ready files cover the Oracle check.
- Changed: the readme art check allows the word Citadel.
- Changed: the deploy layer's Squire kit is refreshed to 0.3.4 (the local .env.squire file is now git-ignored).

## 0.3.4 (2026-10-29)[#](https://squire.apps.wayne.edu/docs/changelog.html#0-3-4-2026-10-29)

- Changed: the deploy layer's Squire kit is refreshed to 0.3.3 (release options can now live in ./.env.squire).
- Fixed: squire init now adds .env and .env.squire to the target .gitignore, so the local deploy secrets file is never committed.

## 0.3.3 (2026-10-29)[#](https://squire.apps.wayne.edu/docs/changelog.html#0-3-3-2026-10-29)

- Fixed: docs say app settings go in a ./.env configuration file; Citadel has no dashboard environment field.
- Fixed: the .env.squire template says to add it as a Citadel configuration file; Citadel has no dashboard environment field.
- Added: release.sh reads release options from ./.env.squire (parsed, never sourced; allow-listed keys; the environment wins), so they can be set on Citadel.

## 0.3.2 (2026-10-29)[#](https://squire.apps.wayne.edu/docs/changelog.html#0-3-2-2026-10-29)

- Added: collapsed prompt blocks show a "Show the whole prompt" button; the fade stays inside the panel.
- Changed: a long command in the "Run it in" panel folds the same way, behind a "Show the whole command" button, instead of a scroll box with no cue.

## 0.3.1 (2026-10-29)[#](https://squire.apps.wayne.edu/docs/changelog.html#0-3-1-2026-10-29)

- Changed: README, site and open questions match 0.3.0 (Sourdough requires Squire, the policy default, the Preflight pin, the static target).
- Fixed: the agent-ready tool's messages write Squire as a word; the static template's SQUIRE_STATIC_ROOT comment matches the code.
- Changed: bin/agent-ready.php is clean under Pint's laravel preset (CI checks it); the README says how consumers keep the kit out of Composer downloads.

## 0.3.0 (2026-10-29)[#](https://squire.apps.wayne.edu/docs/changelog.html#0-3-0-2026-10-29)

- Added: the raw target ships a front controller.

## 0.2.2 (2026-10-29)[#](https://squire.apps.wayne.edu/docs/changelog.html#0-2-2-2026-10-29)

- Fixed: stale capability claims in the site and README.
- Changed: project names written as words except acronyms.
- Changed: templates and the agent-ready label write Squire as a word; older labels are still recognised.
- Changed: CI uses Preflight 0.1.1.

## 0.2.1 (2026-10-29)[#](https://squire.apps.wayne.edu/docs/changelog.html#0-2-1-2026-10-29)

### Fixed[#](https://squire.apps.wayne.edu/docs/changelog.html#fixed)

- Fixed: SQUIRE reported 0.1.0 after the 0.2.0 release.

## 0.2.0 (2026-10-29)[#](https://squire.apps.wayne.edu/docs/changelog.html#0-2-0-2026-10-29)

### Added[#](https://squire.apps.wayne.edu/docs/changelog.html#added)

- `--target=static`: writes everything the `raw` target writes plus a front controller (`public/index.php`) and an `.htaccess` that serve a committed static site behind HELMET. The site folder is `../../site` by default; point `SQUIRE_STATIC_ROOT` at another. `squire init --check` and `squire update` cover the new files, and the template export carries them under `static/`.
- `SQUIRE_PROCTOR=enforce`: a failed PROCTOR check now stops the release. The default stays report-only, so existing apps are unaffected.
- `SQUIRE_PROCTOR_POLICY` defaults to `vendor/waynestate/creed/policy/policy.json` when unset and present, so a static site needs no environment variables in the deploy wizard. When CREED is not installed, PROCTOR runs on its own default policy with a notice instead of the release failing. An explicit value always wins.

### Changed[#](https://squire.apps.wayne.edu/docs/changelog.html#changed)

- PHPStan now includes Pinstripe's raw PHP preset (`waynestate/pinstripe` 0.1) instead of carrying a hand copy of the settings; `phpstan.neon` keeps only SQUIRE's own paths and PHP version range, and the preset's deprecation checks come along with it.
- `symfony/console` floor raised to `^7.4`: `Application::add()` is deprecated since 7.4, and the CLI now calls `addCommand()`.

## 0.1.0 (2026-10-29)[#](https://squire.apps.wayne.edu/docs/changelog.html#0-1-0-2026-10-29)

### Added[#](https://squire.apps.wayne.edu/docs/changelog.html#added-1)

- `squire init` with `--target`, `--only`, `--check` and `--force`; a `.squire.json` manifest of SHA-256 hashes.
- `squire doctor` with text and JSON output, checking 26 rules (see `BEST_PRACTICES.md`).
- Templates for raw PHP and Laravel: `build.sh`, `deployments/001-first-release.sh`, `scripts/release.sh`, `health.php`, `public/up.php`.
- Vue/Inertia layer: `squire init` detects `vue` in `package.json` and writes `.npmrc` with `min-release-age=1` and a Vue `docker-compose.squire.yml` with a Node 22 service (the frontend install and build stay in the one common `scripts/release.sh`); `squire doctor` reports the layer and warns when `node_modules/` is tracked in git (S78); the template export includes the layer under `vue/`.
- Local parity kit: compose file with digest-pinned HA-Proxy and MySQL 5.6.51 images, `.squire/` build context, a fallback base image, `scripts/parity.sh` and `scripts/parity.ps1`.
- CI for GitLab and GitHub: `composer ci`, shellcheck and PSScriptAnalyzer, run in Docker.
- `bin/export-templates.php`: export a generated copy of SQUIRE's templates (for Sourdough and other projects that cannot `composer require` SQUIRE), with a `catalog.json` and a `manifest.json`; `--check` verifies the copy and exits 1 on drift.

### Changed[#](https://squire.apps.wayne.edu/docs/changelog.html#changed-1)

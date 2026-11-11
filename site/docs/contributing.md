---
title: "Contributing | Squire docs"
description: "Where Squire's code lives, the commands that build, lint and test it, and the steps a release goes through before it is tagged."
url: https://squire.apps.wayne.edu/docs/contributing.html
llms: https://squire.apps.wayne.edu/llms.txt
generator: Squire agent-ready
---

# Contributing [#](https://squire.apps.wayne.edu/docs/contributing.html#contributing)

A squire who forgets a buckle hears about it in the build log, so this page lists where the code lives, the development commands, and the release steps. Before you open a change, read [Quick start](https://squire.apps.wayne.edu/docs/guides/quick-start.html) to see what the kit writes, and keep [Upgrading](https://squire.apps.wayne.edu/docs/upgrading.html) in mind for what a version bump means to apps.

## Where the code lives [#](https://squire.apps.wayne.edu/docs/contributing.html#where-the-code-lives)

The canonical repository is on git.wayne.edu: [enterprise-apps/squire](https://git.wayne.edu/enterprise-apps/squire) (clone over SSH as `git@git.wayne.edu:enterprise-apps/squire.git`, or over HTTPS). Open issues and merge requests there; tags are cut there, by a maintainer, with an approver. GitHub ([waynestate/squire](https://github.com/waynestate/squire)) is a read-only push mirror of it: `main` and tags are copied automatically, Packagist reads from it, and nobody pushes to it. Your squire serves one banner, and it flies over git.wayne.edu.

## Development commands [#](https://squire.apps.wayne.edu/docs/contributing.html#development-commands)

Install the dependencies and run the full check suite, the same command that runs on GitLab and GitHub:

composer install composer ci

`composer ci` runs `composer validate --strict`, PHPUnit, Pint, PHPStan and `composer audit`. With only Docker, run it the same way CI does:

docker run --rm -v "$PWD:/app" -w /app composer:2.11.1 sh -c "composer install --no-interaction && composer ci"

The other gates, by platform:

| Task | Windows (PowerShell) | Linux and macOS |
| --- | --- | --- |
| Lint the shell templates (ShellCheck in Docker) | `scripts\lint-shell.ps1` | `scripts/lint-shell.sh` |
| Lint the PowerShell templates (PSScriptAnalyzer) | `scripts\lint-powershell.ps1` | `pwsh -File scripts/lint-powershell.ps1` |
| Local parity, in an app | `scripts\parity.ps1 up` | `scripts/parity.sh up` |
| Front page accessibility gate (Preflight's a11y checker, in Docker) | `scripts\a11y.ps1` | `scripts/a11y.sh` |
| Docs kit JavaScript tests (needs Node, or Docker) | `npm ci && npm test` | `npm ci && npm test` |

PHPStan and Pint follow Pinstripe, the stack's coding standard: `phpstan.neon` includes Pinstripe's raw PHP preset (`vendor/waynestate/pinstripe/phpstan/raw.neon`) and keeps only Squire's own paths and PHP version range, and Pint runs the same built-in `per` preset that Pinstripe's `pint/per.json` wraps, so `pint.json` stays local with Squire's own excludes. The tests run the real shell templates against stub `composer`, `curl`, `npm`, `corepack` and `proctor` programs, and start a PHP server to test `health.php`.

### The accessibility gate [#](https://squire.apps.wayne.edu/docs/contributing.html#the-accessibility-gate)

The front page has its own accessibility gate: `scripts/a11y.sh` on Linux and macOS, `scripts\a11y.ps1` on Windows. It runs Preflight's own a11y checker, vendored in `tools/a11y/check.mjs` from the same Preflight tag the CI job runs at and pinned by `tests/A11yCheckerPinTest.php`, in a real Chromium (Playwright in Docker, so no host browser is needed): every page of `site/` in light and dark mode at 320 px and 1280 px, with the WCAG 2.2 AA tag set and the WCAG 1.4.10 reflow check. The reports land in `build/a11y/` (gitignored). It stays out of `composer ci` because CI has no browser; GitHub runs it as a separate `a11y` job.

### The docs builder [#](https://squire.apps.wayne.edu/docs/contributing.html#the-docs-builder)

The pages under `docs/` build into the committed site at `site/docs/`: rebuild with `composer docs`, check with `composer docs:check`. The builder is `bin/docs-site.php`; see [The docs builder](https://squire.apps.wayne.edu/docs/guides/docs-builder.html) for generated blocks, callouts and what the check proves. Other stack repos take the kit from a Squire checkout with `php bin/docs-site.php --sync-to=<repo>`, then build and commit their own `site/docs/`. Squire's docs wear the front page's look through the theme key in `docs/docs.json`: the masthead is `docs/_header.html` and the styles are `docs/theme/squire.css`. Change those two files, not the kit's `docs.css` or `docs.js`, then rebuild.

The docs kit's JavaScript tests run with Node 22 on the PATH (`npm ci && npm test`) or, with only Docker:

docker run --rm -v "$PWD:/app" -w /app node:22-alpine sh -c "npm ci && npm test"

They run the same way locally and in CI (the `js-tests` job on GitLab and GitHub) and stay out of `composer ci` because the PHP image has no Node.

### The contract suite [#](https://squire.apps.wayne.edu/docs/contributing.html#the-contract-suite)

`scripts/contract-image.sh` proves that `release.sh` runs inside an image. The real Citadel image is internal, so it uses the fallback base Squire already builds for local parity (`.squire/base-fallback.Dockerfile`, tag `squire/php-parity-base:8.4`): php:8.4-apache plus Node 22, multi-arch. It makes a throwaway fixture app, builds the fallback base and the app image for the platform, runs `release.sh` as the `citadel` user with the smoke test on, and prints one PASS or FAIL line per assertion. A green run is a contract, not a certificate: the fallback has no `oci8`, `imagick` or `ssmtp`, its HA-Proxy differs from Citadel's, and nothing here touches Citadel itself. See [Local parity](https://squire.apps.wayne.edu/docs/guides/local-parity.html) for the stack the fallback stands in for.

## Keeping the docs honest [#](https://squire.apps.wayne.edu/docs/contributing.html#keeping-the-docs-honest)

Every behavior change updates README, docs pages, CHANGELOG (Unreleased), site/llms.txt, site/llms-full.txt and the front page. When the nav lists a `changelog` page, the docs builder owns `docs/changelog.md` from `CHANGELOG.md`, so a changed `CHANGELOG.md` is drift until the page is rewritten.

## Releasing [#](https://squire.apps.wayne.edu/docs/contributing.html#releasing)

1. Move the lines under `## Unreleased` in `CHANGELOG.md` to a new `## X.Y.Z (YYYY-MM-DD)` heading, and bump `Squire::VERSION` in `src/Squire.php` to the same number in the release commit: the version guard test fails when the two disagree.
2. `composer ci` and both linters green locally, then both pipelines green for that commit.
3. Run a secrets scan of the history and check that nothing internal (server names, people, IDs) is in the tree.
4. Tag `X.Y.Z` (no `v`) on git.wayne.edu, with an approver. The mirror carries the tag to GitHub, and Packagist picks it up from there. On GitHub, `.github/workflows/release.yml` then creates the release from the matching `## X.Y.Z (` section of `CHANGELOG.md` (it fails loudly when the section is missing or empty, and uses only the built-in `GITHUB_TOKEN`).
5. A release needs a maintainer's approval. Never move or delete a published tag.
6. When Preflight releases, update both of this repo's pins: the component tag in `.gitlab-ci.yml` and the tag in `.github/workflows/ci.yml`.

## Known limits [#](https://squire.apps.wayne.edu/docs/contributing.html#known-limits)

See OPEN_QUESTIONS.md in the repository. The big ones: the local `php.ini` holds safe defaults rather than the platform's file, the Citadel image has no digest to pin, and a full Update may not refresh `vendor/`. The release asks PROCTOR's `proctor gate` for a go or no-go, and installs the PROCTOR version the lock pins when `--no-dev` left it out. The release script is contract-tested inside the fallback image on GitHub and by hand on Citadel's own `citadel/php-apache:8.5r3`, not yet in CI on the real image (OPEN_QUESTIONS 19, "Contract suite on the real image and on GitLab").

## Who maintains it [#](https://squire.apps.wayne.edu/docs/contributing.html#who-maintains-it)

Wayne State University Enterprise Applications maintains Squire. The internal runbook (accounts, tokens, mirrors, Citadel access) lives with Enterprise Applications; ask them.

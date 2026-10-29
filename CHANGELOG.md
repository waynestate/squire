# Changelog

All notable changes to Squire are recorded here. The format follows Keep a Changelog; the project follows Semantic
Versioning once 1.0 is out.

## Unreleased

## 0.3.4 (2026-10-29)

- Changed: the deploy layer's Squire kit is refreshed to 0.3.3 (release options can now live in ./.env.squire).
- Fixed: squire init now adds .env and .env.squire to the target .gitignore, so the local deploy secrets file is never committed.

## 0.3.3 (2026-10-29)

- Fixed: docs say app settings go in a ./.env configuration file; Citadel has no dashboard environment field.
- Fixed: the .env.squire template says to add it as a Citadel configuration file; Citadel has no dashboard environment field.
- Added: release.sh reads release options from ./.env.squire (parsed, never sourced; allow-listed keys; the environment wins), so they can be set on Citadel.

## 0.3.2 (2026-10-29)

- Added: collapsed prompt blocks show a "Show the whole prompt" button; the fade stays inside the panel.
- Changed: a long command in the "Run it in" panel folds the same way, behind a "Show the whole command" button, instead of a scroll box with no cue.

## 0.3.1 (2026-10-29)
- Changed: README, site and open questions match 0.3.0 (Sourdough requires Squire, the policy default, the Preflight pin, the static target).
- Fixed: the agent-ready tool's messages write Squire as a word; the static template's SQUIRE_STATIC_ROOT comment matches the code.
- Changed: bin/agent-ready.php is clean under Pint's laravel preset (CI checks it); the README says how consumers keep the kit out of Composer downloads.

## 0.3.0 (2026-10-29)

- Added: the raw target ships a front controller.

## 0.2.2 (2026-10-29)

- Fixed: stale capability claims in the site and README.

- Changed: project names written as words except acronyms.

- Changed: templates and the agent-ready label write Squire as a word; older labels are still recognised.

- Changed: CI uses Preflight 0.1.1.

## 0.2.1 (2026-10-29)

### Fixed

- Fixed: SQUIRE reported 0.1.0 after the 0.2.0 release.

## 0.2.0 (2026-10-29)

### Added

- `--target=static`: writes everything the `raw` target writes plus a front controller (`public/index.php`) and an
  `.htaccess` that serve a committed static site behind HELMET. The site folder is `../../site` by default; point
  `SQUIRE_STATIC_ROOT` at another. `squire init --check` and `squire update` cover the new files, and the template export
  carries them under `static/`.
- `SQUIRE_PROCTOR=enforce`: a failed PROCTOR check now stops the release. The default stays report-only, so existing apps
  are unaffected.
- `SQUIRE_PROCTOR_POLICY` defaults to `vendor/waynestate/creed/policy/policy.json` when unset and present, so a static
  site needs no environment variables in the deploy wizard. When CREED is not installed, PROCTOR runs on its own default
  policy with a notice instead of the release failing. An explicit value always wins.

### Changed

- PHPStan now includes Pinstripe's raw PHP preset (`waynestate/pinstripe` 0.1) instead of carrying a hand copy of the
  settings; `phpstan.neon` keeps only SQUIRE's own paths and PHP version range, and the preset's deprecation checks
  come along with it.
- `symfony/console` floor raised to `^7.4`: `Application::add()` is deprecated since 7.4, and the CLI now calls
  `addCommand()`.

## 0.1.0 (2026-10-29)

### Added

- `squire init` with `--target`, `--only`, `--check` and `--force`; a `.squire.json` manifest of SHA-256 hashes.
- `squire doctor` with text and JSON output, checking 26 rules (see `BEST_PRACTICES.md`).
- Templates for raw PHP and Laravel: `build.sh`, `deployments/001-first-release.sh`, `scripts/release.sh`, `health.php`,
  `public/up.php`.
- Vue/Inertia layer: `squire init` detects `vue` in `package.json` and writes `.npmrc` with `min-release-age=1` and a Vue
  `docker-compose.squire.yml` with a Node 22 service (the frontend install and build stay in the one common
  `scripts/release.sh`); `squire doctor` reports the layer and warns when `node_modules/` is
  tracked in git (S78); the template export includes the layer under `vue/`.
- Local parity kit: compose file with digest-pinned HA-Proxy and MySQL 5.6.51 images, `.squire/` build context, a fallback
  base image, `scripts/parity.sh` and `scripts/parity.ps1`.
- CI for GitLab and GitHub: `composer ci`, shellcheck and PSScriptAnalyzer, run in Docker.
- `bin/export-templates.php`: export a generated copy of SQUIRE's templates (for Sourdough and other projects that cannot
  `composer require` SQUIRE), with a `catalog.json` and a `manifest.json`; `--check` verifies the copy and exits 1 on drift.

### Changed


---
title: "Upgrading"
description: "What changed in each released minor of Squire (0.6.0, 0.5.0, 0.4.0 and the 0.3.x line), the steps to move an app to them, and how to verify the upgrade held."
---

# Upgrading

A squire keeps the armor a season ahead: this page walks each released minor of Squire, newest first, with what changed, the steps to take, and how to verify the upgrade held. It states only what the CHANGELOG states (the full record is in [Changelog](changelog.md)); after you move a version, run the two checks below and read [Update and drift](guides/update-and-drift.md) for what they report.

## The two checks

The same two checks verify every upgrade on this page, from the app root:

<!-- docs-test: skip needs a real project -->
vendor/bin/squire init --check
vendor/bin/squire update --dry-run

`squire init --check` reports when your copies have drifted from the templates; it exits `0` when they match and `1` when they have. `squire update --dry-run` prints the plan and writes nothing; it exits `1` when an edited file still differs from the template. Exit `2` means the tool itself could not run. For the full command reference, see [Commands](reference/commands.md).

## 0.6.0 (2026-11-07)

### Breaking

None: the CHANGELOG records no breaking change for 0.6.0.

### What changed

**Fixed.** `squire update` writes the template files a newer release added to a group the kit records: a file the target release has that `.squire.json` does not list is written from the template, recorded, and printed as added, so one `update` converges the kit instead of leaving the new files to `squire init`. A file the app already has is still left alone, and an edited file is still refused and shown as a diff. `squire init` without `--only` defaults to the groups the `.squire.json` records instead of `release,parity`, so a bare `init` completes an existing kit without dropping its groups; with no manifest it still writes `release,parity`, and `--only` still wins.

### Steps

1. Update the `waynestate/squire` dev dependency to the next release.
2. Run `vendor/bin/squire update`: it takes the new template versions for the files you never edited, writes the files the release added, and shows a diff for the ones you did.
3. Stack repos: regenerate the `deploy/` folder with `squire init` and check it with `squire init --check`. Sourdough: refresh its generated copy with `php bin/export-templates.php --to=templates/squire` and verify it with `--check`.

### How to verify

Run `squire init --check` and `squire update --dry-run` (the two checks at the top of this page); both exit `0` once the kit is at the new release.

## 0.5.0 (2026-11-03)

### Breaking

None: the CHANGELOG records no breaking change for 0.5.0.

### What changed

**New.** An ADVISOR module (`waynestate/advisor`) with `squire_doctor` and `squire_drift`, so a coding agent that runs ADVISOR can run Squire's checks without leaving its sandbox (see [ADVISOR](guides/advisor.md)). `squire doctor oracle` runs the Oracle connection check on demand, reading `ORACLE_*` settings from the environment and `.env`. CI publishes the multi-arch Oracle images to the project's container registry when a version is tagged. `scripts/contract-image.sh` runs `release.sh` inside the fallback image and asserts the smoke URL, `health.php` and the first-release log. `bin/docs-site.php`, the shared docs builder, with the kit's `docs.css` and `docs.js`; it turns `docs/` into a committed, WayneUI-styled `site/docs/` and fails CI when the pages are stale or a finding is present (see [The docs builder](guides/docs-builder.md)). The docs builder renders a Source link in the header and footer, with a Mirrors list beside it, from the optional `source` and `mirrors` keys in `docs/nav.json`; a source or mirror URL that is not https is a finding. The docs builder's generated blocks: `docs/docs.json` defines blocks that own marked regions in `docs/**/*.md`; the `json-table` type renders a Markdown table from a command's JSON output, the `symfony-commands` type runs `<run> list --format=json` and renders the command reference, and the `env` type scans the scan folders for prefixed environment variable names and renders them with describe meanings and defaults; `--check` reports a stale block instead of writing.

**CI.** GitHub CI now pins the Preflight reusable workflow to the 0.1.3 tag instead of a commit SHA. CI pulls its Docker Hub images through the GitLab Dependency Proxy (the group's `CI_DEPENDENCY_PROXY_GROUP_IMAGE_PREFIX`), so pipelines no longer hit Docker Hub's anonymous pull limit. A `contract-image` job on GitHub runs `scripts/contract-image.sh` on amd64 and arm64. `contract-parity-sh` and `contract-parity-pwsh` jobs on GitHub bring the local parity stack up on the fallback image and check the proxy answers. A front page accessibility gate (`tools/a11y`: axe-core in Playwright, WCAG 2.2 AA) with `scripts/a11y.sh` and `scripts/a11y.ps1` that run it in Docker; GitHub gets an `a11y` job, continue-on-error until it has been seen green once. The PROCTOR deploy job is blocking now.

**Changed.** The deploy layer's Squire kit is refreshed to 0.4.0 (the Oracle check script and the updated build and release scripts). The Oracle image recipes use fully qualified `COPY --from` names so buildah can resolve them; the CI dry run shows amd64 builds need qemu binfmt on the runner (see OPEN_QUESTIONS). The docs and package metadata name nougat as the canonical repository, with git.wayne.edu and GitHub as read-only mirrors, until git.wayne.edu has CI runners.

**Fixed.** The fallback base image builds when the host's uid or gid already exists in the image (GitHub runners use 1001). The PowerShell parity script passes the host's uid and gid to the fallback base image build (1000 on Windows, the current user's ids on Linux and macOS) instead of hard-coded 1000, so the app container starts when the bind-mounted source is owned by another user. The contract-image CI job installs PHP and the Composer dependencies before running `scripts/contract-image.sh`, so it no longer stops on the missing `vendor/autoload.php`. The contract-image job builds with the docker buildx driver so the app image finds the freshly built fallback base. `scripts/a11y.sh` and `scripts/a11y.ps1` create `tools/a11y/node_modules` on the host before `docker run`, so the named volume's mount point exists when the repo is mounted read-only. The front page shows the current version.

**Docs.** WSL 2 file-location advice and an engines and licenses note for local parity. The contract suite gap on GitLab and the real image is recorded as an open question. The docs name the real image path instead of placeholders.

### Steps

1. Update the `waynestate/squire` dev dependency to 0.5.0 (it is on Packagist).
2. Run `vendor/bin/squire update` to take the new template versions for the files you never edited; it shows a diff for the ones you did and never overwrites your edits.
3. Stack repos: regenerate the `deploy/` folder with `squire init` and check it with `squire init --check`. Sourdough: refresh its generated copy with `php bin/export-templates.php --to=templates/squire` and verify it with `--check`.

### How to verify

Run `squire init --check` and `squire update --dry-run` (the two checks at the top of this page); both exit `0` once the kit is at 0.5.0. New in this version: `squire doctor oracle` answers from your terminal, and a coding agent that runs ADVISOR sees the `squire_doctor` and `squire_drift` tools.

## 0.4.0 (2026-11-01)

### Breaking

None: the CHANGELOG records no breaking change for 0.4.0.

### What changed

- `oracle-check.php`, a plain-language Oracle connection check (settings, host, port, driver, login), shipped in the kit's deploy scripts. `release.sh` runs it after PROCTOR and before the smoke test; it is a report, never a gate, and stays silent when the app has no `ORACLE_*` settings (see [Oracle](guides/oracle.md)).
- Opt-in Oracle-ready Docker images, a Laravel Sail flavor (`squire/oracle-sail`) and a parity flavor (`squire/oracle-parity`), with `scripts/oracle-image-build.sh` and `scripts/oracle-image-test.sh`. Oracle Instant Client 21.7 on amd64 and 19.32 on arm64 is downloaded at build time and checksum-pinned; nothing from Oracle is committed.
- The site's agent-ready files cover the Oracle check.
- The readme art check allows the word Citadel.
- The deploy layer's Squire kit is refreshed to 0.3.4 (the local `.env.squire` file is now git-ignored).

### Steps

1. Update the `waynestate/squire` dev dependency to 0.4.0.
2. Run `vendor/bin/squire update` to take the updated build and release scripts, then `vendor/bin/squire init` to write `oracle-check.php` (this release's `update` refreshed only the files the kit already recorded; `update` writes new files from the next release on).
3. Apps that talk to Oracle: optionally build the Oracle-ready images with `scripts/oracle-image-build.sh` (flavors `sail` and `parity`).

### How to verify

Run `squire init --check` and `squire update --dry-run`. Once the kit is at 0.4.0, the app has `oracle-check.php`, and a release log shows the Oracle check after PROCTOR and before the smoke test, printing nothing when the app has no `ORACLE_*` settings.

## 0.3.x (0.3.0 to 0.3.4)

### Breaking

None: the CHANGELOG records no breaking change in the 0.3.x line.

### What changed

- **0.3.4 (2026-10-29).** `squire init` now adds `.env` and `.env.squire` to the target `.gitignore`, so the local deploy secrets file is never committed. The deploy layer's Squire kit is refreshed to 0.3.3 (release options can now live in `./.env.squire`).
- **0.3.3 (2026-10-29).** `release.sh` reads release options from `./.env.squire` (parsed, never sourced; allow-listed keys; the environment wins), so they can be set on Citadel. The docs say app settings go in a `./.env` configuration file, and the `.env.squire` template says to add it as a Citadel configuration file; Citadel has no dashboard environment field.
- **0.3.2 (2026-10-29).** Collapsed prompt blocks show a "Show the whole prompt" button, and the fade stays inside the panel. A long command in the "Run it in" panel folds the same way, behind a "Show the whole command" button, instead of a scroll box with no cue.
- **0.3.1 (2026-10-29).** README, site and open questions match 0.3.0 (Sourdough requires Squire, the policy default, the Preflight pin, the static target). The agent-ready tool's messages write Squire as a word, and the static template's `SQUIRE_STATIC_ROOT` comment matches the code. `bin/agent-ready.php` is clean under Pint's laravel preset (CI checks it), and the README says how consumers keep the kit out of Composer downloads.
- **0.3.0 (2026-10-29).** The raw target ships a front controller.

### Steps

1. Update the `waynestate/squire` dev dependency to 0.3.4, the latest of the line.
2. Run `vendor/bin/squire update` to take the new template versions for the files you never edited.
3. If your app predates the line, run `vendor/bin/squire init` so the new template files land (for example the raw target's front controller from 0.3.0); `init` never overwrites a file you edited.
4. On Citadel, set the release options in the app's `./.env.squire` configuration file, which `release.sh` reads from 0.3.3 on.

### How to verify

Run `squire init --check` and `squire update --dry-run`. Check the version-specific results: 0.3.4 appends `.env` and `.env.squire` to the target `.gitignore` when they are missing; 0.3.3 reads release options from `./.env.squire`; 0.3.0 gives a raw PHP app the front controller `public/index.php`.

Releases before 0.3.0 (0.2.x and 0.1.0) predate this page; their entries are in [Changelog](changelog.md).

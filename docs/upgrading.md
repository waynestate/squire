---
title: "Upgrading"
description: "What changed in each released minor of Squire (0.11.0, 0.10.9, 0.10.8, 0.10.5, 0.10.4, 0.10.3, 0.10.2, 0.10.1, 0.10.0, 0.9.0, 0.8.0, 0.7.1, 0.7.0, 0.6.0, 0.5.0, 0.4.0 and the 0.3.x line), the steps to move an app to them, and how to verify the upgrade held."
---

# Upgrading

A squire keeps the armor a season ahead: this page walks each released minor of Squire, newest first, with what changed, the steps to take, and how to verify the upgrade held. It states only what the CHANGELOG states (the full record is in [Changelog](changelog.md)); after you move a version, run the two checks below and read [Update and drift](guides/update-and-drift.md) for what they report.

## The two checks

The same two checks verify every upgrade on this page, from the app root:

<!-- docs-test: skip needs a real project -->
vendor/bin/squire init --check
vendor/bin/squire update --dry-run

`squire init --check` reports when your copies have drifted from the templates; it exits `0` when they match and `1` when they have. `squire update --dry-run` prints the plan and writes nothing; it exits `1` when an edited file still differs from the template. Exit `2` means the tool itself could not run. For the full command reference, see [Commands](reference/commands.md).

## 0.11.0 (2026-11-13)

No breaks, and no template changes in the kits, so `squire update` has nothing new to take. What is new:

- `squire facts` fills marked regions from a committed `.squire/facts.json`, `squire facts --check` fails CI on drift, and `squire facts --snapshot` rebuilds the file offline from CREED and ADVISOR data in vendor.
- `squire site build` builds a pinned library's docs site and agent-ready files from its tag, with `--check` to compare against the committed output (ADR-0023).
- The MySQL upgrade guide says the supported path ends at 8.4 LTS.

### Steps

1. `composer update waynestate/squire` (the `^0.10` constraint does not take 0.11; widen it to `^0.11`).

### How to verify

`squire init --check` exits `0`, and `squire update --dry-run` lists nothing left to take.

## 0.10.9 (2026-11-11)

No breaks for an app that already has a `.squire.json`: it keeps its recorded groups and its PHP line. What changes:

- A new app starts on the Citadel PHP line `8.5r3` and records it as `php` in `.squire.json`. An app whose `.squire.json` has no `php` key stays on `8.4r5`, and `squire update` never moves it; `squire init --php <line>` picks a line on purpose.
- A fresh `squire init` writes only the `release` group. The `parity` group is opt-in (`--only release,parity`), because Parity runs the app locally now; `init` points at `parity init --from-squire` when the group is not in play.
- The release script fetches the PROCTOR version your `composer.lock` pins when `--no-dev` left it out, so the deploy gate runs on Citadel; `SQUIRE_PROCTOR_FETCH=0` turns that off.
- The release script installs the frontend from a `yarn.lock` too (Yarn 1, or Yarn 2 and later with `"packageManager"` in `package.json`).
- The parity kit's HAProxy runs on the current `haproxy:3.0-alpine` digest and reads the engine's nameserver, so Podman no longer drops the app a minute after `up`.
- Every tag gets a GitLab Release with the tag's changelog section as its notes.

### Steps

1. `composer update waynestate/squire` (the `^0.10` constraint takes it).
2. `vendor/bin/squire update` to take the new `scripts/release.sh` and `.env.squire.example`, and, if your app has the `parity` group, `scripts\parity.ps1` and `docker-compose.squire.yml`.

### How to verify

Run the two checks above: `squire init --check` exits `0`, and `squire update --dry-run` lists nothing left to take.

## 0.10.8 (2026-11-10)

No breaks: a patch, and no template changes in the kits, so `squire update` has nothing new to take. Two things change what you see:

- `squire update` no longer reports drift for a file whose edit already matches the new template byte for byte (an edit you took upstream); it reports the file current and records it.
- The published Oracle images are multi-arch again: the amd64 half is built natively, so an amd64 host can pull them instead of building them with `scripts/oracle-image-build.sh`.

### Steps

1. `composer update waynestate/squire` (the `^0.10` constraint takes it).
2. On amd64, you may switch from building the Oracle images to pulling them ([Oracle](guides/oracle.md)).

### How to verify

Run the two checks above: `squire init --check` exits `0`, and `squire update --dry-run` lists no file you had already brought up to the template.

## 0.10.5 (2026-11-09)

No breaks: a patch on 0.10.4, and no kit file changes, so `squire update` has nothing to take. Squire's `parity` group is deprecated: [Parity](https://git.wayne.edu/enterprise-apps/parity), the stack's own tool, runs Citadel on your computer now, and `init`, `update` and `doctor` each say so in one line when the group is in play. The kit still works and is removed no sooner than Squire 0.12.0, and not before 2027-01-08. The PHP 8.5 Oracle images build again, and tags publish the Oracle images for linux/arm64 only (amd64 users build them locally).

### Steps

1. Update the `waynestate/squire` dev dependency: `composer update waynestate/squire` when the app already requires `^0.10`, otherwise `composer require --dev waynestate/squire:^0.10.5`.
2. If the app uses Squire's parity kit, move to Parity before 0.12.0: install it, then run `parity init --from-squire`, `parity image get` and `parity up` in the app's folder ([Local parity](guides/local-parity.md)). Keep the kit files until you have; nothing deletes them yet.
3. On an Apple silicon Mac or an arm64 Linux host, an app can pull the published Oracle images instead of building them ([Oracle](guides/oracle.md)). On amd64, keep building them with `scripts/oracle-image-build.sh`.

### How to verify

Run the two checks at the top of this page; both exit `0`. `squire update --dry-run` on an app with the parity group ends with the Parity line, and `squire doctor` shows an S35 info line pointing at Parity.

## 0.10.4 (2026-11-09)

No breaks: a patch on 0.10.3, and no kit file changes, so `squire update` has nothing to take. The Oracle-ready local images build on linux/arm64 again (Apple silicon, arm64 Linux): `images/oracle/install-oracle.sh` unpacked the Instant Client SDK over the Basic Light files and stopped at a "replace?" prompt, which failed every arm64 build of both flavors. PHP 8.5 builds still fail in 0.10.4 on every architecture (PHP 8.5 quotes the path `php --ini` prints; fixed on main for the next release), so build the 8.3 or 8.4 line until then. The CI publish script for those images also stops failing on its Sail flavor. The published images are still not available: the CI runner cannot build linux/amd64 (MAINTAINING.md has the details), so build them locally with `scripts/oracle-image-build.sh`.

### Steps

1. Update the `waynestate/squire` dev dependency: `composer update waynestate/squire` when the app already requires `^0.10`, otherwise `composer require --dev waynestate/squire:^0.10.4`.
2. If you build the Oracle images on an arm64 machine, build them again as the [Oracle guide](guides/oracle.md) shows (`scripts/oracle-image-build.sh --flavour parity --php 8.4`, or `--flavour sail`).

### How to verify

Run the two checks at the top of this page; both exit `0`. On arm64, `scripts/oracle-image-test.sh --flavour all --platform linux/arm64` exits `0`.

## 0.10.3 (2026-11-09)

No breaks: a patch on 0.10.2, and comments only. The kit files every app copies stop citing Squire's internal item IDs and say in plain words what each comment meant (the opcache switch, the web's `disable_functions` list, secrets as files, the digest pins, the npm install-script escape hatch, the Oracle check). No setting, default or behavior changes. The files that change: `.squire/Dockerfile`, `.squire/apache-hardening.conf`, `.squire/base-fallback.Dockerfile`, `.squire/fallback/citadel-apache-entrypoint`, `.squire/php-web.ini`, `.squire/php.ini`, `.squire/portable.Dockerfile`, `.squire/portable.Dockerfile.dockerignore`, `.squire/portable/squire-entrypoint.sh`, `docker-compose.squire.yml`, `oracle-check.php` and `scripts/release.sh`.

### Steps

1. Update the `waynestate/squire` dev dependency: `composer update waynestate/squire` when the app already requires `^0.10`, otherwise `composer require --dev waynestate/squire:^0.10.3`.
2. Run `vendor/bin/squire update`: it takes the reworded files the app never edited, and shows a diff for any it did.
3. Commit the changed kit files and `.squire.json`.
4. Stack repos: refresh `deploy/` the same way. Sourdough: refresh its generated copy with `php bin/export-templates.php --to=templates/squire`.

### How to verify

Run the two checks at the top of this page; both exit `0`.

## 0.10.2 (2026-11-09)

No breaks for an app that builds with Docker: a patch on 0.10.1. Three kit files change. Raw PHP apps get SameSite=Lax session cookies: the kit's `php.ini` sets `session.cookie_samesite = Lax` (it was empty), next to the `session.cookie_httponly = 1` and `session.cookie_secure = 1` it already set, so an app that never calls `helmet_boot()` sends a session cookie SESAME has nothing to warn about (wherever the kit's `php.ini` is used: local parity and the portable image; on Citadel the platform's `php.ini` decides). The kit's Dockerfiles name every image in full (`docker.io/library/php:...`), so Buildah and Podman build them with short-name mode enforcing, and the fallback base takes its Composer image as a build argument (`COMPOSER_IMAGE`) like PHP and Node. The portable image drops `node_modules/` after the frontend build.

### Steps

1. Update the `waynestate/squire` dev dependency: `composer update waynestate/squire` when the app already requires `^0.10`, otherwise `composer require --dev waynestate/squire:^0.10.2`.
2. Run `vendor/bin/squire update`: it takes the new `.squire/php.ini`, `.squire/base-fallback.Dockerfile` and `.squire/portable.Dockerfile` when the app never edited them, and shows a diff when it did.
3. Commit the changed kit files and `.squire.json`.
4. A CI job that builds the fallback base through a pull-through proxy and prefixes the `ARG` defaults itself: the defaults now start with `docker.io/library/`, so strip that before adding the proxy prefix, and pass `COMPOSER_IMAGE` the same way instead of rewriting `COPY --from` (the loop is in [Image hardening](guides/image-hardening.md#through-a-proxy-or-a-mirror)). A job that passes no build arguments needs no change.
5. An app whose session must survive a cross-site POST (a form another site posts to, or a SAML ACS that reads the session on the POST itself) sets `session.cookie_samesite` back for that app, or parks the POST and finishes on a GET the way SESAME's SAML driver does. A CAS ticket and an OIDC code come back on a top-level GET, which still carries a Lax cookie.
6. Stack repos: refresh `deploy/` the same way. Sourdough: refresh its generated copy with `php bin/export-templates.php --to=templates/squire`.

### How to verify

Run the two checks at the top of this page; both exit `0`. `.squire/php.ini` sets `session.cookie_samesite = Lax`, `.squire/base-fallback.Dockerfile` has an `ARG COMPOSER_IMAGE=docker.io/library/composer:2.11.1@sha256:...` line, and a built portable image has no `/citadel/src/node_modules`.

## 0.10.1 (2026-11-09)

No breaks: a patch on 0.10.0. The kit's fallback base (`.squire/base-fallback.Dockerfile`) moves Composer from 2.10.3 to 2.11.1. 2.10 is no longer maintained, and 2.11.0 fixed GHSA-c968-33g3-wh7p (package names in `installed.json` could escape the vendor directory) and GHSA-fm2w-xgf2-f9fh (inline repository HTTP headers could be written into `composer.lock`). 2.11 drops the `source-fallback` setting, which Squire never used, and its new `policy.cooldown` stays off by default. The other two changes are Squire's own: its `deploy/` copy is on the 0.10.0 kit, and `scripts/a11y.ps1` works on a Windows checkout with CRLF line endings.

### Steps

1. Update the `waynestate/squire` dev dependency: `composer update waynestate/squire` when the app already requires `^0.10`, otherwise `composer require --dev waynestate/squire:^0.10.1`.
2. Run `vendor/bin/squire update`: it takes the new `.squire/base-fallback.Dockerfile` when the app never edited it, and shows a diff when it did.
3. Commit the changed kit files (`.squire/base-fallback.Dockerfile` and `.squire.json`). The fallback image takes the new Composer the next time it builds.
4. Stack repos: refresh `deploy/` the same way. Sourdough: refresh its generated copy with `php bin/export-templates.php --to=templates/squire`.

### How to verify

Run the two checks at the top of this page; both exit `0`. `.squire/base-fallback.Dockerfile` names `composer:2.11.1`.

## 0.10.0 (2026-11-09)

No breaks, and no kit file changes: an app that takes 0.10.0 gets the same files as 0.9.0. The release adds the MySQL upgrade path guide ([MySQL upgrade path](guides/mysql-upgrade-path.md)) with a read-only pre-upgrade check script, and docs kit fixes for sites built with Squire's docs builder: the heading "#" stays on the line of its last word, and "On this page" marks the section in view. A site takes those by resyncing the kit and builder with `--sync-to` (see [Docs builder](guides/docs-builder.md)).

### Steps

1. Update the `waynestate/squire` dev dependency: `composer require --dev waynestate/squire:^0.10`.
2. Run `vendor/bin/squire update`; it records the new release in `.squire.json`.

### How to verify

Run the two checks at the top of this page; both exit `0`.

## 0.9.0 (2026-11-08)

One behavior change for Laravel apps: the release script now checks the app's environment before anything installs. A release stops when `APP_KEY` is unset, or when `APP_DEBUG` is true in production, and an `APP_ENV` that is not `production` earns a warning (Sourdough practice 4.14). The release gate also passes `--php-runtime` to PROCTOR, so the deploy path grades the PHP that serves the app for PROCTOR's unpatched check; `SQUIRE_PHP_RUNTIME` (`auto` by default, `skip`, or an `X.Y.Z` version) changes what it grades.

### Steps

1. Before you deploy, make sure the Citadel environment sets `APP_KEY` (in `.env` or as a masked variable in the Citadel dashboard) and sets `APP_DEBUG` to false.
2. Update the `waynestate/squire` dev dependency: `composer require --dev waynestate/squire:^0.9`.
3. Run `vendor/bin/squire update`: it takes the new `scripts/release.sh` and `.env.squire.example` when the app never edited them, and shows a diff when it did.
4. Stack repos: refresh `deploy/` the same way. Sourdough: refresh its generated copy with `php bin/export-templates.php --to=templates/squire`.

### How to verify

Run the two checks at the top of this page; both exit `0`. `scripts/release.sh` checks `APP_KEY` and `APP_DEBUG` (search it for `Sourdough practice 4.14`).

## 0.8.0 (2026-11-08)

No breaks. The portable image (`.squire/portable.Dockerfile`, ADR-0010) bakes the code in and runs as `web`, `worker`, `scheduler` or `migrate`, picked by argument or `SQUIRE_ROLE`, as a non-root user in group 0 with Apache on 8080, for OpenShift, Coolify and raw Docker. `health.php` reads `SQUIRE_APP_DIR` for the app root, so a kit kept in a subfolder checks the app it serves. The release script keeps Composer's and npm's download caches in `.cache/squire` inside the app folder, so a Citadel Update no longer downloads every package again. The Citadel path is unchanged.

### Steps

1. Update the `waynestate/squire` dev dependency: `composer require --dev waynestate/squire:^0.8`.
2. Run `vendor/bin/squire update`: it adds the portable files and shows a diff for any kit file the app edited.
3. Stack repos: refresh `deploy/` the same way. Sourdough: refresh its generated copy with `php bin/export-templates.php --to=templates/squire`.

### How to verify

Run the two checks at the top of this page; both exit `0`. The app now carries the five new portable files, led by `.squire/portable.Dockerfile`.

## 0.7.1 (2026-11-08)

No breaks: a patch on 0.7.0. The kit's `php.ini` no longer sets `session.sid_length` or `session.sid_bits_per_character`. PHP 8.4 deprecated both and raises a notice for each, and leaving them out gives PHP's default session ID (32 hex characters, 128 bits), which is what HELMET and SESAME's S45 expect. Squire's own `deploy/.squire.json` also records the `release.sh` it ships, so `squire update` on Squire itself no longer refuses that file.

### Steps

1. Update the `waynestate/squire` dev dependency: `composer require --dev waynestate/squire:^0.7.1`.
2. Run `vendor/bin/squire update`: it takes the new `.squire/php.ini` when the app never edited it, and shows a diff when it did. Keep your edits and drop the two `session.sid_` lines.
3. Stack repos: refresh `deploy/` the same way. Sourdough: refresh its generated copy with `php bin/export-templates.php --to=templates/squire`.

### How to verify

Run the two checks at the top of this page; both exit `0`. `.squire/php.ini` has no `session.sid_length` or `session.sid_bits_per_character` line.

## 0.7.0 (2026-11-08)

### Breaking

One behavior change for the web: the app image now sets `disable_functions` for mod_php to `exec`, `passthru`, `shell_exec`, `system`, `pcntl_exec`, `phpinfo`, `show_source` and `highlight_file`. Web code that calls one of them stops working. The CLI (Composer scripts, artisan, the health check, the release script) keeps every function, and `proc_open` and `popen` stay for Symfony Mailer, Laravel's Process and PHPMailer. An app that needs one back sets `SQUIRE_PHP_DISABLE_FUNCTIONS` to its own list, or to `none`. The kit's `php.ini` also stops setting `disable_classes`, which PHP 8.5 removed.

### What changed

**Hardening.** The fallback base sets `ServerTokens Prod`, `ServerSignature Off` and `TraceEnable Off`. The kit's `php.ini` turns on production opcache for the web, with one switch, `SQUIRE_OPCACHE_VALIDATE`: `1` (the default) checks files every 2 seconds, for code pulled into a running container such as a Citadel Quick Update; `0` never checks, for an image that bakes its code in. The release script and the fallback entrypoint load secrets mounted as files from `SQUIRE_SECRETS_DIR` (default `/run/secrets`). `SQUIRE_NPM_REBUILD` lets named packages run their install scripts after `npm ci --ignore-scripts`. Every image the kit pulls is pinned by its multi-arch list digest. The image contract suite checks the hardening inside the running image.

**Release script.** After `laravel optimize`, a new report-only step restarts Laravel's long-lived processes: `php artisan reload` where Laravel has it, otherwise `queue:restart` and `schedule:interrupt`, each only when that command exists. `SQUIRE_ARTISAN_RESTART=0` turns it off (WELD W22). A new "check opcache" step never fails.

**Fixed.** `squire update` records the current release in `.squire.json` even when every file already matches it. The pipeline's PROCTOR jobs no longer pass `--policy`. The docs kit's copy button and mobile menu, and the front page's quick start and Get started prompt, are fixed (see the CHANGELOG).

**CI and deploy layer.** CI pins the Preflight components to 0.3.1 and runs its a11y component on `site/`, blocking. Squire's own deploy layer requires HELMET `^1.0` and PROCTOR `^0.8`.

### Steps

1. Update the `waynestate/squire` dev dependency: `composer require --dev waynestate/squire:^0.7`.
2. Run `vendor/bin/squire update`: it takes the new template versions for the files you never edited and shows a diff for the ones you did.
3. If web code calls one of the disabled functions, set `SQUIRE_PHP_DISABLE_FUNCTIONS` in the app's environment (see [Configuration](reference/configuration.md)) or move that call to a command.
4. An app that bakes its code into the image (OpenShift, Coolify, raw Docker) sets `SQUIRE_OPCACHE_VALIDATE=0`. Citadel apps keep the default.
5. Stack repos: regenerate the `deploy/` folder with `squire init` and check it with `squire init --check`. Sourdough: refresh its generated copy with `php bin/export-templates.php --to=templates/squire` and verify it with `--check`.

### How to verify

Run `squire init --check` and `squire update --dry-run` (the two checks at the top of this page); both exit `0` once the kit is at the new release. After the next release on the server, the release log shows the "laravel restart long-lived processes" and "check opcache" steps.

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

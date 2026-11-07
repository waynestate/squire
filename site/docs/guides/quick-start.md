---
title: "Quick start | Squire docs"
description: "Install Squire, run squire init and squire doctor, and commit .squire.json: the shortest ride from composer require to a deploy-ready app."
url: https://squire.apps.wayne.edu/docs/guides/quick-start.html
llms: https://squire.apps.wayne.edu/llms.txt
generator: Squire agent-ready
---

# Quick start[#](https://squire.apps.wayne.edu/docs/guides/quick-start.html#quick-start)

A squire carries the kit and readies the armor before the knight charges, and this page is that ride. Four numbered steps take a new app from `composer require` to a checked, deploy-ready setup; do them in order, then follow a next link.

## 1. Install Squire[#](https://squire.apps.wayne.edu/docs/guides/quick-start.html#1-install-squire)

Squire is a dev dependency. It runs on your machine or in CI, not on Citadel; what lands on Citadel is the scripts it writes. PHP `^8.3`, tested on 8.3, 8.4 and 8.5.

```bash
composer require waynestate/squire --dev
```

## 2. Run squire init[#](https://squire.apps.wayne.edu/docs/guides/quick-start.html#2-run-squire-init)

`init` picks the target from your app: `laravel` when there is an `artisan` file, `raw` without one, or set it yourself with `--target`. It detects a Vue/Inertia layer from `package.json` and adds the layer's release script and Node 22 parity service.

```bash
vendor/bin/squire init
```

The full option set:

```text
vendor/bin/squire init   [--path=.] [--target=auto|raw|laravel|static] [--only=release|parity] [--check] [--force]
```

`init` never overwrites a file you edited, or a file it did not write, unless you pass `--force`. It records a SHA-256 of each file in `.squire.json`. `--only=release` writes just the Citadel scripts, `--only=parity` just the local setup. The release installs from the lock file, so when the app has no `composer.json` or no `composer.lock`, `init` ends with a "Heads up" line: commit both before the first deploy (`composer init`, then `composer update`), or the release stops at step 1.

## 3. Run squire doctor[#](https://squire.apps.wayne.edu/docs/guides/quick-start.html#3-run-squire-doctor)

```bash
vendor/bin/squire doctor
```

`doctor` checks an app against the best practices a machine can decide. The lock file, composer audit and MySQL 5.6 checks are owned by PROCTOR; `doctor` hands off to it (S50) instead of repeating them.

Exit codes: `0` pass, `1` findings (`init --check`: drift; `update`: an edited file still differs from the template; `doctor`: a failed rule), `2` the tool itself could not run.

## 4. Commit .squire.json[#](https://squire.apps.wayne.edu/docs/guides/quick-start.html#4-commit-squire-json)

Commit the `.squire.json` record file. `update` reads it to know which files Squire wrote, and `init --check` reports when your copies have drifted from the templates (depend, do not copy).

## What you built[#](https://squire.apps.wayne.edu/docs/guides/quick-start.html#what-you-built)

| File | What it is |
| --- | --- |
| `build.sh` | Citadel's build hook: system packages only, POSIX sh, runs as root with no app source. No Composer, no npm. |
| `deployments/001-first-release.sh` | Runs once per app: calls the release script with the smoke test off (Apache is not up yet). |
| `scripts/release.sh` | The idempotent release: preconditions, `composer install --no-dev` from the lock file, audit, PROCTOR's deploy gate (`proctor gate`, a report by default, a stop with `SQUIRE_PROCTOR=enforce`), frontend build, Laravel `migrate --force` and `optimize`, an Oracle connection check (a report, never a gate), `/up` smoke test. Use it as the Quick Update post-script as well. |
| `health.php` | Citadel's health check (`["php","health.php"]`): one HTTP request to `/up`, never boots the framework. |
| `oracle-check.php` | The plain-language Oracle connection check the release runs: settings, host, port, driver, login. A report, never a gate; silent when the app has no `ORACLE_*` settings. |
| `public/up.php` | Raw PHP and static: the endpoint that answers `ok`. Laravel has `/up` built in. |
| `public/index.php` | Raw: the front controller that serves `public/` files safely and hands the rest to the app's entry. Static: the one that serves the committed site with HELMET's headers. Never written over an app's own. |
| `public/.htaccess` | Raw: sends every request but `index.php` and `up.php` to the front controller. Static: routes every request that is not a real file to it. |
| `.npmrc` | Vue layer only: sets `min-release-age=1` for the frontend install. |
| Vue `docker-compose.squire.yml` | Vue layer only: the common parity compose plus a Node 22 service for frontend tooling. |
| `docker-compose.squire.yml`, `.squire/`, `.env.squire.example`, `scripts/parity.sh`, `scripts/parity.ps1` | Local parity. |

Every shell script uses `set -euo pipefail` (`build.sh` uses `set -eu`, because Citadel runs it with `/bin/sh`), prints a `[squire] ==> step N: ...` marker for each step, and can be run twice with the same result.

## Next steps[#](https://squire.apps.wayne.edu/docs/guides/quick-start.html#next-steps)

- [Raw PHP app](https://squire.apps.wayne.edu/docs/guides/raw-php-app.html)
- [Static site](https://squire.apps.wayne.edu/docs/guides/static-site.html)
- [Citadel setup](https://squire.apps.wayne.edu/docs/guides/citadel-setup.html)
- [Release script](https://squire.apps.wayne.edu/docs/guides/release-script.html)
- [Local parity](https://squire.apps.wayne.edu/docs/guides/local-parity.html)
- [Doctor](https://squire.apps.wayne.edu/docs/guides/doctor.html)
- [Update and drift](https://squire.apps.wayne.edu/docs/guides/update-and-drift.html)
- [Commands](https://squire.apps.wayne.edu/docs/reference/commands.html) and [Files](https://squire.apps.wayne.edu/docs/reference/files.html)

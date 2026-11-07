---
title: "Files Squire writes | Squire docs"
description: "The table of files Squire writes into an app, which target or layer each belongs to, and the .gitignore lines init keeps."
url: https://squire.apps.wayne.edu/docs/reference/files.html
llms: https://squire.apps.wayne.edu/llms.txt
generator: Squire agent-ready
---

# Files Squire writes[#](https://squire.apps.wayne.edu/docs/reference/files.html#files-squire-writes)

What `squire init` writes into your app, which target or layer each file belongs to, and the `.gitignore` lines `init` keeps honest. Next, see [Quick start](https://squire.apps.wayne.edu/docs/guides/quick-start.html) to write your first set of files and [Configuration](https://squire.apps.wayne.edu/docs/reference/configuration.html) for the settings they read.

## What lands in your app[#](https://squire.apps.wayne.edu/docs/reference/files.html#what-lands-in-your-app)

| File | What it is | Target or layer |
| --- | --- | --- |
| `build.sh` | Citadel's build hook: system packages only, POSIX sh, runs as root with no app source. No Composer, no npm. | All targets |
| `deployments/001-first-release.sh` | Runs once per app: calls the release script with the smoke test off (Apache is not up yet). | All targets |
| `scripts/release.sh` | The idempotent release: preconditions, `composer install --no-dev` from the lock file, audit, PROCTOR's deploy gate (`proctor gate`, a report by default, a stop with `SQUIRE_PROCTOR=enforce`), frontend build, Laravel `migrate --force` and `optimize`, an Oracle connection check (a report, never a gate), `/up` smoke test. Use it as the Quick Update post-script as well. | All targets |
| `health.php` | Citadel's health check (`["php","health.php"]`): one HTTP request to `/up`, never boots the framework. | All targets |
| `oracle-check.php` | The plain-language Oracle connection check the release runs: settings, host, port, driver, login. A report, never a gate; silent when the app has no `ORACLE_*` settings. | All targets |
| `public/up.php` | The endpoint that answers `ok`. Laravel has `/up` built in. | Raw PHP and static |
| `public/index.php` | Raw: the front controller that serves `public/` files safely and hands the rest to the app's entry. Static: the one that serves the committed site with HELMET's headers. Never written over an app's own. | Raw and static |
| `public/.htaccess` | Raw: sends every request but `index.php` and `up.php` to the front controller. Static: routes every request that is not a real file to it. | Raw and static |
| `.npmrc` | Sets `min-release-age=1` for the frontend install. | Vue layer only |
| `docker-compose.squire.yml` | The common parity compose plus a Node 22 service for frontend tooling. | Vue layer only |
| `docker-compose.squire.yml`, `.squire/`, `.env.squire.example`, `scripts/parity.sh`, `scripts/parity.ps1` | Local parity. | Local parity kit |

## The .gitignore lines init keeps[#](https://squire.apps.wayne.edu/docs/reference/files.html#the-gitignore-lines-init-keeps)

In a directory below the repository root (a `deploy/` layer, say), `init` also keeps the `.gitignore` honest: it appends `/vendor/`, `.env` and `.env.squire` when they are missing, never touches a line the app added, and leaves the repository root's `.gitignore` to the app.

## Conventions[#](https://squire.apps.wayne.edu/docs/reference/files.html#conventions)

Every shell script uses `set -euo pipefail` (`build.sh` uses `set -eu`, because Citadel runs it with `/bin/sh`), prints a `[squire] ==> step N: ...` marker for each step, and can be run twice with the same result.

Note

`init` records a SHA-256 of each file in `.squire.json`; commit that file. `init --check` reports when your copies have drifted from the templates (depend, do not copy), and `update` takes the new template versions for the files you never edited.

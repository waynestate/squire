---
title: "Files Squire writes | Squire docs"
description: "The table of files Squire writes into an app, which target or layer each belongs to, and the .gitignore lines init keeps."
url: https://squire.apps.wayne.edu/docs/reference/files.html
llms: https://squire.apps.wayne.edu/llms.txt
generator: Squire agent-ready
---

# Files Squire writes [#](https://squire.apps.wayne.edu/docs/reference/files.html#files-squire-writes)

What `squire init` writes into your app, which target or layer each file belongs to, and the `.gitignore` lines `init` keeps honest. Next, see [Quick start](https://squire.apps.wayne.edu/docs/guides/quick-start.html) to write your first set of files and [Configuration](https://squire.apps.wayne.edu/docs/reference/configuration.html) for the settings they read.

## What lands in your app [#](https://squire.apps.wayne.edu/docs/reference/files.html#what-lands-in-your-app)

| File | What it is | Target or layer |
| --- | --- | --- |
| `build.sh` | Citadel's build hook: system packages only, POSIX sh, runs as root with no app source. No Composer, no npm. | All targets |
| `deployments/001-first-release.sh` | Runs once per app: calls the release script with the smoke test off (Apache is not up yet). | All targets |
| `scripts/release.sh` | The idempotent release: preconditions, `composer install --no-dev` from the lock file, audit, PROCTOR's deploy gate (`proctor gate`, a report by default, a stop with `SQUIRE_PROCTOR=enforce`), frontend build, Laravel `migrate --force` and `optimize`, an Oracle connection check (a report, never a gate), `/up` smoke test. Use it as the Quick Update post-script as well. | All targets |
| `health.php` | Citadel's health check (`["php","health.php"]`): one HTTP request to `/up`, never boots the framework. On a Laravel app, keep Pint off it: [Keep Pint off the kit's root files](https://squire.apps.wayne.edu/docs/guides/quick-start.html#keep-pint-off-the-kit-s-root-files). | All targets |
| `oracle-check.php` | The plain-language Oracle connection check the release runs: settings, host, port, driver, login. A report, never a gate; silent when the app has no `ORACLE_*` settings. On a Laravel app, keep Pint off it as well: [Keep Pint off the kit's root files](https://squire.apps.wayne.edu/docs/guides/quick-start.html#keep-pint-off-the-kit-s-root-files). | All targets |
| `.gitattributes` | Pins line endings: LF for shell scripts, `.conf` files and Dockerfiles, CRLF for PowerShell and batch files, so a Windows checkout cannot break a deploy. Written only when the folder has none; never written over an app's own. | All targets |
| `public/up.php` | The endpoint that answers `ok`. Laravel has `/up` built in. | Raw PHP and static |
| `public/index.php` | Raw: the front controller that serves `public/` files safely and hands the rest to the app's entry. Static: the one that serves the committed site with HELMET's headers. Never written over an app's own. | Raw and static |
| `public/.htaccess` | Raw: sends every request but `index.php` and `up.php` to the front controller. Static: routes every request that is not a real file to it. | Raw and static |
| `.npmrc` | Sets `min-release-age=1` (days) for the frontend install, with the escape hatches in its comments. See [Image hardening](https://squire.apps.wayne.edu/docs/guides/image-hardening.html#npm-no-install-scripts-no-day-old-versions). | Vue layer only |
| `.squire/Dockerfile`, `.squire/Dockerfile.dockerignore`, `.squire/vhost.conf` | The Citadel mirror: the app image on Citadel's own base, the code mounted at `/citadel/src`, Apache on 80. | Images group (opt-in) |
| `.squire/base-fallback.Dockerfile`, `.squire/fallback/` | The fallback base: an approximation of Citadel's PHP image for the app's line, built from the official `php` image, for machines without the real one. See [Image hardening](https://squire.apps.wayne.edu/docs/guides/image-hardening.html). | Images group (opt-in) |
| `.squire/php.ini` | The images' `php.ini`: production opcache, safe session cookies, no `disable_classes`. See [Image hardening](https://squire.apps.wayne.edu/docs/guides/image-hardening.html). | Images group (opt-in) |
| `.squire/apache-hardening.conf` | `ServerTokens Prod`, `ServerSignature Off` and `TraceEnable Off`, enabled by the fallback base. See [Image hardening](https://squire.apps.wayne.edu/docs/guides/image-hardening.html). | Images group (opt-in) |
| `.squire/portable.Dockerfile`, `.squire/portable.Dockerfile.dockerignore` | The portable image for platforms other than Citadel: the code baked in, `USER 10001:0`, group-0 writable folders, port 8080, `STOPSIGNAL SIGTERM`. Its ignore file keeps `.env`, secrets, `.git`, `vendor/` and `node_modules/` out of the build. See [The portable image](https://squire.apps.wayne.edu/docs/guides/portable-image.html). | Images group (opt-in) |
| `.squire/portable/squire-entrypoint.sh` | The portable image's entrypoint: the `web`, `worker`, `scheduler`, `migrate` and `health` roles, picked by argument or `SQUIRE_ROLE`. | Images group (opt-in) |
| `.squire/portable/ports.conf`, `.squire/portable/vhost.conf` | Apache for the portable image: `Listen ${SQUIRE_PORT}`, the same document root as Citadel's vhost, logs to stdout and stderr. | Images group (opt-in) |
| `.squire/php-web.ini` | The web's own settings: the `disable_functions` list. The app image appends it to `php.ini` as `php-apache2handler.ini`, so the CLI keeps every function. See [Image hardening](https://squire.apps.wayne.edu/docs/guides/image-hardening.html). | Images group (opt-in) |

## The .gitignore lines init keeps [#](https://squire.apps.wayne.edu/docs/reference/files.html#the-gitignore-lines-init-keeps)

In a directory below the repository root (a `deploy/` layer, say), `init` also keeps the `.gitignore` honest: it appends `/vendor/`, `.env` and `.env.squire` when they are missing, never touches a line the app added, and leaves the repository root's `.gitignore` to the app.

## Conventions [#](https://squire.apps.wayne.edu/docs/reference/files.html#conventions)

Every shell script uses `set -euo pipefail` (`build.sh` uses `set -eu`, because Citadel runs it with `/bin/sh`), prints a `[squire] ==> step N: ...` marker for each step, and can be run twice with the same result.

Note

`init` records a SHA-256 of each file in `.squire.json`; commit that file. `init --check` reports when your copies have drifted from the templates (depend, do not copy), and `update` takes the new template versions for the files you never edited.

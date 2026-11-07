---
title: "Configuration reference"
description: "Every SQUIRE_* setting Squire reads, the file it lives in, and its default."
---

# Configuration reference

Every `SQUIRE_*` setting Squire reads, grouped by the script that reads it, with the file each group lives in and its default. All settings are optional. Next, see [Commands](commands.md) for the CLI flags and [Files](files.md) for what Squire writes.

> [!WARNING]
> A real environment variable wins. `release.sh` reads the release options from the file, but a variable already in the environment wins. Locally, the same file holds the parity settings.

## Where the settings live

All optional. On Citadel, add `./.env.squire` as a configuration file (the configuration-files step after the wizard; the wizard has no environment field) and keep it out of git. `release.sh` reads the release options from it, and a variable already in the environment wins. Locally, the same file holds the parity settings.

| Group | Reader | File |
|---|---|---|
| Release script | `scripts/release.sh` | `./.env.squire` (on Citadel, added as a configuration file and kept out of git) |
| Front controller | raw `public/index.php`, static `public/index.php` | the app's environment |
| Health check | `health.php` | the app's environment |
| Local parity | the parity compose file | `.env.squire` (see `.env.squire.example`; it holds placeholders only) |

## Release script (`scripts/release.sh`)

| Variable | Default | Meaning |
|---|---|---|
| `SQUIRE_APP_DIR` | parent of `scripts/` | App root. |
| `SQUIRE_FRAMEWORK` | `auto` | `auto`, `laravel` or `raw`. Laravel is detected from `artisan`. |
| `SQUIRE_ALLOW_ROOT` | `0` | `1` allows running as root. |
| `SQUIRE_REQUIRE_ENV` | `auto` | `1` requires a `.env` file; `auto` means Laravel does. |
| `SQUIRE_WRITABLE_DIRS` | Laravel: `storage`, `bootstrap/cache` | Directories to create. |
| `SQUIRE_COMPOSER_AUDIT` | `report` | `report`, `fail` or `skip`. |
| `SQUIRE_NPM` | `auto` | `auto` or `skip` (for committed built assets). |
| `SQUIRE_NPM_IGNORE_SCRIPTS` | `1` | Install with `--ignore-scripts`. |
| `SQUIRE_NPM_BUILD_SCRIPT` | `build` | The `package.json` script to run. |
| `SQUIRE_MIGRATE` | `1` | Laravel: `migrate --force`. |
| `SQUIRE_ARTISAN_OPTIMIZE` | `1` | Laravel: `optimize`. |
| `SQUIRE_PROCTOR` | `auto` | `auto`, `skip` or `enforce`. The release asks `proctor gate` for a go or no-go (`proctor check` on a PROCTOR without the gate). By default a no-go is a report and never stops the release; `enforce` makes a no-go stop it, before the frontend build and the migrations. |
| `SQUIRE_PROCTOR_BIN` | `vendor/bin/proctor`, then `PATH` | Where PROCTOR is. It is a dev dependency, so `--no-dev` removes it: point this at a copy. |
| `SQUIRE_PROCTOR_POLICY` | `vendor/waynestate/creed/policy/policy.json`, when installed | Path to the policy file. When unset, the script falls back to the policy the CREED package ships (the deploy wizard has no environment field); when CREED is not installed, PROCTOR runs on its own default policy with a notice. An explicit value always wins. |
| `SQUIRE_PROCTOR_RECORD_DIR` | `storage/squire` | Where the gate keeps its verdicts, relative to the app root: `proctor-gate.json` (the last verdict) and `proctor-gate-baseline.json` (the verdict of the last release that went on, the next run's baseline). The folder gets a `.gitignore` of `*`, so it never reaches git. |
| `SQUIRE_SMOKE` | `1` | Request the health URL at the end and fail unless it answers 200. |
| `SQUIRE_SMOKE_URL` | `http://127.0.0.1:80/up` (Laravel), `/up.php` (raw and static) | Full URL. |
| `SQUIRE_SMOKE_RETRIES` | `5` | Attempts. |
| `SQUIRE_SMOKE_DELAY` | `2` | Seconds between attempts. |
| `SQUIRE_SECRETS_DIR` | `/run/secrets` | A folder of secret files, read before the first step: each regular file named like a variable (`DB_PASSWORD`) becomes that variable, unless the environment already sets it. Only names are printed. The fallback entrypoint reads the same folder for Apache. See [Image hardening](../guides/image-hardening.md). |

## Front controller

| Variable | Default | Meaning |
|---|---|---|
| `SQUIRE_APP_ENTRY` | `app.php` | Front controller (raw `public/index.php`): the app's entry, relative to the app directory. |
| `SQUIRE_STATIC_ROOT` | `../../site` | Static `public/index.php`: the site folder. |

## Health check (`health.php`)

| Variable | Default | Meaning |
|---|---|---|
| `SQUIRE_HEALTH_PATH` | not stated | The path the health check requests. |
| `SQUIRE_HEALTH_PORT` | `80` | The port the health check requests. |
| `SQUIRE_HEALTH_TIMEOUT` | `5`, at most `14`, inside Citadel's `15` | Seconds the health check waits. |
| `SQUIRE_HEALTH_VENDOR` | `1` also requires `vendor/autoload.php` | Whether the health check also requires the autoloader. |

## PHP in the image (`.squire/php.ini`)

PHP reads these from the container's environment when it starts (PHP 8.3 and later). The parity compose file passes `SQUIRE_OPCACHE_VALIDATE_TIMESTAMPS` and `SQUIRE_PHP_DISABLE_FUNCTIONS` from `.env.squire`; on Citadel the platform's own `php.ini` decides. See [Image hardening](../guides/image-hardening.md).

| Variable | Default | Meaning |
|---|---|---|
| `SQUIRE_OPCACHE_VALIDATE_TIMESTAMPS` | `0` in the image, `1` in the parity compose file | `1` makes opcache notice changed files (a bind mount, a release in place); `0` never checks until Apache restarts. |
| `SQUIRE_OPCACHE_MEMORY` | `128` | opcache memory, in megabytes. |
| `SQUIRE_OPCACHE_MAX_FILES` | `20000` | How many scripts opcache keeps. |
| `SQUIRE_PHP_DISABLE_FUNCTIONS` | `exec,passthru,shell_exec,system,pcntl_exec,phpinfo,show_source,highlight_file` | The web's `disable_functions` list (`.squire/php-web.ini`; the CLI disables nothing). It replaces the list; `none` disables nothing, and an empty value means the default. |

## Local parity (`.env.squire`)

| Variable | Default | Meaning |
|---|---|---|
| `SQUIRE_PROJECT` | not stated | Local parity: the project name. |
| `SQUIRE_BASE_IMAGE` | not stated | Local parity: the app image. |
| `SQUIRE_PROXY_IMAGE` | not stated | Local parity: the proxy image. |
| `SQUIRE_MYSQL_IMAGE` | not stated | Local parity: the MySQL image. |
| `SQUIRE_PUBLIC_DIR` | not stated | Local parity: the public directory. |
| `SQUIRE_SERVER_NAME` | not stated | Local parity: the server name. |
| `SQUIRE_TZ` | not stated | Local parity: the timezone. |
| `SQUIRE_ENVIRONMENT` | not stated | Local parity: the environment. |
| `SQUIRE_DEPLOYMENTS` | not stated | Local parity: the deployments path. |
| `SQUIRE_HTTP_PORT` | not stated | Local parity: the HTTP port. |
| `SQUIRE_APP_MEMORY` | not stated | Local parity: the app memory limit. |
| `SQUIRE_SOURCE_UID` | not stated | Local parity: the uid that owns the mounted source. |
| `SQUIRE_SOURCE_GID` | not stated | Local parity: the gid that owns the mounted source. |
| `SQUIRE_DB_NAME` | not stated | Local parity: the database name. |
| `SQUIRE_DB_USER` | not stated | Local parity: the database user. |
| `SQUIRE_DB_PASSWORD` | not stated | Local parity: the database password. |
| `SQUIRE_DB_ROOT_PASSWORD` | not stated | Local parity: the database root password. |
| `SQUIRE_DB_PORT` | not stated | Local parity: the database port. |
| `SQUIRE_DB_MEMORY` | not stated | Local parity: the database memory limit. |

The Vue layer's compose file also reads `SQUIRE_NODE_IMAGE` (default `node:22-bookworm-slim`), `SQUIRE_NODE_ENV` (default `production`) and `SQUIRE_NODE_MEMORY` (default `512m`). The compose file refuses to start without the two passwords. See `.env.squire.example`; it holds placeholders only.

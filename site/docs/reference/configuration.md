---
title: "Configuration reference | Squire docs"
description: "Every SQUIRE_* setting Squire reads, the file it lives in, and its default."
url: https://squire.apps.wayne.edu/docs/reference/configuration.html
llms: https://squire.apps.wayne.edu/llms.txt
generator: Squire agent-ready
---

# Configuration reference [#](https://squire.apps.wayne.edu/docs/reference/configuration.html#configuration-reference)

Every `SQUIRE_*` setting Squire reads, grouped by the script that reads it, with the file each group lives in and its default. All settings are optional. Next, see [Commands](https://squire.apps.wayne.edu/docs/reference/commands.html) for the CLI flags and [Files](https://squire.apps.wayne.edu/docs/reference/files.html) for what Squire writes.

Warning

A real environment variable wins. `release.sh` reads the release options from the file, but a variable already in the environment wins. Locally, the same file holds the parity settings.

## Where the settings live [#](https://squire.apps.wayne.edu/docs/reference/configuration.html#where-the-settings-live)

All optional. On Citadel, add `./.env.squire` as a configuration file (the configuration-files step after the wizard; the wizard has no environment field) and keep it out of git. `release.sh` reads the release options from it, and a variable already in the environment wins. Locally, the same file holds the parity settings.

| Group | Reader | File |
| --- | --- | --- |
| Release script | `scripts/release.sh` | `./.env.squire` (on Citadel, added as a configuration file and kept out of git) |
| Front controller | raw `public/index.php`, static `public/index.php` | the app's environment |
| Health check | `health.php` | the app's environment |
| Local parity | the parity compose file | `.env.squire` (see `.env.squire.example`; it holds placeholders only) |
| Portable image | `.squire/portable/squire-entrypoint.sh`, `docker build` | the container's environment, and build arguments |

## Release script (`scripts/release.sh`) [#](https://squire.apps.wayne.edu/docs/reference/configuration.html#release-script-scripts-release-sh)

| Variable | Default | Meaning |
| --- | --- | --- |
| `SQUIRE_APP_DIR` | parent of `scripts/` | App root. |
| `SQUIRE_FRAMEWORK` | `auto` | `auto`, `laravel` or `raw`. Laravel is detected from `artisan`. |
| `SQUIRE_ALLOW_ROOT` | `0` | `1` allows running as root. |
| `SQUIRE_REQUIRE_ENV` | `auto` | `1` requires a `.env` file; `auto` means Laravel does. |
| `SQUIRE_WRITABLE_DIRS` | Laravel: `storage`, `bootstrap/cache` | Directories to create. |
| `SQUIRE_COMPOSER_AUDIT` | `report` | `report`, `fail` or `skip`. |
| `SQUIRE_NPM` | `auto` | `auto` or `skip` (for committed built assets). |
| `SQUIRE_NPM_IGNORE_SCRIPTS` | `1` | Install with `--ignore-scripts` (Yarn 2 and later: `--mode=skip-build`). |
| `SQUIRE_NPM_REBUILD` | empty | Packages, separated by spaces, whose install scripts run after the install (`npm rebuild --ignore-scripts=false`, `pnpm rebuild`, or `yarn rebuild` for Yarn 2 and later; Yarn 1 apps get `npm rebuild`; pnpm 10 also needs them in `pnpm.onlyBuiltDependencies`). The escape hatch for a package that needs its own scripts, such as a native addon. |
| `SQUIRE_NPM_BUILD_SCRIPT` | `build` | The `package.json` script to run. |
| `SQUIRE_CACHE_DIR` | `.cache/squire` | Where Composer and npm keep their download caches between releases, inside the app folder so a Citadel Update keeps them; `off` leaves their defaults. |
| `SQUIRE_MIGRATE` | `1` | Laravel: `migrate --force`. |
| `SQUIRE_ARTISAN_OPTIMIZE` | `1` | Laravel: `optimize`. |
| `SQUIRE_ARTISAN_RESTART` | `1` | Laravel: tell queue workers and `schedule:work` to restart on the new code (`reload`, or `queue:restart` and `schedule:interrupt`). Report only. |
| `SQUIRE_PROCTOR` | `auto` | `auto`, `skip` or `enforce`. The release asks `proctor gate` for a go or no-go (`proctor check` on a PROCTOR without the gate). By default a no-go is a report and never stops the release; `enforce` makes a no-go stop it, before the frontend build and the migrations. |
| `SQUIRE_PROCTOR_BIN` | `vendor/bin/proctor`, then `PATH`, then the fetch below | Where PROCTOR is. It is a dev dependency, so `--no-dev` removes it; set this to use a copy of your own (PROCTOR's tagged phar works, marked executable). |
| `SQUIRE_PROCTOR_FETCH` | `1` | When no PROCTOR is found, install the version `composer.lock` pins as a dev package into `.cache/squire/proctor/<version>` (`composer create-project --no-dev`, once per version) and run that. `0` turns it off. A failed fetch is a warning, or a stop under `enforce`. Needs `SQUIRE_CACHE_DIR` on. |
| `SQUIRE_PROCTOR_POLICY` | `vendor/waynestate/creed/policy/policy.json`, when installed | Path to the policy file. When unset, the script falls back to the policy the CREED package ships (the deploy wizard has no environment field); when CREED is not installed, PROCTOR runs on its own default policy with a notice. An explicit value always wins. |
| `SQUIRE_PROCTOR_RECORD_DIR` | `storage/squire` | Where the gate keeps its verdicts, relative to the app root: `proctor-gate.json` (the last verdict) and `proctor-gate-baseline.json` (the verdict of the last release that went on, the next run's baseline). The folder gets a `.gitignore` of `*`, so it never reaches git. |
| `SQUIRE_PHP_RUNTIME` | `auto` | The PHP version the gate grades for PROCTOR's unpatched check, passed as `--php-runtime`: `auto` reads the php that runs `release.sh` (it is the one serving the app; a suffix such as `-dev` is cut), `skip` grades no runtime, and an `X.Y.Z` value is graded as given. A value that is not `X.Y.Z` is a note and grades nothing. Only `proctor gate` gets it; the older `proctor check` fallback does not. |
| `SQUIRE_SMOKE` | `1` | Request the health URL at the end and fail unless it answers 200. |
| `SQUIRE_SMOKE_URL` | `http://127.0.0.1:80/up` (Laravel), `/up.php` (raw and static) | Full URL. |
| `SQUIRE_SMOKE_RETRIES` | `5` | Attempts. |
| `SQUIRE_SMOKE_DELAY` | `2` | Seconds between attempts. |
| `SQUIRE_SECRETS_DIR` | `/run/secrets` | A folder of secret files, read before the first step: each regular file named like a variable (`DB_PASSWORD`) becomes that variable, unless the environment already sets it. Only names are printed. The fallback entrypoint reads the same folder for Apache. See [Image hardening](https://squire.apps.wayne.edu/docs/guides/image-hardening.html). |

## Front controller [#](https://squire.apps.wayne.edu/docs/reference/configuration.html#front-controller)

| Variable | Default | Meaning |
| --- | --- | --- |
| `SQUIRE_APP_ENTRY` | `app.php` | Front controller (raw `public/index.php`): the app's entry, relative to the app directory. |
| `SQUIRE_STATIC_ROOT` | `../../site` | Static `public/index.php`: the site folder. |

## Health check (`health.php`) [#](https://squire.apps.wayne.edu/docs/reference/configuration.html#health-check-health-php)

| Variable | Default | Meaning |
| --- | --- | --- |
| `SQUIRE_HEALTH_PATH` | not stated | The path the health check requests. |
| `SQUIRE_HEALTH_PORT` | `80` | The port the health check requests. |
| `SQUIRE_HEALTH_TIMEOUT` | `5`, at most `14`, inside Citadel's `15` | Seconds the health check waits. |
| `SQUIRE_HEALTH_VENDOR` | `1` also requires `vendor/autoload.php` | Whether the health check also requires the autoloader. |
| `SQUIRE_APP_DIR` | the folder `health.php` is in | The app root the check reads (`artisan`, `vendor/`). The portable image sets `/citadel/src`, so a kit in a subfolder checks the app it serves. |

## Portable image (`.squire/portable.Dockerfile`) [#](https://squire.apps.wayne.edu/docs/reference/configuration.html#portable-image-squire-portable-dockerfile)

The entrypoint reads these when a container starts; the build arguments are read at `docker build`. Citadel never reads either. See [The portable image](https://squire.apps.wayne.edu/docs/guides/portable-image.html).

| Variable | Default | Meaning |
| --- | --- | --- |
| `SQUIRE_ROLE` | `web` | `web`, `worker`, `scheduler` or `migrate`. A first argument naming a role wins. |
| `SQUIRE_PORT` | `PORT`, else `8080` | The port Apache listens on and the health check asks. Below 1024 is refused for a user that is not root. |
| `PORT` | not set | Read when `SQUIRE_PORT` is not set (the name several platforms set). |
| `SQUIRE_WORKER_COMMAND` | `php artisan queue:work --tries=3 --max-time=3600 --sleep=3` | The worker's command, split on spaces. Required for a raw PHP app. |
| `SQUIRE_SCHEDULER_COMMAND` | `php artisan schedule:work` | The scheduler's command. Required for a raw PHP app. |
| `SQUIRE_MIGRATE_COMMAND` | `php artisan migrate --force` | The migrate role's command; a raw PHP app without one has nothing to migrate. |
| `SQUIRE_ARTISAN_OPTIMIZE` | `1` | Laravel: `php artisan optimize` when a `web`, `worker` or `scheduler` container starts. |
| `SQUIRE_SECRETS_DIR` | `/run/secrets` | The same secrets loader as the release script, before any role starts. |
| `SQUIRE_KIT_DIR` (build argument) | `.` | Where the kit sits in the build context (`deploy` for WELD). |
| `SQUIRE_WRITABLE_DIRS` (build argument) | Laravel: `storage bootstrap/cache` | Folders handed to group 0 and made group-writable, separated by spaces. |
| `SQUIRE_OPCACHE_VALIDATE` (build argument) | `0` | Baked code: opcache never checks files. A run-time `1` still wins. |
| `SQUIRE_KEEP_NODE_MODULES` (build argument) | `0` | `1` keeps `node_modules/` in the image after the frontend build. By default it is removed in the same step, because no role runs Node. |

## PHP in the image (`.squire/php.ini`) [#](https://squire.apps.wayne.edu/docs/reference/configuration.html#php-in-the-image-squire-php-ini)

PHP reads these from the container's environment when it starts (PHP 8.3 and later). The parity compose file passes `SQUIRE_OPCACHE_VALIDATE` and `SQUIRE_PHP_DISABLE_FUNCTIONS` from `.env.squire`; on Citadel the platform's own `php.ini` decides. See [Image hardening](https://squire.apps.wayne.edu/docs/guides/image-hardening.html).

| Variable | Default | Meaning |
| --- | --- | --- |
| `SQUIRE_OPCACHE_VALIDATE` | `1` | How code arrives. `1`: pulled into the running container (Citadel, a bind mount, or unknown), so opcache checks files every 2 seconds. `0`: baked into an image that never changes, so it never checks. Also a build argument of `.squire/Dockerfile` (default `1`); the run-time value wins. Never empty: that turns the checks off. |
| `SQUIRE_OPCACHE_MEMORY` | `128` | opcache memory, in megabytes. |
| `SQUIRE_OPCACHE_MAX_FILES` | `20000` | How many scripts opcache keeps. |
| `SQUIRE_PHP_DISABLE_FUNCTIONS` | `exec,passthru,shell_exec,system,pcntl_exec,phpinfo,show_source,highlight_file` | The web's `disable_functions` list (`.squire/php-web.ini`; the CLI disables nothing). It replaces the list; `none` disables nothing. Leave it unset for the default: an empty value disables nothing too. |

## Local parity (`.env.squire`) [#](https://squire.apps.wayne.edu/docs/reference/configuration.html#local-parity-env-squire)

| Variable | Default | Meaning |
| --- | --- | --- |
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
| `SQUIRE_PORTABLE_HTTP_PORT` | `8081` | The `portable` profile: where the web container is published on loopback. |
| `SQUIRE_PORTABLE_USER` | `10001:0` | The `portable` profile: the UID and group the containers run as (try `1000123:0`). |
| `SQUIRE_PORTABLE_ENV_FILE` | `.env` | The `portable` profile: the app's env file, read at run time and never baked in. |
| `SQUIRE_PORTABLE_CONTEXT` | `.` | The `portable` profile: the build context, relative to the compose file (`..` for WELD). |
| `SQUIRE_KIT_DIR` | `.` | The `portable` profile: the kit's folder inside that context (`deploy` for WELD). |

The Vue layer's compose file also reads `SQUIRE_NODE_IMAGE` (default `node:22.23.4-bookworm-slim`, pinned by digest), `SQUIRE_NODE_ENV` (default `production`) and `SQUIRE_NODE_MEMORY` (default `512m`). The compose file refuses to start without the two passwords. See `.env.squire.example`; it holds placeholders only.

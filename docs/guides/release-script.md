---
title: "The release script"
description: "What scripts/release.sh does step by step on Citadel: preconditions, composer install from the lock file, audit, PROCTOR's deploy gate, frontend build, Laravel migrate, optimize and the restart of the long-lived processes, the Oracle check, and the /up smoke test."
---

# The release script

This page walks through `scripts/release.sh`, the one common script every app gets, in the order its steps run. It is the artifact that lands on Citadel; read [Citadel setup](citadel-setup.md) first if you have not wired the dashboard yet.

## What it is

`scripts/release.sh` is the idempotent release: preconditions, `composer install --no-dev` from the lock file, audit, PROCTOR's deploy gate (a report by default, a stop with `SQUIRE_PROCTOR=enforce`), frontend build, Laravel `migrate --force`, `optimize` and the restart of the long-lived processes, an Oracle connection check (a report, never a gate), and a `/up` smoke test. It runs from the first-release hook and from the Quick Update post-script (user `citadel`, script `./scripts/release.sh`).

Its neighbors in the kit:

| File | What it is |
|---|---|
| `build.sh` | Citadel's build hook: system packages only, POSIX sh, runs as root with no app source. No Composer, no npm. |
| `deployments/001-first-release.sh` | Runs once per app: calls the release script with the smoke test off (Apache is not up yet). |
| `health.php` | Citadel's health check (`["php","health.php"]`): one HTTP request to `/up`, never boots the framework. |
| `oracle-check.php` | The plain-language Oracle connection check the release runs: settings, host, port, driver, login. A report, never a gate; silent when the app has no `ORACLE_*` settings. |

Every shell script uses `set -euo pipefail` (`build.sh` uses `set -eu`, because Citadel runs it with `/bin/sh`), prints a `[squire] ==> step N: ...` marker for each step, and can be run twice with the same result.

## Idempotence and step markers

Safe to run twice: every step repeats with the same result (S10). Each step prints a marker such as `[squire] ==> step 4: composer install from the lock file, without dev packages`, and a failure names the step: `[squire] FAILED at step N (name): reason` with the exit code and line number. A release script that is not idempotent is just a rehearsal that happens to ship.

## Step by step

1. **check preconditions**: refuses to run as root unless `SQUIRE_ALLOW_ROOT=1` (files it creates could not be written by the web process); requires `composer.json` and `composer.lock` in the app root (the lock file is the release, S13); requires `php` and `composer` on PATH; resolves the framework (`auto` detects Laravel from `artisan`, otherwise `raw`); requires `.env` when `SQUIRE_REQUIRE_ENV` resolves to `1` (Laravel always does); picks the smoke URL (`http://127.0.0.1:80/up` for Laravel, `http://127.0.0.1:80/up.php` for raw PHP).
2. **create writable directories**: creates the `SQUIRE_WRITABLE_DIRS` entries, or `storage bootstrap/cache` for Laravel by default.
3. **package download caches**: skipped when `SQUIRE_CACHE_DIR=off`, which leaves Composer's and npm's own defaults alone. Otherwise it creates `$SQUIRE_CACHE_DIR/composer` and `$SQUIRE_CACHE_DIR/npm` (the default `.cache/squire` is relative to the app root, an absolute path is used as is), writes a `.gitignore` of `*` and an `.htaccess` of `Require all denied` into the folder when they are missing (so git ignores the cache without touching the app's own `.gitignore`, and Apache never serves it), and points `COMPOSER_CACHE_DIR` and `npm_config_cache` at the two folders unless the environment already sets them (a value the host set wins). The app folder survives a Citadel Update, the container home does not, so the next release installs from what this one downloaded. It notes both cache paths in the log.
4. **composer install from the lock file, without dev packages**: logs the Composer version (it moves at every container start, S58), then `composer install --no-dev --optimize-autoloader --no-interaction --no-progress --prefer-dist`.
5. **composer audit**: `composer audit --locked --no-interaction`. By default (`SQUIRE_COMPOSER_AUDIT=report`) a non-zero exit prints a warning and the release goes on; `fail` stops it, `skip` skips it.
6. **PROCTOR gate**: see below. It runs before the frontend build and the migrations, so an enforced no-go stops the release before it touches the database.
7. **frontend install and build**: skipped without a `package.json` or with `SQUIRE_NPM=skip`. With `package-lock.json` it runs `npm ci --prefer-offline` (with `--ignore-scripts` when `SQUIRE_NPM_IGNORE_SCRIPTS=1`); with `pnpm-lock.yaml` it runs `corepack pnpm install --frozen-lockfile`; with neither lock file it fails, telling you to commit one or set `SQUIRE_NPM=skip` if the built assets are committed. The packages named in `SQUIRE_NPM_REBUILD` then run their own install scripts, and nothing else does. With npm older than 11.10 and a `min-release-age` line in `.npmrc`, it notes that this npm ignores the line (the lock file still decides what installs). It then runs the `build` script (or `SQUIRE_NPM_BUILD_SCRIPT`) when `package.json` defines it.
8. **laravel migrate** (Laravel only): `php artisan migrate --force` when `SQUIRE_MIGRATE=1`.
9. **laravel optimize** (Laravel only): `php artisan optimize` when `SQUIRE_ARTISAN_OPTIMIZE=1`. After this the config is cached and `.env` is no longer read, so `env()` works only inside `config/` (S16).
10. **laravel restart long-lived processes** (Laravel only): skipped when `SQUIRE_ARTISAN_RESTART=0`. When `php artisan reload --help` succeeds it runs `php artisan reload`; otherwise it runs `php artisan queue:restart` and `php artisan schedule:interrupt`, each only when its own `--help` succeeds, and notes the skip when neither exists. A failing command is a warning naming the command and its exit code, and the release goes on. A worker that keeps running holds the old code in memory until it restarts, so the step sits after the caches are built and before the smoke test.
11. **check Oracle (never fails the release)**: runs `php oracle-check.php` when the file exists, and notes a skip when it does not (the app does not use Oracle).
12. **check opcache (never fails the release)**: reports whether the web server sees changed files by itself. With the kit's default (`SQUIRE_OPCACHE_VALIDATE=1`, files checked every 2 seconds) it is a note. With `0`, meant for an image that bakes its code in, Apache keeps serving the PHP it compiled before this release until it restarts, so after a release with Apache running this is a warning naming `SQUIRE_OPCACHE_VALIDATE=1`; in the first-release hook, before Apache starts, it is a note. See [Image hardening](image-hardening.md).
13. **smoke test the health endpoint**: skipped when `SQUIRE_SMOKE=0` (Apache is not running inside the first-release hook). Otherwise it requests `SQUIRE_SMOKE_URL` up to `SQUIRE_SMOKE_RETRIES` attempts (default 5), sleeping `SQUIRE_SMOKE_DELAY` seconds (default 2) between them, and fails unless the answer is HTTP 200.

## PROCTOR: the deploy gate

The release asks PROCTOR's deploy gate, `proctor gate`, for a go or no-go on this commit. The gate runs the deploy-time checks (the PHP floor and runtime, the Laravel line and its PHP range, the lock file, known advisories and the overrides) and ratchets them against a baseline: a failure the running release already had is held, and only a new failure, a first release that fails, or an expired override blocks. The squire holds the gate; the proctor says who may pass.

- **Report or stop.** By default (`SQUIRE_PROCTOR=auto`) a no-go is a warning and the release goes on: a failing post-script cannot undo a Quick Update, because the new commit is already live (S20). With `SQUIRE_PROCTOR=enforce` a no-go stops the release before the frontend build and the migrations: the first release never comes up healthy, and a Quick Update keeps the new commit checked out, so fix the BLOCK lines (or record a dated override in `policy-overrides.json`) and re-run. A gate that could not decide (exit 2) is a warning under `auto` and a stop under `enforce`. `SQUIRE_PROCTOR=skip` skips the step.
- **The record.** Every gated release writes its verdict to `storage/squire/proctor-gate.json` (`SQUIRE_PROCTOR_RECORD_DIR`). When the release goes on (a go, or a reported no-go, which goes live anyway) that verdict is copied to `proctor-gate-baseline.json`, and the next release passes it to the gate as `--baseline`. A stopped release leaves the baseline alone, so re-running after a stop is judged against the release that is really live. The folder gets a `.gitignore` of `*`. The first gated release has no baseline, so every failure blocks it.
- **An older PROCTOR.** When the installed PROCTOR has no `gate` command, the step prints a warning naming its version, runs `proctor check` as before (a failed check is a report, or a stop under `enforce`), and writes no record. Update `waynestate/proctor` to a release that has the gate.

- The binary is `vendor/bin/proctor`, then `proctor` on PATH, or `SQUIRE_PROCTOR_BIN`. It is a dev dependency, so `--no-dev` removes it: point `SQUIRE_PROCTOR_BIN` at a copy.
- The policy is `SQUIRE_PROCTOR_POLICY` when set; otherwise the policy the CREED package ships (`vendor/waynestate/creed/policy/policy.json`, when installed); otherwise PROCTOR runs on its own default policy with a notice. An explicit value always wins.

## The Oracle check

The release also runs an Oracle connection check (`oracle-check.php`) after the migrations and before the smoke test. It is a report, never a gate: a finding prints in the deployment log and the release goes on. When the app does not use Oracle (no `ORACLE_*` settings) it prints nothing. It checks, in order, that the settings are present, that the host resolves, that the port answers within 3 seconds, that the `oci8` driver is loaded, and that the login works, stopping at the first failure so a bad port never costs a login attempt. Settings: `ORACLE_USERNAME`, `ORACLE_PASSWORD`, and either `ORACLE_TNS` (Easy Connect `host:port/service`, or a full `(DESCRIPTION=...)` descriptor) or `ORACLE_HOST`, `ORACLE_PORT` and `ORACLE_SERVICE_NAME`. Only setting names are ever printed, never values.

## Configuration

Before the first step the script reads two sources: `./.env.squire` (the release options) and the secrets folder, `SQUIRE_SECRETS_DIR` (default `/run/secrets`), where each file named like a variable becomes that variable unless the environment already sets it. Only names are printed. See [Image hardening](image-hardening.md#secrets-files-at-run-time-never-in-a-layer).

Configuration is environment variables, all optional, or the same names in `./.env.squire` in the app root. On Citadel add that file as a configuration file (the configuration-files step after the wizard; the wizard has no environment field) and keep it out of git. A variable already in the environment wins over the file. See `.env.squire.example`.

> [!WARNING]
> PROCTOR is a dev dependency and the release installs with `--no-dev`, so `vendor/bin/proctor` is gone by the time the PROCTOR step runs. Set `SQUIRE_PROCTOR_BIN` to a copy that survives, or the step reports itself missing.

## Next

Read [Citadel setup](citadel-setup.md) for the dashboard steps that make this script run, or [Local parity](local-parity.md) to watch the same pipeline on your machine.

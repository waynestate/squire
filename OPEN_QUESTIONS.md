# Open questions

Each entry says what is unknown, the default 0.1 chose, and what would change it.

## Questions

1. **`build.sh` shell.** Citadel runs `build.sh` with `/bin/sh` (dash), which has no `pipefail`. Default: POSIX sh with
   `set -eu` and a guarded `set -o pipefail` where the shell supports it. The brief asked for `pipefail`; this is the
   closest that works. Changes if Citadel starts running the script with bash.
2. **How does `vendor/` refresh on a full Update?** Deployment hooks run once per app lifetime, so a changed
   `composer.lock` on a full Update has no install step. Default: tell teams to also use `scripts/release.sh` as the Quick
    Update post-script. Needs a Citadel maintainer's answer.
3. **Is the public directory `src/public`?** Default for local parity: `SQUIRE_PUBLIC_DIR=src/public`. The dashboard passes
   the real value; check it matches.
4. **Can a secret be mounted?** SQUIRE assumes environment variables from the dashboard only. Default: the scripts read
   `SQUIRE_*` from the environment; nothing is read from a file in the repository.
5. **PowerShell minimum.** The `.ps1` scripts are written for Windows PowerShell 5.1 and PowerShell 7; the lint (PSScriptAnalyzer)
   runs on 7.4 only. 5.1 behaviour is by inspection, not run.
6. **PROCTOR is a dev dependency.** `composer install --no-dev` removes it, so the release step needs
   `SQUIRE_PROCTOR_BIN` and `SQUIRE_PROCTOR_POLICY`. Default: the step skips with a stated reason when either is missing.
   Changes when PROCTOR ships a phar.
7. **Local proxy version.** Locally HA-Proxy 3.0; Citadel's template targets 1.5. Both add `X-Forwarded-For` and
   `X-Forwarded-Proto`; other 1.5 behaviours (connection reuse, header casing) may differ.
8. **`cap_drop: [ALL]` on the real image.** Verified on the fallback image only (the app starts and answers `/up.php`).
   Apache needs `CHOWN`, `SETUID`, `SETGID`, `DAC_OVERRIDE` and `NET_BIND_SERVICE` on some images; the compose file adds back
   what the fallback needed. Re-check on the real image.
9. **`php.ini` is safe defaults, not a mirror.** `.squire/php.ini` holds the safe defaults HELMET also enforces at
   runtime, not a copy of the platform's file, so the local app runs with hardened PHP settings. For exact parity with
   the platform, replace it with a copy of the platform's file.
10. **Docker Hub digests.** The brief says to pin by digest where one can be found in the Citadel clone. The clone has none
    for any image it uses (Citadel builds images locally). Default: pin HA-Proxy and MySQL to the digests observed on Docker
    Hub on 2026-10-28 (`haproxy:3.0-alpine`, `mysql:5.6.51`) and leave the Citadel image unpinned (below). A deviation from
    the brief; revert to placeholders if you prefer.
11. **Executable bit from Windows.** Git on Windows does not keep it. Default: documented `git update-index --chmod=+x`.
    Citadel also runs `chmod -R ug+x` on the deployments path and the quick-update and health scripts.
12. **Apple Silicon.** The real Citadel images and MySQL 5.6 are amd64; Docker emulates them and builds can take 30 minutes
    or more. The scripts print a warning; nothing else is done.
13. **No Node version check.** `release.sh` uses whatever `node` and `npm` the image has. Citadel's images ship a Node LTS
    from NodeSource; the version is not asserted.
14. **Audit default.** `SQUIRE_COMPOSER_AUDIT=report`. A finding does not fail a release by default, because a Quick Update
    cannot roll back and an advisory published today should not take down a site that deployed fine yesterday. `fail` is
    available.
15. **Bind-mount permissions locally.** On the first end-to-end run, the release hook failed with `Permission denied` writing
    `vendor/composer/installed.json` because the source folder (created on the host by another tool, owned by root) was not
    writable by the container's `citadel` user. After making it writable the hook ran, the app became healthy and the proxy
    answered. Default: documented in the README; `SQUIRE_SOURCE_UID` and `SQUIRE_SOURCE_GID` match the user on Linux. Not
    solved for Docker Desktop folders that are root-owned.
16. **Which Citadel health check value.** The dashboard wizard value is assumed to be `["php","health.php"]`. Confirm the
    exact form.

## Missing configuration

- **Citadel app image digest.** `SQUIRE_BASE_IMAGE=citadel/php-apache:8.4r5` is unpinned (placeholder). Citadel builds the
  image locally from `build-images/*.dockerfile`; there is no registry digest to copy. `doctor` warns (S67) until one is
  set. After building: `scripts/parity.sh pin`.
- **PROCTOR policy file.** `SQUIRE_PROCTOR_POLICY` has no default. Without it the PROCTOR step is skipped.
- **PROCTOR phar and `gate` command.** PROCTOR 0.1 has only `check`; a deploy gate needs both.
- **PREFLIGHT tag pin.** The GitHub workflow pins PREFLIGHT to a commit; the GitLab file pins the component to `0.1.0`.
  Update both when PREFLIGHT cuts a release tag on GitHub.
- **Database passwords.** `.env.squire.example` holds `change-me-local-only` placeholders. The compose file refuses to start
  without real values.
- **The platform's actual PHP settings.** `.squire/php.ini` holds safe defaults, not the platform's file. See question 9.

## Not built yet

- A deploy gate that calls PROCTOR, and queue workers.
- Checks for rules that need a running app or a server (most of the list of S-rules beyond the 26 in `BEST_PRACTICES.md`).
- A GitLab or GitHub release job; 0.1.0 is tagged and on Packagist; a release job is not built.
- Templates for apps that are neither raw PHP nor Laravel.
- Running the PowerShell scripts on Windows PowerShell 5.1 itself (see question 5) and on macOS.

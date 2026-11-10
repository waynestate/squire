---
title: "Troubleshooting"
description: "Symptoms, causes and fixes for the Squire setup, from permission errors to doctor exit codes."
---

# Troubleshooting

Symptoms you may meet setting up a Squire app, each with its cause, a fix and a check command. Only what the README and the open questions ground is listed here. Next, see [Doctor](guides/doctor.md) for the full rule list and [Local parity](guides/local-parity.md) for the parity setup. The `scripts/parity.sh` symptoms below belong to Squire's deprecated parity kit; [Parity](https://git.wayne.edu/enterprise-apps/parity), which replaces it, has its own `parity check`.

## Symptoms

### The first release stops at step 1: composer.json not found

Cause: `scripts/release.sh` installs from the lock file, so it requires `composer.json` and `composer.lock` in the app root and stops at step 1 (check preconditions) without them. A raw PHP app that never used Composer has neither. `squire init` prints a "Heads up" line when it writes the release into such an app.

Fix: give the app the two files, even with no dependencies, and commit both: `composer init` writes `composer.json`, then `composer update` writes `composer.lock`.

Check:

<!-- docs-test: skip needs a real project -->
```sh
ls composer.json composer.lock
vendor/bin/squire init --check
```

### Permission error writing vendor/

Cause: on the first end-to-end run, the release hook failed with `Permission denied` writing `vendor/composer/installed.json` because the source folder (created on the host by another tool, owned by root) was not writable by the container's `citadel` user (OPEN_QUESTIONS 15). If the build step fails with a permission error writing `vendor/`, the source folder is not writable by the container's `citadel` user (uid 1000 by default).

Fix: on Linux set `SQUIRE_SOURCE_UID` and `SQUIRE_SOURCE_GID` to yours. After making it writable the hook ran, the app became healthy and the proxy answered. Not solved for Docker Desktop folders that are root-owned.

Check:

<!-- docs-test: skip needs a real project -->
```sh
ls -ld <your-source-folder>
scripts/parity.sh status
```

### uid or gid mismatch between host and container

Cause: the parity container runs as the `citadel` user, uid 1000 by default. When your host user does not match, the mounted source is not writable and the release hook fails with `Permission denied` (OPEN_QUESTIONS 15).

Fix: set `SQUIRE_SOURCE_UID` and `SQUIRE_SOURCE_GID` in `.env.squire` to your host uid and gid, then bring the stack back up.

Check:

<!-- docs-test: skip needs a real project -->
```sh
id -u
id -g
scripts/parity.sh status
```

### init refuses to overwrite a file

Cause: `init` never overwrites a file you edited, or a file it did not write, unless you pass `--force`. An app-owned `public/index.php` or `public/.htaccess` is reported as `app-owned`, never recorded or overwritten (not even with `--force`).

Fix: delete your copy and run `init` to take Squire's, or pass `--force` for a file Squire wrote.

Check:

<!-- docs-test: skip needs a real project -->
```sh
vendor/bin/squire init --check
```

### doctor exit codes

Cause: `doctor` ends with `0` pass, `1` findings (a failed rule), `2` the tool itself could not run.

Fix: for `1`, read the findings and fix the failed rules. For `2`, the tool could not run at all, so check your PHP setup before anything else.

Check:

<!-- docs-test: skip needs a real project -->
```sh
vendor/bin/squire doctor --format=json
echo $?
```

### Bind mounts on Windows

Cause: bind mounts from the Windows side are much slower (Composer and npm installs feel it most), and file ownership and permissions do not map cleanly; that is the cause of the `Permission denied` failure described under the bind-mount question (OPEN_QUESTIONS 15).

Fix: keep the app's source tree inside the WSL 2 Linux filesystem (for example under `~/src` in your WSL distribution) and run `scripts/parity.sh` from there, not from a Windows folder such as `C:\Users\you\...` reached through `/mnt/c`. Advice, not a requirement. The PowerShell `scripts\parity.ps1` still works from a Windows folder, only slower.

Check:

<!-- docs-test: skip needs a real project -->
```sh
scripts/parity.sh status
```

### No access to the real image

Cause: the real Citadel image is internal and has no pullable digest to pin (OPEN_QUESTIONS 19 and the missing configuration list).

Fix: `--fallback` builds an approximation from the official `php` image plus Citadel's extensions. It is good for trying Squire out and not for proving parity.

Check:

<!-- docs-test: skip needs a real project -->
```sh
scripts/parity.sh up --fallback
```

### Scripts lose their executable bit on Windows

Cause: Git on Windows does not keep the executable bit (OPEN_QUESTIONS 11).

Fix: mark the scripts executable in git.

<!-- docs-test: skip needs a real project -->
```sh
git update-index --chmod=+x build.sh scripts/*.sh deployments/*.sh
```

Check:

<!-- docs-test: skip needs a real project -->
```sh
git ls-files -s build.sh scripts/*.sh deployments/*.sh
```

### Parity never turns healthy: vendor/autoload.php is missing

Cause: another app on this machine ran parity under the same `SQUIRE_PROJECT` (both left on the default `squire-app`). One name is one compose project, so the two apps share the database and the logs volume, and the logs volume holds the record of which `deployments/` files already ran. Citadel runs each file once, so this app's first release is skipped as already run, and the health check reports `vendor/autoload.php is missing; the release did not finish`. Since 2026-11-10 `parity up` warns when the name is the default.

Fix: give the app its own name in `.env.squire` (for example `SQUIRE_PROJECT=my-app`), then start it again. Nothing of the other app's is touched; its stack stays under the old name.

<!-- docs-test: skip needs a real project -->
```sh
scripts/parity.sh down
scripts/parity.sh up
```

Check:

<!-- docs-test: skip needs a real project -->
```sh
scripts/parity.sh status
```

### Parity compose refuses to start

Cause: `.env.squire.example` holds `change-me-local-only` placeholders, and the compose file refuses to start without the two passwords.

Fix: copy the example to `.env.squire` and set the passwords.

<!-- docs-test: skip needs a real project -->
```sh
cp .env.squire.example .env.squire
```

Check:

<!-- docs-test: skip needs a real project -->
```sh
scripts/parity.sh status
```

### A full Update does not refresh vendor/

Cause: deployment hooks run once per app lifetime, so a changed `composer.lock` on a full Update has no install step (OPEN_QUESTIONS 2).

Fix: tell teams to also use `scripts/release.sh` as the Quick Update post-script. Needs a Citadel maintainer's answer.

Check:

<!-- docs-test: skip needs a real project -->
```sh
vendor/bin/squire doctor
```

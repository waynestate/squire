---
title: "Troubleshooting | Squire docs"
description: "Symptoms, causes and fixes for the Squire setup, from permission errors to doctor exit codes."
url: https://squire.apps.wayne.edu/docs/troubleshooting.html
llms: https://squire.apps.wayne.edu/llms.txt
generator: Squire agent-ready
---

# Troubleshooting[#](https://squire.apps.wayne.edu/docs/troubleshooting.html#troubleshooting)

Symptoms you may meet setting up a Squire app, each with its cause, a fix and a check command. Only what the README and the open questions ground is listed here. Next, see [Doctor](https://squire.apps.wayne.edu/docs/guides/doctor.html) for the full rule list and [Local parity](https://squire.apps.wayne.edu/docs/guides/local-parity.html) for the parity setup.

## Symptoms[#](https://squire.apps.wayne.edu/docs/troubleshooting.html#symptoms)

### Permission error writing vendor/[#](https://squire.apps.wayne.edu/docs/troubleshooting.html#permission-error-writing-vendor)

Cause: on the first end-to-end run, the release hook failed with `Permission denied` writing `vendor/composer/installed.json` because the source folder (created on the host by another tool, owned by root) was not writable by the container's `citadel` user (OPEN_QUESTIONS 15). If the build step fails with a permission error writing `vendor/`, the source folder is not writable by the container's `citadel` user (uid 1000 by default).

Fix: on Linux set `SQUIRE_SOURCE_UID` and `SQUIRE_SOURCE_GID` to yours. After making it writable the hook ran, the app became healthy and the proxy answered. Not solved for Docker Desktop folders that are root-owned.

Check:

```sh
ls -ld <your-source-folder>
scripts/parity.sh status
```

### uid or gid mismatch between host and container[#](https://squire.apps.wayne.edu/docs/troubleshooting.html#uid-or-gid-mismatch-between-host-and-container)

Cause: the parity container runs as the `citadel` user, uid 1000 by default. When your host user does not match, the mounted source is not writable and the release hook fails with `Permission denied` (OPEN_QUESTIONS 15).

Fix: set `SQUIRE_SOURCE_UID` and `SQUIRE_SOURCE_GID` in `.env.squire` to your host uid and gid, then bring the stack back up.

Check:

```sh
id -u
id -g
scripts/parity.sh status
```

### init refuses to overwrite a file[#](https://squire.apps.wayne.edu/docs/troubleshooting.html#init-refuses-to-overwrite-a-file)

Cause: `init` never overwrites a file you edited, or a file it did not write, unless you pass `--force`. An app-owned `public/index.php` or `public/.htaccess` is reported as `app-owned`, never recorded or overwritten (not even with `--force`).

Fix: delete your copy and run `init` to take Squire's, or pass `--force` for a file Squire wrote.

Check:

```sh
vendor/bin/squire init --check
```

### doctor exit codes[#](https://squire.apps.wayne.edu/docs/troubleshooting.html#doctor-exit-codes)

Cause: `doctor` ends with `0` pass, `1` findings (a failed rule), `2` the tool itself could not run.

Fix: for `1`, read the findings and fix the failed rules. For `2`, the tool could not run at all, so check your PHP setup before anything else.

Check:

```sh
vendor/bin/squire doctor --format=json
echo $?
```

### Bind mounts on Windows[#](https://squire.apps.wayne.edu/docs/troubleshooting.html#bind-mounts-on-windows)

Cause: bind mounts from the Windows side are much slower (Composer and npm installs feel it most), and file ownership and permissions do not map cleanly; that is the cause of the `Permission denied` failure described under the bind-mount question (OPEN_QUESTIONS 15).

Fix: keep the app's source tree inside the WSL 2 Linux filesystem (for example under `~/src` in your WSL distribution) and run `scripts/parity.sh` from there, not from a Windows folder such as `C:\Users\you\...` reached through `/mnt/c`. Advice, not a requirement. The PowerShell `scripts\parity.ps1` still works from a Windows folder, only slower.

Check:

```sh
scripts/parity.sh status
```

### No access to the real image[#](https://squire.apps.wayne.edu/docs/troubleshooting.html#no-access-to-the-real-image)

Cause: the real Citadel image is internal and has no pullable digest to pin (OPEN_QUESTIONS 19 and the missing configuration list).

Fix: `--fallback` builds an approximation from the official `php` image plus Citadel's extensions. It is good for trying Squire out and not for proving parity.

Check:

```sh
scripts/parity.sh up --fallback
```

### Scripts lose their executable bit on Windows[#](https://squire.apps.wayne.edu/docs/troubleshooting.html#scripts-lose-their-executable-bit-on-windows)

Cause: Git on Windows does not keep the executable bit (OPEN_QUESTIONS 11).

Fix: mark the scripts executable in git.

```sh
git update-index --chmod=+x build.sh scripts/*.sh deployments/*.sh
```

Check:

```sh
git ls-files -s build.sh scripts/*.sh deployments/*.sh
```

### Parity compose refuses to start[#](https://squire.apps.wayne.edu/docs/troubleshooting.html#parity-compose-refuses-to-start)

Cause: `.env.squire.example` holds `change-me-local-only` placeholders, and the compose file refuses to start without the two passwords.

Fix: copy the example to `.env.squire` and set the passwords.

```sh
cp .env.squire.example .env.squire
```

Check:

```sh
scripts/parity.sh status
```

### A full Update does not refresh vendor/[#](https://squire.apps.wayne.edu/docs/troubleshooting.html#a-full-update-does-not-refresh-vendor)

Cause: deployment hooks run once per app lifetime, so a changed `composer.lock` on a full Update has no install step (OPEN_QUESTIONS 2).

Fix: tell teams to also use `scripts/release.sh` as the Quick Update post-script. Needs a Citadel maintainer's answer.

Check:

```sh
vendor/bin/squire doctor
```

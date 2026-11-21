---
title: "Troubleshooting | Squire docs"
description: "Symptoms, causes and fixes for the Squire setup, from permission errors to doctor exit codes."
url: https://squire.apps.wayne.edu/docs/troubleshooting.html
llms: https://squire.apps.wayne.edu/llms.txt
generator: Squire agent-ready
---

# Troubleshooting [#](https://squire.apps.wayne.edu/docs/troubleshooting.html#troubleshooting)

Symptoms you may meet setting up a Squire app, each with its cause, a fix and a check command. Only what the README and the open questions ground is listed here. Next, see [Doctor](https://squire.apps.wayne.edu/docs/guides/doctor.html) for the full rule list Running the app on your computer is [Parity](https://git.wayne.edu/enterprise-apps/parity)'s job, and `parity check` covers its own symptoms; the parity kit's old ones left with it in 0.12.0 ([Moved to Parity](https://squire.apps.wayne.edu/docs/guides/local-parity.html)).

## Symptoms [#](https://squire.apps.wayne.edu/docs/troubleshooting.html#symptoms)

### The first release stops at step 1: composer.json not found [#](https://squire.apps.wayne.edu/docs/troubleshooting.html#the-first-release-stops-at-step-1-composer-json-not-found)

Cause: `scripts/release.sh` installs from the lock file, so it requires `composer.json` and `composer.lock` in the app root and stops at step 1 (check preconditions) without them. A raw PHP app that never used Composer has neither. `squire init` prints a "Heads up" line when it writes the release into such an app.

Fix: give the app the two files, even with no dependencies, and commit both: `composer init` writes `composer.json`, then `composer update` writes `composer.lock`.

Check:

```sh
ls composer.json composer.lock
vendor/bin/squire init --check
```

### init refuses to overwrite a file [#](https://squire.apps.wayne.edu/docs/troubleshooting.html#init-refuses-to-overwrite-a-file)

Cause: `init` never overwrites a file you edited, or a file it did not write, unless you pass `--force`. An app-owned `public/index.php` or `public/.htaccess` is reported as `app-owned`, never recorded or overwritten (not even with `--force`).

Fix: delete your copy and run `init` to take Squire's, or pass `--force` for a file Squire wrote.

Check:

```sh
vendor/bin/squire init --check
```

### doctor exit codes [#](https://squire.apps.wayne.edu/docs/troubleshooting.html#doctor-exit-codes)

Cause: `doctor` ends with `0` pass, `1` findings (a failed rule), `2` the tool itself could not run.

Fix: for `1`, read the findings and fix the failed rules. For `2`, the tool could not run at all, so check your PHP setup before anything else.

Check:

```sh
vendor/bin/squire doctor --format=json
echo $?
```

### squire update lists kit-leftover files [#](https://squire.apps.wayne.edu/docs/troubleshooting.html#squire-update-lists-kit-leftover-files)

Cause: the app was set up with Squire's parity kit, which left Squire in 0.12.0. Its `.squire.json` still names the kit's files (`docker-compose.squire.yml`, `.squire/haproxy.cfg`, `scripts/parity.sh`, `scripts/parity.ps1`, `.env.squire.example`). Squire reports each one as `kit-leftover`, drops it from the record, and never deletes it.

Fix: move the app to [Parity](https://git.wayne.edu/enterprise-apps/parity) (`parity init --from-squire`, then `parity up`), then delete the files and commit ([Moved to Parity](https://squire.apps.wayne.edu/docs/guides/local-parity.html)).

Check:

```sh
vendor/bin/squire doctor    # S35 passes once no kit file is left
```

### --only=parity exits 2 [#](https://squire.apps.wayne.edu/docs/troubleshooting.html#only-parity-exits-2)

Cause: the `parity` group left Squire in 0.12.0. A script or CI job still asks for it.

Fix: ask for `--only=release,images` when you want the image files (the Citadel mirror, the fallback base, the portable image), or plain `--only=release`. To run the app on your computer, use Parity.

Check:

```sh
vendor/bin/squire init --check --only=release,images
```

### No access to the real image [#](https://squire.apps.wayne.edu/docs/troubleshooting.html#no-access-to-the-real-image)

Cause: the real Citadel image is internal and has no pullable digest to pin (OPEN_QUESTIONS 19 and the missing configuration list).

Fix: on your computer, `parity image get` loads Citadel's own image, checked byte for byte. Where Parity cannot reach it, the `images` group's fallback base (`.squire/base-fallback.Dockerfile`) builds an approximation from the official `php` image plus Citadel's extensions; it is good for trying an image build and not for proving parity.

Check:

```sh
docker build -f .squire/base-fallback.Dockerfile -t squire/php-parity-base:8.5r3 .squire
```

### Scripts lose their executable bit on Windows [#](https://squire.apps.wayne.edu/docs/troubleshooting.html#scripts-lose-their-executable-bit-on-windows)

Cause: Git on Windows does not keep the executable bit (OPEN_QUESTIONS 11).

Fix: mark the scripts executable in git.

```sh
git update-index --chmod=+x build.sh scripts/*.sh deployments/*.sh
```

Check:

```sh
git ls-files -s build.sh scripts/*.sh deployments/*.sh
```

### A full Update does not refresh vendor/ [#](https://squire.apps.wayne.edu/docs/troubleshooting.html#a-full-update-does-not-refresh-vendor)

Cause: deployment hooks run once per app lifetime, so a changed `composer.lock` on a full Update has no install step (OPEN_QUESTIONS 2).

Fix: tell teams to also use `scripts/release.sh` as the Quick Update post-script. Needs a Citadel maintainer's answer.

Check:

```sh
vendor/bin/squire doctor
```

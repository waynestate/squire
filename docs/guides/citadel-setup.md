---
title: "Citadel setup"
description: "The four steps to wire a new app into Squire's release pipeline on Citadel: dashboard settings, lock files, executable bits, and the ./.env and ./.env.squire configuration files."
---

# Citadel setup

This page lists the numbered steps that connect a new app to the scripts Squire wrote: what to set in the dashboard, what to commit, and where the configuration lives. Finish the steps below, then read [The release script](release-script.md) to see what runs on every deploy, or try [Local parity](local-parity.md) first.

## The steps

1. In the dashboard set the build script to `build.sh`, the health check to `["php","health.php"]`, and the Quick Update post-script to `./scripts/release.sh` (user `citadel`).
2. Commit `composer.lock`, and `package-lock.json` or `pnpm-lock.yaml` if you have a frontend.
3. Mark the scripts executable in git. On Windows: `git update-index --chmod=+x build.sh scripts/*.sh deployments/*.sh`.
4. Set `SQUIRE_*` values in the app's `./.env` configuration file (below), not in a file in the repository. The dashboard has no environment field; its configuration-files step, after the wizard, writes `./.env` into the source tree. A Laravel app reads `./.env` already; a raw PHP app loads it with `vlucas/phpdotenv` (`Dotenv\Dotenv::createUnsafeImmutable($root)->safeLoad()`), so `getenv()` sees the values and a real environment variable wins.

## Executable bits

Citadel runs the scripts as files, so git must record them as executable. Step 3 above does that with `git update-index --chmod=+x`; the release script itself starts with `set -euo pipefail` and expects to run as the `citadel` user from the Quick Update post-script.

## ./.env

`./.env` is the app's own configuration, written into the source tree by the dashboard's configuration-files step (after the wizard; the wizard has no environment field). A Laravel app reads it already; a raw PHP app loads it with `vlucas/phpdotenv` (`Dotenv\Dotenv::createUnsafeImmutable($root)->safeLoad()`), so `getenv()` sees the values and a real environment variable wins.

## ./.env.squire

`./.env.squire` is Squire's file: the release options for `scripts/release.sh`, and locally the parity settings. All of its values are optional. On Citadel, add `./.env.squire` as a configuration file (the configuration-files step after the wizard; the wizard has no environment field) and keep it out of git. `release.sh` reads the release options from it, and a variable already in the environment wins. Locally, the same file holds the parity settings. See `.env.squire.example` in the app; it holds placeholders only.

> [!NOTE]
> [!IMPORTANT]
> Secrets (a database password, `APP_KEY`, an Oracle login) go in `./.env`, never in the repository, `build.sh` or a Dockerfile: the configuration-files step writes `./.env` into the source tree when the app deploys, so it never lands in an image layer. `build.sh` runs as root while the image builds, with no app source, so it must never fetch a secret either. See [Image hardening](image-hardening.md#secrets-files-at-run-time-never-in-a-layer).

> Two files, two owners. `./.env` is yours and the app reads it; `./.env.squire` is Squire's and the release script and the parity kit read it. Neither belongs in git.

## Next

Read [The release script](release-script.md) for what `scripts/release.sh` does step by step, or [Local parity](local-parity.md) to run the same setup on your machine.

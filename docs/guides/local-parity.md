---
title: "Moved to Parity"
description: "Running Citadel on your computer moved from Squire's parity kit to Parity, the stack's own tool, in Squire 0.12.0: the four commands that move an app over, which kit files to delete afterward, and what Squire still does."
---

# Moved to Parity

Squire 0.12.0 removed its parity kit. To run your app on Citadel's own PHP image on your computer, behind Citadel's proxy, with MySQL 5.6 beside it, use [Parity](https://git.wayne.edu/enterprise-apps/parity). Squire still writes the files Citadel runs (`build.sh`, the release script, the health check) and checks them with `squire doctor`. Squire gets the app onto Citadel; Parity brings Citadel to your desk. The squire has hung up the traveling armor: it only dresses the knight for the castle now.

## Move an app over

Parity is one program, `parity`, for Windows, macOS and Linux (x64 and arm64). It runs on Docker or Podman (Podman is recommended) and needs no PHP or Composer on your computer. [Parity's README](https://git.wayne.edu/enterprise-apps/parity) has the install line for each OS. Then, in the app's folder:

<!-- docs-test: skip needs Parity and a container engine -->
```sh
parity check                 # what to fix on this computer, one finding per line
parity init --from-squire    # writes parity.json from the kit's files (it reads them, deletes none)
parity image get             # Citadel's image, checked against Citadel's, byte for byte
parity up                    # build, start, and print the address
```

`parity init --from-squire` reads `docker-compose.squire.yml`, `.env.squire.example` and `.env.squire`. It carries the kit's settings over (base image, public folder, ports, database name, time zone, deployments) and leaves passwords and every other value behind; Parity makes its own local passwords. Two differences to expect: Parity runs Citadel's own `php.ini`, not the kit's hardened one, and the kit's `scheduler` and `worker` services become one cron entry that runs Laravel's scheduler every minute, because Citadel has cron and no services.

An app with no kit (a fresh `squire init` writes none) starts with plain `parity init`.

## Then delete the kit files

Once `parity up` serves the app, delete what is left of the kit and commit:

<!-- docs-test: skip needs a real project -->
```sh
git rm docker-compose.squire.yml .squire/haproxy.cfg scripts/parity.sh scripts/parity.ps1 .env.squire.example
```

Delete only the ones you have; `squire update` lists them as `kit-leftover`, and `squire doctor` notes them under S35 until they are gone. Squire never deletes them for you. Keep `.env.squire` if it holds release options: `scripts/release.sh` reads it on Citadel.

The rest of `.squire/` is not the kit. The Citadel mirror (`.squire/Dockerfile`), the fallback base, the hardened `php.ini` and `php-web.ini`, and [the portable image](portable-image.md) are Squire's `images` group, opt-in with `squire init --only=release,images`. Keep them if you build those images.

## In CI

The same MySQL pin checks every merge: Preflight's [`mysql56-migrate` component](https://preflight.apps.wayne.edu/docs/guides/component-mysql56-migrate.html) runs the app's migrations against `mysql:5.6.51` on an amd64 runner, and PROCTOR's `mysql56-ci` check warns when an app with migrations has no such job. Parity catches a bad migration on your computer; the pipeline catches the one that slipped past.

## Next

[Upgrading](../upgrading.md#0-12-0-2026-11-20) has the whole 0.12.0 move, and [Citadel setup](citadel-setup.md) the dashboard side of the pipeline.

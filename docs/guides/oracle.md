---
title: "Oracle"
description: "How Squire checks an app's Oracle connection, and the opt-in Oracle-ready Docker images for local work."
---

# Oracle

This page covers the plain-language Oracle connection check that `release.sh` runs on Citadel and that `squire doctor oracle` runs from your terminal, and the two opt-in Oracle-ready Docker images Squire ships for local work. Next, read [Citadel setup](citadel-setup.md) to wire the release script into the dashboard, or [Local parity](local-parity.md) to run the app on the real images.

## The connection check

The release runs an Oracle connection check (`oracle-check.php`) after PROCTOR and before the smoke test. It is a report, never a gate: a finding prints in the deployment log and the release goes on. When the app does not use Oracle (no `ORACLE_*` settings) it prints nothing.

It checks, in order:

1. the settings are present,
2. the host resolves,
3. the port answers within 3 seconds,
4. the `oci8` driver is loaded,
5. the login works.

The check stops at the first failure, so a bad port never costs a login attempt. Only setting names are ever printed, never values.

Settings: `ORACLE_USERNAME`, `ORACLE_PASSWORD`, and either `ORACLE_TNS` (Easy Connect `host:port/service`, or a full `(DESCRIPTION=...)` descriptor) or `ORACLE_HOST`, `ORACLE_PORT` and `ORACLE_SERVICE_NAME`.

## `squire doctor oracle`

`vendor/bin/squire doctor oracle` runs the same plain-language Oracle connection check that `release.sh` runs on Citadel, from your terminal or `sail php vendor/bin/squire doctor oracle`. It reads the `ORACLE_*` settings from the environment and the app's `.env`, prints setting names and never values, and stays silent when the app has no Oracle settings. Add `-v` for the client version.

## Oracle-ready images (local)

Apps that talk to Oracle need the Instant Client and the `oci8` extension. Squire ships two opt-in Docker recipes that carry both, so nobody compiles them by hand:

- `squire/oracle-sail:<php>`: Laravel Sail's own runtime plus the Oracle install. In your `docker-compose.yml`, swap the service's `build:` block for `image: squire/oracle-sail:8.4` and keep the rest of the service as Sail wrote it; the image keeps Sail's entrypoint contract, including the `WWWUSER` UID remap.
- `squire/oracle-parity:<php>`: the official `php:<php>-cli-bookworm` image with the extensions Citadel apps use (intl, ldap, soap, zip, gd, pdo_mysql, mysqli, bcmath, pcntl, exif, redis, imagick), Composer and git, plus the Oracle install. This is the flavor WELD's `compose.oracle.yaml` points its services at. It runs as a non-root `app` user whose UID is a build argument (`APP_UID`) and can still be overridden at run time.

The names above are the local names of `scripts/oracle-image-build.sh`. The published images live in your GitLab container registry, at `$CI_REGISTRY_IMAGE/oracle-parity:8.4` and `$CI_REGISTRY_IMAGE/oracle-sail:8.4` (in GitLab CI, `$CI_REGISTRY_IMAGE` is set for you; elsewhere it is the registry address of the Squire project shown on its Container Registry page), plus `:8.4-<squire version>` for a fixed one. CI publishes them when a Squire version is tagged, multi-arch (linux/amd64 and linux/arm64); a Sail app swaps `build:` for `image:` with that path.

Both flavors download Oracle's Instant Client Basic Light at build time under Oracle's license and never commit it: the install script pins the exact SHA-256 of each zip. Oracle does not publish a 21.x client for arm64, so the line differs per architecture: linux/amd64 gets 21.7.0.0.0 and linux/arm64 gets 19.32.0.0.0.

<!-- docs-test: skip needs a real project -->
```
scripts/oracle-image-build.sh --flavour sail --php 8.4        # or: --flavour parity
scripts/oracle-image-test.sh --flavour all --platform linux/amd64
```

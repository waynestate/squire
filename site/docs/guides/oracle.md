---
title: "Oracle | Squire docs"
description: "How Squire checks an app's Oracle connection, and the opt-in Oracle-ready Docker images for local work."
url: https://squire.apps.wayne.edu/docs/guides/oracle.html
llms: https://squire.apps.wayne.edu/llms.txt
generator: Squire agent-ready
---

# Oracle [#](https://squire.apps.wayne.edu/docs/guides/oracle.html#oracle)

This page covers the plain-language Oracle connection check that `release.sh` runs on Citadel and that `squire doctor oracle` runs from your terminal, and the two opt-in Oracle-ready Docker images Squire ships for local work. Next, read [Citadel setup](https://squire.apps.wayne.edu/docs/guides/citadel-setup.html) to wire the release script into the dashboard, or [Local parity](https://squire.apps.wayne.edu/docs/guides/local-parity.html) to run the app on the real images.

## The connection check [#](https://squire.apps.wayne.edu/docs/guides/oracle.html#the-connection-check)

The release runs an Oracle connection check (`oracle-check.php`) after PROCTOR and before the smoke test. It is a report, never a gate: a finding prints in the deployment log and the release goes on. When the app does not use Oracle (no `ORACLE_*` settings) it prints nothing.

It checks, in order:

1. the settings are present,
2. the host resolves,
3. the port answers within 3 seconds,
4. the `oci8` driver is loaded,
5. the login works.

The check stops at the first failure, so a bad port never costs a login attempt. Only setting names are ever printed, never values.

Settings: `ORACLE_USERNAME`, `ORACLE_PASSWORD`, and either `ORACLE_TNS` (Easy Connect `host:port/service`, or a full `(DESCRIPTION=...)` descriptor) or `ORACLE_HOST`, `ORACLE_PORT` and `ORACLE_SERVICE_NAME`.

## `squire doctor oracle` [#](https://squire.apps.wayne.edu/docs/guides/oracle.html#squire-doctor-oracle)

`vendor/bin/squire doctor oracle` runs the same plain-language Oracle connection check that `release.sh` runs on Citadel, from your terminal or `sail php vendor/bin/squire doctor oracle`. It reads the `ORACLE_*` settings from the environment and the app's `.env`, prints setting names and never values, and stays silent when the app has no Oracle settings. Add `-v` for the client version.

## Oracle-ready images (local) [#](https://squire.apps.wayne.edu/docs/guides/oracle.html#oracle-ready-images-local)

Apps that talk to Oracle need the Instant Client and the `oci8` extension. Squire ships two opt-in Docker recipes that carry both, so nobody compiles them by hand:

- `squire/oracle-sail:<php>`: Laravel Sail's own runtime plus the Oracle install. In your `docker-compose.yml`, swap the service's `build:` block for `image: squire/oracle-sail:8.4` and keep the rest of the service as Sail wrote it; the image keeps Sail's entrypoint contract, including the `WWWUSER` UID remap.
- `squire/oracle-parity:<php>`: the official `php:<php>-cli-bookworm` image with the extensions Citadel apps use (intl, ldap, soap, zip, gd, pdo_mysql, mysqli, bcmath, pcntl, exif, redis, imagick), Composer and git, plus the Oracle install. This is the flavor WELD's `compose.oracle.yaml` points its services at. It runs as a non-root `app` user whose UID is a build argument (`APP_UID`) and can still be overridden at run time.

The names above are the local names of `scripts/oracle-image-build.sh`. The published images live in the group's `images` project (the stack's shared image registry; Squire's own GitLab has no container registry), at `<registry>/enterprise-apps/images/squire-oracle-parity:8.4` and `<registry>/enterprise-apps/images/squire-oracle-sail:8.4` (`<registry>` is the registry address of the images project shown on its Container Registry page), plus `:8.4-<squire version>` for a fixed one; a Sail app swaps `build:` for `image:` with that path.

**Every tag so far published linux/arm64 only.** From Squire 0.10.5, a tag publishes both flavors for PHP 8.3, 8.4 and 8.5 for linux/arm64: Apple silicon Macs and arm64 Linux hosts can pull them. The pipeline now builds linux/amd64 natively on an amd64 runner as well, so the next tag publishes both architectures in one list; until a tag has, on an amd64 computer, Citadel-shaped hosts included, build the images locally with `scripts/oracle-image-build.sh` (below). Pulling a tag that predates the first multi-arch one on amd64 fails with "no matching manifest for linux/amd64", which is the image's way of saying the same thing.

The 0.10.5 tag published all six (linux/arm64). Pin the fixed tag with its list digest so a pull always gets the same bytes:

| Image | List digest |
| --- | --- |
| `squire-oracle-sail:8.3-0.10.5` | `sha256:135f9c2c545acca83ba85a9ba0cdc749e49901187616be2707b6be40db1e80fb` |
| `squire-oracle-sail:8.4-0.10.5` | `sha256:3d942883ea31c802e2a958542697941c6bff4b36989931e12e2c24289b690a87` |
| `squire-oracle-sail:8.5-0.10.5` | `sha256:d3e66740c7a23221c12f112046c7ec6f316be5a8a96cf2005cbccdea4bd61bfb` |
| `squire-oracle-parity:8.3-0.10.5` | `sha256:4a14a2af005f2ba30eb32c23d6f9d4fdbe7e5593c9dc6a0c2f2616fdb532bc71` |
| `squire-oracle-parity:8.4-0.10.5` | `sha256:531b22047eedfd4e8bceee4583cbce2d0aac3129d27afdae97aec8b22bb54367` |
| `squire-oracle-parity:8.5-0.10.5` | `sha256:ebfdb9536d7a3e857a11d797a701f12b2129b7504bd48250b6d946a55a2adb7e` |

So a Sail app on an Apple silicon Mac writes `image: <registry>/enterprise-apps/images/squire-oracle-sail:8.4-0.10.5@sha256:3d942883ea31c802e2a958542697941c6bff4b36989931e12e2c24289b690a87`. Later tags are listed in MAINTAINING.md, What each tag published.

Both flavors download Oracle's Instant Client Basic Light at build time under Oracle's license and never commit it: the install script pins the exact SHA-256 of each zip. Oracle does not publish a 21.x client for arm64, so the line differs per architecture: linux/amd64 gets 21.7.0.0.0 and linux/arm64 gets 19.32.0.0.0.

```
scripts/oracle-image-build.sh --flavour sail --php 8.4        # or: --flavour parity
scripts/oracle-image-test.sh --flavour all --platform linux/amd64
```

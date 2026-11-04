---
title: "Local parity"
description: "Run your app on the Citadel PHP image behind a proxy like Citadel's, with the same MySQL 5.6.51: Docker requirements, WSL 2 advice, engines and licenses, the parity.sh and parity.ps1 commands, and the fallback build."
---

# Local parity

This page sets up the local kit: your app on the Citadel image, behind an HA-Proxy that adds the same forwarding headers, with MySQL 5.6.51 beside it. Finish [Citadel setup](citadel-setup.md) first if you have not run `squire init` yet.

## Requirements

Needs Docker with Compose v2. Windows needs Docker Desktop with the WSL 2 backend; no shell other than PowerShell is required.

**On Windows, keep the source in the WSL 2 filesystem.** Advice, not a requirement: keep the app's source tree inside the WSL 2 Linux filesystem (for example under `~/src` in your WSL distribution) and run `scripts/parity.sh` from there, not from a Windows folder such as `C:\Users\you\...` reached through `/mnt/c`. Bind mounts from the Windows side are much slower (Composer and npm installs feel it most), and file ownership and permissions do not map cleanly; that is the cause of the `Permission denied` failure described under the bind-mount question (OPEN_QUESTIONS 15). The PowerShell `scripts\parity.ps1` still works from a Windows folder, only slower.

## Engines and licenses

Squire needs any engine that runs Docker Compose v2 compatible files. The ones named here:

- **Docker Engine on Linux**: open source, Apache 2.0.
- **Docker Desktop on Windows** with the WSL 2 backend: a commercial product, free for small business, personal and educational use, and paid for larger organizations, so check your own license.
- **Podman** with a Compose v2 compatible provider, and **Colima** on macOS: expected to work, not tested here.

Only Docker Desktop and Docker Engine have been run.

## Starting parity

<!-- docs-test: skip needs a real project -->
```sh
# Linux and macOS
cp .env.squire.example .env.squire     # then set the passwords
scripts/parity.sh up                   # or: scripts/parity.sh up --fallback

# Windows (PowerShell 5.1 or 7)
Copy-Item .env.squire.example .env.squire
scripts\parity.ps1 up                  # or: scripts\parity.ps1 up -Fallback
```

Commands: `up`, `down`, `reset` (also removes volumes), `logs [service]`, `shell [service]`, `status`, `pin` (prints the digests of the images you have pulled, so you can pin them).

## What it starts

It starts the app container from the Citadel image (built by Citadel's `build_images` command, or pulled from wherever you keep it) with your source mounted at `/citadel/src`, runs your `deployments/` as the `citadel` user the way Citadel does, and puts an HA-Proxy in front that adds the same forwarding headers. MySQL 5.6.51 is reachable from the app as host `mysql` and from your machine on `127.0.0.1:3307`. Containers drop capabilities, set `no-new-privileges`, and carry memory and process limits. The database port is bound to loopback only. The Vue layer's compose file adds a Node 22 service, pinned to the same major as the Citadel image, for frontend tooling.

## No access to the real image?

`--fallback` builds an approximation from the official `php` image plus Citadel's extensions. It is good for trying Squire out and not for proving parity. If the build step fails with a permission error writing `vendor/`, the source folder is not writable by the container's `citadel` user (uid 1000 by default); on Linux set `SQUIRE_SOURCE_UID` and `SQUIRE_SOURCE_GID` to yours.

> [!NOTE]
> The compose file refuses to start without the two passwords. Set them in `.env.squire` after copying `.env.squire.example`; the example holds placeholders only.

## Next

Read [The release script](release-script.md) for what runs inside the app container, or [Citadel setup](citadel-setup.md) for the dashboard side of the same pipeline.

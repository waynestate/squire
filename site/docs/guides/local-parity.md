---
title: "Local parity | Squire docs"
description: "Run Citadel on your computer with Parity, the stack's own tool, and move off Squire's deprecated parity kit; plus the kit itself while it lasts: Docker requirements, WSL 2 advice, engines and licenses, the parity.sh and parity.ps1 commands, and the fallback build."
url: https://squire.apps.wayne.edu/docs/guides/local-parity.html
llms: https://squire.apps.wayne.edu/llms.txt
generator: Squire agent-ready
---

# Local parity [#](https://squire.apps.wayne.edu/docs/guides/local-parity.html#local-parity)

To run your app on Citadel's own PHP image, behind Citadel's proxy, with MySQL 5.6 beside it, use [Parity](https://git.wayne.edu/enterprise-apps/parity). The rest of this page is Squire's older parity kit, which is deprecated.

## Use Parity [#](https://squire.apps.wayne.edu/docs/guides/local-parity.html#use-parity)

Parity is the stack's tool for running Citadel on your computer: one program, `parity`, for Windows, macOS and Linux (x64 and arm64), for any app, with or without Squire. It runs on Docker or Podman (Podman is recommended) and needs no PHP or Composer on your computer. It uses Citadel's own PHP image (checked against Citadel's, byte for byte), Citadel's `vhost.conf` and `php.ini`, an HAProxy that adds the same forwarding headers, and MySQL 5.6. It runs the files Squire writes (`build.sh`, `deployments/`, the health check) the way Citadel does, applies the app's `sign_in` setting, and runs its Citadel-style cron entries. Its commands are `check`, `init`, `up`, `down`, `status`, `logs`, `shell`, `image` and `test`.

Coming from this kit, in the app's folder:

```sh
parity check                 # what to fix on this computer, one finding per line
parity init --from-squire    # writes parity.json from docker-compose.squire.yml and .env.squire
parity image get             # Citadel's image, checked against Citadel's
parity up                    # build, start, and print the address
```

`parity init --from-squire` carries the kit's settings over (base image, public folder, ports, database name, time zone, deployments) and leaves passwords and every other value behind; Parity makes its own local passwords. It lists the kit's files and deletes none of them. Two differences to expect: Parity runs Citadel's own `php.ini`, not the kit's hardened one, and the kit's `scheduler` and `worker` services become one cron entry that runs Laravel's scheduler every minute, because Citadel has cron and no services. Parity's [README](https://git.wayne.edu/enterprise-apps/parity) has the install line for each OS and the full command list.

Squire still does its own job: it writes the files Citadel runs and checks them with `squire doctor`. Squire gets the app onto Citadel; Parity runs Citadel on your computer.

In CI, the same MySQL pin checks every merge: Preflight's [`mysql56-migrate` component](https://preflight.apps.wayne.edu/docs/guides/component-mysql56-migrate.html) (0.6.0) runs the app's migrations against `mysql:5.6.51` on an amd64 runner, and PROCTOR's `mysql56-ci` check warns when an app with migrations has no such job. Parity catches a bad migration on your computer; the pipeline catches the one that slipped past.

## The parity kit is deprecated [#](https://squire.apps.wayne.edu/docs/guides/local-parity.html#the-parity-kit-is-deprecated)

Warning

Squire's `parity` group (`docker-compose.squire.yml`, `.squire/`, `.env.squire.example`, `scripts/parity.sh` and `scripts/parity.ps1`) is deprecated as of Squire 0.10.5. It still works, and `squire init`, `squire update` and `squire doctor` say so in one line. It is removed no sooner than two minor releases and 60 days later: Squire 0.12.0, and not before 2027-01-08 (the stack's ADR-0022). When it goes, `squire update` removes only the kit files you never edited and prints where Parity is. The portable image files (`.squire/portable.Dockerfile`, `.squire/portable/`) are not leaving: they move to a group of their own.

The rest of this page sets up the kit for teams that still use it: your app on the Citadel image, behind an HA-Proxy that adds the same forwarding headers, with MySQL 5.6.51 beside it. Finish [Citadel setup](https://squire.apps.wayne.edu/docs/guides/citadel-setup.html) first if you have not run `squire init` yet.

## Requirements [#](https://squire.apps.wayne.edu/docs/guides/local-parity.html#requirements)

Needs Docker with Compose v2. Windows needs Docker Desktop with the WSL 2 backend; no shell other than PowerShell is required.

**On Windows, keep the source in the WSL 2 filesystem.** Advice, not a requirement: keep the app's source tree inside the WSL 2 Linux filesystem (for example under `~/src` in your WSL distribution) and run `scripts/parity.sh` from there, not from a Windows folder such as `C:\Users\you\...` reached through `/mnt/c`. Bind mounts from the Windows side are much slower (Composer and npm installs feel it most), and file ownership and permissions do not map cleanly; that is the cause of the `Permission denied` failure described under the bind-mount question (OPEN_QUESTIONS 15). The PowerShell `scripts\parity.ps1` still works from a Windows folder, only slower.

## Engines and licenses [#](https://squire.apps.wayne.edu/docs/guides/local-parity.html#engines-and-licenses)

Squire needs any engine that runs Docker Compose v2 compatible files. The ones named here:

- **Docker Engine on Linux**: open source, Apache 2.0.
- **Docker Desktop on Windows** with the WSL 2 backend: a commercial product, free for small business, personal and educational use, and paid for larger organizations, so check your own license.
- **Podman** with a Compose v2 compatible provider, and **Colima** on macOS: expected to work, not tested here.

Only Docker Desktop and Docker Engine have been run.

## Starting parity [#](https://squire.apps.wayne.edu/docs/guides/local-parity.html#starting-parity)

```sh
# Linux and macOS
cp .env.squire.example .env.squire     # then set the passwords
scripts/parity.sh up                   # or: scripts/parity.sh up --fallback

# Windows (PowerShell 5.1 or 7)
Copy-Item .env.squire.example .env.squire
scripts\parity.ps1 up                  # or: scripts\parity.ps1 up -Fallback
```

Commands: `up`, `down`, `reset` (also removes volumes), `logs [service]`, `shell [service]`, `status`, `pin` (prints the digests of the images you have pulled, so you can pin them).

## What it starts [#](https://squire.apps.wayne.edu/docs/guides/local-parity.html#what-it-starts)

It starts the app container from the Citadel image (built by Citadel's `build_images` command, or pulled from wherever you keep it) with your source mounted at `/citadel/src`, runs your `deployments/` as the `citadel` user the way Citadel does, and puts an HA-Proxy in front that adds the same forwarding headers. MySQL 5.6.51 is reachable from the app as host `mysql` and from your machine on `127.0.0.1:3307`. Containers drop capabilities, set `no-new-privileges`, and carry memory and process limits. The database port is bound to loopback only. The Vue layer's compose file adds a Node 22 service, pinned to the same major as the Citadel image, for frontend tooling.

The compose file's `portable` profile starts nothing on a plain `up`. Named, it runs [the portable image](https://squire.apps.wayne.edu/docs/guides/portable-image.html) instead: the code baked in, one image as `migrate`, `web`, `worker` and `scheduler`, as a non-root user in group 0 on 8080, the way a platform other than Citadel runs it.

## No access to the real image? [#](https://squire.apps.wayne.edu/docs/guides/local-parity.html#no-access-to-the-real-image)

`--fallback` builds an approximation from the official `php` image plus Citadel's extensions. It is good for trying Squire out and not for proving parity. If the build step fails with a permission error writing `vendor/`, the source folder is not writable by the container's `citadel` user (uid 1000 by default); on Linux set `SQUIRE_SOURCE_UID` and `SQUIRE_SOURCE_GID` to yours.

Note

The compose file refuses to start without the two passwords. Set them in `.env.squire` after copying `.env.squire.example`; the example holds placeholders only.

## Next [#](https://squire.apps.wayne.edu/docs/guides/local-parity.html#next)

Read [The release script](https://squire.apps.wayne.edu/docs/guides/release-script.html) for what runs inside the app container, or [Citadel setup](https://squire.apps.wayne.edu/docs/guides/citadel-setup.html) for the dashboard side of the same pipeline.

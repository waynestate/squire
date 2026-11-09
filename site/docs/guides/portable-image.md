---
title: "The portable image | Squire docs"
description: "Run a Squire app off Citadel (OpenShift, Coolify, raw Docker): one image with the code baked in, run as web, worker, scheduler or migrate, as an arbitrary non-root UID in group 0 on port 8080, with the Citadel mirror left exactly as it was."
url: https://squire.apps.wayne.edu/docs/guides/portable-image.html
llms: https://squire.apps.wayne.edu/llms.txt
generator: Squire agent-ready
---

# The portable image [#](https://squire.apps.wayne.edu/docs/guides/portable-image.html#the-portable-image)

Citadel builds its own image, and `.squire/Dockerfile` mirrors that one and stays a mirror. Everywhere else (OpenShift, Coolify, a plain `docker run`, ADR-0010's portable path) an app needs an image it can take with it: the code inside, one image for every process type, and no assumption that it starts as root. That is `.squire/portable.Dockerfile`. A squire packs one saddlebag that fits every horse.

Read [Local parity](https://squire.apps.wayne.edu/docs/guides/local-parity.html) first for the base image, and [Queue workers on Citadel](https://squire.apps.wayne.edu/docs/guides/queue-workers.html) for the Citadel side of the same worker.

## What differs from the Citadel mirror [#](https://squire.apps.wayne.edu/docs/guides/portable-image.html#what-differs-from-the-citadel-mirror)

Both start from the same base, the same `php.ini` and `php-web.ini`, and YOUR `build.sh`. Then:

|  | `.squire/Dockerfile` (Citadel mirror) | `.squire/portable.Dockerfile` |
| --- | --- | --- |
| Code | mounted at `/citadel/src` | baked in at `/citadel/src` |
| Process types | Apache only (`CMD ["apache2-foreground"]`) | web, worker, scheduler or migrate, by argument or `SQUIRE_ROLE` |
| User | starts as root, Apache drops to `prisoner` | `USER 10001:0`; any UID in group 0 works |
| Port | 80 | 8080 (`SQUIRE_PORT`, else `PORT`) |
| Logs | files under `/citadel/logs`, rotated | stdout and stderr |
| Stop signal | `SIGWINCH` (the base's) | `SIGTERM`, a graceful stop for every role |
| Release | `scripts/release.sh` on the running container | its install-and-build half at image build; config cached at container start; migrations from the `migrate` role |
| opcache | checks files (a bind mount changes them) | never checks files (`SQUIRE_OPCACHE_VALIDATE=0`) |

Citadel never reads the portable files, so nothing here changes a Citadel app.

## Build it [#](https://squire.apps.wayne.edu/docs/guides/portable-image.html#build-it)

From the app root, with the kit at the root (where Sourdough and `squire init` put it):

```sh
docker build -f .squire/portable.Dockerfile --build-arg BASE_IMAGE=<base image> -t my-app .
```

With the kit in a subfolder (WELD keeps it in `deploy/`), the context is still the app root; say where the kit is:

```sh
docker build -f deploy/.squire/portable.Dockerfile --build-arg SQUIRE_KIT_DIR=deploy --build-arg BASE_IMAGE=<base image> -t my-app .
```

`BASE_IMAGE` is the real Citadel image or the fallback base (`.squire/base-fallback.Dockerfile`), pushed to a registry the platform can pull from. The build runs `scripts/release.sh` with the run-time steps off: Composer without dev packages, the frontend build, the writable folders, and nothing that needs the app's settings (no migrate, no optimize, no restart, no smoke test, and PROCTOR's gate belongs in CI on this path). Composer's and npm's downloads go through a BuildKit cache mount, so a rebuild fetches only what changed.

`portable.Dockerfile.dockerignore` sits next to the Dockerfile, so Docker reads it for this build only: `.env` files, `auth.json`, `secrets`, keys, `.git`, `vendor/` and `node_modules/` never reach the build context, let alone a layer.

## Run it as an arbitrary UID [#](https://squire.apps.wayne.edu/docs/guides/portable-image.html#run-it-as-an-arbitrary-uid)

OpenShift runs a container as a random UID that has no `/etc/passwd` entry and is always in group 0 ([OKD: support arbitrary user IDs](https://docs.okd.io/latest/openshift_images/create-images.html#use-uid_create-images)). The image follows that recipe: the folders the app and Apache write (Apache's run, lock and log folders; `storage/` and `bootstrap/cache/` for Laravel) belong to group 0 and are group-writable (`chgrp -R 0` then `chmod -R g=u`). Try it the way OpenShift would:

```sh
docker run -d --name my-app-web --user 1000123:0 --cap-drop ALL --security-opt no-new-privileges:true \
  --env-file .env -p 127.0.0.1:8081:8080 my-app
curl -i http://127.0.0.1:8081/up
```

It needs no capability at all: it never starts as root, and 8080 is not a privileged port. A raw PHP app that writes somewhere else names those folders at build time with `--build-arg SQUIRE_WRITABLE_DIRS="var/cache var/log"`.

## One image, four roles [#](https://squire.apps.wayne.edu/docs/guides/portable-image.html#one-image-four-roles)

The first argument picks the role, else `SQUIRE_ROLE`, else `web`:

| Role | Runs | Replace it with |
| --- | --- | --- |
| `web` | Apache and mod_php on `SQUIRE_PORT` (else `PORT`, else 8080) |  |
| `worker` | `php artisan queue:work --tries=3 --max-time=3600 --sleep=3` | `SQUIRE_WORKER_COMMAND` |
| `scheduler` | `php artisan schedule:work` | `SQUIRE_SCHEDULER_COMMAND` |
| `migrate` | `php artisan migrate --force`, once per release | `SQUIRE_MIGRATE_COMMAND` |

Arguments after the role are passed on (`worker --queue=high,default`), and options alone go to the `SQUIRE_ROLE` process, so a platform that sets only `command: ["--queue=high"]` beside `SQUIRE_ROLE=worker` gets what it means. Any other first argument is run as a command: `docker run --rm my-app php artisan about`.

The Laravel roles run `php artisan optimize` first, so config, routes and views are cached for the container's own environment when it starts, never at build time, where they would freeze the build machine's settings. `SQUIRE_ARTISAN_OPTIMIZE=0` skips it (the same switch `scripts/release.sh` reads). A route closure that cannot be serialized fails `route:cache` and stops the container with Laravel's message; skip optimize while you fix the route, and know the release on Citadel stumbles on the same route.

A raw PHP app has no `artisan`, so it names its own worker and scheduler; the role stops with a plain message (exit 64) when the command is missing, instead of starting something that is not there. `migrate` with nothing to migrate succeeds, so the services that wait for it still start.

A bad setting stops the container before anything starts, with exit 64 and one line that says what to change: a port that is not a number, a port below 1024 for a user that is not root, an unknown role, an empty named command.

## Compose: the `portable` profile [#](https://squire.apps.wayne.edu/docs/guides/portable-image.html#compose-the-portable-profile)

`docker-compose.squire.yml` carries the same four roles as a profile beside the parity stack. A plain `up` still starts only the Citadel parity services; the profile starts nothing until you name it:

```sh
docker compose -f docker-compose.squire.yml --env-file .env.squire --profile portable up -d --build web worker scheduler
```

- **One image.** Every service builds and runs `squire-local/portable:<project>`; they differ only in `SQUIRE_ROLE`.
- **migrate** runs once, after MySQL is healthy, and the other three wait for it to finish successfully (`restart: "no"`).
- **web** is published on `127.0.0.1:${SQUIRE_PORTABLE_HTTP_PORT:-8081}`, beside the parity proxy on 8080.
- **worker** comes back after `--max-time` ends it, through `restart: unless-stopped`.
- **scheduler** sets `QUEUE_WORKER_MODE=none`: Sourdough's Laravel shapes schedule a short queue worker by default, and here the worker service has the queue.
- Every service runs as `${SQUIRE_PORTABLE_USER:-10001:0}` with `cap_drop: [ALL]`, `no-new-privileges`, and memory and process limits, reads the app's settings from `${SQUIRE_PORTABLE_ENV_FILE:-.env}` (never baked in), and logs to stderr.

The settings are in `.env.squire.example` under "the portable image". WELD sets `SQUIRE_PORTABLE_CONTEXT=..` and `SQUIRE_KIT_DIR=deploy`, because its app sits one folder up from the kit.

## Deploys and signals [#](https://squire.apps.wayne.edu/docs/guides/portable-image.html#deploys-and-signals)

Every role stops on `SIGTERM`, the signal every platform sends. Apache's graceful stop is `SIGWINCH`, so for `web` the entrypoint stays as a small init that turns `SIGTERM` and `SIGINT` into `SIGWINCH` and exits with Apache's status: requests in flight finish. `worker` and `scheduler` exec PHP directly, and `queue:work` finishes its current job on `SIGTERM` before it exits ([queues](https://laravel.com/docs/13.x/queues#queue-workers-and-deployment)). A platform that replaces containers on a deploy therefore starts the new ones on the new code, and needs no restart signal; `scripts/release.sh`'s `SQUIRE_ARTISAN_RESTART` step stays the Citadel answer, where the container lives on across releases. Keep each job's timeout below its connection's `retry_after`, as on Citadel.

## Health [#](https://squire.apps.wayne.edu/docs/guides/portable-image.html#health)

The image's `HEALTHCHECK` runs `squire-entrypoint health`. For `web` that is `health.php` on the port Apache listens on; `SQUIRE_APP_DIR` (set in the image) points `health.php` at the app root even when the kit sits in a subfolder. `worker`, `scheduler` and `migrate` have no endpoint to ask, so they are healthy while their process runs, and the platform's restart policy covers a process that dies.

## Not covered here [#](https://squire.apps.wayne.edu/docs/guides/portable-image.html#not-covered-here)

- The base image's `EXPOSE 80` still shows in the image metadata (a Dockerfile cannot take back an inherited port); nothing listens there.
- Publishing the image is the platform's or the CI's job; this kit builds it.
- The Citadel mirror, `vhost.conf` on 80 and the fallback base's `EXPOSE 80` are unchanged on purpose: a change there would claim a parity Citadel does not have.

## How it was checked [#](https://squire.apps.wayne.edu/docs/guides/portable-image.html#how-it-was-checked)

`tests/PortableImageTest.php` reads every portable file and runs the entrypoint in bash with stand-ins for `php`, `apache2-foreground` and `id`: each role, the argument and setting precedence, the refusals, the health check, the secrets loader, and `SIGTERM` reaching Apache as its graceful stop. On 2026-11-08 the image was also built and run for real, for a raw PHP app and for WELD: `docker run --user 1000123:0` answered `/up` with 200 on 8080 from Apache running as `1000123:0`; the compose profile ran `migrate` (exit 0), then `web`, `worker` and `scheduler` healthy as `1000123:0`; the worker ran a queued job; and `docker stop` ended all three in about two seconds, each with exit 0.

## Next [#](https://squire.apps.wayne.edu/docs/guides/portable-image.html#next)

Read [Queue workers on Citadel](https://squire.apps.wayne.edu/docs/guides/queue-workers.html) for the timeout rule both paths share, or [Image hardening](https://squire.apps.wayne.edu/docs/guides/image-hardening.html) for what the base and the kit harden.

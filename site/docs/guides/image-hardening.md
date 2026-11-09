---
title: "Image hardening | Squire docs"
description: "What Squire's images and kit harden, and how to turn each piece down when an app needs it: Apache's banner and TRACE, opcache set by how code arrives, a web-only disable_functions list, secrets as files at run time, npm without install scripts, and bases pinned by digest."
url: https://squire.apps.wayne.edu/docs/guides/image-hardening.html
llms: https://squire.apps.wayne.edu/llms.txt
generator: Squire agent-ready
---

# Image hardening [#](https://squire.apps.wayne.edu/docs/guides/image-hardening.html#image-hardening)

This page lists what the kit's images harden, where each setting lives, and the switch that turns it down when an app needs that. Every item has a test that reads the file, and the [image contract suite](https://squire.apps.wayne.edu/docs/guides/image-hardening.html#the-image-contract-suite) checks it again inside the built image. Read [Local parity](https://squire.apps.wayne.edu/docs/guides/local-parity.html) first for the images themselves.

Two kinds of file carry the hardening. The **base** (`.squire/base-fallback.Dockerfile`) stands in for the Citadel image; what it sets is what Squire asks the real image to set, and the real image keeps deciding on Citadel. The **app image** (`.squire/Dockerfile`) is the local copy of the Dockerfile Citadel generates, and runs on either base.

## Apache: a quieter banner, no TRACE [#](https://squire.apps.wayne.edu/docs/guides/image-hardening.html#apache-a-quieter-banner-no-trace)

The base enables `.squire/apache-hardening.conf` as `zz-squire-hardening.conf`, so it loads after Debian's `security.conf`, which ships `ServerTokens OS` and `ServerSignature On`:

| Directive | Effect |
| --- | --- |
| `ServerTokens Prod` | The `Server` header says `Apache`, not `Apache/2.4.x (Debian)`. |
| `ServerSignature Off` | Error pages Apache writes itself carry no version footer. |
| `TraceEnable Off` | `TRACE` answers 405, so a script cannot read cookies back off an echo. |

These live in the base, never in the app image: locally the app image also runs on the real Citadel image, and hardening it there would show a parity the platform does not have.

## opcache: set by how your code arrives [#](https://squire.apps.wayne.edu/docs/guides/image-hardening.html#opcache-set-by-how-your-code-arrives)

`.squire/php.ini` turns opcache on for the web (`opcache.enable = 1`, `opcache.enable_cli = 0`), and the fallback base loads the extension when its image does not already. Whether opcache looks at your files again depends on how the code gets into the container, so there are two cases and one switch, `SQUIRE_OPCACHE_VALIDATE`:

| How code arrives | `SQUIRE_OPCACHE_VALIDATE` | What opcache does |
| --- | --- | --- |
| Pulled into a running container: a Citadel Quick Update, a bind mount on your machine, or anywhere Squire cannot tell | `1`, the default | Checks a file again at most every 2 seconds (`revalidate_freq = 2`), so a release is served within 2 seconds with no restart. |
| Baked into an image that never changes: OpenShift, Coolify or raw Docker with the code copied in at build time | `0`, which you choose | Never checks a file again. The image is replaced, not edited, so there is nothing to notice, and requests skip the check. |

Set the switch where the image is built, `docker build --build-arg SQUIRE_OPCACHE_VALIDATE=0 -f .squire/Dockerfile .`, or when it runs, `-e SQUIRE_OPCACHE_VALIDATE=0` (the run-time value wins). Use `0` or `1`, never an empty value: PHP falls back to the default only when the variable is unset, and empty turns the checks off. Locally, put `SQUIRE_OPCACHE_VALIDATE=0` in `.env.squire` to try the baked case on your bind mount, and remember that saved files then wait for an Apache restart.

The other values, each read from the environment (PHP 8.3 and later):

| Setting | Default | Environment variable |
| --- | --- | --- |
| `opcache.memory_consumption` | `128` (megabytes) | `SQUIRE_OPCACHE_MEMORY` |
| `opcache.max_accelerated_files` | `20000` (a Laravel app with its `vendor/` is 10000 to 15000 files) | `SQUIRE_OPCACHE_MAX_FILES` |
| `opcache.interned_strings_buffer` | `16` | none |

**The release warning stays.** The release script's "check opcache" step never fails, and says what it sees: a note when opcache checks files, and a warning when a release runs in place (Apache up) against an opcache set to `0`, because Apache keeps serving the PHP it compiled before the release until it restarts. In the first-release hook, before Apache starts, `0` is only a note.

Note

On Citadel the platform's own `php.ini` decides, not this file. Ask for the same as the kit's default: `validate_timestamps` on, `revalidate_freq` 2, because a Quick Update pulls code into the running container.

## disable_functions: the web loses the shell [#](https://squire.apps.wayne.edu/docs/guides/image-hardening.html#disable-functions-the-web-loses-the-shell)

A web request should never shell out. `.squire/php-web.ini` holds the settings only the web server gets, and the app image appends it to `php.ini` as `php-apache2handler.ini`, the file mod_php reads instead of `php.ini`. The CLI reads `php.ini` alone, so Composer and its scripts, artisan, queue workers, the health check and the release script keep every function.

| Disabled on the web | Why |
| --- | --- |
| `exec`, `passthru`, `shell_exec`, `system` | Run a shell command: the first thing an injected payload reaches for. |
| `pcntl_exec` | Replaces the PHP process with another program. |
| `phpinfo`, `show_source`, `highlight_file` | Print the server's setup or its source to whoever asked. |

`proc_open` and `popen` stay: Symfony Mailer's sendmail transport, Laravel's `Process` and PHPMailer use them on the web. A test runs PHP on the same two files and checks both sides: the web list is gone, and the CLI and the mail functions are still there.

**The switch.** `SQUIRE_PHP_DISABLE_FUNCTIONS` replaces the list, and `none` (a name no function has) disables nothing: the way out for an older app that calls `exec` from a page while you move that work to a queued job. Leave it unset for the default list: PHP falls back only when the variable is unset, so an empty value disables nothing as well, and the parity compose files pass it by name only for that reason. Locally, put `SQUIRE_PHP_DISABLE_FUNCTIONS=none` in `.env.squire`.

`disable_classes` is not set. It never stopped a caller who could reach another class, and PHP 8.5 removed it.

Note

Like opcache, this is the kit's request: on Citadel the platform's own `php.ini` decides. A local run is stricter than Citadel until the platform adopts the list, which is the safe direction: an app that works locally works there.

## Secrets: files at run time, never in a layer [#](https://squire.apps.wayne.edu/docs/guides/image-hardening.html#secrets-files-at-run-time-never-in-a-layer)

An image layer is readable by anyone who can pull the image, and `docker history` shows every build argument. So no secret is ever a build argument, an `ENV` line, a copied file, or something `build.sh` fetches. Tests hold the kit to it: the Dockerfiles carry no `ARG` or `ENV` named like a password, key, token or secret, the build context is `build.sh` and `.squire/` only (the `.dockerignore` starts with `*` and never lets an env file back in), and `build.sh` never reads `.env`.

Secrets arrive when the container runs:

| Where | How a secret arrives | Who reads it |
| --- | --- | --- |
| Citadel | the dashboard's configuration-files step writes `./.env` into the source tree at deploy time | the app (Laravel, or phpdotenv in a raw PHP app); see [Citadel setup](https://squire.apps.wayne.edu/docs/guides/citadel-setup.html) |
| Docker Compose | a `secrets:` entry, mounted as `/run/secrets/NAME` | the release script and the fallback entrypoint, below |
| `docker run` | `-v "$PWD/secrets/DB_PASSWORD:/run/secrets/DB_PASSWORD:ro"` | the same |
| OpenShift, Kubernetes | a Secret volume mounted at `/run/secrets` (each key is a file) | the same |

**The secrets folder.** `scripts/release.sh` and the fallback base's entrypoint share one loader: each regular file in `SQUIRE_SECRETS_DIR` (default `/run/secrets`) whose name is a variable name becomes that variable, with one trailing line ending trimmed, unless the environment already sets it. Names that would change how the shell runs (`PATH`, `IFS`, `BASH_ENV`, `LD_*` and a few more) are skipped, and a file the user cannot read is a warning. The log names what it loaded and never prints a value. The entrypoint loads the folder before Apache starts, so the PHP it runs sees the variables through `getenv()`; the release loads it again, because `su -l citadel` starts it with a clean environment.

Name the file after the variable the app reads. With Compose, set the file name with `target`:

```yaml
services:
  app:
    secrets:
      - source: db_password
        target: DB_PASSWORD
secrets:
  db_password:
    file: ./secrets/db_password.txt
```

Note

The real Citadel image's entrypoint does not read `/run/secrets`; on Citadel, `./.env` is the secret path. Keep `./.env` and any `secrets/` folder out of git.

## npm: no install scripts, no day-old versions [#](https://squire.apps.wayne.edu/docs/guides/image-hardening.html#npm-no-install-scripts-no-day-old-versions)

A package's install script runs with the release's rights, and a hijacked version is usually caught and pulled within hours. So the release does two things:

- **No install scripts.** `npm ci --ignore-scripts` (or `pnpm install --frozen-lockfile --ignore-scripts`), on by default with `SQUIRE_NPM_IGNORE_SCRIPTS=1`. A package that needs its own scripts (a native addon such as `sharp`, a binary download such as `esbuild`) is named in `SQUIRE_NPM_REBUILD`, and only it runs them after the install: `npm rebuild --ignore-scripts=false sharp`, or `pnpm rebuild sharp` (pnpm 10 also needs it in `pnpm.onlyBuiltDependencies`). Each name is checked against the shape of a package name first.
- **A minimum release age.** The Vue layer's `.npmrc` sets `min-release-age=1`: `npm install` and `npm update` skip versions published less than a day ago, so they never reach the lock file. `npm ci` installs the lock exactly, so this guards the day the lock is written, not the release. npm reads the setting from 11.10 on; Node 22 ships npm 10, which ignores it, and the release says so in its log.

**Policy.** Keep one day for every app; raise it, never lower it, in the app's own `.npmrc`. To take a fix published this morning, install it on purpose and review the lock diff:

```sh
npm install --min-release-age=0 some-package@1.2.3
```

## Base images: pinned by digest [#](https://squire.apps.wayne.edu/docs/guides/image-hardening.html#base-images-pinned-by-digest)

A tag such as `php:8.4-apache` moves: two builds of the same commit a week apart can get different bytes. Every image the kit's files pull is pinned by the digest of its multi-arch list instead, `php:8.4.27-apache@sha256:...`, which names the same bytes on an arm64 laptop or CI runner and on an amd64 server: the fallback base's PHP, Node and Composer, the Vue layer's Node service, HA-Proxy and MySQL in the compose files, and the Oracle parity bases. A test fails on any reference without a digest.

The kit's Composer (the fallback base and the Oracle parity image) is 2.11.1, `composer:2.11.1@sha256:8de2184c...`; 2.10 is no longer maintained. Composer 2.11's `policy.cooldown` is off by default, and the kit does not turn it on.

To bump one, read the new list's digest and check it lists both platforms, then replace the whole reference:

```sh
docker buildx imagetools inspect php:8.4.28-apache
```

Look for the `Digest:` line, and for `linux/amd64` and `linux/arm64/v8` under Manifests. Then run the tests and the image contract suite on both platforms. Maintainers have the full procedure in `MAINTAINING.md`. Two references are yours, not the kit's: the real Citadel image (`SQUIRE_BASE_IMAGE`, pin it once you have its digest) and any `SQUIRE_NODE_IMAGE`, `SQUIRE_PROXY_IMAGE` or `SQUIRE_MYSQL_IMAGE` override.

## The image contract suite [#](https://squire.apps.wayne.edu/docs/guides/image-hardening.html#the-image-contract-suite)

A file can promise anything; the running image is what counts. `scripts/contract-image.sh` (in Squire's repository) builds the fallback base and the app image for a platform, runs the container with a throwaway raw PHP app mounted at `/citadel/src` and a throwaway secret mounted at `/run/secrets`, runs the release as `citadel`, and then pipes `scripts/contract-image-checks.php` to `php` inside the container. One PASS or FAIL line per check:

| Item | Checked in the running image |
| --- | --- |
| Apache | the `Server` header is exactly `Apache`; no `X-Powered-By`; `TRACE` answers 405 with no version footer |
| opcache | code pulled in place: the web checks files (`validate_timestamps` 1, `revalidate_freq` 2) and the release notes it; code baked in (the app image rebuilt with `SQUIRE_OPCACHE_VALIDATE=0`): the web never checks; 20000 files either way |
| disable_functions | the web has no `exec` and the rest, and keeps `proc_open` and `popen`; the CLI disables nothing; a Composer `post-install-cmd` that shells out runs in the release |
| Secrets | the web sees the mounted secret (compared as an md5, never printed), the release names it, and its value is in no log, no layer and no image config |

```sh
sh scripts/contract-image.sh --platform=linux/amd64
sh scripts/contract-image.sh --platform=linux/arm64   # needs qemu emulation on an amd64 host
```

GitHub CI runs it for both platforms on every push. It proves the fallback base, not the real Citadel image, which is internal: a green run is a contract, not a certificate.

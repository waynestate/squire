---
title: "Image hardening | Squire docs"
description: "What Squire's images and kit harden, and how to turn each piece down when an app needs it: Apache's banner and TRACE, production opcache with a dev override, a web-only disable_functions list, and secrets as files at run time."
url: https://squire.apps.wayne.edu/docs/guides/image-hardening.html
llms: https://squire.apps.wayne.edu/llms.txt
generator: Squire agent-ready
---

# Image hardening[#](https://squire.apps.wayne.edu/docs/guides/image-hardening.html#image-hardening)

This page lists what the kit's images harden, where each setting lives, and the switch that turns it down when an app needs that. Every item has a test that reads the file. Read [Local parity](https://squire.apps.wayne.edu/docs/guides/local-parity.html) first for the images themselves.

Two kinds of file carry the hardening. The **base** (`.squire/base-fallback.Dockerfile`) stands in for the Citadel image; what it sets is what Squire asks the real image to set, and the real image keeps deciding on Citadel. The **app image** (`.squire/Dockerfile`) is the local copy of the Dockerfile Citadel generates, and runs on either base.

## Apache: a quieter banner, no TRACE[#](https://squire.apps.wayne.edu/docs/guides/image-hardening.html#apache-a-quieter-banner-no-trace)

The base enables `.squire/apache-hardening.conf` as `zz-squire-hardening.conf`, so it loads after Debian's `security.conf`, which ships `ServerTokens OS` and `ServerSignature On`:

| Directive | Effect |
| --- | --- |
| `ServerTokens Prod` | The `Server` header says `Apache`, not `Apache/2.4.x (Debian)`. |
| `ServerSignature Off` | Error pages Apache writes itself carry no version footer. |
| `TraceEnable Off` | `TRACE` answers 405, so a script cannot read cookies back off an echo. |

These live in the base, never in the app image: locally the app image also runs on the real Citadel image, and hardening it there would show a parity the platform does not have.

## opcache: production settings, a dev override[#](https://squire.apps.wayne.edu/docs/guides/image-hardening.html#opcache-production-settings-a-dev-override)

`.squire/php.ini` turns opcache on for the web (`opcache.enable = 1`, `opcache.enable_cli = 0`) with production values, and the fallback base loads the extension when its image does not already. A value written `${NAME:-default}` reads the container's environment (PHP 8.3 and later), so the same image serves production and a bind mount:

| Setting | Default | Environment variable |
| --- | --- | --- |
| `opcache.validate_timestamps` | `0`: a compiled file is never checked again until Apache restarts | `SQUIRE_OPCACHE_VALIDATE_TIMESTAMPS` |
| `opcache.revalidate_freq` | `0`: when checking is on, every request sees the newest file | none |
| `opcache.memory_consumption` | `128` (megabytes) | `SQUIRE_OPCACHE_MEMORY` |
| `opcache.max_accelerated_files` | `20000` (a Laravel app with its `vendor/` is 10000 to 15000 files) | `SQUIRE_OPCACHE_MAX_FILES` |
| `opcache.interned_strings_buffer` | `16` | none |

**The dev override.** The parity compose file sets `SQUIRE_OPCACHE_VALIDATE_TIMESTAMPS=1` for the app container, because your source is a bind mount you edit. Set it to `0` in `.env.squire` to see production behavior locally.

**Releases in place.** With `0`, Apache serves the PHP it compiled before a release until it restarts. That is right for an image that carries its code, or a platform that restarts Apache on a release. Where a release changes files in place (a Citadel Quick Update runs `scripts/release.sh` in the running container), either the platform restarts Apache or the container runs with `SQUIRE_OPCACHE_VALIDATE_TIMESTAMPS=1`. The release script reports which it sees, in its "check opcache" step: a warning after a release with Apache running, a note in the first-release hook, never a failure.

Note

On Citadel the platform's own `php.ini` decides, not this file. Whether a Quick Update restarts Apache is the open Quick Update reset test; until it is answered, ask for `validate_timestamps` on, with `revalidate_freq` above 0, rather than off.

## disable_functions: the web loses the shell[#](https://squire.apps.wayne.edu/docs/guides/image-hardening.html#disable-functions-the-web-loses-the-shell)

A web request should never shell out. `.squire/php-web.ini` holds the settings only the web server gets, and the app image appends it to `php.ini` as `php-apache2handler.ini`, the file mod_php reads instead of `php.ini`. The CLI reads `php.ini` alone, so Composer and its scripts, artisan, queue workers, the health check and the release script keep every function.

| Disabled on the web | Why |
| --- | --- |
| `exec`, `passthru`, `shell_exec`, `system` | Run a shell command: the first thing an injected payload reaches for. |
| `pcntl_exec` | Replaces the PHP process with another program. |
| `phpinfo`, `show_source`, `highlight_file` | Print the server's setup or its source to whoever asked. |

`proc_open` and `popen` stay: Symfony Mailer's sendmail transport, Laravel's `Process` and PHPMailer use them on the web. A test runs PHP on the same two files and checks both sides: the web list is gone, and the CLI and the mail functions are still there.

**The switch.** `SQUIRE_PHP_DISABLE_FUNCTIONS` replaces the list, and `none` (a name no function has) disables nothing: the way out for an older app that calls `exec` from a page while you move that work to a queued job. An empty value is the same as unset, because PHP falls back to the default for both. Locally, put `SQUIRE_PHP_DISABLE_FUNCTIONS=none` in `.env.squire`.

`disable_classes` is not set. It never stopped a caller who could reach another class, and PHP 8.5 removed it.

Note

Like opcache, this is the kit's request: on Citadel the platform's own `php.ini` decides. A local run is stricter than Citadel until the platform adopts the list, which is the safe direction: an app that works locally works there.

## Secrets: files at run time, never in a layer[#](https://squire.apps.wayne.edu/docs/guides/image-hardening.html#secrets-files-at-run-time-never-in-a-layer)

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

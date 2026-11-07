---
title: "Image hardening"
description: "What Squire's images and kit harden, and how to turn each piece down when an app needs it: Apache's banner and TRACE, and production opcache with a dev override."
---

# Image hardening

This page lists what the kit's images harden, where each setting lives, and the switch that turns it down when an app needs that. Every item has a test that reads the file. Read [Local parity](local-parity.md) first for the images themselves.

Two kinds of file carry the hardening. The **base** (`.squire/base-fallback.Dockerfile`) stands in for the Citadel image; what it sets is what Squire asks the real image to set, and the real image keeps deciding on Citadel. The **app image** (`.squire/Dockerfile`) is the local copy of the Dockerfile Citadel generates, and runs on either base.

## Apache: a quieter banner, no TRACE

The base enables `.squire/apache-hardening.conf` as `zz-squire-hardening.conf`, so it loads after Debian's `security.conf`, which ships `ServerTokens OS` and `ServerSignature On`:

| Directive | Effect |
|---|---|
| `ServerTokens Prod` | The `Server` header says `Apache`, not `Apache/2.4.x (Debian)`. |
| `ServerSignature Off` | Error pages Apache writes itself carry no version footer. |
| `TraceEnable Off` | `TRACE` answers 405, so a script cannot read cookies back off an echo. |

These live in the base, never in the app image: locally the app image also runs on the real Citadel image, and hardening it there would show a parity the platform does not have.

## opcache: production settings, a dev override

`.squire/php.ini` turns opcache on for the web (`opcache.enable = 1`, `opcache.enable_cli = 0`) with production values, and the fallback base loads the extension when its image does not already. A value written `${NAME:-default}` reads the container's environment (PHP 8.3 and later), so the same image serves production and a bind mount:

| Setting | Default | Environment variable |
|---|---|---|
| `opcache.validate_timestamps` | `0`: a compiled file is never checked again until Apache restarts | `SQUIRE_OPCACHE_VALIDATE_TIMESTAMPS` |
| `opcache.revalidate_freq` | `0`: when checking is on, every request sees the newest file | none |
| `opcache.memory_consumption` | `128` (megabytes) | `SQUIRE_OPCACHE_MEMORY` |
| `opcache.max_accelerated_files` | `20000` (a Laravel app with its `vendor/` is 10000 to 15000 files) | `SQUIRE_OPCACHE_MAX_FILES` |
| `opcache.interned_strings_buffer` | `16` | none |

**The dev override.** The parity compose file sets `SQUIRE_OPCACHE_VALIDATE_TIMESTAMPS=1` for the app container, because your source is a bind mount you edit. Set it to `0` in `.env.squire` to see production behavior locally.

**Releases in place.** With `0`, Apache serves the PHP it compiled before a release until it restarts. That is right for an image that carries its code, or a platform that restarts Apache on a release. Where a release changes files in place (a Citadel Quick Update runs `scripts/release.sh` in the running container), either the platform restarts Apache or the container runs with `SQUIRE_OPCACHE_VALIDATE_TIMESTAMPS=1`. The release script reports which it sees, in its "check opcache" step: a warning after a release with Apache running, a note in the first-release hook, never a failure.

> [!NOTE]
> On Citadel the platform's own `php.ini` decides, not this file. Whether a Quick Update restarts Apache is the open Quick Update reset test; until it is answered, ask for `validate_timestamps` on, with `revalidate_freq` above 0, rather than off.

---
title: "Image hardening | Squire docs"
description: "What Squire's images and kit harden, and how to turn each piece down when an app needs it: Apache's banner and TRACE."
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

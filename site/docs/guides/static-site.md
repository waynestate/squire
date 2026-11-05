---
title: "Static site | Squire docs"
description: "The static target: committed site files served by Squire's front controller, SQUIRE_STATIC_ROOT, and the Vue/Inertia layer's .npmrc and Node 22 parity service."
url: https://squire.apps.wayne.edu/docs/guides/static-site.html
llms: https://squire.apps.wayne.edu/llms.txt
generator: Squire agent-ready
---

# Static site[#](https://squire.apps.wayne.edu/docs/guides/static-site.html#static-site)

For a site with no backend, the squire does double duty: it writes the serving layer and leaves the site itself to you. This page covers `squire init --target=static`, the `SQUIRE_STATIC_ROOT` setting, and the Vue/Inertia layer that sits on top of the target.

## What the static target writes[#](https://squire.apps.wayne.edu/docs/guides/static-site.html#what-the-static-target-writes)

A static site is a target of its own: `--target=static` writes everything the `raw` target writes, with its own front controller, `public/index.php`, and an `.htaccess` that routes every request that is not a real file to it. The site itself is plain files you commit; the front controller serves them with HELMET's headers, injects the CSP nonce into the HTML, and caches the assets.

## SQUIRE_STATIC_ROOT[#](https://squire.apps.wayne.edu/docs/guides/static-site.html#squire-static-root)

The site folder is `../../site` by default (the app lives in `deploy/` beside it); point `SQUIRE_STATIC_ROOT` at a different folder. `public/up.php` still answers the health check, because a real file is served straight by Apache and never reaches the front controller.

## The Vue/Inertia layer[#](https://squire.apps.wayne.edu/docs/guides/static-site.html#the-vue-inertia-layer)

A Vue/Inertia app is a layer on `raw`, `laravel` or `static`, not a fourth target: `init` detects `vue` in `package.json` (dependencies or devDependencies) and writes the layer's `.npmrc` and its `docker-compose.squire.yml` (with a Node 22 service) beside the target's files.

- `.npmrc` sets `min-release-age=1` for the frontend install.
- The Vue `docker-compose.squire.yml` is the common parity compose plus a Node 22 service for frontend tooling, pinned to the same major as the Citadel image.

Every app, Vue or not, gets the one common `scripts/release.sh`, which installs the frontend from the lock file and runs the `build` script whenever there is a `package.json`.

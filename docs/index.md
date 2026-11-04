---
title: Squire
description: Squire is the Citadel kit: the standard build.sh, release script and health check, and local parity on the real Citadel images.
---

Squire is the Citadel kit: the standard `build.sh`, the release script, the health check, and a local setup that runs your app on the real Citadel images, behind a proxy like Citadel's. The next step is [installing it](install.md), which takes a minute and ends with a doctor check.

## What it does

- `squire init` writes the scripts a Citadel app needs from templates, for raw PHP, Laravel or a static site, and detects a Vue/Inertia layer from `package.json`, adding the layer's release script and Node 22 parity service. `squire init --check` reports when your copies have drifted from the templates (depend, do not copy). In a directory below the repository root (a `deploy/` layer, say), `init` also keeps the `.gitignore` honest: it appends `/vendor/`, `.env` and `.env.squire` when they are missing, never touches a line the app added, and leaves the repository root's `.gitignore` to the app.
- `squire update` takes the new template versions for the files you never edited, and shows a diff for the ones you did. It never overwrites your edits, and it runs the same in CI and on a laptop.
- `squire doctor` checks an app against the best practices a machine can decide. The lock file, composer audit and MySQL 5.6 checks are owned by PROCTOR; `doctor` hands off to it (S50) instead of repeating them.
- A local setup that runs your app on the Citadel PHP image, behind a proxy like Citadel's, with the same MySQL 5.6.
- Squire's maintainers send platform improvement proposals to the platform team privately. Squire never touches a server.

## Where the code lives

The canonical repository is on nougat: [enterprise-apps/squire](https://nougat.snu.gs/enterprise-apps/squire). git.wayne.edu and GitHub ([waynestate/squire](https://github.com/waynestate/squire)) are read-only mirrors of it.
Open issues and merge requests on nougat, not on the mirrors.
This moves to git.wayne.edu once it has CI runners. Your squire serves one banner, and it flies over nougat.

## Its place in the stack

Squire is part of WELD, the Wayne State application stack, beside WayneUI (project 0, the interface layer every other project builds on), CREED, PROCTOR, Preflight, SESAME, HELMET, Pinstripe, Sourdough, Booster, Rollcall, Drawbridge and ADVISOR.

> A knight never suits up alone. Your app shouldn't deploy alone either.

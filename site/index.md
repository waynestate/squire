---
title: "Squire: Suits you up for the Citadel."
description: "Squire is the deploy kit for Citadel. It writes your app's build, release and health-check scripts from templates, and checks them with squire doctor. Running the app on your computer is Parity's job."
url: https://squire.apps.wayne.edu/
llms: https://squire.apps.wayne.edu/llms.txt
generator: Squire agent-ready
---

The Citadel kit

# Squire

Suits you up for the Citadel.

A squire carries the knight's kit. This one carries your app's. `squire init` writes the scripts Citadel runs when you deploy and `squire doctor` checks them. To run your app on your laptop on the same PHP image and MySQL 5.6 as Citadel, behind the same kind of proxy, Squire rides with [Parity](https://parity.apps.wayne.edu/), which took that job over in Squire 0.12.0.

`$ composer require waynestate/squire --dev`

Already packed into every [Sourdough](https://sourdough.apps.wayne.edu/) app, and [WELD](https://weld.apps.wayne.edu/) deploys to Citadel through it. Use Squire on its own to bring an app you already have onto Citadel.

[See the packing list](https://squire.apps.wayne.edu/#kit) [Meet the party](https://squire.apps.wayne.edu/#party)

### Get started with Squire

Pack your app for the Citadel; Parity lets you try it at home first.

A prompt for your coding agent. Copy it as it stands:

```text
You are a coding agent. Read https://squire.apps.wayne.edu/llms.txt and ask ADVISOR.
Install it with `composer require waynestate/squire --dev`, then run `vendor/bin/squire init` at the root of your app.
Ask me before you guess.
```

vendor/bin/squire init

## The packing list for deploy day

`squire init` writes the files Citadel needs from templates, for raw PHP, Laravel or a static site, and adds a Vue layer when `package.json` has Vue. It records a SHA-256 of each file in `.squire.json`. A file you edited is never written over unless you pass `--force`.

1. `build.sh` Citadel's build hook. System packages only, POSIX sh, no Composer or npm (S1).
2. `deployments/001-first-release.sh` Runs once per app and calls the release with the smoke test off; Apache is not up yet.
3. `scripts/release.sh` The idempotent release: install from the lock file, audit, ask PROCTOR's gate for a go or no-go, build, migrate, smoke-test `/up`. Also your Quick Update post-script.
4. `health.php` Citadel's health check: one request to `/up`, never boots the framework.
5. `oracle-check.php` The plain-language Oracle connection check the release runs: settings, host, port, driver, login. A report, never a gate. `.gitattributes` rides in the same pocket and keeps every script LF.

![Squire's satchel](https://squire.apps.wayne.edu/brand/squire-avatar-512.png)

**To** the Citadel **From** my-app **Holds** 6 files

5 of 5 packed, buckled for deploy day

**Try the other commands**

~/my-app

$ vendor/bin/squire init

created build.sh (written from the template)

created deployments/001-first-release.sh (written from the template)

created scripts/release.sh (written from the template)

created health.php (written from the template)

created oracle-check.php (written from the template)

created .gitattributes (written from the template)

Target: laravel. Wrote .squire.json.

Next: git update-index --chmod=+x build.sh deployments/001-first-release.sh scripts/release.sh

Run it locally with Parity: parity init, then parity up (https://git.wayne.edu/enterprise-apps/parity).

Every shell script runs with `set -euo pipefail`, prints a `[squire] ==> step N` marker, and gives the same result when you run it twice. Building images too? `--only=release,images` adds the opt-in image files: the Citadel mirror, the fallback base and the portable image for OpenShift, Coolify and raw Docker.

vendor/bin/squire doctor

## The squire inspects the armor

Doctor reads your repository and checks the rules a machine can decide. It doesn't run your app or touch a server. A fail exits 1 and a warning or info note doesn't, so if you want doctor to block a merge, run it in CI.

fail exits 1 warn reported info noted

- S1`build.sh` runs no Composer or npm.
- S3Every script has `set -e`.
- S4No download piped into a shell.
- S8No CRLF in shell scripts.
- S9Build, first release and release are three jobs.
- S12Install from the lock file; never update.
- S14The release runs `proctor gate` or `proctor check`.
- S15`auth.json` stays out of git.
- S16Laravel runs `optimize`.
- S17Migrations run and stay old-code friendly.
- S19Refuses to run as root.
- S20PROCTOR is a report, never a bare command.
- S21The release requests `/up` at the end.
- S22One marker per step.
- S35No parity kit left over: use Parity.
- S42`.env` files stay out of git.
- S50Lock file, audit and MySQL 5.6 are PROCTOR's: run `proctor check`.
- S55The health check never boots the framework.
- S57npm installs with `--ignore-scripts`.
- S78A Vue app keeps `node_modules/` out of git.

Every rule, its level and why it matters: `BEST_PRACTICES.md`. Drift from the templates is a warning under S9, never a failure; your copies are yours to edit.

~/my-app excerpt

$ vendor/bin/squire doctor

PASS S1 build.sh runs no composer or npm commands. \[build.sh\]

PASS S12 \`composer install --no-dev\` from the lock file.

PASS S14 The release script runs PROCTOR (proctor gate or proctor check).

PASS S20 PROCTOR runs as a report and cannot stop the release.

PASS S55 health.php is an artisan-free health check. \[health.php\]

PASS S35 No parity kit files left over. Parity runs the app on your computer: https://git.wayne.edu/enterprise-apps/parity

WARN S8 .gitattributes does not force LF for shell scripts. Add \`\*.sh text eol=lf\`, and \`\*.conf text eol=lf\`, \`Dockerfile text eol=lf\` and \`\*.Dockerfile text eol=lf\`, which PROCTOR checks too.

RESULT: pass (0 fail, 1 warn, 0 info, 6 pass)

Run it locally

## Practice on the real field

[Parity](https://parity.apps.wayne.edu/) runs your app on Citadel's own PHP image, behind the same HAProxy that adds the forwarding headers, with MySQL 5.6.51 beside it. If something would only break on Citadel, you should see it on your laptop first.

Squire handed this job on

### Parity runs Citadel on your computer

Parity is the stack's own tool for this: one program for Windows, macOS and Linux, for any app, with or without Squire, on Docker or Podman (Podman recommended). Squire dresses the knight for the castle; Parity builds a practice castle in your yard.

Squire's own parity kit left in 0.12.0. An app that still has it runs `parity init --from-squire`, then deletes the kit's files. `squire update` lists them and never deletes them; [Moved to Parity](https://squire.apps.wayne.edu/docs/guides/local-parity.html) has the four commands.

1. Your browser on localhost
2. HAProxy adds X-Forwarded-For and -Proto
3. Your app Citadel PHP image, run as `citadel`
4. MySQL 5.6.51 `mysql` inside, `127.0.0.1:3307` outside

**Linux and macOS**

Docker or Podman. Install Parity once, then in your app's folder:

```
curl -fsSL https://parity.apps.wayne.edu/install.sh | sh
parity init                            # or: parity init --from-squire
parity up
```

**Windows**

Podman Desktop or Docker Desktop, in PowerShell. Install Parity once, then in your app's folder:

```
irm https://parity.apps.wayne.edu/install.ps1 | iex
parity init                            # or: parity init --from-squire
parity up
```

### Commands

- `check`
- `init`
- `image get`
- `up`
- `test`
- `down`

`check` tells you what to fix on this computer, one finding per line. `image get` downloads Citadel's image and checks it is Citadel's, byte for byte. `down` stops the app and keeps your data.

Building the images too? Squire's opt-in `images` group still writes the Citadel mirror, the fallback base and the portable image. Parity runs Citadel's own image and nothing else.

Citadel setup

## Onto the field

Squire itself runs on your machine and in CI. Only the scripts it wrote go to Citadel.

1. ### Point the dashboard at the kit
   Build script `build.sh`, health check `["php","health.php"]`, Quick Update post-script `./scripts/release.sh` as user `citadel`.
2. ### Commit the lock files
   `composer.lock`, and `package-lock.json` or `pnpm-lock.yaml` if you have a frontend. The release installs from them and never runs an update.
3. ### Mark the scripts executable
   Git on Windows forgets: `git update-index --chmod=+x build.sh scripts/*.sh deployments/*.sh`
4. ### Settings in the configuration file
   Set `SQUIRE_*` values in the app's `./.env` configuration file, never in a file in the repository. The dashboard has no environment field; its configuration-files step, after the wizard, writes `./.env` into the source tree. A Laravel app reads it already; a raw PHP app loads it with `vlucas/phpdotenv`. All of them are optional.

- **`0`**: pass
- **`1`**: findings: drift for `init --check`, a failed rule for `doctor`
- **`2`**: the tool itself could not run

Squire's own repository rides along: `php bin/docs-site.php` turns `docs/` into the committed pages under `site/docs/`, renders GitHub alert callouts (`[!NOTE]`, `[!TIP]`, `[!WARNING]` and `[!DANGER]`) as labeled asides, groups consecutive `tab="..."` code fences into labeled code tabs that read without JavaScript, generates a changelog page from `CHANGELOG.md` when the nav lists one, and lets a site theme its docs with a header partial and a stylesheet through a `theme` key in `docs/docs.json`. The kit's `docs.css` styles the pages in light and dark themes (an explicit reader choice first, then the system setting), with every text pair at WCAG AA contrast in both.

The platform

## Letters for the castle

A good squire carries messages too. When we find something Citadel could do better, we write it up and send it to the platform team privately. Squire never touches a server, and none of its scripts wait on a proposal.

Its place in WELD

## Every knight rides with a party

Squire is one part of WELD, the Wayne State application stack. It rides last. The parts ahead of it handle the headers, sign-in, the interface, the checks and CI, and Squire takes the finished app to Citadel.

1. **[HELMET](https://helmet.apps.wayne.edu/)** sets security headers
2. **[SESAME](https://sesame.apps.wayne.edu/)** signs the user in
3. **[WayneUI](https://wayneui.apps.wayne.edu/)** renders the page
4. **[PROCTOR](https://proctor.apps.wayne.edu/)** checks the code
5. **[Preflight](https://preflight.apps.wayne.edu/)** runs CI
6. **Squire** deploys you are here

### Also in the stack

- **[CREED](https://creed.apps.wayne.edu/)** the standards and `policy.json`
- **[Pinstripe](https://pinstripe.apps.wayne.edu/)** the coding standard
- **[Sourdough](https://sourdough.apps.wayne.edu/)** starters you feed
- **[WELD](https://weld.apps.wayne.edu/)** every part, joined

### Around your app

- **[Booster](https://booster.apps.wayne.edu/)** gets you onto a supported PHP
- **[Rollcall](https://rollcall.apps.wayne.edu/)** every app answers “present”
- **[ADVISOR](https://advisor.apps.wayne.edu/)** what applies, and what next
- **[Drawbridge](https://drawbridge.apps.wayne.edu/)** the Citadel sign-in gateway
- **Squire** the Citadel kit
- **[Parity](https://parity.apps.wayne.edu/)** Citadel on your computer

Known limits

## Scuffs on the leather

Squire 0.12.2 is tagged and on Packagist. Where we had to guess how Citadel behaves, `OPEN_QUESTIONS.md` lists each guess and the default it chose.

- The images group's `php.ini` holds the safe defaults HELMET also enforces at runtime, not the platform's file.
- Old parity kit files are listed, never deleted. Once Parity runs the app, delete them yourself; doctor notes them under S35 until you do.
- A full Update may not refresh `vendor/`. Use `scripts/release.sh` as the Quick Update post-script too.
- The release asks `proctor gate` for a go or no-go. A no-go is a report by default; set `SQUIRE_PROCTOR=enforce` and it stops the release before the build and the migrations.

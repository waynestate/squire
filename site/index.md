---
title: "Squire: Suits you up for the Citadel."
description: "Squire is the deploy kit for Citadel. It writes your app's build, release and health-check scripts from templates, checks them with squire doctor, and runs the app locally on Citadel's PHP image behind the same kind of proxy."
url: https://squire.apps.wayne.edu/
llms: https://squire.apps.wayne.edu/llms.txt
generator: Squire agent-ready
---

The Citadel kit

# Squire

Suits you up for the Citadel.

A squire carries the knight's kit. This one carries your app's. `squire init` writes the scripts Citadel runs when you deploy and `squire doctor` checks them. The parity setup runs your app on your laptop on the same PHP image and MySQL 5.6 as Citadel, behind the same kind of proxy.

`$ composer require waynestate/squire --dev`

Already packed into every [Sourdough](https://sourdough.apps.wayne.edu/) app, and [WELD](https://weld.apps.wayne.edu/) deploys to Citadel through it. Use Squire on its own to bring an app you already have onto Citadel.

[See the packing list](https://squire.apps.wayne.edu/#kit) [Meet the party](https://squire.apps.wayne.edu/#party)

### Get started with Squire

Pack your app for the Citadel and try it at home first.

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
3. `scripts/release.sh` The idempotent release: install from the lock file, audit, build, migrate, PROCTOR as a report, smoke-test `/up`. Also your Quick Update post-script.
4. `health.php` Citadel's health check: one request to `/up`, never boots the framework.
5. `The parity kit 12 files` `docker-compose.squire.yml`, `.squire/`, `.env.squire.example`, `scripts/parity.sh` and `scripts/parity.ps1`: Citadel, on your machine.

![Squire's satchel](https://squire.apps.wayne.edu/brand/squire-avatar-512.png)

**To** the Citadel **From** my-app **Holds** 16 files

5 of 5 packed, buckled for deploy day

**Try the other commands**

~/my-app

$ vendor/bin/squire init

created build.sh (written from the template)

created deployments/001-first-release.sh (written from the template)

created scripts/release.sh (written from the template)

created health.php (written from the template)

created docker-compose.squire.yml (written from the template)

created .squire/Dockerfile (written from the template)

created .squire/Dockerfile.dockerignore (written from the template)

created .squire/base-fallback.Dockerfile (written from the template)

created .squire/fallback/citadel-apache-entrypoint (written from the template)

created .squire/fallback/citadel-deploy (written from the template)

created .squire/haproxy.cfg (written from the template)

created .squire/php.ini (written from the template)

created .squire/vhost.conf (written from the template)

created scripts/parity.sh (written from the template)

created scripts/parity.ps1 (written from the template)

created .env.squire.example (written from the template)

Target: laravel. Wrote .squire.json.

Every shell script runs with `set -euo pipefail`, prints a `[squire] ==> step N` marker, and gives the same result when you run it twice. Pack half the bag with `--only=release` or `--only=parity`.

vendor/bin/squire doctor

## The squire inspects the armour

Doctor reads your repository and checks the rules a machine can decide. It doesn't run your app or touch a server. A fail exits 1 and a warning or info note doesn't, so if you want doctor to block a merge, run it in CI.

fail exits 1 warn reported info noted

- S1`build.sh` runs no Composer or npm.
- S3Every script has `set -e`.
- S4No download piped into a shell.
- S8No CRLF in shell scripts.
- S9Build, first release and release are three jobs.
- S12Install from the lock file; never update.
- S14The release runs `proctor check`.
- S15`auth.json` stays out of git.
- S16Laravel runs `optimize`.
- S17Migrations run and stay old-code friendly.
- S19Refuses to run as root.
- S20PROCTOR is a report, never a bare command.
- S21The release requests `/up` at the end.
- S22One marker per step.
- S35Locally, the Citadel PHP image.
- S36A forwarding proxy in front.
- S37Locally, MySQL 5.6.
- S41The Docker socket is not mounted.
- S42`.env` files stay out of git.
- S50Lock file, audit and MySQL 5.6 are PROCTOR's: run `proctor check`.
- S55The health check never boots the framework.
- S57npm installs with `--ignore-scripts`.
- S67Images pinned by digest.
- S70Containers drop capabilities.
- S71Citadel's health-check timing.

Every rule, its level and why it matters: `BEST_PRACTICES.md`. Drift from the templates is a warning under S9, never a failure; your copies are yours to edit.

~/my-app excerpt

$ vendor/bin/squire doctor

PASS S1 build.sh runs no composer or npm commands. \[build.sh\]

PASS S12 \`composer install --no-dev\` from the lock file.

PASS S14 The release script runs proctor check.

PASS S20 PROCTOR runs as a report and cannot stop the release.

PASS S55 health.php is an artisan-free health check. \[health.php\]

WARN S8 .gitattributes does not force LF for shell scripts. Add \`\*.sh text eol=lf\`.

WARN S67 Not pinned by digest: citadel/php-apache:8.4r5. Run \`scripts/parity.sh pin\` and put the digests in .env.squire.

RESULT: pass (0 fail, 2 warn, 0 info, 5 pass)

Local parity

## Practise on the real field

Parity runs your app on the Citadel PHP image, behind an HA-Proxy that adds the same forwarding headers, with MySQL 5.6.51 beside it. If something would only break on Citadel, you should see it on your laptop first.

1. Your browser on localhost
2. HA-Proxy adds X-Forwarded-For and -Proto
3. Your app Citadel PHP image, run as `citadel`
4. MySQL 5.6.51 `mysql` inside, `127.0.0.1:3307` outside

**Linux and macOS**

Docker with Compose v2.

```
cp .env.squire.example .env.squire     # then set the passwords
scripts/parity.sh up                   # or: scripts/parity.sh up --fallback
```

**Windows**

Docker Desktop with the WSL 2 backend. PowerShell 5.1 or 7; no other shell needed.

```
Copy-Item .env.squire.example .env.squire
scripts\parity.ps1 up                  # or: scripts\parity.ps1 up -Fallback
```

### Commands

- `up`
- `down`
- `reset`
- `logs`
- `shell`
- `status`
- `pin`

`pin` prints the digests of the images you pulled, so you can pin them. `reset` also removes the volumes.

Containers drop capabilities, set `no-new-privileges` and carry memory and process limits; the database listens on loopback only. The compose file refuses to start without real passwords.

No access to the real image? `--fallback` builds an approximation from the official PHP image plus Citadel's extensions. It's fine for a practice bout. It doesn't prove parity.

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

Known limits

## Scuffs on the leather

Squire 0.3.3 is tagged and on Packagist. Where we had to guess how Citadel behaves, `OPEN_QUESTIONS.md` lists each guess and the default it chose.

- The local `php.ini` holds the safe defaults HELMET also enforces at runtime, not the platform's file.
- The Citadel image has no registry digest to pin yet; doctor warns under S67 until you run `pin`.
- A full Update may not refresh `vendor/`. Use `scripts/release.sh` as the Quick Update post-script too.
- PROCTOR reports during a release by default; set `SQUIRE_PROCTOR=enforce` and a failed check stops it.

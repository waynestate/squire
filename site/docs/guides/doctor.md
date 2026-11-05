---
title: "Doctor | Squire docs"
description: "What squire doctor checks (the S-rules), its handoff to PROCTOR, its text and json output, and its exit codes."
url: https://squire.apps.wayne.edu/docs/guides/doctor.html
llms: https://squire.apps.wayne.edu/llms.txt
generator: Squire agent-ready
---

# Doctor[#](https://squire.apps.wayne.edu/docs/guides/doctor.html#doctor)

This page explains what `squire doctor` checks (the S-rules), how it hands off to PROCTOR, and how to read its output and exit codes. Next, read [Update and drift](https://squire.apps.wayne.edu/docs/guides/update-and-drift.html) to fix the template drift doctor reports.

## What it checks[#](https://squire.apps.wayne.edu/docs/guides/doctor.html#what-it-checks)

`squire doctor` checks an app against the best practices a machine can decide. It decides the rules below by reading the files in your repository. It never runs your app and never touches a server. A **fail** makes `doctor` exit 1; a **warn** or **info** does not. The numbers (S1 and so on) are the rule numbers from the WELD Citadel best-practices list; the list has more rules than a machine can decide, and those are not here.

Note

`squire doctor` checks deploy setup only; standards checks are PROCTOR's.

## The S-rules[#](https://squire.apps.wayne.edu/docs/guides/doctor.html#the-s-rules)

| Rule | Level | What it checks | Why |
| --- | --- | --- | --- |
| S1 | fail | `build.sh` runs no `composer` or `npm` commands. | Citadel runs `build.sh` as root with only that file copied in; the source is not there. |
| S3 | warn | Every shell script has `set -e`; bash scripts also have `pipefail`. | A failing step must stop the script. |
| S4 | fail | No download is piped into a shell. | Pin a version and verify a checksum instead. |
| S8 | fail / warn | No CRLF in shell scripts (fail); `.gitattributes` forces LF for `*.sh` (warn). | CRLF breaks a script on Linux; Git on Windows adds it. |
| S9 | fail / warn | `scripts/release.sh` exists (fail); `build.sh` and a `deployments/` hook exist (warn); files match the template version (warn). | Build tooling, the one-time hook and the release script are three jobs. |
| S12 | fail | The release runs `composer install --no-dev`, never `composer update`, and uses `npm ci` or a frozen-lockfile install. | The lock file is the release contract. |
| S14 | warn | The release runs `proctor check`. | PROCTOR runs `composer audit` and the other app checks, so known-vulnerable dependencies show up in the deploy log. |
| S15 | fail / warn | `auth.json` is git-ignored (fail); Composer runs non-interactively (warn). | Credentials stay out of git; Citadel has no terminal to prompt on. |
| S16 | warn | Laravel: `php artisan optimize` runs in the release. | Config, route and view caches. |
| S17 | info | Laravel: `migrate --force` runs, or something else runs migrations. | Keep migrations compatible with the old code (parallel change). |
| S19 | warn | The release refuses to run as root; no `sudo` in scripts. | Files created as root cannot be written by the web process. |
| S20 | fail / warn | PROCTOR runs as a report (the command is followed by an OR branch that records the status), never as a bare command. | After a Quick Update the new commit is already live; a failing post-script cannot undo it. |
| S21 | warn | The release requests `/up` at the end. | A release that cannot answer a request did not ship. |
| S22 | warn | The release prints a marker per step. | The dashboard log shows where it stopped. |
| S35 | warn | The compose file uses a `citadel/php-apache` image. | Same PHP and extensions as production. |
| S36 | warn | A proxy that adds `X-Forwarded-For` and `X-Forwarded-Proto` is in front of the app. | Trusted-proxy and HTTPS detection bugs only show behind one. |
| S37 | warn | The local database is MySQL 5.6. | Its limits then show up locally. |
| S41 | fail | The Docker socket is not mounted. | It is root on the host. |
| S42 | warn | `.env` and `.env.squire` are git-ignored. | Secrets stay out of git. |
| S50 | info | Hand-off: points at `proctor check` for the lock file, composer audit and MySQL 5.6 checks. | Each check has one owner; PROCTOR owns those, and `doctor` may run where PROCTOR is not installed. |
| S55 | fail / warn | A health check that does not run `artisan` or boot the framework exists. | Citadel runs it every 5 minutes as root. |
| S57 | warn | JavaScript packages install with `--ignore-scripts`; `.npmrc` sets `min-release-age`. | A compromised package cannot run at install time, and a version published an hour ago is not installed. |
| S67 | warn | Every image in the compose file is pinned by digest. | The same bytes tomorrow as today. |
| S70 | warn | Local containers drop capabilities and set `no-new-privileges`. | Least privilege, also locally. |
| S71 | warn | The local health check uses Citadel's timing (5 minutes, 15 seconds). | A slow `/up` shows up before it ships. |
| S78 | warn | A Vue app does not track `node_modules/` in git. | Dependencies are rebuilt from the lock file; the tree is not source. |

## Handoff to PROCTOR (S50)[#](https://squire.apps.wayne.edu/docs/guides/doctor.html#handoff-to-proctor-s50)

The lock file (S13), composer audit and MySQL 5.6 safety (S18) are owned by PROCTOR; `doctor` no longer repeats them and hands off instead (S50). Each check has one owner; PROCTOR owns those, and `doctor` may run where PROCTOR is not installed.

Drift from the Squire templates is reported as a warning under S9, never as a failure: you may edit your copies.

`doctor` is a lint, not a gate. Wire it into CI if you want it to block; Squire does not decide that for you.

## Output[#](https://squire.apps.wayne.edu/docs/guides/doctor.html#output)

`doctor` prints a text report by default, or a machine-readable one with `--format=json`.

```
vendor/bin/squire doctor [--path=.] [--format=text|json]
```

## Exit codes[#](https://squire.apps.wayne.edu/docs/guides/doctor.html#exit-codes)

Exit codes: `0` pass, `1` findings (`init --check`: drift; `update`: an edited file still differs from the template; `doctor`: a failed rule), `2` the tool itself could not run.

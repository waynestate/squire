---
title: Install
description: Install Squire as a dev dependency, write your app's deploy scripts, and verify them with squire doctor.
---

# Install

## Requirements

PHP `^8.3`, tested on 8.3, 8.4 and 8.5. The package runs on your machine or in CI, not on Citadel; what lands on Citadel is the scripts it wrote.

## Install

```
composer require waynestate/squire --dev
vendor/bin/squire init
vendor/bin/squire doctor
```

## Verify

`vendor/bin/squire doctor` checks the app against the best practices a machine can decide. Exit codes: `0` pass, `1` findings (`init --check`: drift; `update`: an edited file still differs from the template; `doctor`: a failed rule), `2` the tool itself could not run.

## Update notice

After any command finishes, Squire prints one line on stderr when a newer stable release exists, with the command that updates this install, for example `squire 0.11.1 is out of date: 0.11.2 is the newest release. Update: composer update waynestate/squire`. The newest release comes from Packagist, checked at most once a day per machine; the answer sits in the user cache dir as `waynestate/squire/update-check.json`, so an offline machine stays quiet and tries again the next day. Nothing is printed when `CI` is set to anything other than empty, `0` or `false`, when `WAYNE_NO_UPDATE_CHECK=1`, when the command runs with `--quiet` or `-q`, or when the running copy is not a plain release. Stdout and exit codes are never touched.

Next: the [command reference](reference/commands.md).

---
title: Commands
description: Every squire command with its usage, arguments and options, plus the exit codes to expect.
---

The block below is owned by the docs builder: it lists every command the CLI keeps, with its usage, arguments and options, and is rewritten from the repository on every build.

<!-- generated:commands -->
<!-- /generated -->

## Exit codes

`0` pass, `1` findings (`init --check`: drift; `update`: an edited file still differs from the template; `doctor`: a failed rule), `2` the tool itself could not run.

## doctor oracle

`vendor/bin/squire doctor oracle` runs the same plain-language Oracle connection check that `release.sh` runs on Citadel, from your terminal or `sail php vendor/bin/squire doctor oracle`. It reads the `ORACLE_*` settings from the environment and the app's `.env`, prints setting names and never values, and stays silent when the app has no Oracle settings. Add `-v` for the client version.

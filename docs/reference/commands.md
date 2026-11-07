---
title: Commands
description: Every squire command with its usage, arguments and options, plus the exit codes to expect.
---

# Commands

The block below is owned by the docs builder: it lists every command the CLI keeps, with its usage, arguments and options, and is rewritten from the repository on every build.

<!-- generated:commands -->
| Command | What it does |
| --- | --- |
| `squire doctor` | Check an app's Citadel release setup against the SQUIRE rules (S1 to S78). |
| `squire init` | Write the standard Citadel scripts into an app, or report drift with --check. |
| `squire update` | Take new template versions for files SQUIRE wrote that were never edited, add the files a newer release wrote, and show a diff for the edited ones. |

### `squire doctor`

Check an app's Citadel release setup against the SQUIRE rules (S1 to S78).

Usage: `squire doctor [--path PATH] [--format FORMAT] [--] [<topic>]`

| Argument | Required | Meaning |
| --- | --- | --- |
| `topic` | no | Optional topic. Use `oracle` to check the Oracle connection settings. |

| Option | Meaning | Default |
| --- | --- | --- |
| `--path=VALUE` | Path to the application. | `.` |
| `--format=VALUE` | Output format: text or json. | `text` |
| `--silent` | Do not output any message |  |

### `squire init`

Write the standard Citadel scripts into an app, or report drift with --check.

Usage: `squire init [--path PATH] [--target TARGET] [--only ONLY] [--check] [--force]`

| Option | Meaning | Default |
| --- | --- | --- |
| `--path=VALUE` | Path to the application. | `.` |
| `--target=VALUE` | raw, laravel or static (default: the target recorded in .squire.json, else laravel when an artisan file exists; static must be asked for). |  |
| `--only=VALUE` | Comma-separated groups to write: release, parity. (default: the groups recorded in .squire.json, else release, parity) |  |
| `--check` | Write nothing; report files that are missing, outdated or edited. Exit 1 on drift. |  |
| `--force` | Overwrite files that were edited or that SQUIRE did not write. |  |
| `--silent` | Do not output any message |  |

### `squire update`

Take new template versions for files SQUIRE wrote that were never edited, add the files a newer release wrote, and show a diff for the edited ones.

Usage: `squire update [--path PATH] [--target TARGET] [--only ONLY] [--dry-run]`

| Option | Meaning | Default |
| --- | --- | --- |
| `--path=VALUE` | Path to the application. | `.` |
| `--target=VALUE` | raw, laravel or static (default: the target recorded in .squire.json). |  |
| `--only=VALUE` | Comma-separated groups to update: release, parity. (default: the groups recorded in .squire.json) |  |
| `--dry-run` | Print the plan; write nothing. |  |
| `--silent` | Do not output any message |  |

### Options every command accepts

| Option | Meaning | Default |
| --- | --- | --- |
| `-h`, `--help` | Display help for the given command. When no command is given display help for the list command |  |
| `-q`, `--quiet` | Only errors are displayed. All other output is suppressed |  |
| `-v|-vv|-vvv`, `--verbose` | Increase the verbosity of messages: 1 for normal output, 2 for more verbose output and 3 for debug |  |
| `-V`, `--version` | Display this application version |  |
| `--ansi` | Force (or disable --no-ansi) ANSI output |  |
| `--no-ansi` | Negate the "--ansi" option |  |
| `-n`, `--no-interaction` | Do not ask any interactive question |  |
<!-- /generated -->

## Exit codes

`0` pass, `1` findings (`init --check`: drift; `update`: an edited file still differs from the template; `doctor`: a failed rule), `2` the tool itself could not run.

## doctor oracle

`vendor/bin/squire doctor oracle` runs the same plain-language Oracle connection check that `release.sh` runs on Citadel, from your terminal or `sail php vendor/bin/squire doctor oracle`. It reads the `ORACLE_*` settings from the environment and the app's `.env`, prints setting names and never values, and stays silent when the app has no Oracle settings. Add `-v` for the client version.

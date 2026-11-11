---
title: "The docs builder | Squire docs"
description: "How bin/docs-site.php turns docs/ into committed HTML under site/docs/, with generated blocks, a changelog page, callouts, task lists, what the check proves, tested examples and JavaScript tests."
url: https://squire.apps.wayne.edu/docs/guides/docs-builder.html
llms: https://squire.apps.wayne.edu/llms.txt
generator: Squire agent-ready
---

# The docs builder [#](https://squire.apps.wayne.edu/docs/guides/docs-builder.html#the-docs-builder)

For maintainers of other stack repos: this page explains how Squire's docs builder turns Markdown into committed, accessible pages, and how to adopt it. Next, see [The agent-ready kit](https://squire.apps.wayne.edu/docs/guides/agent-ready-kit.html) for the files that style the result.

## How the build works [#](https://squire.apps.wayne.edu/docs/guides/docs-builder.html#how-the-build-works)

`bin/docs-site.php` turns the Markdown under `docs/` into committed, accessible HTML pages under `site/docs/`: one page per file, in the order of `docs/nav.json`, with a sidebar, an "On this page" list, prev and next links, and the version stamped from the top of `CHANGELOG.md`. The optional `source` and `mirrors` keys in `docs/nav.json` render a Source link in the header and footer, with the mirrors beside it, so the canonical repo home is one config value, never a hard-coded URL. Pages are styled by WayneUI plus the kit's `docs.css` and `docs.js` in the site's `assets/` folder, so every stack repo reads the same way. The same stamp lands on the front page: any element in a top-level `site/*.html` page that carries `data-docs-version` gets it as its text, and `--check` reports that page stale after a release (see [Theming your docs](https://squire.apps.wayne.edu/docs/guides/theming.html)). `docs.js` adds a copy button to each code block; it says Copied or Failed where there is room, and over the code it keeps its resting width and shows a check or a cross instead, so it never covers a line. Tables fit their column: each body cell carries its column's header in `data-label`, so a table of three or more columns becomes one card per row when the table is 560 px wide or less (a container query, so a tablet beside the sidebar gets cards too), and a long code token in a cell gets a `<wbr>` at its slashes, underscores, commas, colons and dots before a letter (a digest, every 16 characters) so it wraps at a seam; the first column of a wider table keeps its names whole. A table that still cannot fit, on the narrowest phones, scrolls inside a keyboard-focusable region.

Squire owns the kit, and a repo takes a new version from a Squire checkout with `php bin/docs-site.php --sync-to=<repo>`, which copies the kit and the tool itself. The repo then builds with `php bin/docs-site.php` (or `composer docs`) and commits `site/docs/`, and runs `--check` (or `composer docs:check`) in CI to fail when a page is stale or a finding is present. A squire keeps the map current, so the knight never asks for directions twice.

```bash
php bin/docs-site.php --sync-to=<repo>   # copy the kit and the tool into the repo
php bin/docs-site.php                    # build (or: composer docs)
php bin/docs-site.php --check            # verify in CI (or: composer docs:check)
```

## Generated blocks [#](https://squire.apps.wayne.edu/docs/guides/docs-builder.html#generated-blocks)

A page may hold a region the builder owns, marked in the Markdown source:

```text
<!-- generated:commands -->
...whatever the builder wrote last time...
<!-- /generated -->
```

`docs/docs.json` defines the blocks by name. Each block rewrites its region from the repo's own state: `json-table` and `symfony-commands` run a command from the repo root (a leading `php ` means the PHP binary, so the same file works on Windows, macOS and Linux) and render the command's JSON output, and `env` scans the source files for the environment variables the code reads.

### The json-table type [#](https://squire.apps.wayne.edu/docs/guides/docs-builder.html#the-json-table-type)

The `json-table` type runs its `run` command, takes the list at its `path` key from the JSON output, and renders a Markdown table: each column is `[heading, key]` or `[heading, key, "code"]` for a code span, a missing key gives an empty cell, booleans print `yes` or `no`, lists print comma separated, Symfony console style tags are stripped so a help cell reads plain, and pipes in a cell are escaped.

```json
{
    "blocks": {
        "hops": {
            "type": "json-table",
            "run": "php bin/booster hops --format=json",
            "path": "hops",
            "columns": [["Hop", "id", "code"], ["What it does", "summary"]]
        }
    }
}
```

### The symfony-commands type [#](https://squire.apps.wayne.edu/docs/guides/docs-builder.html#the-symfony-commands-type)

The `symfony-commands` type runs `<run> list --format=json` and renders the command reference: an overview table of command and description, then per command a heading at `level` (default 3), its description, one `Usage:` line per usage string, an Arguments table when it has arguments, and an Options table when it has options. An option prints its shortcut first (`-p`, `--path=VALUE`) and gets `=VALUE` only when it takes a value; its default prints in a code span, or stays empty when it is false. The built-in commands (`help`, `list`, `completion`, `_complete`) and hidden commands are skipped, and the application-level options (`--help`, `--quiet`, `--verbose`, `--version`, `--ansi`, `--no-ansi`, `--no-interaction`) are listed once, under "Options every command accepts".

```json
{
    "blocks": {
        "commands": {
            "type": "symfony-commands",
            "run": "php bin/console",
            "cli": "bin/console",
            "level": 3
        }
    }
}
```

### The env type [#](https://squire.apps.wayne.edu/docs/guides/docs-builder.html#the-env-type)

The `env` type walks the `scan` folders for quoted environment variable names that start with `prefix`, skips the names in `ignore` (an exact name, or a trailing `*` for a prefix), and renders one row per variable, sorted by name: its meaning and default from `describe` (a `[meaning, default]` pair) and the first file that reads it. A variable the code reads that `describe` does not list, and a variable `describe` lists that no scanned file reads, are findings.

```json
{
    "blocks": {
        "environment": {
            "type": "env",
            "scan": ["src", "config"],
            "prefix": "APP_",
            "ignore": ["APP_KEY"],
            "describe": {
                "APP_NAME": ["The app name", "Demo"],
                "APP_ENV": ["The environment", "production"]
            }
        }
    }
}
```

A plain run rewrites stale regions in the sources and then builds; `--check` reports a stale region instead of writing. A region whose name is not in `docs.json`, a block no page places, a block placed twice, and an opening marker with no closing marker are findings.

## Changelog page [#](https://squire.apps.wayne.edu/docs/guides/docs-builder.html#changelog-page)

When `docs/nav.json` lists a page whose path is `changelog`, the builder owns `docs/changelog.md`: a plain run writes it from the repo's `CHANGELOG.md` before building, and `--check` never writes. The page takes the usual front matter (`title: "Changelog"` and a description), a marker comment naming its source, and the changelog's body with its top-level heading dropped, so every version heading stays as written: `## Unreleased`, `## [Unreleased]`, `## 0.5.0 (2026-11-03)` and `## [0.1.9] - 2026-11-03` all keep their shape and get the page's usual anchors. Links follow the site's rules: an `http(s)` link stays, a relative link becomes a blob link under the nav.json `source` when there is one, and loses its link, keeping its label, when there is not. The blob path follows the source's host: a GitHub source (`github.com` or `www.github.com`) gets `/blob/main/` (`https://github.com/acme/app/blob/main/README.md`) and any other host keeps GitLab's `/-/blob/main/` (`https://repo.example.com/acme/app/-/blob/main/README.md`). Links inside a code span or a fenced code block are left as written. A nav entry without a `CHANGELOG.md` is a finding, and a changed `CHANGELOG.md` or a missing `docs/changelog.md` is drift.

## Callouts [#](https://squire.apps.wayne.edu/docs/guides/docs-builder.html#callouts)

A blockquote whose first line is a marker alone becomes a callout instead of a quotation:

```text
> [!NOTE]
> The body keeps the note.
```

The marker is case-insensitive and one of `[!NOTE]`, `[!TIP]`, `[!WARNING]` or `[!DANGER]`. The builder renders an `<aside>` with the classes `docs-callout` and `docs-callout--note` (one per kind), `role="note"`, a visible label, and the body below it, and drops the marker line from the body. A blockquote without a marker, or with a marker that is not one of the four, stays a plain blockquote. The kit's `docs.css` styles all four kinds from WayneUI tokens, in light and dark themes.

## Task lists [#](https://squire.apps.wayne.edu/docs/guides/docs-builder.html#task-lists)

A GitHub task list item (`- [ ] Protect the default branch`) renders a disabled checkbox. The builder wraps the checkbox and the item's own text in a `<label class="docs-task">`, so the checkbox has an accessible name (axe's label rule) and is named by what it checks; a nested list or a second paragraph stays outside the label.

## What the check proves [#](https://squire.apps.wayne.edu/docs/guides/docs-builder.html#what-the-check-proves)

- For a `symfony-commands` block, a code span or fenced line that starts with the CLI name must name a real command (`help` and `list` always pass), and every `--option` on it must be an option of that command or an application option; an unknown command or option is a finding.
- Every command and every option the CLI keeps must appear in the docs at least once (the generated block counts); a command or option nobody documented is a finding.
- A fenced block whose info string contains `docs-test: skip` is not scanned, and plain prose is never scanned.
- Every link a person wrote in the docs reaches something the site serves: a built page, a file under `site/`, or an external address; a relative link to a file the site does not serve (for example `../README.md`) is a finding.
- A fragment in a link must name a real heading on the page it points at, whether that is the page itself (`#setup`) or another built page (`guides/start.html#setup`); a missing id is a finding.
- Every image a page references (a Markdown image or an HTML `<img>`) must name a file under `docs/`, resolved against the source page's folder, and the build copies it byte for byte to the same relative path under `site/docs/` so the built page's relative src still resolves; a missing image is a finding, and unreferenced files are never copied.
- Every page carries the version stamp as a `docs-version` meta tag and in the footer, so a reader sees which release the docs describe.
- A `composer.json` with a `version` field must agree with the stamp; a different version is a finding.
- `--require-release` fails when `CHANGELOG.md` has no released heading yet, because the stamp would be `unreleased`.
- `--require-tag` fails when the repo's latest version tag does not match the stamp (a tag such as `v1.6.0` counts as `1.6.0`); the flag is off by default, so an untagged repo builds clean.
- When the nav lists a `changelog` page, `docs/changelog.md` mirrors `CHANGELOG.md` (front matter, marker comment, no top-level heading); a changed `CHANGELOG.md` or a missing page is drift, and a nav entry without a `CHANGELOG.md` is a finding.

## Tested examples [#](https://squire.apps.wayne.edu/docs/guides/docs-builder.html#tested-examples)

`php bin/docs-site.php --examples` runs the tagged command examples in the docs and fails when reality drifts from what they promise. A fenced block whose info string is `bash run`, `sh run`, `console run` or `shell run` (optionally `exit=N`, default 0) is a command block: each non-empty line is a command (a leading `$ ` is stripped), run in order in a fresh temp copy of the `examples.cwd` folder; the next fenced block, when its info string is exactly `output`, is the expected output (stdout then stderr). Both sides are normalized before the comparison: CRLF to LF, trailing spaces trimmed per line, trailing blank lines removed, the example working directory and the repo root rewritten to the placeholders below, plus every regex rule in `examples.normalize`:

```text
<cwd>
<root>
```

Every shell block (bash, sh, shell, console, powershell or pwsh) must be tagged `run` or carry an HTML comment line reading `docs-test: skip REASON` directly before it; a block with neither, and a marker without a reason, are findings naming the file and line. Skips are counted and grouped by the words before the first comma, case-folded, in the one-line summary (`Examples: 7 run, 12 skipped (reasons: needs network 5, needs Docker 4)`), and `examples.maxSkips` in `docs/docs.json` caps them. The other keys of `examples` are optional: `cwd` names the repo folder copied into each example working directory, `alias` replaces a matching first word with another command (a leading `php ` means the PHP binary, a relative script path resolves against the repo root), `env` adds environment variables, and `timeout` caps each command in seconds (default 60). Generated-block regions are ignored, and a plain build and `--check` never run examples.

## JavaScript tests [#](https://squire.apps.wayne.edu/docs/guides/docs-builder.html#javascript-tests)

The kit's `docs.js` carries its own tests in `tests/js/` (node:test with jsdom, pinned exactly in `package.json` and `package-lock.json`), and they run the same way locally and in CI. With Node 22 on the PATH:

```bash
npm ci && npm test
```

or, with only Docker:

```bash
docker run --rm -v "$PWD:/app" -w /app node:22-alpine sh -c "npm ci && npm test"
```

The `js-tests` job runs the same two commands on GitLab and GitHub. It stays out of `composer ci` because the PHP image has no Node.

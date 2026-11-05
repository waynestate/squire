---
title: "Update and drift"
description: "The .squire.json SHA-256 record, init --check drift reporting, squire update, app-owned files, and Sourdough's copy of the templates."
---

# Update and drift

This page explains how Squire tracks the files it writes (the `.squire.json` SHA-256 record), how `init --check` reports drift, how `squire update` moves your copies forward, and how Sourdough keeps its copy of the templates in step. Next, read [Commands](../reference/commands.md) for the full flag reference.

## The `.squire.json` record

`init` records a SHA-256 of each file it writes in `.squire.json`; commit that file. The record is what lets Squire tell the files you never edited from the ones you did.

## Drift: `init --check`

`squire init --check` reports when your copies have drifted from the templates (depend, do not copy). Drift from the Squire templates is reported as a warning under S9, never as a failure: you may edit your copies. See [Doctor](doctor.md) for the rule.

## `squire update`

`squire update` takes the new template versions for the files you never edited, and shows a diff for the ones you did. It never overwrites your edits, and it runs the same in CI and on a laptop.

`update` reads the record: a file you never edited takes the new template, a file you edited is left alone and shown as a diff, and a file Squire did not write is never touched. A squire polishes the armor; the knight keeps the sword. `--dry-run` prints the plan and writes nothing.

<!-- docs-test: skip needs a real project -->
```
vendor/bin/squire init   [--path=.] [--target=auto|raw|laravel|static] [--only=release|parity] [--check] [--force]
vendor/bin/squire update [--path=.] [--target=raw|laravel|static] [--only=release|parity] [--dry-run]
```

## App-owned files and `--force`

`init` never overwrites a file you edited, or a file it did not write, unless you pass `--force`. An app that already has its own `public/index.php` or `public/.htaccess` keeps it: `init` reports the file as `app-owned`, never records or overwrites it (not even with `--force`), and `init --check` does not count it as drift. Delete your copy and run `init` to take Squire's. A front controller Squire wrote is tracked like any other file.

## Sourdough's copy of the templates

Sourdough carries a generated copy of Squire's deploy templates instead of writing its own. The copy began when its apps could not `composer require` Squire from an internal repository (no internal URLs may be committed). Squire is on Packagist now, and Sourdough requires it as a dev dependency and regenerates its copy from the package. Squire owns the templates; the copy is checked in and verified, never hand-edited.

<!-- docs-test: skip needs a real project -->
```
php bin/export-templates.php --to=templates/squire            # write the copy
php bin/export-templates.php --to=templates/squire --check    # verify it; exit 1 on drift
```

The copy holds, for each kind (`raw`, `laravel`, `static`) and for the Vue layer (`vue`), the files a new app gets from `squire init`, at their target paths, with each file's mode recorded so executables stay executable. `catalog.json` lists which files each kind and layer installs and where; `manifest.json` records the Squire version and a SHA-256 per file. Refresh the copy when Squire's templates move on, and run `--check` in CI to keep it honest. A squire keeps the armor polished; the copy stays in step.

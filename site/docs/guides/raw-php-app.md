---
title: "Raw PHP app | Squire docs"
description: "The raw target: the front controller's three answers, its 404 rules, SQUIRE_APP_ENTRY, app-owned files and public/up.php."
url: https://squire.apps.wayne.edu/docs/guides/raw-php-app.html
llms: https://squire.apps.wayne.edu/llms.txt
generator: Squire agent-ready
---

# Raw PHP app [#](https://squire.apps.wayne.edu/docs/guides/raw-php-app.html#raw-php-app)

This page is for apps without a framework: what `squire init --target=raw` writes, and how the front controller decides what to serve, what to refuse, and what to hand to your entry. If you have not run `init` yet, start with the [quick start](https://squire.apps.wayne.edu/docs/guides/quick-start.html).

## What the raw target writes [#](https://squire.apps.wayne.edu/docs/guides/raw-php-app.html#what-the-raw-target-writes)

A raw PHP app (`--target=raw`, the default without an `artisan` file) gets a front controller, `public/index.php`, and an `.htaccess` that sends every request to it.

## The front controller's three answers [#](https://squire.apps.wayne.edu/docs/guides/raw-php-app.html#the-front-controller-s-three-answers)

- a real file in `public/` (CSS, images, fonts, an HTML page) is served with its type, an ETag and an hour of cache, with HELMET's headers when HELMET is installed, and HELMET's CSP nonce on every `<script>` of an HTML page;
- a 404 for a dot file (`.htaccess`, `.git`), a NUL byte, a path that climbs out of `public/`, any `.php` or `.phar` other than the front controller, and any file that is not a site asset (a `.sql` dump, a `.bak`, a `.md` with no `.html` beside it);
- everything else goes to the app's entry: `app.php` beside `public/`, or the file `SQUIRE_APP_ENTRY` names (relative to the app directory, for example `src/bootstrap.php`). A `POST`, `PUT` or `DELETE` always goes to the entry.

## SQUIRE_APP_ENTRY [#](https://squire.apps.wayne.edu/docs/guides/raw-php-app.html#squire-app-entry)

The entry is the only PHP file the front controller runs, and it must sit inside the app directory and end in `.php`; anything else is a plain 500 that names `SQUIRE_APP_ENTRY` and no path. The entry boots its own HELMET with its own settings: the front controller boots HELMET (citadel preset) only for the answers it gives itself, never before the entry, so HELMET is never booted twice.

## App-owned files [#](https://squire.apps.wayne.edu/docs/guides/raw-php-app.html#app-owned-files)

An app that already has its own `public/index.php` or `public/.htaccess` keeps it: `init` reports the file as `app-owned`, never records or overwrites it (not even with `--force`), and `init --check` does not count it as drift. Delete your copy and run `init` to take Squire's. A front controller Squire wrote is tracked like any other file.

## public/up.php [#](https://squire.apps.wayne.edu/docs/guides/raw-php-app.html#public-up-php)

`public/up.php` answers on its own, because the `.htaccess` lets Apache run it directly. The path checks and the MIME map are the static target's, and the tests keep the two in step; see [Static site](https://squire.apps.wayne.edu/docs/guides/static-site.html) for that side.

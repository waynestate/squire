---
title: "Proof | Squire docs"
description: "Squire run against 82 Wayne State applications on 2026-11-05: what ran, what held, what it caught in Squire itself, what it does not prove, and how to run the same on your app."
url: https://squire.apps.wayne.edu/docs/reference/proof.html
llms: https://squire.apps.wayne.edu/llms.txt
generator: Squire agent-ready
---

# Proof [#](https://squire.apps.wayne.edu/docs/reference/proof.html#proof)

This page says how Squire was proven on real Wayne State applications: what ran, what held, what the run caught in Squire itself, and what it does not prove yet. Next, see [Doctor](https://squire.apps.wayne.edu/docs/guides/doctor.html) for the rules the run checked and [Local parity](https://squire.apps.wayne.edu/docs/guides/local-parity.html) for the setup the boots used.

A squire is judged on the field, not in the tent. So on 2026-11-05 Squire went out to every PHP application in the stack's shared proof corpus, the same set PROCTOR and Preflight are measured on.

## Last run [#](https://squire.apps.wayne.edu/docs/reference/proof.html#last-run)

The table below records the most recent time the checks on this page were run against Squire itself, so a reader can see when the page was last verified and what the run found.

| Check | Date | Commit | Result | Duration |
| --- | --- | --- | --- | --- |
| `composer ci` | 2026-11-07 | 88fd2ec | pass: 404 tests, 2771 assertions, 0 phpstan errors, 0 advisories | 8m 14s |
| Local parity rig (`parity.sh up --fallback`, shell twin under WSL 2 bash) | 2026-11-07 | 88fd2ec | pass: the release ran all eight steps in 6s and `/up.php` answered 200 with body `ok` through the proxy | 27s to healthy (images prebuilt on this machine) |
| Image contract on the fallback (`contract-image.sh`, linux/amd64, Git Bash on Docker Desktop) | 2026-11-10 | c33f789 plus the `--base-image` change | pass: 26 of 26, under the compose file's least privilege | 4m 19s (base built from cache) |
| Image contract on Citadel's own image (`contract-image.sh --base-image=citadel/php-apache:8.5r3`, image id `950dcf67937a`) | 2026-11-10 | the same | pass: 23 checks passed, 3 gaps that only the image can close (below) | 1m 20s |
| Local parity rig on Windows PowerShell 5.1.26100 (`parity.ps1 up -Fallback`, then `up` on `citadel/php-apache:8.5r3`, `status`, `pin`, `reset`) | 2026-11-10 | the same plus the 5.1 fix | pass: `/up.php` answered 200 with body `ok` through the proxy on both bases; before the fix, `up` with no base image died on a raw `NativeCommandError` instead of its own message | 49s fallback, 26s Citadel's image |

To refresh this section, rerun `composer ci` and the local parity rig from [Local parity](https://squire.apps.wayne.edu/docs/guides/local-parity.html), then rewrite the table. The 82-app corpus run and the four real-app boots were not rerun: they need the shared proof corpus and the internal app repositories, which are not available on this machine, so the parity row uses the synthetic fixture that `squire init` writes, the same way CI does.

## What ran [#](https://squire.apps.wayne.edu/docs/reference/proof.html#what-ran)

Squire 0.5.0 from `main`, plus the `init` notice described below, in `php:8.4-cli` with no network. The 82 applications are 64 raw PHP apps (33 with a `composer.json`, 31 without one), 17 Laravel apps from 5.8 to 13, and 1 Slim 2 app. Six libraries, one template and six test sites ran too and are counted on their own line.

Each app was a throwaway copy of its default branch, checked out with Linux line endings, with `.env` files, `auth.json` and `config.files/` removed, and made into a fresh git repository so `doctor` could see what is tracked. Then, in order:

1. `squire doctor --format=json` on the app as it was;
2. `squire init`, letting Squire pick the target;
3. `squire init --check`, which must find no drift in what `init` just wrote;
4. `squire init` again, which must change nothing;
5. `squire doctor --format=json` on the result;
6. `squire update --dry-run`;
7. a lint of every file `init` wrote: `sh -n` or `bash -n` for the shell scripts, `php -l` for the PHP, a JSON parse for `.squire.json`, and a search for carriage returns.

An app **passes** when every step does what it should and `doctor` ends with exit 0. It is **flagged** when Squire did its job and still has something to tell the app (a fail or an untracked file the app wrote itself). A **tool error** is Squire breaking: a crash, an exit 2, invalid JSON, drift in its own output, a second `init` that changes something, or a written file that fails its lint.

## Results [#](https://squire.apps.wayne.edu/docs/reference/proof.html#results)

| Units | Pass | Flagged | Tool error |
| --- | --- | --- | --- |
| 82 applications | 76 | 6 | 0 |
| 13 libraries, template and test sites | 10 | 3 | 0 |

| Shape | Apps | Target `init` picked |
| --- | --- | --- |
| Raw PHP, no `composer.json` | 31 | raw |
| Raw PHP with Composer | 33 | raw |
| Slim 2 | 1 | raw |
| Laravel 5.8 to 13 | 17 | laravel (from `artisan`) |

- `init` wrote 18 to 21 files per app, and every one of them passed its lint. A second `init` changed nothing anywhere.
- 36 apps already had their own `public/index.php` and 2 their own `public/.htaccess`. `init` reported each as `app-owned` and left it alone, as documented.
- The six flagged apps had their own `build.sh` or `health.php`. `init` skipped those files without `--force`, `init --check` named them as untracked, and in one of them `doctor` failed S1: its own `build.sh` runs `composer require`, which cannot work at image build time.
- The median app took 3 seconds for all seven steps.

## Four apps on local parity [#](https://squire.apps.wayne.edu/docs/reference/proof.html#four-apps-on-local-parity)

Doctor reads files; it never runs an app. To see whether the kit actually brings an app up, four apps of different shapes were booted on [local parity](https://squire.apps.wayne.edu/docs/guides/local-parity.html) with the fallback base image, HAProxy in front and MySQL 5.6.51 behind, with every Wayne State host mapped to `127.0.0.1` so no app could reach a real service.

| App shape | What happened |
| --- | --- |
| Raw PHP, no `composer.json` | The first release stopped at step 1: `composer.json not found`. See below. |
| Raw PHP with Composer | Healthy in 23 seconds. `/up.php` answered `ok` through the proxy. The app's own front page answered 500 without its configuration, which the proof does not supply. |
| Slim 2 | The release stopped at step 3: the lock file installs one package from Subversion, and the image has no `svn`. The message named the step and the cause. |
| Laravel 13 | Without a `.env`, step 1 refused, as documented. With one made from `.env.example`, as the Citadel dashboard would, the release ran all ten steps in 106 seconds, including the migrations on MySQL 5.6. The app's own `.htaccess` then redirects every plain HTTP request to HTTPS, so `/up` answered 307 and the health check said unhealthy. That is the trusted-proxy class of problem parity exists to show before Citadel does. |

## The release on Citadel's own image [#](https://squire.apps.wayne.edu/docs/reference/proof.html#the-release-on-citadel-s-own-image)

The boots above used the fallback base. On 2026-11-10 the image contract suite ran on Citadel's own PHP image instead: `citadel/php-apache:8.5r3`, loaded byte for byte by [Parity](https://parity.apps.wayne.edu/)'s `parity image get` (image id `950dcf67937a`), with the app container under the same least privilege the kit's compose file gives it. Citadel's entrypoint fetched Composer and ran the first release as `citadel`; a second release ran with the smoke test on; `/up.php` answered 200 from the host and `health.php` passed inside; opcache checked mounted code every 2 seconds and never checked baked code; the web had no `exec` and kept `proc_open`; the CLI kept everything; a Composer script shelled out; and the mounted secret's value was in no log, layer or image config.

Three findings belong to the image, not to the kit, and no app file can change them:

- The `Server` header is `Apache/2.4.69 (Debian)`: every answer names the Apache version and the OS (`ServerTokens Prod` fixes it).
- Apache's own error pages carry the version footer (`ServerSignature Off` fixes it). `TRACE` already answers 405.
- The entrypoint does not turn `/run/secrets` files into variables for Apache, so on Citadel `./.env` stays the secret path, as [Image hardening](https://squire.apps.wayne.edu/docs/guides/image-hardening.html#on-citadel-s-own-image) says.

## What it caught in Squire [#](https://squire.apps.wayne.edu/docs/reference/proof.html#what-it-caught-in-squire)

A run that only finds praise was not looking hard enough. This one found these, and every finding is about Squire, not the apps:

- **Fixed: the release needs a lock file, and `init` never said so.** 31 of the 82 apps have no `composer.json` and no `composer.lock`. `init` wrote a release into each, `doctor` passed it, and the first boot showed that release stopping at step 1. `init` now ends with a "Heads up" line naming the missing files and how to make them; see [Troubleshooting](https://squire.apps.wayne.edu/docs/troubleshooting.html).
- **Fixed: Yarn.** Two apps keep a `yarn.lock`. The release installed JavaScript only from `package-lock.json` or `pnpm-lock.yaml`, so the frontend step stopped with "commit a lock file", which read oddly when one was committed. It now installs from `yarn.lock` too, through corepack: Yarn 1 with `--frozen-lockfile`, Yarn 2 and later with `--immutable` (see [The release script](https://squire.apps.wayne.edu/docs/guides/release-script.html)). Both were run by hand inside `citadel/php-apache:8.5r3` on 2026-11-10: corepack's Yarn 1.22.22 and Yarn 4.5.3 each installed from their lock with scripts off and then ran the build.
- **Open: fresh output that warns about itself.** Right after `init`, `doctor` warns on every app that `.env.squire` is not ignored at the repository root (`init` only edits `.gitignore` in a folder below the root) and that the Citadel image in the compose file is not pinned by digest (S67). Both warnings are true and both have a documented fix, but a kit should not hand its owner homework on day one.
- **Windows checkouts.** The first pass used clones made on Windows, where Git had turned the line endings of existing deploy scripts into CRLF. `doctor` failed S8 on 11 apps that are LF in their repositories, exactly the trap the rule describes. The numbers above are from the Linux checkout Citadel sees.

## What this does not prove [#](https://squire.apps.wayne.edu/docs/reference/proof.html#what-this-does-not-prove)

- No app was deployed to Citadel and no Quick Update ran. The four app boots used the fallback base image, an approximation of Citadel's image; only the image contract's fixture app ran on Citadel's own image, and never behind Citadel's proxy.
- No real configuration, database contents, Oracle, CAS or Entra were used, so an app's own pages are only shown to start, not to work.
- `doctor` decides only the rules a machine can read from files; the rest of the best practices are not checked here.
- The per-app record names the applications and stays in the stack's internal notes.

## Run it on your app [#](https://squire.apps.wayne.edu/docs/reference/proof.html#run-it-on-your-app)

The same steps on your own app, from its root:

```sh
composer require waynestate/squire --dev
vendor/bin/squire doctor
vendor/bin/squire init
vendor/bin/squire init --check
vendor/bin/squire doctor
```

Then bring it up on local parity:

Linux / macOS

```sh
scripts/parity.sh up --fallback
```

Windows

```powershell
scripts\parity.ps1 up -Fallback
```

## Related [#](https://squire.apps.wayne.edu/docs/reference/proof.html#related)

- [Doctor](https://squire.apps.wayne.edu/docs/guides/doctor.html)
- [Local parity](https://squire.apps.wayne.edu/docs/guides/local-parity.html)
- [The release script](https://squire.apps.wayne.edu/docs/guides/release-script.html)
- [Troubleshooting](https://squire.apps.wayne.edu/docs/troubleshooting.html)

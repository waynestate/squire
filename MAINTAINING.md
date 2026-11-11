# MAINTAINING: polishing the armor

How to build, test, release and keep Squire current. Squire is the Citadel kit: the standard `build.sh`, release
script, deployment hooks, health check, `/up`, and local parity on the Citadel images. It carries the knight's kit,
so a knight never deploys without it. A squire who forgets a buckle hears about it in the build log.

## What Squire is and what it depends on

- `waynestate/squire` on Packagist, with the CLI `bin/squire` (`squire init`, `squire init --check` and friends).
- Runtime dependency: `symfony/console` only. Dev: PHPUnit, Pint, PHPStan.
- The templates in `templates/` are the product: what `squire init` writes into an app, for raw PHP, Laravel and a Vue
  layer.
- **Who uses it:** apps (`squire init`), Sourdough (it carries a generated copy of these templates in
  `templates/squire/`), and every stack repo's own `deploy/` folder for its front page.
- Release scripts run on Citadel (Linux) only. Local tools come in pairs: `.ps1` for Windows, `.sh` for Linux and
  macOS.

## Where it lives

Development happens on git.wayne.edu, the canonical repository (https://git.wayne.edu/enterprise-apps/squire; clone
over SSH as `git@git.wayne.edu:enterprise-apps/squire.git`, or over HTTPS). Changes, issues and merge requests go
there; tags are cut there, by a maintainer, with an approver. This GitHub repository is a read-only push mirror of
it: `main` and tags are copied automatically, Packagist reads from it, and nobody pushes to it.

## Build and test

| Task | Windows (PowerShell) | Linux and macOS |
|---|---|---|
| Install | `composer install` | `composer install` |
| All checks (what CI runs) | `composer ci` | `composer ci` |
| Lint the shell templates (ShellCheck in Docker) | `scripts\lint-shell.ps1` | `scripts/lint-shell.sh` |
| Lint the PowerShell templates (PSScriptAnalyzer) | `scripts\lint-powershell.ps1` | `pwsh -File scripts/lint-powershell.ps1` |
| Local parity, in an app (deprecated: use Parity, `parity up`) | `scripts\parity.ps1 up` | `scripts/parity.sh up` |
| Front page accessibility gate (Preflight's a11y checker, in Docker) | `scripts\a11y.ps1` | `scripts/a11y.sh` |
| Docs kit JavaScript tests (needs Node, or Docker) | `npm ci && npm test` | `npm ci && npm test` |

With only Docker:

```
docker run --rm -v "${PWD}:/app" -w /app composer:2.11.1 sh -c "composer install --no-interaction && composer ci"
```

`composer ci` runs `composer validate --strict`, PHPUnit, Pint, PHPStan and `composer audit`.

## Contract suite (image)

`scripts/contract-image.sh` proves that `release.sh` runs inside an image. By default it uses the fallback base Squire
already builds for local parity (`.squire/base-fallback.Dockerfile`, tag `squire/php-parity-base:8.4`): php:8.4-apache
plus Node 22, multi-arch and public, so CI can build it. `--base-image=REF` runs it on an image already on this
machine instead, above all Citadel's own (`citadel/php-apache:8.5r3`, which `parity image get` loads byte for byte).

```
sh scripts/contract-image.sh --platform=linux/amd64
sh scripts/contract-image.sh --platform=linux/arm64   # needs qemu emulation on an amd64 host
sh scripts/contract-image.sh --base-image=citadel/php-apache:8.5r3   # Citadel's own image, never pulled
sh scripts/contract-image.sh --keep                   # keep the container, image, volume and temp dir
```

It makes a throwaway fixture app (`squire init --target=raw`, with `composer.json` and `composer.lock` seeded from
`deploy/`), builds the fallback base (unless `--base-image`) and the app image for the platform, runs the container
with the fixture mounted at `/citadel/src` the way the kit's compose file mounts it and with the compose file's least
privilege (`--cap-drop=ALL`, the seven capabilities the app service adds back, `no-new-privileges`, 256 processes,
512 MB; `ContractImageTest` fails when the driver and the compose file disagree), runs `release.sh` as the `citadel` user with the smoke
test on, and prints one PASS or FAIL line per assertion: the release.sh exit code, HTTP 200 from `/up.php` on the
host, `php health.php` inside the container, and the first-release deployment log with no failed sibling. Then it
checks the hardening in the running image: `scripts/contract-image-checks.php` is piped to `php` in the
container and asks Apache for the fixture's probe page (`scripts/contract-image-probe.php`, copied in as `app.php`).
It checks that the `Server` header is exactly `Apache`, there is no `X-Powered-By`, `TRACE` answers 405 with no
version footer, the web's opcache is on and checks files (`validate_timestamps` 1, `revalidate_freq` 2) for the
mounted code, a second app image built with `SQUIRE_OPCACHE_VALIDATE=0` never checks them (the baked case), the
web has no `exec` but keeps `proc_open`
and `popen`, the CLI disables nothing, and a Composer `post-install-cmd` that shells out ran in the release. A
throwaway secret is mounted at `/run/secrets/SQUIRE_CONTRACT_SECRET`: the web must see it (compared as an md5), the
release must name it, and its value must be in no log, no `docker history` line and no image config. The exit
code is 1 on any FAIL. It needs Docker with buildx and network access (the fixture installs its Composer
dependencies); without PHP on the PATH it runs the initializer in a `composer:2.11.1` container.

**What it proves and what it does not.** On the fallback it proves the release mechanics end to end on the public
stand-in: the fallback base builds for the platform, the app image builds on top of it, the first-release hook runs
at container start, `release.sh` is safe to run twice, Apache serves the front controller and the health endpoint,
and the deployment log lands where `citadel-deploy` puts it. The fallback has no `oci8`, `imagick` or `ssmtp`, so
that run is a contract, not a certificate.

**On Citadel's own image.** Three checks test what only the base image decides (the Apache server directives
`ServerTokens`, `TraceEnable` and `ServerSignature`, and whether the entrypoint loads `/run/secrets` for Apache). On
the fallback they are the kit's promises and a miss is a FAIL; with `--base-image` a miss is a `GAP:` line that is
counted and printed but does not fail the run (`CONTRACT_BASE=image` tells `contract-image-checks.php`). The run on
2026-11-10, `citadel/php-apache:8.5r3` (image id `950dcf67937a`), linux/amd64, Docker Desktop, 80 seconds: every
check the kit owns passed, under the least-privilege flags (which answers OPEN_QUESTIONS 8 for that image), and three
gaps showed: the `Server` header is `Apache/2.4.69 (Debian)`, Apache's error pages carry the version footer, and the
entrypoint does not read `/run/secrets` (on Citadel `./.env` is the secret path). `TRACE` answers 405. That is
evidence for the platform's Apache hardening request, not a kit bug. Still not proven: Citadel's HA-Proxy, its host,
`citadel/php-apache:8.4r5` (Citadel has published no download for it), and any CI run on Citadel's image, since CI
cannot reach it. Rerun after each new Citadel image and update this paragraph and the Proof page.

**In CI.** The `contract-image` job on GitHub runs the script for both platforms, linux/amd64 and linux/arm64, with
the arm64 leg under QEMU. It passed on 2026-11-08 and has blocked the run since. The `contract-parity-sh` and
`contract-parity-pwsh` jobs bring the local parity stack up on the fallback image with `scripts/parity.sh` and
`scripts/parity.ps1` and check that the proxy answers 200 on `/up.php`. The hardening checks run in the same
`contract-image` job, so both platforms check them. GitLab has no job yet because its runner has no Docker daemon:
the in-image checks are a PHP file piped to the container, so a buildah or podman driver can reuse them once the
runner can run a container with a port.

## CI

- **GitLab:** Preflight's `php-test` (PHP 8.3, 8.4, 8.5), `composer-audit`, `proctor` (on `deploy/`) and `a11y`
  components, a `composer-ci` job, `wayneui-copies`, `shellcheck`, `psscriptanalyzer` (PowerShell lint in a pinned
  multi-arch image), and `js-tests` (the docs kit's JavaScript, node:test and jsdom in a pinned multi-arch Node 22
  image).
- **Code Quality on GitLab (checked 2026-11-08).** The `proctor` component runs
  `proctor check --format=codequality` and uploads the result as a `codequality` report artifact, the import path
  GitLab documents for every tier. Squire never includes GitLab's CodeClimate-based Code Quality template, which
  GitLab deprecated in 17.3 and still plans to remove (the docs page says 19.0; the deprecation issue now expects
  19.5), so that removal changes nothing here. On Community Edition the findings show in the merge request's
  reports; the pipeline's Code Quality tab needs Premium and the inline diff view needs Ultimate. Check again if a
  Code Quality job of Squire's own is ever added.
- **GitHub:** `.github/workflows/ci.yml` calls Preflight's reusable workflow with `composer ci`, plus `shellcheck`
  and `js-tests` (the same two commands, `npm ci` and `npm test`, in the same pinned Node image as a container job).
  PowerShell lint runs on GitLab only.
- **GitHub actions are pinned by commit SHA**, never by a tag: `actions/checkout@<40-character SHA> # v4.4.0`. A
  tag can be moved to other code by whoever controls the action's repository; a commit cannot. The trailing comment
  names the tag the SHA stands for, and Renovate's `helpers:pinGitHubActionDigests` (in the Preflight preset) moves
  both together. To bump one by hand, read the tag's commit with
  `git ls-remote https://github.com/actions/checkout.git refs/tags/v4.4.0 'refs/tags/v4.4.0^{}'` (an annotated tag
  lists its commit on the `^{}` line) and replace every copy at once. `GitHubActionsPinTest` fails on a tag pin, a
  missing version comment, or one action at two SHAs.
- Green means all of those pass.

GitLab runs lint and unit tests only; the contract suite runs on the GitHub mirror (see
[Contract suite (image)](#contract-suite-image)).

## Bumping a pinned image

Every image Squire's files pull is pinned by the digest of its multi-arch list, not a tag: the fallback
base's `PHP_IMAGE`, `NODE_IMAGE` and `COMPOSER_IMAGE`, the Vue layer's Node service, HA-Proxy and MySQL in the
compose files, the Oracle parity bases (one row per PHP line in `images/oracle/php-images.txt`) and the Composer
image the Oracle build scripts run. A list digest names the same bytes on an arm64 runner or laptop and on an amd64
server; a tag can move between two builds of the same commit. `ImageHardeningTest` fails on any reference without
a digest.

To bump one:

1. Read the new list's digest, and check that it lists both platforms:
   `docker buildx imagetools inspect php:8.4.28-apache` (the `Digest:` line, and `linux/amd64` and `linux/arm64/v8`
   under Manifests).
2. Replace the whole reference, tag and digest together (`docker.io/library/php:8.4.28-apache@sha256:...`, named in
   full for Buildah), in every file that has it:
   `git grep -n 'php:8.4.27-apache'`. For an Oracle parity line, edit its row in `images/oracle/php-images.txt`, and
   for 8.4 the `ARG PHP_IMAGE` default in `images/oracle/parity.Dockerfile` too (a test keeps the two equal).
3. Run `composer ci`, then `sh scripts/contract-image.sh --platform=linux/amd64` and, where you can,
   `--platform=linux/arm64`. For the Oracle images, run the manual `oracle-images-dry-run` job.
4. Add a CHANGELOG line naming the old and new versions.

Sail's own runtime Dockerfile (inside `laravel/sail`) picks its Ubuntu base; Squire pins the Composer image that
fetches it, not that base. The real Citadel image (`citadel/php-apache:8.4r5`) is a local build name; pin it with
`SQUIRE_BASE_IMAGE` once you have its digest (S67).

## Publishing the Oracle images

- The `oracle-images` job runs when a Squire version is tagged. It builds both flavours (`sail` and `parity`) for
  PHP 8.3, 8.4 and 8.5 with buildah (the runner has no Docker daemon), and pushes a manifest list to the group's
  `images` project (the stack's shared image registry; this GitLab has no container registry of its own).
- **Each half builds natively.** `oracle-images` stages the linux/arm64 half on the default runner and
  `oracle-images-amd64` stages the linux/amd64 half on a runner tagged `amd64`; `oracle-images-manifest` stitches
  the staged halves into the one manifest list per image and pushes `<php>-<version>` (fixed) and `<php>`
  (floating). The staged `<php>-<version>-<arch>` tags are single-arch and stay in the registry: they are pointers
  to the same layers the list uses.
- The CI variable `SQUIRE_BUILD_AMD64` is the one switch. It defaults to `"1"`; set it to `"0"` (a project CI/CD
  variable wins over the file's default) to publish linux/arm64 only, for when the amd64 runner is away. Only jobs
  that need amd64 carry the `amd64` tag, because that runner is a small shared host; every other job stays untagged
  on the default runner.
- The destination host, user and token are the protected CI variables `NOUGAT_REGISTRY`, `NOUGAT_REGISTRY_USER`
  and `NOUGAT_REGISTRY_TOKEN` (a deploy token with read and write on the images project), readable only in tag
  pipelines; the job fails plainly when any of them is empty, and the host is never written down in the pipeline or
  this file (the repository is mirrored publicly).
- The published images are `<registry>/enterprise-apps/images/squire-oracle-sail:<php>` and
  `<registry>/enterprise-apps/images/squire-oracle-parity:<php>`, each with a floating `<php>` tag and a fixed
  `<php>-<squire version>` tag, where `<registry>` is `NOUGAT_REGISTRY` in CI and the registry address of the
  images project shown on its Container Registry page elsewhere.
- `oracle-images-dry-run` is manual and runs on branches (never on tags): it builds what a tag would publish and
  prints what would be pushed, without logging in or pushing. Run it before you tag.
- Each half now builds natively: linux/arm64 on the default runner, linux/amd64 on a runner tagged `amd64`, so CI
  needs no qemu binfmt emulation. `--stage` refuses to build off-native, and a build that still dies with an exec
  format error says so plainly and names the two runners, so the job can be checked against the runner it ran on.
- Each job prints `Pushed <reference> (<platforms>) digest: sha256:...` for the fixed and the floating tag; the
  digest is the manifest list's. Record each tag's digests in the table below.

**History: no tag published the images before 0.10.5.** Every tag pipeline since this GitLab became the home (0.6.0
to 0.10.4) failed all six `oracle-images` jobs. Four causes, all handled:

- Fixed in 0.10.4: the Sail flavor never reached a build. The script ran `buildah from
  docker://composer:<tag>@sha256:...`, and buildah's `docker://` transport refuses a tag and a digest together
  (`invalid reference format`). It now names the image in full without the prefix.
- Fixed in 0.10.4: every linux/arm64 build failed in `install-oracle.sh`. The arm64 Instant Client zips share
  their `META-INF/` signing files, and the second `unzip` stopped to ask about replacing them, which fails with no
  terminal. The SDK now unpacks with `unzip -n`. The 0.10.4 tag pipeline built PHP 8.3 and 8.4 of both flavors
  for linux/arm64.
- Fixed in 0.10.5: every PHP 8.5 build failed in `install-oracle.sh`. PHP 8.5's `php --ini` prints the ini scan
  directory in double quotes, and the script wrote `zz-oci8.ini` into a path with the quotes in it. It strips them
  now and fails by name if the folder is missing.
- Worked around in 0.10.5 (linux/arm64 only behind `SQUIRE_BUILD_AMD64`, above): the only runner is linux/arm64
  and cannot run linux/amd64 binaries, so every amd64 build dies at its
  first `RUN` step (`fork/exec /bin/sh: exec format error`). The job cannot fix that itself. A probe job on
  2026-11-09 found Docker's default capabilities (no `CAP_SYS_ADMIN`), seccomp on, `/proc/sys` read-only and
  binfmt_misc not mountable, so `tonistiigi/binfmt --install` and any other in-job registration fail; the kernel
  (5.14) also predates the private binfmt_misc mounts of Linux 6.7, which would have scoped emulation to the job.
  There is no amd64 runner to build that half natively and stitch the list. The decision was (c), then (a), from
  OPEN_QUESTIONS.md, "Oracle images: amd64 on an arm64 runner": publish arm64 now, and the runner host's
  administrator installs qemu-user-static later, when `SQUIRE_BUILD_AMD64=1` turns amd64 back on.
- Fixed after 0.10.7: an amd64 runner (tag `amd64`) builds the amd64 half natively and `oracle-images-manifest`
  stitches the list, option (b) of OPEN_QUESTIONS 18.

### What each tag published

Each digest is the manifest list's, under `<registry>/enterprise-apps/`. The fixed tag keeps it for good; the floating
`<php>` tag had it when the tag pipeline ran and moves with the next tag.

| Tag | Image (fixed tag, and the floating one) | Platforms | List digest |
|---|---|---|---|
| 0.10.5 | `images/squire-oracle-sail:8.3-0.10.5` (and `:8.3`) | linux/arm64 | `sha256:135f9c2c545acca83ba85a9ba0cdc749e49901187616be2707b6be40db1e80fb` |
| 0.10.5 | `images/squire-oracle-sail:8.4-0.10.5` (and `:8.4`) | linux/arm64 | `sha256:3d942883ea31c802e2a958542697941c6bff4b36989931e12e2c24289b690a87` |
| 0.10.5 | `images/squire-oracle-sail:8.5-0.10.5` (and `:8.5`) | linux/arm64 | `sha256:d3e66740c7a23221c12f112046c7ec6f316be5a8a96cf2005cbccdea4bd61bfb` |
| 0.10.5 | `images/squire-oracle-parity:8.3-0.10.5` (and `:8.3`) | linux/arm64 | `sha256:4a14a2af005f2ba30eb32c23d6f9d4fdbe7e5593c9dc6a0c2f2616fdb532bc71` |
| 0.10.5 | `images/squire-oracle-parity:8.4-0.10.5` (and `:8.4`) | linux/arm64 | `sha256:531b22047eedfd4e8bceee4583cbce2d0aac3129d27afdae97aec8b22bb54367` |
| 0.10.5 | `images/squire-oracle-parity:8.5-0.10.5` (and `:8.5`) | linux/arm64 | `sha256:ebfdb9536d7a3e857a11d797a701f12b2129b7504bd48250b6d946a55a2adb7e` |

## Changing a template

- Templates follow Citadel's real behavior: `build.sh` runs at image build time as root with only that one file
  present; `deployments/` scripts run once per file name at container start; the Quick Update post-script runs after
  `git pull`. Everything that runs twice must be safe to run twice.
- A changed `build.sh` rebuilds its image step on a full Update (tested on Citadel pre-production, 2026-11-07); a
  Quick Update never runs it. Bump the `SQUIRE_BUILD_REVISION` marker with each change so the build log shows which
  version ran.
- Shell scripts need their executable bit in git, also when committed from Windows:
  `git update-index --chmod=+x <file>`.
- **After any template change, refresh Sourdough's copy** and check it:
  ```
  php bin/export-templates.php --to=../sourdough/templates/squire
  php bin/export-templates.php --to=../sourdough/templates/squire --check
  ```
  Commit the copy in Sourdough with this repo's commit in the message. Each stack repo's `deploy/` folder is
  regenerated with `squire init` and checked with `squire init --check`.
- Local parity runs a newer HA-Proxy than Citadel's; keep proxy-dependent behavior simple.

## Releasing

1. Move the lines under `## Unreleased` in `CHANGELOG.md` to a new `## X.Y.Z (YYYY-MM-DD)` heading, and bump
   `Squire::VERSION` in `src/Squire.php` to the same number in the release commit: the version guard test fails when
   the two disagree.
2. `composer ci` and both linters green locally, then both pipelines green for that commit.
3. Run a secrets scan of the history and check that nothing internal (server names, people, IDs) is in the tree.
4. Tag `X.Y.Z` (no `v`) on git.wayne.edu, with an approver. The mirror carries the tag to GitHub, and Packagist picks it up from there. Both homes then publish a release whose notes are the matching `## X.Y.Z (` section of `CHANGELOG.md`, cut by one script, `scripts/release-notes.sh`, which fails loudly when the section is missing or empty: on git.wayne.edu the `gitlab-release` job creates the GitLab Release with the job's own token (a release that already exists is left as it is), and on GitHub `.github/workflows/release.yml` creates the GitHub Release with only the built-in `GITHUB_TOKEN`. Neither creates, moves or deletes a tag.
5. A release needs a maintainer's approval. Never move or delete a published tag.
6. When Preflight releases, update both of this repo's pins: the component tag in `.gitlab-ci.yml` and the tag
   in `.github/workflows/ci.yml`.

## The front page

`site/` is the static front page, for `https://squire.apps.wayne.edu/` on Citadel, served through the raw-PHP kit this
repo makes. Squire dogfoods Squire.

## Who maintains it

The stack maintainer (a role, not a named person), Wayne State University Enterprise Applications. To reach them,
open an issue on git.wayne.edu. Internal runbook (accounts, tokens, mirrors, Citadel access): ask Enterprise
Applications.

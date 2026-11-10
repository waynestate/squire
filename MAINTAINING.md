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
| Local parity, in an app | `scripts\parity.ps1 up` | `scripts/parity.sh up` |
| Front page accessibility gate (Preflight's a11y checker, in Docker) | `scripts\a11y.ps1` | `scripts/a11y.sh` |
| Docs kit JavaScript tests (needs Node, or Docker) | `npm ci && npm test` | `npm ci && npm test` |

With only Docker:

```
docker run --rm -v "${PWD}:/app" -w /app composer:2.11.1 sh -c "composer install --no-interaction && composer ci"
```

`composer ci` runs `composer validate --strict`, PHPUnit, Pint, PHPStan and `composer audit`.

## Contract suite (image)

`scripts/contract-image.sh` proves that `release.sh` runs inside an image. The real Citadel image is internal, so it
uses the fallback base Squire already builds for local parity (`.squire/base-fallback.Dockerfile`, tag
`squire/php-parity-base:8.4`): php:8.4-apache plus Node 22, multi-arch.

```
sh scripts/contract-image.sh --platform=linux/amd64
sh scripts/contract-image.sh --platform=linux/arm64   # needs qemu emulation on an amd64 host
sh scripts/contract-image.sh --keep                   # keep the container, image, volume and temp dir
```

It makes a throwaway fixture app (`squire init --target=raw`, with `composer.json` and `composer.lock` seeded from
`deploy/`), builds the fallback base and the app image for the platform, runs the container with the fixture mounted
at `/citadel/src` the way the kit's compose file mounts it, runs `release.sh` as the `citadel` user with the smoke
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

**What it proves and what it does not.** It proves the release mechanics end to end on the public stand-in: the
fallback base builds for the platform, the app image builds on top of it, the first-release hook runs at container
start, `release.sh` is safe to run twice, Apache serves the front controller and the health endpoint, and the
deployment log lands where `citadel-deploy` puts it. It does not prove parity with the real image: the fallback has
no `oci8`, `imagick` or `ssmtp`, its HA-Proxy differs from Citadel's, and nothing here touches Citadel itself. A
green run is a contract, not a certificate.

**In CI.** The `contract-image` job on GitHub runs the script for both platforms, linux/amd64 and linux/arm64, with
the arm64 leg under QEMU and allowed to fail until it has been seen green once. The `contract-parity-sh` and
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
  PHP 8.3, 8.4 and 8.5, each for linux/amd64 and linux/arm64, with buildah (the runner has no Docker daemon), and
  pushes a manifest list to the group's `images` project (the stack's shared image registry; this GitLab has no
  container registry of its own).
- The destination host, user and token are the protected CI variables `NOUGAT_REGISTRY`, `NOUGAT_REGISTRY_USER`
  and `NOUGAT_REGISTRY_TOKEN` (a deploy token with read and write on the images project), readable only in tag
  pipelines; the job fails plainly when any of them is empty, and the host is never written down in the pipeline or
  this file (the repository is mirrored publicly).
- The published images are `<registry>/enterprise-apps/images/squire-oracle-sail:<php>` and
  `<registry>/enterprise-apps/images/squire-oracle-parity:<php>`, each with a floating `<php>` tag and a fixed
  `<php>-<squire version>` tag, where `<registry>` is `NOUGAT_REGISTRY` in CI and the registry address of the
  images project shown on its Container Registry page elsewhere.
- `oracle-images-dry-run` is manual and runs on branches (never on tags): it builds everything and prints what would
  be pushed, without logging in or pushing. Run it to prove the runner can build both architectures before you tag.
- The runner must be able to run the architecture it is not (qemu binfmt emulation registered on its host) and run
  buildah inside a container. The script builds the runner's own architecture first, so a dry run always proves
  that half; if the other build dies with an exec format error, the job says so plainly and stops. Until a dry run
  passes, nothing is pushed.

**Known limit: no tag has published the images yet.** Every tag pipeline since this GitLab became the home (0.6.0
to 0.10.3) failed all six `oracle-images` jobs. Three causes, two fixed:

- Fixed (Unreleased): the Sail flavor never reached a build. The script ran `buildah from
  docker://composer:<tag>@sha256:...`, and buildah's `docker://` transport refuses a tag and a digest together
  (`invalid reference format`). It now names the image in full without the prefix.
- Fixed (Unreleased): every linux/arm64 build failed in `install-oracle.sh`. The arm64 Instant Client zips share
  their `META-INF/` signing files, and the second `unzip` stopped to ask about replacing them, which fails with no
  terminal. The SDK now unpacks with `unzip -n`.
- Open: the only runner is linux/arm64 and cannot run linux/amd64 binaries, so every amd64 build dies at its
  first `RUN` step (`fork/exec /bin/sh: exec format error`). The job cannot fix that itself. A probe job on
  2026-11-09 found Docker's default capabilities (no `CAP_SYS_ADMIN`), seccomp on, `/proc/sys` read-only and
  binfmt_misc not mountable, so `tonistiigi/binfmt --install` and any other in-job registration fail; the kernel
  (5.14) also predates the private binfmt_misc mounts of Linux 6.7, which would have scoped emulation to the job.
  There is no amd64 runner to build that half natively and stitch the list. The ways out are on the runner host
  or are a product call: see OPEN_QUESTIONS.md, "Oracle images: amd64 on an arm64 runner". Until then, build the
  images locally with `scripts/oracle-image-build.sh`.

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
4. Tag `X.Y.Z` (no `v`) on git.wayne.edu, with an approver. The mirror carries the tag to GitHub, and Packagist picks it up from there. On GitHub, `.github/workflows/release.yml` then creates the release from the matching `## X.Y.Z (` section of `CHANGELOG.md` (it fails loudly when the section is missing or empty, and uses only the built-in `GITHUB_TOKEN`).
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

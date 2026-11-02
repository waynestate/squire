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

Development happens on Wayne State's GitLab. This GitHub repository is a read-only release mirror: `main` and tags are
copied here automatically, and changes, issues and merge requests go to GitLab first. Nobody pushes to GitHub
directly.

## Build and test

| Task | Windows (PowerShell) | Linux and macOS |
|---|---|---|
| Install | `composer install` | `composer install` |
| All checks (what CI runs) | `composer ci` | `composer ci` |
| Lint the shell templates (ShellCheck in Docker) | `scripts\lint-shell.ps1` | `scripts/lint-shell.sh` |
| Lint the PowerShell templates (PSScriptAnalyzer) | `scripts\lint-powershell.ps1` | `pwsh -File scripts/lint-powershell.ps1` |
| Local parity, in an app | `scripts\parity.ps1 up` | `scripts/parity.sh up` |

With only Docker:

```
docker run --rm -v "${PWD}:/app" -w /app composer:2.10.3 sh -c "composer install --no-interaction && composer ci"
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
host, `php health.php` inside the container, and the first-release deployment log with no failed sibling. The exit
code is 1 on any FAIL. It needs Docker with buildx and network access (the fixture installs its Composer
dependencies); without PHP on the PATH it runs the initializer in a `composer:2.10.3` container.

**What it proves and what it does not.** It proves the release mechanics end to end on the public stand-in: the
fallback base builds for the platform, the app image builds on top of it, the first-release hook runs at container
start, `release.sh` is safe to run twice, Apache serves the front controller and the health endpoint, and the
deployment log lands where `citadel-deploy` puts it. It does not prove parity with the real image: the fallback has
no `oci8`, `imagick` or `ssmtp`, its HA-Proxy differs from Citadel's, and nothing here touches Citadel itself. A
green run is a contract, not a certificate.

## CI

- **GitLab:** Preflight's `php-test` (PHP 8.3, 8.4, 8.5) and `composer-audit` components, a `composer-ci` job,
  `shellcheck`, and `psscriptanalyzer` (PowerShell lint in a pinned multi-arch image).
- **GitHub:** `.github/workflows/ci.yml` calls Preflight's reusable workflow with `composer ci`, plus `shellcheck`.
  PowerShell lint runs on GitLab only.
- Green means all of those pass.

## Publishing the Oracle images

- The `oracle-images` job runs when a Squire version is tagged. It builds both flavours (`sail` and `parity`) for
  PHP 8.3, 8.4 and 8.5, each for linux/amd64 and linux/arm64, with buildah (the runner has no Docker daemon), and
  pushes a manifest list to the project's container registry.
- The published images are `$CI_REGISTRY_IMAGE/oracle-sail:<php>` and `$CI_REGISTRY_IMAGE/oracle-parity:<php>`, each
  with a floating `<php>` tag and a fixed `<php>-<squire version>` tag. In CI, `$CI_REGISTRY_IMAGE` is set for you;
  elsewhere it is the registry address of the Squire project shown on its Container Registry page.
- `oracle-images-dry-run` is manual and runs on branches (never on tags): it builds everything and prints what would
  be pushed, without logging in or pushing. Run it to prove the runner can build both architectures before you tag.
- The runner must be able to build linux/amd64 (qemu binfmt emulation) and run buildah inside a container. If the
  amd64 build dies with an exec format error, the job says so plainly: ask the runner's owner to enable amd64
  emulation. Until a dry run passes, nothing is pushed.

**Known limit.** The runner is linux/arm64 and has no qemu-user binfmt emulation. A manual dry run of
`oracle-images-dry-run` (parity, PHP 8.4) pulls the images and gets through the arm64 build, but the amd64 build
dies at the first `RUN` step with an `exec format error` (`process exited with error: exec: not started`): an
amd64 image cannot be built there today. See OPEN_QUESTIONS.md, "Oracle images: amd64 on an arm64 runner".

## Changing a template

- Templates follow Citadel's real behavior: `build.sh` runs at image build time as root with only that one file
  present; `deployments/` scripts run once per file name at container start; the Quick Update post-script runs after
  `git pull`. Everything that runs twice must be safe to run twice.
- Citadel can reuse a cached build layer after `build.sh` changes. Bump the `SQUIRE_BUILD_REVISION` marker so the log
  shows which version ran.
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
4. Tag `X.Y.Z` (no `v`) on GitLab. The mirror carries the tag to GitHub, and Packagist picks it up from there.
5. A release needs a maintainer's approval. Never move or delete a published tag.
6. When Preflight releases, update both of this repo's pins: the component tag in `.gitlab-ci.yml` and the commit SHA
   in `.github/workflows/ci.yml`.

## The front page

`site/` is the static front page, for `https://squire.apps.wayne.edu/` on Citadel, served through the raw-PHP kit this
repo makes. Squire dogfoods Squire.

## Who maintains it

Adam Perkins, Wayne State University Enterprise Applications. Internal runbook (accounts, tokens, mirrors, Citadel
access): ask Enterprise Applications.

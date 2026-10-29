# MAINTAINING: polishing the armour

How to build, test, release and keep SQUIRE current. SQUIRE is the Citadel kit: the standard `build.sh`, release
script, deployment hooks, health check, `/up`, and local parity on the Citadel images. It carries the knight's kit,
so a knight never deploys without it. A squire who forgets a buckle hears about it in the build log.

## What SQUIRE is and what it depends on

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

## CI

- **GitLab:** PREFLIGHT's `php-test` (PHP 8.3, 8.4, 8.5) and `composer-audit` components, a `composer-ci` job,
  `shellcheck`, and `psscriptanalyzer` (PowerShell lint in a pinned multi-arch image).
- **GitHub:** `.github/workflows/ci.yml` calls PREFLIGHT's reusable workflow with `composer ci`, plus `shellcheck`.
  PowerShell lint runs on GitLab only.
- Green means all of those pass.

## Changing a template

- Templates follow Citadel's real behaviour: `build.sh` runs at image build time as root with only that one file
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
- Local parity runs a newer HA-Proxy than Citadel's; keep proxy-dependent behaviour simple.

## Releasing

1. Move the lines under `## Unreleased` in `CHANGELOG.md` to a new `## X.Y.Z (YYYY-MM-DD)` heading.
2. `composer ci` and both linters green locally, then both pipelines green for that commit.
3. Run a secrets scan of the history and check that nothing internal (server names, people, IDs) is in the tree.
4. Tag `X.Y.Z` (no `v`) on GitLab. The mirror carries the tag to GitHub, and Packagist picks it up from there.
5. A release needs a maintainer's approval. Never move or delete a published tag.
6. When PREFLIGHT releases, update both of this repo's pins: the component tag in `.gitlab-ci.yml` and the commit SHA
   in `.github/workflows/ci.yml`.

## The front page

`site/` is the static front page, for `https://squire.apps.wayne.edu/` on Citadel, served through the raw-PHP kit this
repo makes. SQUIRE dogfoods SQUIRE.

## Who maintains it

Adam Perkins, Wayne State University Enterprise Applications. Internal runbook (accounts, tokens, mirrors, Citadel
access): ask Enterprise Applications.

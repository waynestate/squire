# Open questions

Each entry says what is unknown, the default 0.1 chose, and what would change it.

## Questions

1. **`build.sh` shell.** Citadel runs `build.sh` with `/bin/sh` (dash), which has no `pipefail`. Default: POSIX sh with
   `set -eu` and a guarded `set -o pipefail` where the shell supports it. The brief asked for `pipefail`; this is the
   closest that works. Changes if Citadel starts running the script with bash.
2. **How does `vendor/` refresh on a full Update?** Deployment hooks run once per app lifetime, so a changed
   `composer.lock` on a full Update has no install step. Default: tell teams to also use `scripts/release.sh` as the Quick
    Update post-script. Needs a Citadel maintainer's answer.
3. **Is the public directory `src/public`?** Default for local parity: `SQUIRE_PUBLIC_DIR=src/public`. The dashboard passes
   the real value; check it matches.
4. **Can a secret be mounted?** Squire assumes environment variables from the dashboard only. Default: the scripts read
   `SQUIRE_*` from the environment; nothing is read from a file in the repository.
5. **PowerShell minimum.** The `.ps1` scripts are written for Windows PowerShell 5.1 and PowerShell 7; the lint (PSScriptAnalyzer)
   runs on 7.4 only. 5.1 behavior is by inspection, not run.
6. **PROCTOR is a dev dependency.** `composer install --no-dev` removes it, so the release step needs
   `SQUIRE_PROCTOR_BIN`. Default: the step skips with a stated reason when it is missing; the policy falls back to the
   copy CREED ships, and to PROCTOR's own default with a notice when CREED is not installed.
   Changes when PROCTOR ships a phar.
7. **Local proxy version.** Locally HA-Proxy 3.0; Citadel's template targets 1.5. Both add `X-Forwarded-For` and
   `X-Forwarded-Proto`; other 1.5 behaviors (connection reuse, header casing) may differ.
8. **`cap_drop: [ALL]` on the real image.** Verified on the fallback image only (the app starts and answers `/up.php`).
   Apache needs `CHOWN`, `SETUID`, `SETGID`, `DAC_OVERRIDE` and `NET_BIND_SERVICE` on some images; the compose file adds back
   what the fallback needed. Re-check on the real image.
9. **`php.ini` is safe defaults, not a mirror.** `.squire/php.ini` holds the safe defaults HELMET also enforces at
   runtime, not a copy of the platform's file, so the local app runs with hardened PHP settings. For exact parity with
   the platform, replace it with a copy of the platform's file.
10. **Docker Hub digests.** The brief says to pin by digest where one can be found in the Citadel clone. The clone has none
    for any image it uses (Citadel builds images locally). Default: pin HA-Proxy and MySQL to the digests observed on Docker
    Hub on 2026-10-28 (`haproxy:3.0-alpine`, `mysql:5.6.51`) and leave the Citadel image unpinned (below). A deviation from
    the brief; revert to placeholders if you prefer.
11. **Executable bit from Windows.** Git on Windows does not keep it. Default: documented `git update-index --chmod=+x`.
    Citadel also runs `chmod -R ug+x` on the deployments path and the quick-update and health scripts.
12. **Apple Silicon.** The real Citadel images and MySQL 5.6 are amd64; Docker emulates them and builds can take 30 minutes
    or more. The scripts print a warning; nothing else is done. No emulated build time has been measured yet; the plan is
    to time one `parity.sh up --fallback` build and one real-image build on an Apple Silicon machine and record both dates
    and numbers here.
13. **No Node version check.** `release.sh` uses whatever `node` and `npm` the image has. Answered 2026-11-08 from the
    Citadel image build files (`citadel-php8.3r3`, `8.4r5` and `8.5r3`): every current image installs **Node 22** from
    NodeSource's `setup_22.x` repo, with the npm that Node 22 bundles and pnpm and yarn through `corepack enable`. The
    major is fixed per image; the minor is whatever NodeSource served when the image was built. Node 22 is a
    maintenance LTS (security fixes until April 2027), so PROCTOR's `node-eol` check warns on it before then. Default:
    still no assertion in `release.sh`; an app that needs a newer Node asks Citadel for a newer image.
14. **Audit default.** `SQUIRE_COMPOSER_AUDIT=report`. A finding does not fail a release by default, because a Quick Update
    cannot roll back and an advisory published today should not take down a site that deployed fine yesterday. `fail` is
    available.
15. **Bind-mount permissions locally.** On the first end-to-end run, the release hook failed with `Permission denied` writing
    `vendor/composer/installed.json` because the source folder (created on the host by another tool, owned by root) was not
    writable by the container's `citadel` user. After making it writable the hook ran, the app became healthy and the proxy
    answered. Default: documented in the README; `SQUIRE_SOURCE_UID` and `SQUIRE_SOURCE_GID` match the user on Linux. Not
    solved for Docker Desktop folders that are root-owned. See README Local parity: keep the source in the WSL 2
    filesystem on Windows.
16. **Which Citadel health check value.** The dashboard wizard value is assumed to be `["php","health.php"]`. Confirm the
    exact form.
17. **Runner amd64 emulation.** The CI runner is linux/arm64; building the Oracle images for linux/amd64 needs qemu
    binfmt emulation and buildah running inside a container. Default: the `oracle-images-dry-run` job proves it on a
    branch before anyone tags; nothing is pushed until it passes. If the runner cannot emulate amd64, ask its owner
    to enable it.
18. **Oracle images: amd64 on an arm64 runner.** The CI runner is linux/arm64. A manual dry run of
    `oracle-images-dry-run` (parity, PHP 8.4) now pulls the images and gets through the arm64 build, but the amd64
    build dies at the first `RUN` step with an `exec format error` (`process exited with error: exec: not
    started`): the runner has no qemu-user binfmt emulation, so an amd64 image cannot be built there today. The
    arm64 half builds without complaint; the amd64 half does not survive its first `RUN`, which is either dramatic
    or efficient. Three ways out: (a) the runner host gets qemu-user-static binfmt registered (the GitLab
    administrator's call); (b) an amd64 runner builds the amd64 half and the arm64 runner merges the manifest list;
    (c) the tag job publishes arm64 now and amd64 is built by hand with `scripts/oracle-image-build.sh` until (a) or
    (b) lands.
19. **Contract suite on the real image and on GitLab.** What runs today: the three GitHub jobs in
    `.github/workflows/ci.yml` (`contract-image` on linux/amd64 and linux/arm64, the arm64 leg under QEMU and blocking
    now that it passed on 2026-11-08; `contract-parity-sh`; and `contract-parity-pwsh`, pwsh 7 on Linux, also blocking
    now that it passed on 2026-11-08), all on the fallback image, not the real Citadel image. Since SQUIRE-40 the `contract-image` job also checks the
    hardening inside the running image (Apache's banner and TRACE, opcache, the web's disable_functions, a mounted
    secret, a Composer script), with the checks in `scripts/contract-image-checks.php` so another driver can reuse
    them. What is
    not proven: the real Citadel image (internal, with no pullable digest), Windows PowerShell 5.1, and the GitLab
    runner path (the runner is arm64, has no Docker daemon and no qemu binfmt, so the GitLab pipeline has no contract
    job). What is needed to close it: Docker access on the GitLab runner (and qemu binfmt for amd64-only images), a
    pullable copy of the real image with a digest, and a decision on where the suite lives long term. Default: the
    GitHub jobs stay; no GitLab job is committed until the runner can run it.

## Missing configuration

- **Citadel app image digest.** `SQUIRE_BASE_IMAGE=citadel/php-apache:8.4r5` is unpinned (placeholder). Citadel builds the
  image locally from `build-images/*.dockerfile`; there is no registry digest to copy. `doctor` warns (S67) until one is
  set. After building: `scripts/parity.sh pin`.
- **PROCTOR policy file.** `SQUIRE_PROCTOR_POLICY` defaults to the policy in `vendor/waynestate/creed`, when CREED is installed. Without CREED, PROCTOR runs on its own default policy with a notice.
- **PROCTOR phar.** PROCTOR 0.7.0 and later ship `proctor gate`, and the release script uses it when the installed PROCTOR has it. The phar is built from source but not yet published.
- **Preflight tag pin on GitHub.** The GitLab pipeline pins the Preflight components to the 0.4.0 release tag. The GitHub workflow stays on Preflight 0.1.4, pinned by commit, because GitHub's copy of Preflight has no later tag yet (D-069); move it when one appears.
- **Database passwords.** `.env.squire.example` holds `change-me-local-only` placeholders. The compose file refuses to start
  without real values.
- **The platform's actual PHP settings.** `.squire/php.ini` holds safe defaults, not the platform's file. See question 9.

## Not built yet

- The Citadel side of the deploy gate (`proctor gate` in `runGitPull()`, before the commit is checked out), and a supervised queue worker on Citadel (today: the scheduler-driven short worker, untested on Citadel, and the portable image's `worker` role off Citadel; see the queue workers guide).
- Checks for rules that need a running app or a server (most of the list of S-rules beyond the 26 in `BEST_PRACTICES.md`).
- A GitLab release job. Tags are cut by hand on git.wayne.edu; the push mirror carries each one to GitHub, where the `release` workflow (`.github/workflows/release.yml`) publishes it as a GitHub Release with its CHANGELOG notes, and Packagist reads the tag from the mirror.
- Templates for apps that are none of raw PHP, Laravel or a static site.
- Running the PowerShell scripts on Windows PowerShell 5.1 itself (see question 5) and on macOS.

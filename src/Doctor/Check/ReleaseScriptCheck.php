<?php

declare(strict_types=1);

namespace Waynestate\Squire\Doctor\Check;

use Waynestate\Squire\Doctor\Check;
use Waynestate\Squire\Doctor\Finding;
use Waynestate\Squire\Doctor\Project;

/**
 * What the release script does (S10 to S22, S57). It reads the script's code and not its comments, so a comment that
 * says "composer install --no-dev" cannot pass the check on its own.
 */
final class ReleaseScriptCheck implements Check
{
    public function run(Project $project): array
    {
        $release = $project->releaseScript();
        if ($release === null) {
            return [Finding::info('S12', 'No release script, so the release rules (S10 to S22) were not checked.')];
        }

        $code = $project->code($release);
        $findings = [];

        // S12: install from the lock files, without dev packages, and never update on the server.
        if (preg_match('/(?:^|&&|;|\|\|)\s*composer\s+(update|upgrade)\b/m', $code) === 1) {
            $findings[] = Finding::fail('S12', '`composer update` in a release script changes versions on the server. Use `composer install` from the lock file.', $release);
        } elseif (preg_match('/(?:^|&&|;|\|\|)\s*composer\s+install\b([^\n]*)/m', $code, $m) !== 1) {
            $findings[] = Finding::fail('S12', 'The release script never runs `composer install`.', $release);
        } elseif (!str_contains($m[1], '--no-dev')) {
            $findings[] = Finding::fail('S12', '`composer install` without --no-dev puts dev packages on the server.', $release);
        } else {
            $findings[] = Finding::pass('S12', '`composer install --no-dev` from the lock file.', $release);
            if (!str_contains($m[1], '--no-interaction') && !str_contains($code, 'COMPOSER_NO_INTERACTION')) {
                $findings[] = Finding::warn('S15', 'Composer may prompt and fail silently on Citadel. Pass --no-interaction or set COMPOSER_NO_INTERACTION=1.', $release);
            } else {
                $findings[] = Finding::pass('S15', 'Composer runs non-interactively.', $release);
            }
        }

        $hasNpmLock = $project->has('package-lock.json');
        $hasPnpmLock = $project->has('pnpm-lock.yaml');
        if (preg_match('/\bnpm\s+install\b(?!\s+(-g|--global))/', $code) === 1) {
            $findings[] = Finding::fail('S12', '`npm install` can change the lock file on the server. Use `npm ci`.', $release);
        } elseif (($hasNpmLock && !str_contains($code, 'npm ci')) || ($hasPnpmLock && !str_contains($code, '--frozen-lockfile'))) {
            $findings[] = Finding::warn('S12', 'The app has a JavaScript lock file but the release script does not install from it (npm ci, or pnpm install --frozen-lockfile).', $release);
        }

        if ($hasNpmLock || $hasPnpmLock || $project->has('package.json')) {
            $findings[] = str_contains($code, '--ignore-scripts')
                ? Finding::pass('S57', 'JavaScript packages install with --ignore-scripts.', $release)
                : Finding::warn('S57', 'Install JavaScript packages with --ignore-scripts so a compromised package cannot run code at install time.', $release);

            $npmrc = $project->read('.npmrc') ?? '';
            $findings[] = str_contains($npmrc, 'min-release-age')
                ? Finding::pass('S57', '.npmrc sets min-release-age.', '.npmrc')
                : Finding::warn('S57', 'Set min-release-age (days) in .npmrc so a version published an hour ago is not installed.', '.npmrc');
        }

        // S14
        $findings[] = str_contains($code, 'composer audit')
            ? Finding::pass('S14', 'The release script runs composer audit.', $release)
            : Finding::warn('S14', 'The release script does not run `composer audit --locked`.', $release);

        // S16 and S17 are Laravel rules.
        if ($project->isLaravel()) {
            $findings[] = preg_match('/artisan\s+(optimize|config:cache)\b/', $code) === 1
                ? Finding::pass('S16', 'The release caches framework config after the environment is in place.', $release)
                : Finding::warn('S16', 'Run `php artisan optimize` in the release so config, routes and views are cached.', $release);

            $findings[] = str_contains($code, 'migrate --force')
                ? Finding::pass('S17', 'The release runs migrate --force. Keep migrations compatible with the old code (parallel change).', $release)
                : Finding::info('S17', 'The release does not run migrations. Fine if they run another way; otherwise add `php artisan migrate --force`.', $release);
        }

        // S19: root.
        $findings[] = preg_match('/\bid\s+-u\b/', $code) === 1
            ? Finding::pass('S19', 'The release script checks it is not running as root.', $release)
            : Finding::warn('S19', 'The release script does not refuse to run as root; files it creates could not be written by the web process.', $release);

        // S20: PROCTOR as a report, not a gate.
        if (stripos($code, 'proctor') === false) {
            $findings[] = Finding::warn('S20', 'The release script does not run PROCTOR. Run `proctor check` as a report in the deploy log.', $release);
        } elseif (preg_match_all('/^\s*(\S*\/)?proctor\s+(check|gate)\b[^\n]*$/m', $code, $m) > 0 && array_filter($m[0], static fn(string $line): bool => !str_contains($line, '||')) !== []) {
            $findings[] = Finding::fail('S20', 'PROCTOR runs as a bare command, so its exit code stops the release. After a Quick Update the new commit is already live; make it a report (`|| status=$?`).', $release);
        } else {
            $findings[] = Finding::pass('S20', 'PROCTOR runs as a report and cannot stop the release.', $release);
        }

        // S21: smoke test.
        $findings[] = preg_match('#/up\b#', $code) === 1 && preg_match('/\b(curl|wget|file_get_contents|http_status)\b/', $code) === 1
            ? Finding::pass('S21', 'The release smoke-tests the health endpoint.', $release)
            : Finding::warn('S21', 'The release does not request /up at the end. Add a smoke test that fails the script unless it answers 200.', $release);

        // S22: step markers.
        $findings[] = preg_match('/^\s*(step\s*\(\)|function\s+step\b)/m', $code) === 1 || substr_count($code, '==>') >= 3
            ? Finding::pass('S22', 'The release prints step markers.', $release)
            : Finding::warn('S22', 'Print one marker line per step so the dashboard log viewer shows where a release stopped.', $release);

        return $findings;
    }
}

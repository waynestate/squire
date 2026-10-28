<?php

declare(strict_types=1);

namespace Waynestate\Squire\Doctor\Check;

use Waynestate\Squire\Doctor\Check;
use Waynestate\Squire\Doctor\Finding;
use Waynestate\Squire\Doctor\Project;

/**
 * Repository-level rules: the lock file (S13), line endings (S8), secrets kept out of git (S15, S42), and
 * migrations that work on MySQL 5.6 (S18).
 */
final class RepositoryCheck implements Check
{
    public function run(Project $project): array
    {
        $findings = [];

        // S13
        $findings[] = $project->has('composer.lock')
            ? Finding::pass('S13', 'composer.lock is present.', 'composer.lock')
            : Finding::fail('S13', 'composer.lock is missing. The lock file is the release contract; commit it.');

        // S8
        $attributes = $project->read('.gitattributes');
        if ($attributes === null) {
            $findings[] = Finding::warn('S8', 'No .gitattributes. Git on Windows can convert shell scripts to CRLF; add `* text=auto eol=lf` and `*.sh text eol=lf`.');
        } elseif (preg_match('/^\*\s.*eol=lf|^\*\.sh\s.*eol=lf/m', $attributes) !== 1) {
            $findings[] = Finding::warn('S8', '.gitattributes does not force LF for shell scripts. Add `*.sh text eol=lf`.', '.gitattributes');
        } else {
            $findings[] = Finding::pass('S8', '.gitattributes forces LF for shell scripts.', '.gitattributes');
        }

        // S15 and S42
        $ignore = $project->read('.gitignore') ?? '';
        $ignored = static fn(string $name): bool => preg_match('/^\/?' . preg_quote($name, '/') . '\s*$/m', $ignore) === 1;

        if (!$ignored('.env')) {
            $findings[] = Finding::warn('S42', '.gitignore does not ignore .env. Keep real secrets out of git; commit .env.example instead.', '.gitignore');
        } else {
            $findings[] = Finding::pass('S42', '.env is ignored by git.', '.gitignore');
        }
        if ($project->has('auth.json') && !$ignored('auth.json')) {
            $findings[] = Finding::fail('S15', 'auth.json holds Composer credentials and is not in .gitignore.', 'auth.json');
        }
        if ($project->has('docker-compose.squire.yml') && !$ignored('.env.squire')) {
            $findings[] = Finding::warn('S42', '.gitignore does not ignore .env.squire, which holds the local database passwords.', '.gitignore');
        }

        // S18
        $findings = [...$findings, ...$this->migrations($project)];

        return $findings;
    }

    /** @return list<Finding> */
    private function migrations(Project $project): array
    {
        $files = $project->filesUnder('database/migrations', ['php']);
        if ($files === []) {
            return [];
        }

        $json = [];
        $indexes = false;
        foreach ($files as $file) {
            $code = $project->read($file) ?? '';
            if (preg_match('/->jsonb?\s*\(/', $code) === 1) {
                $json[] = $file;
            }
            if (preg_match('/->(unique|index)\s*\(/', $code) === 1) {
                $indexes = true;
            }
        }

        $findings = [];
        $findings[] = $json === []
            ? Finding::pass('S18', 'No JSON columns in the migrations (MySQL 5.6 has none).')
            : Finding::warn('S18', 'JSON columns in ' . implode(', ', array_slice($json, 0, 3)) . ': the shared MySQL 5.6 server has no JSON type.');

        if ($indexes) {
            $has191 = false;
            foreach ($project->filesUnder('app', ['php'], 500) as $file) {
                if (str_contains($project->read($file) ?? '', 'defaultStringLength(191)')) {
                    $has191 = true;
                    break;
                }
            }
            $findings[] = $has191
                ? Finding::pass('S18', 'Schema::defaultStringLength(191) is set, so indexed strings fit MySQL 5.6.')
                : Finding::warn('S18', 'Migrations create indexes but nothing calls Schema::defaultStringLength(191). Indexed utf8mb4 strings over 191 characters fail on MySQL 5.6.');
        }

        return $findings;
    }
}

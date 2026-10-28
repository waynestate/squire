<?php

declare(strict_types=1);

namespace Waynestate\Squire\Doctor\Check;

use Waynestate\Squire\Doctor\Check;
use Waynestate\Squire\Doctor\Finding;
use Waynestate\Squire\Doctor\Project;

/**
 * Repository-level rules: line endings (S8) and secrets kept out of git (S15, S42). The lock file (S13) and
 * MySQL 5.6 safety (S18) are owned by PROCTOR; the doctor hands off to it (S50) instead of repeating them.
 */
final class RepositoryCheck implements Check
{
    public function run(Project $project): array
    {
        $findings = [];

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

        return $findings;
    }
}

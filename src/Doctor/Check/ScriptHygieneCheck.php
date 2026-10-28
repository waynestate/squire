<?php

declare(strict_types=1);

namespace Waynestate\Squire\Doctor\Check;

use Waynestate\Squire\Doctor\Check;
use Waynestate\Squire\Doctor\Finding;
use Waynestate\Squire\Doctor\Project;

/**
 * S3, S4, S8 and S19 across every shell script that runs on Citadel: strict mode, no piped downloads, LF line
 * endings, and no sudo.
 */
final class ScriptHygieneCheck implements Check
{
    public function run(Project $project): array
    {
        $scripts = $project->shellScripts();
        if ($scripts === []) {
            return [Finding::info('S3', 'No shell scripts to examine.')];
        }

        $findings = [];
        $crlf = [];
        $lax = [];
        $noPipefail = [];
        $piped = [];
        $sudo = [];

        foreach ($scripts as $script) {
            $raw = $project->read($script) ?? '';
            $code = $project->code($script);

            if (str_contains($raw, "\r\n")) {
                $crlf[] = $script;
            }
            if (preg_match('/^\s*set\s+(-[a-zA-Z]*e[a-zA-Z]*\b|-o\s+errexit\b)/m', $code) !== 1) {
                $lax[] = $script;
            } elseif (preg_match('/^#!.*\bbash\b/', $raw) === 1 && !str_contains($code, 'pipefail')) {
                $noPipefail[] = $script;
            }
            if (preg_match('/\b(curl|wget)\b[^|\n]*\|\s*(sudo\s+)?(ba|z|da)?sh\b/', $code) === 1) {
                $piped[] = $script;
            }
            if (preg_match('/(^|[;&|]\s*)sudo\s/m', $code) === 1) {
                $sudo[] = $script;
            }
        }

        $findings[] = $crlf === []
            ? Finding::pass('S8', 'No CRLF line endings in the scripts.')
            : Finding::fail('S8', 'CRLF line endings break a shell script on Linux: ' . implode(', ', $crlf) . '. Convert to LF and add a .gitattributes rule.');

        $findings[] = $lax === []
            ? Finding::pass('S3', 'Every script stops on error (set -e).')
            : Finding::warn('S3', 'No `set -e` (or errexit) in: ' . implode(', ', $lax) . '. A failing step would not stop the script.');

        if ($noPipefail !== []) {
            $findings[] = Finding::warn('S3', 'Bash scripts without `set -o pipefail`: ' . implode(', ', $noPipefail) . '. A failing command at the start of a pipe goes unnoticed.');
        }

        $findings[] = $piped === []
            ? Finding::pass('S4', 'No download is piped into a shell.')
            : Finding::fail('S4', 'A download is piped into a shell in: ' . implode(', ', $piped) . '. Pin the version and verify a checksum instead.');

        $findings[] = $sudo === []
            ? Finding::pass('S19', 'No script uses sudo.')
            : Finding::warn('S19', 'sudo in: ' . implode(', ', $sudo) . '. Application tooling should run as the app user, not root.');

        return $findings;
    }
}

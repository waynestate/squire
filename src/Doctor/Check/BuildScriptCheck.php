<?php

declare(strict_types=1);

namespace Waynestate\Squire\Doctor\Check;

use Waynestate\Squire\Doctor\Check;
use Waynestate\Squire\Doctor\Finding;
use Waynestate\Squire\Doctor\Project;

/**
 * S1 and S9: build.sh holds image tooling only, and the release is split into three scripts that each do one job.
 */
final class BuildScriptCheck implements Check
{
    public function run(Project $project): array
    {
        $findings = [];

        if (!$project->has('build.sh')) {
            $findings[] = Finding::warn('S9', 'build.sh is missing. Citadel runs it at image build for image-level tooling; `squire init` writes the standard one.');
        } else {
            $code = $project->code('build.sh');
            if (preg_match('/\b(composer|npm|pnpm|yarn)\s+(install|ci|update|require|run)\b/', $code, $m) === 1) {
                $findings[] = Finding::fail(
                    'S1',
                    sprintf('build.sh runs `%s`. The app source is absent at image build; install dependencies in the release script.', trim($m[0])),
                    'build.sh',
                );
            } else {
                $findings[] = Finding::pass('S1', 'build.sh runs no composer or npm commands.', 'build.sh');
            }
        }

        $hooks = $project->filesIn('deployments');
        $release = $project->releaseScript();

        if ($release === null) {
            $findings[] = Finding::fail('S9', 'No release script found (scripts/release.sh). Without it nothing installs, migrates or smoke-tests a release.');
        } elseif ($hooks === []) {
            $findings[] = Finding::warn('S9', 'No deployments/ hook found. Add one for first-release work that calls the release script.');
        } else {
            $findings[] = Finding::pass('S9', sprintf('Build tooling, a one-time hook (%s) and a release script (%s) are separate.', $hooks[0], $release));
        }

        return $findings;
    }
}

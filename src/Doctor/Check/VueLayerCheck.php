<?php

declare(strict_types=1);

namespace Waynestate\Squire\Doctor\Check;

use Waynestate\Squire\Doctor\Check;
use Waynestate\Squire\Doctor\Finding;
use Waynestate\Squire\Doctor\Project;
use Waynestate\Squire\Template\Catalog;

/**
 * Reports the Vue layer: a Vue app should not commit node_modules/.
 */
final class VueLayerCheck implements Check
{
    public function run(Project $project): array
    {
        if (Catalog::detectLayer($project->path) === null) {
            return [];
        }

        if ($project->hasDirectory('node_modules') && $project->gitTracked('node_modules')) {
            return [Finding::warn('S78', 'node_modules/ is tracked in git; add it to .gitignore and remove it from the index.', 'node_modules')];
        }

        return [Finding::pass('S78', 'The Vue layer is present and node_modules/ is not tracked in git.', 'package.json')];
    }
}

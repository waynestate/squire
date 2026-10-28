<?php

declare(strict_types=1);

namespace Waynestate\Squire\Doctor\Check;

use Waynestate\Squire\Doctor\Check;
use Waynestate\Squire\Doctor\Finding;
use Waynestate\Squire\Doctor\Project;
use Waynestate\Squire\Squire;
use Waynestate\Squire\Template\Catalog;
use Waynestate\Squire\Template\Initializer;
use Waynestate\Squire\Template\Manifest;
use Waynestate\Squire\ToolError;

/**
 * Reports files that fell behind SQUIRE's templates. It is a warning and never a failure: an edited release script is
 * the team's right, and the other checks judge whether it is still correct.
 */
final class DriftCheck implements Check
{
    public function __construct(private readonly Catalog $catalog) {}

    public function run(Project $project): array
    {
        try {
            $manifest = Manifest::load($project->path);
        } catch (ToolError $e) {
            return [Finding::warn('S9', $e->getMessage(), Squire::MANIFEST)];
        }
        if ($manifest === null) {
            return [Finding::info('S9', sprintf('No %s, so drift from the SQUIRE templates was not checked. `squire init` creates it.', Squire::MANIFEST))];
        }

        $layer = $manifest->layer ?? Catalog::detectLayer($project->path);
        $results = (new Initializer($this->catalog))->run($project->path, $manifest->target, $manifest->groups, false, true, $layer);

        $findings = [];
        foreach ($results as $result) {
            if (!$result->isCurrent()) {
                $findings[] = Finding::warn('S9', sprintf('%s is %s: %s.', $result->path, $result->state, $result->detail), $result->path);
            }
        }
        if ($findings === []) {
            $findings[] = Finding::pass('S9', sprintf('Every SQUIRE file matches template version %s.', Squire::VERSION));
        }

        return $findings;
    }
}

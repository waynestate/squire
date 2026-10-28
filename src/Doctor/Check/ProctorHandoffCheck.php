<?php

declare(strict_types=1);

namespace Waynestate\Squire\Doctor\Check;

use Waynestate\Squire\Doctor\Check;
use Waynestate\Squire\Doctor\Finding;
use Waynestate\Squire\Doctor\Project;

/**
 * S50: the hand-off. The lock file (S13), composer audit and MySQL 5.6 safety (S18) are owned by PROCTOR, so the
 * doctor points at it instead of repeating those checks. It never runs PROCTOR itself: doctor may run on a Citadel
 * host at deploy time, where PROCTOR is not installed.
 */
final class ProctorHandoffCheck implements Check
{
    public function run(Project $project): array
    {
        return $project->has('vendor/bin/proctor')
            ? [Finding::info('S50', 'Run `vendor/bin/proctor check` for the lock file, composer audit and MySQL 5.6 checks.', 'vendor/bin/proctor')]
            : [Finding::info('S50', 'PROCTOR is not installed. Get it with `composer require --dev waynestate/proctor` (or the PROCTOR phar), then run `proctor check` for the lock file, composer audit and MySQL 5.6 checks.')];
    }
}

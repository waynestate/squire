<?php

declare(strict_types=1);

namespace Waynestate\Squire\Doctor\Check;

use Waynestate\Squire\Doctor\Check;
use Waynestate\Squire\Doctor\Finding;
use Waynestate\Squire\Doctor\Project;
use Waynestate\Squire\Squire;
use Waynestate\Squire\Template\Catalog;

/**
 * S35: no parity kit files left over. The kit left Squire in 0.12.0 and Parity runs Citadel on your computer now, so
 * any of its files still in the app are noted (info, never a failure) with the way out: `parity init --from-squire`,
 * then delete them. The kit's own checks (S36, S37, S41, S67, S70, S71) left with it; Parity checks its own setup.
 */
final class ParityCheck implements Check
{
    public function run(Project $project): array
    {
        $left = array_values(array_filter(Catalog::LEFTOVER_KIT_FILES, static fn(string $file): bool => $project->has($file)));
        if ($left === []) {
            return [Finding::pass('S35', 'No parity kit files left over. Parity runs the app on your computer: ' . Squire::PARITY_URL)];
        }

        return [Finding::info(
            'S35',
            sprintf(
                'Parity kit files left over: %s. The kit left Squire in 0.12.0: run `parity init --from-squire` to carry its settings over, then delete them; use Parity (%s).',
                implode(', ', $left),
                Squire::PARITY_URL,
            ),
            $left[0],
        )];
    }
}

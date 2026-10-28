<?php

declare(strict_types=1);

namespace Waynestate\Squire\Doctor;

/**
 * A group of related rule checks. Each returns the findings for the rules it covers.
 */
interface Check
{
    /** @return list<Finding> */
    public function run(Project $project): array;
}

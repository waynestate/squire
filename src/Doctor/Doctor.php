<?php

declare(strict_types=1);

namespace Waynestate\Squire\Doctor;

use Waynestate\Squire\Doctor\Check\BuildScriptCheck;
use Waynestate\Squire\Doctor\Check\DriftCheck;
use Waynestate\Squire\Doctor\Check\HealthEndpointCheck;
use Waynestate\Squire\Doctor\Check\ParityCheck;
use Waynestate\Squire\Doctor\Check\ProctorHandoffCheck;
use Waynestate\Squire\Doctor\Check\ReleaseScriptCheck;
use Waynestate\Squire\Doctor\Check\RepositoryCheck;
use Waynestate\Squire\Doctor\Check\ScriptHygieneCheck;
use Waynestate\Squire\Squire;
use Waynestate\Squire\Template\Catalog;

/**
 * Runs every check against an app and gathers the findings. It only reads; nothing in the app changes.
 */
final class Doctor
{
    /** @var list<Check> */
    private array $checks;

    /**
     * @param list<Check>|null $checks the checks to run; null runs all of them
     */
    public function __construct(?array $checks = null)
    {
        $this->checks = $checks ?? [
            new BuildScriptCheck(),
            new ScriptHygieneCheck(),
            new ReleaseScriptCheck(),
            new HealthEndpointCheck(),
            new RepositoryCheck(),
            new ParityCheck(),
            new DriftCheck(new Catalog(Squire::templatesDir())),
            new ProctorHandoffCheck(),
        ];
    }

    /** @return list<Finding> */
    public function run(Project $project): array
    {
        $findings = [];
        foreach ($this->checks as $check) {
            foreach ($check->run($project) as $finding) {
                $findings[] = $finding;
            }
        }

        return $findings;
    }

    /**
     * 0 when nothing failed (warnings are allowed), 1 when at least one finding failed.
     *
     * @param list<Finding> $findings
     */
    public static function exitCode(array $findings): int
    {
        foreach ($findings as $finding) {
            if ($finding->status === Finding::FAIL) {
                return 1;
            }
        }

        return 0;
    }
}

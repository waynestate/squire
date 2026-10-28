<?php

declare(strict_types=1);

namespace Waynestate\Squire\Doctor\Check;

use Waynestate\Squire\Doctor\Check;
use Waynestate\Squire\Doctor\Finding;
use Waynestate\Squire\Doctor\Project;

/**
 * S55: the health check must be fast and must not boot the framework through artisan.
 */
final class HealthEndpointCheck implements Check
{
    public function run(Project $project): array
    {
        if ($project->has('health.php')) {
            $code = $project->code('health.php');
            $bootsFramework = preg_match('/\b(exec|system|passthru|shell_exec|proc_open|popen)\s*\([^;]*artisan/s', $code) === 1
                || preg_match('/php\s+artisan\b/', $code) === 1
                || preg_match('/\brequire(_once)?\b[^;]*bootstrap\/app\.php/', $code) === 1;
            if ($bootsFramework) {
                return [Finding::fail('S55', 'health.php runs artisan or boots the framework. That is slow, and Citadel runs the check every 5 minutes as root.', 'health.php')];
            }

            return [Finding::pass('S55', 'health.php is an artisan-free health check.', 'health.php')];
        }

        if ($project->requires('waynestate/helmet')) {
            return [Finding::pass('S55', 'HELMET supplies the /up handler. Point the Citadel health check at it.')];
        }

        $bootstrap = $project->read('bootstrap/app.php') ?? '';
        if ($project->isLaravel() && preg_match('/health:\s*[\'"]\/up[\'"]/', $bootstrap) === 1) {
            return [Finding::pass('S55', "Laravel's built-in /up route is enabled in bootstrap/app.php.", 'bootstrap/app.php')];
        }

        return [Finding::warn('S55', 'No artisan-free health check found (health.php, HELMET, or the framework /up route).')];
    }
}

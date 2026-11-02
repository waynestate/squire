<?php

declare(strict_types=1);

namespace Waynestate\Squire\Advisor;

use Waynestate\Advisor\Exception\ToolInputError;
use Waynestate\Advisor\Module\Module;
use Waynestate\Advisor\Module\ModuleContext;
use Waynestate\Advisor\Module\ToolDefinition;
use Waynestate\Squire\Doctor\Doctor;
use Waynestate\Squire\Doctor\Project;
use Waynestate\Squire\Doctor\Report;
use Waynestate\Squire\Squire;
use Waynestate\Squire\Template\Catalog;
use Waynestate\Squire\Template\FileResult;
use Waynestate\Squire\Template\Initializer;
use Waynestate\Squire\Template\Manifest;
use Waynestate\Squire\ToolError;

/**
 * SQUIRE's ADVISOR module: two read-only tools that run this repository's own checks in-process, against an app
 * inside the project root. No subprocess, no network, no model call: the tool takes one argument (the app folder)
 * and nothing free-form, and it reports what it ran and where the answer came from.
 */
final class SquireModule implements Module
{
    /** The groups `squire init` writes by default; the drift check covers the same ground. */
    private const GROUPS = ['release', 'parity'];

    public function __construct(private readonly ModuleContext $context) {}

    public function id(): string
    {
        return 'squire';
    }

    /** @return list<ToolDefinition> */
    public function tools(): array
    {
        return [
            self::doctorTool($this->context),
            self::driftTool($this->context),
        ];
    }

    /** @return list<\Waynestate\Advisor\Module\ResourceDefinition> */
    public function resources(): array
    {
        return [];
    }

    /** @return list<\Waynestate\Advisor\Module\PromptDefinition> */
    public function prompts(): array
    {
        return [];
    }

    private static function doctorTool(ModuleContext $context): ToolDefinition
    {
        return new ToolDefinition(
            name: 'squire_doctor',
            title: 'Run SQUIRE doctor on an app',
            description: 'Runs SQUIRE\'s own deploy-setup checks (the S-rules, S1 to S78) against an app folder and returns the findings as JSON, the same report `vendor/bin/squire doctor --format=json` prints. It runs in-process: no subprocess, no network, and it changes nothing in the app. The path must be inside the project. It reports only: SQUIRE in CI decides pass or fail.',
            inputSchema: [
                'type' => 'object',
                'properties' => [
                    'path' => ['type' => 'string', 'maxLength' => 1024, 'description' => 'App folder, inside the project root. Default: the root itself.'],
                ],
                'additionalProperties' => false,
            ],
            handler: static function (array $args) use ($context): array {
                $app = $context->guard->resolve((string) ($args['path'] ?? '.'));
                if (!is_dir($app)) {
                    throw new ToolInputError('The path must be a directory that holds the app.');
                }

                try {
                    $findings = (new Doctor())->run(new Project($app));
                } catch (ToolError $e) {
                    throw new ToolInputError($e->getMessage());
                }

                /** @var array<string, mixed> $report the shape of `squire doctor --format=json` */
                $report = json_decode(Report::json($app, $findings), true, 512, JSON_THROW_ON_ERROR);

                return [
                    'tool' => Squire::TOOL,
                    'ran' => sprintf('Ran the SQUIRE doctor checks in-process against %s (no subprocess, no network).', $context->guard->relative($app)),
                    'squire_version' => $report['version'],
                    'app' => $context->guard->relative($app),
                    'result' => $report['result'],
                    'exit' => $report['exit'],
                    'counts' => $report['counts'],
                    'findings' => $report['findings'],
                    'notice' => 'Reference data from SQUIRE ' . Squire::version() . '. SQUIRE in CI decides pass or fail.',
                ];
            },
            group: 'squire',
            readOnly: true,
        );
    }

    private static function driftTool(ModuleContext $context): ToolDefinition
    {
        return new ToolDefinition(
            name: 'squire_drift',
            title: 'Check an app for SQUIRE template drift',
            description: 'Reports which of the files SQUIRE wrote into an app have drifted from the current templates, the same check `vendor/bin/squire init --check` performs: missing, outdated, edited or untracked, per file. It runs in-process with the check flag set, so it writes nothing, makes no network call, and leaves the app exactly as it found it. The path must be inside the project. It reports only: `squire init` is the fix, and it never overwrites an edit.',
            inputSchema: [
                'type' => 'object',
                'properties' => [
                    'path' => ['type' => 'string', 'maxLength' => 1024, 'description' => 'App folder, inside the project root. Default: the root itself.'],
                ],
                'additionalProperties' => false,
            ],
            handler: static function (array $args) use ($context): array {
                $app = $context->guard->resolve((string) ($args['path'] ?? '.'));
                if (!is_dir($app)) {
                    throw new ToolInputError('The path must be a directory that holds the app.');
                }

                try {
                    // The same resolution `squire init --check` uses: the recorded target wins, else detection;
                    // the default groups and the detected layer.
                    $manifest = Manifest::load($app);
                    $target = $manifest !== null ? $manifest->target : Catalog::detectTarget($app);
                    $layer = Catalog::detectLayer($app);
                    $results = (new Initializer(new Catalog(Squire::templatesDir())))->run($app, $target, self::GROUPS, false, true, $layer);
                } catch (ToolError $e) {
                    throw new ToolInputError($e->getMessage());
                }

                $files = [];
                foreach ($results as $result) {
                    $files[] = [
                        'path' => $result->path,
                        'state' => $result->state,
                        'detail' => $result->detail,
                    ];
                }
                $drift = array_filter($results, static fn(FileResult $r): bool => !$r->isCurrent());

                return [
                    'tool' => Squire::TOOL,
                    'ran' => sprintf('Ran the SQUIRE drift check in-process against %s, the same check `squire init --check` performs (nothing was written).', $context->guard->relative($app)),
                    'squire_version' => Squire::version(),
                    'app' => $context->guard->relative($app),
                    'target' => $target,
                    'groups' => self::GROUPS,
                    'layer' => $layer,
                    'result' => $drift === [] ? 'pass' : 'drift',
                    'need_attention' => count($drift),
                    'total' => count($results),
                    'files' => $files,
                    'notice' => 'Reference data from SQUIRE ' . Squire::version() . '. `squire init` updates the files; it never overwrites an edit.',
                ];
            },
            group: 'squire',
            readOnly: true,
        );
    }
}

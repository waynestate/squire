<?php

declare(strict_types=1);

namespace Waynestate\Squire\Tests\Advisor;

use DateTimeImmutable;
use ReflectionClass;
use RuntimeException;
use Waynestate\Advisor\Advisor;
use Waynestate\Advisor\Config;
use Waynestate\Advisor\Content\ContentBundle;
use Waynestate\Advisor\Exception\ToolInputError;
use Waynestate\Advisor\Module\ModuleContext;
use Waynestate\Advisor\Security\PathGuard;
use Waynestate\Advisor\Security\ProcessOutput;
use Waynestate\Advisor\Security\ProcessRunner;
use Waynestate\Advisor\Support\Clock;
use Waynestate\Squire\Advisor\SquireModule;
use Waynestate\Squire\Doctor\Doctor;
use Waynestate\Squire\Doctor\Project;
use Waynestate\Squire\Doctor\Report;
use Waynestate\Squire\Squire;
use Waynestate\Squire\Tests\SquireTestCase;

final class SquireModuleTest extends SquireTestCase
{
    /**
     * A module whose project root is the given directory, with a process runner that fails loudly if any tool
     * ever tries to start a subprocess.
     */
    private function module(string $root): SquireModule
    {
        $clock = new class implements Clock {
            public function today(): DateTimeImmutable
            {
                return new DateTimeImmutable('2026-11-02');
            }
        };
        $processes = new class implements ProcessRunner {
            public function run(array $argv, string $cwd, int $timeoutSeconds): ProcessOutput
            {
                throw new RuntimeException('A SQUIRE tool started a subprocess; the tools must run in-process.');
            }
        };
        $context = new ModuleContext(
            Config::fromEnv(['ADVISOR_ENABLED' => '1', 'ADVISOR_PROJECT_ROOT' => $root]),
            new ContentBundle(dirname((new ReflectionClass(Advisor::class))->getFileName()) . '/../data'),
            $clock,
            new PathGuard($root),
            $processes,
        );

        return new SquireModule($context);
    }

    /** The fixture app lives under the system temp dir, so the temp dir is the project root for these tests. */
    private function doctor(SquireModule $module, string $app): array
    {
        return ($module->tools()[0]->handler)(['path' => $app]);
    }

    private function drift(SquireModule $module, string $app): array
    {
        return ($module->tools()[1]->handler)(['path' => $app]);
    }

    public function testTheModuleDeclaresTwoReadOnlyToolsInItsOwnGroup(): void
    {
        $module = $this->module($this->tempDir());

        self::assertSame('squire', $module->id());
        self::assertSame(['squire_doctor', 'squire_drift'], array_map(static fn($t) => $t->name, $module->tools()));
        foreach ($module->tools() as $tool) {
            self::assertTrue($tool->readOnly, $tool->name . ' must be declared read-only');
            self::assertSame('squire', $tool->group);
            self::assertSame(false, $tool->inputSchema['additionalProperties']);
        }
        self::assertSame([], $module->resources());
        self::assertSame([], $module->prompts());
    }

    public function testDoctorReturnsLabelledFindingsOnAFixtureApp(): void
    {
        $app = $this->initializedApp();
        $result = $this->doctor($this->module(sys_get_temp_dir()), $app);

        self::assertSame(Squire::TOOL, $result['tool']);
        self::assertSame(Squire::version(), $result['squire_version']);
        self::assertSame('pass', $result['result']);
        self::assertSame(0, $result['exit']);
        self::assertSame(0, $result['counts']['fail']);
        self::assertStringContainsString('SQUIRE doctor', $result['ran']);
        self::assertStringContainsString('in-process', $result['ran']);
        self::assertStringStartsWith('Reference data from SQUIRE', $result['notice']);

        // The findings are exactly what `squire doctor --format=json` prints, relabelled by relative path.
        $expected = json_decode(Report::json($app, (new Doctor())->run(new Project($app))), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($expected['findings'], $result['findings']);
        foreach ($result['findings'] as $finding) {
            self::assertArrayHasKey('rule', $finding);
            self::assertArrayHasKey('status', $finding);
            self::assertArrayHasKey('message', $finding);
        }
    }

    public function testDoctorReportsFailuresWhenTheAppIsBroken(): void
    {
        $app = $this->initializedApp();
        file_put_contents($app . '/build.sh', "#!/bin/sh\nset -eu\ncomposer install\n");
        $result = $this->doctor($this->module(sys_get_temp_dir()), $app);

        self::assertSame('fail', $result['result']);
        self::assertGreaterThan(0, $result['exit']);
        self::assertGreaterThanOrEqual(1, $result['counts']['fail']);
        $rules = array_filter($result['findings'], static fn($f) => $f['status'] === 'fail');
        self::assertContains('S1', array_column($rules, 'rule'));
    }

    public function testDriftPassesOnAFreshAppAndFlagsAnEditedFileWithoutWritingAnything(): void
    {
        $app = $this->initializedApp();
        $module = $this->module(sys_get_temp_dir());

        $fresh = $this->drift($module, $app);
        self::assertSame('pass', $fresh['result']);
        self::assertSame(0, $fresh['need_attention']);
        self::assertGreaterThan(0, $fresh['total']);
        self::assertSame('raw', $fresh['target']);
        self::assertSame(['release', 'parity'], $fresh['groups']);
        self::assertSame('ok', $fresh['files'][0]['state']);
        self::assertStringContainsString('init --check', $fresh['ran']);

        // Edit a scaffolded file: the drift check must flag it and leave the edit in place.
        $edited = "#!/bin/sh\nset -eu\necho hacked\n";
        file_put_contents($app . '/build.sh', $edited);
        $drifted = $this->drift($module, $app);

        self::assertSame('drift', $drifted['result']);
        self::assertSame(1, $drifted['need_attention']);
        $byPath = [];
        foreach ($drifted['files'] as $file) {
            $byPath[$file['path']] = $file['state'];
        }
        self::assertSame('modified', $byPath['build.sh']);
        self::assertSame($edited, file_get_contents($app . '/build.sh'));

        // Delete one: it shows up as missing, still nothing written.
        unlink($app . '/scripts/release.sh');
        $gone = $this->drift($module, $app);
        self::assertSame(2, $gone['need_attention']);
        $byPath = [];
        foreach ($gone['files'] as $file) {
            $byPath[$file['path']] = $file['state'];
        }
        self::assertSame('missing', $byPath['scripts/release.sh']);
        self::assertFalse(is_file($app . '/scripts/release.sh'));
    }

    public function testPathsOutsideTheProjectRootAreRefused(): void
    {
        $app = $this->initializedApp();
        $module = $this->module(sys_get_temp_dir());
        $outside = dirname(sys_get_temp_dir());

        foreach ([['path' => $outside], ['path' => '../..'], ['path' => '~/.ssh']] as $args) {
            try {
                $this->doctorHandler($module, $args);
                self::fail('Expected a refusal for ' . json_encode($args));
            } catch (ToolInputError $e) {
                self::assertStringContainsString('project', $e->getMessage());
            }
            try {
                $this->driftHandler($module, $args);
                self::fail('Expected a refusal for ' . json_encode($args));
            } catch (ToolInputError $e) {
                self::assertStringContainsString('project', $e->getMessage());
            }
        }
    }

    public function testAPathThatIsNotADirectoryIsRefused(): void
    {
        $app = $this->initializedApp();
        $module = $this->module(sys_get_temp_dir());

        try {
            $this->doctorHandler($module, ['path' => $app . '/build.sh']);
            self::fail('Expected a refusal for a file path');
        } catch (ToolInputError $e) {
            self::assertStringContainsString('directory', $e->getMessage());
        }
    }

    public function testComposerJsonDoesNotRequireAdvisor(): void
    {
        $json = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);

        self::assertArrayNotHasKey('waynestate/advisor', $json['require'] ?? []);
        self::assertArrayHasKey('waynestate/advisor', $json['require-dev']);
        self::assertArrayHasKey('waynestate/advisor', $json['suggest']);
    }

    /** @param array<string, mixed> $args */
    private function doctorHandler(SquireModule $module, array $args): array
    {
        return ($module->tools()[0]->handler)($args);
    }

    /** @param array<string, mixed> $args */
    private function driftHandler(SquireModule $module, array $args): array
    {
        return ($module->tools()[1]->handler)($args);
    }
}

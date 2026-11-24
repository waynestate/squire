<?php

declare(strict_types=1);

namespace Waynestate\Squire\Tests;

use Symfony\Component\Console\Tester\CommandTester;
use Waynestate\Squire\Console\InitCommand;

/**
 * `squire init --check` guards the deploy layer name (D-171): a `deploy/composer.json` with no name, or a
 * `waynestate/*` name, needs attention; `app/deploy` (or any other name) does not. Squire reports the fix and
 * never edits the file.
 */
final class InitDeployNameTest extends SquireTestCase
{
    /**
     * Runs `squire init` in-process and returns the exit code and display.
     *
     * @param array<string, string> $options option name (with dashes) => value, e.g. ['--check' => '']
     *
     * @return array{exit: int, stdout: string}
     */
    private function init(string $app, array $options = []): array
    {
        $tester = new CommandTester(new InitCommand());
        $exit = $tester->execute(['--path' => $app] + $options);

        return ['exit' => $exit, 'stdout' => $tester->getDisplay()];
    }

    /** Writes a deploy/composer.json fixture into the app. */
    private function deployComposer(string $app, string $json): void
    {
        $this->put($app, 'deploy/composer.json', $json . "\n");
    }

    public function testNoNameIsFlagged(): void
    {
        $app = $this->app();
        $first = $this->init($app);
        self::assertSame(0, $first['exit'], $first['stdout']);
        $this->deployComposer($app, '{"require":{"php":"^8.3"}}');

        $result = $this->init($app, ['--check' => true]);

        self::assertSame(1, $result['exit'], $result['stdout']);
        self::assertStringContainsString('deploy/composer.json', $result['stdout']);
        self::assertStringContainsString('set "name" to "app/deploy" (D-171)', $result['stdout']);
    }

    public function testWaynestateNameIsFlagged(): void
    {
        $app = $this->app();
        $first = $this->init($app);
        self::assertSame(0, $first['exit'], $first['stdout']);
        $this->deployComposer($app, '{"name":"waynestate/x","require":{"php":"^8.3"}}');

        $result = $this->init($app, ['--check' => true]);

        self::assertSame(1, $result['exit'], $result['stdout']);
        self::assertStringContainsString('deploy/composer.json', $result['stdout']);
        self::assertStringContainsString('set "name" to "app/deploy" (D-171)', $result['stdout']);
    }

    public function testAppDeployNamePasses(): void
    {
        $app = $this->app();
        $first = $this->init($app);
        self::assertSame(0, $first['exit'], $first['stdout']);
        $this->deployComposer($app, '{"name":"app/deploy","require":{"php":"^8.3"}}');

        $result = $this->init($app, ['--check' => true]);

        self::assertSame(0, $result['exit'], $result['stdout']);
        self::assertStringNotContainsString('deploy/composer.json', $result['stdout']);
    }
}

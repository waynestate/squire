<?php

declare(strict_types=1);

namespace Waynestate\Squire\Tests;

use Symfony\Component\Console\Tester\CommandTester;
use Waynestate\Squire\Console\InitCommand;
use Waynestate\Squire\Squire;

/**
 * A fresh `squire init` writes only the release group; parity is opt-in (D-082).
 */
final class InitDefaultGroupsTest extends SquireTestCase
{
    /**
     * Runs `squire init` in-process and returns the exit code and display.
     *
     * @param array<string, string> $options option name (with dashes) => value, e.g. ['--only' => 'release,parity']
     *
     * @return array{exit: int, stdout: string}
     */
    private function init(string $app, array $options = []): array
    {
        $tester = new CommandTester(new InitCommand());
        $exit = $tester->execute(['--path' => $app] + $options);

        return ['exit' => $exit, 'stdout' => $tester->getDisplay()];
    }

    public function testANewInitWritesOnlyTheReleaseGroup(): void
    {
        $app = $this->app();
        $result = $this->init($app);

        self::assertSame(0, $result['exit'], $result['stdout']);
        self::assertFileExists($app . '/build.sh');
        self::assertFileExists($app . '/scripts/release.sh');
        self::assertFileDoesNotExist($app . '/scripts/parity.sh');
        self::assertFileDoesNotExist($app . '/docker-compose.squire.yml');
        self::assertDirectoryDoesNotExist($app . '/.squire');
        $manifest = json_decode((string) file_get_contents($app . '/' . Squire::MANIFEST), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['release'], $manifest['groups']);
    }

    public function testOnlyParityStillWritesTheParityGroup(): void
    {
        $app = $this->app();
        $result = $this->init($app, ['--only' => 'release,parity']);

        self::assertSame(0, $result['exit'], $result['stdout']);
        self::assertFileExists($app . '/scripts/parity.sh');
        self::assertFileExists($app . '/scripts/parity.ps1');
        self::assertFileExists($app . '/docker-compose.squire.yml');
        self::assertDirectoryExists($app . '/.squire');
        $manifest = json_decode((string) file_get_contents($app . '/' . Squire::MANIFEST), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['release', 'parity'], $manifest['groups']);
    }

    public function testAnExistingManifestKeepsItsRecordedGroups(): void
    {
        $app = $this->app();
        $first = $this->init($app, ['--only' => 'release,parity']);
        self::assertSame(0, $first['exit'], $first['stdout']);
        self::assertFileExists($app . '/scripts/parity.sh');

        $second = $this->init($app);

        self::assertSame(0, $second['exit'], $second['stdout']);
        self::assertFileExists($app . '/scripts/parity.sh', 'a bare init neither removes nor skips the parity files it recorded');
        self::assertFileExists($app . '/docker-compose.squire.yml');
        self::assertStringContainsString('unchanged', $second['stdout']);
        $manifest = json_decode((string) file_get_contents($app . '/' . Squire::MANIFEST), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['release', 'parity'], $manifest['groups']);
    }

    public function testTheNextStepsPointAtParityWhenTheParityGroupIsOff(): void
    {
        $off = $this->init($this->app());
        self::assertSame(0, $off['exit'], $off['stdout']);
        self::assertStringContainsString('Run it locally with Parity: parity init --from-squire, then parity up (' . Squire::PARITY_URL . ').', $off['stdout']);
        self::assertStringNotContainsString(Squire::PARITY_POINTER, $off['stdout']);

        $on = $this->init($this->app(), ['--only' => 'release,parity']);
        self::assertSame(0, $on['exit'], $on['stdout']);
        self::assertStringContainsString(Squire::PARITY_POINTER, $on['stdout']);
        self::assertStringNotContainsString('Run it locally with Parity:', $on['stdout']);
    }
}

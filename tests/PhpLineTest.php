<?php

declare(strict_types=1);

namespace Waynestate\Squire\Tests;

use Symfony\Component\Console\Tester\CommandTester;
use Waynestate\Squire\Console\InitCommand;
use Waynestate\Squire\Squire;
use Waynestate\Squire\Template\Catalog;
use Waynestate\Squire\Template\FileResult;
use Waynestate\Squire\Template\Manifest;
use Waynestate\Squire\Template\Updater;

/**
 * D-090 slice B: a new app is written on the default PHP line and records it, an existing app without a php key
 * stays on the legacy line, and `squire update` never moves an app to another line.
 */
final class PhpLineTest extends SquireTestCase
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

    public function testANewAppIsWrittenOnTheDefaultLine(): void
    {
        $app = $this->app();
        $result = $this->init($app, ['--only' => 'release,parity']);

        self::assertSame(0, $result['exit'], $result['stdout']);
        $compose = (string) file_get_contents($app . '/docker-compose.squire.yml');
        self::assertStringContainsString('citadel/php-apache:' . Squire::DEFAULT_PHP_LINE, $compose);
        self::assertStringNotContainsString('{{PHP_LINE}}', $compose);
        $env = (string) file_get_contents($app . '/.env.squire.example');
        self::assertStringContainsString('citadel/php-apache:' . Squire::DEFAULT_PHP_LINE, $env);
        $data = json_decode((string) file_get_contents($app . '/' . Squire::MANIFEST), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(Squire::DEFAULT_PHP_LINE, $data['php']);
    }

    public function testAnExistingManifestWithoutAPhpKeyStaysOnTheLegacyLine(): void
    {
        $app = $this->app();
        $first = $this->init($app, ['--only' => 'release,parity']);
        self::assertSame(0, $first['exit'], $first['stdout']);

        // An app written before the php key existed: no key in the manifest, files re-rendered on the legacy line.
        $manifest = Manifest::load($app);
        self::assertNotNull($manifest);
        (new Manifest($manifest->version, $manifest->target, $manifest->groups, $manifest->files, $manifest->layer))->save($app);
        $second = $this->init($app);
        self::assertSame(0, $second['exit'], $second['stdout']);
        self::assertStringContainsString('citadel/php-apache:' . Squire::LEGACY_PHP_LINE, (string) file_get_contents($app . '/docker-compose.squire.yml'));

        $results = (new Updater(new Catalog(Squire::templatesDir())))->run($app, 'raw', ['release', 'parity'], false);
        $states = $this->states($results);

        self::assertFalse(in_array(FileResult::UPDATED, $states, true), 'update does not move a legacy app to another line');
        self::assertFalse(in_array(FileResult::EDITED, $states, true), 'update reports no drift on an untouched legacy app');
        $compose = (string) file_get_contents($app . '/docker-compose.squire.yml');
        self::assertStringContainsString('citadel/php-apache:' . Squire::LEGACY_PHP_LINE, $compose);
        self::assertStringNotContainsString('citadel/php-apache:' . Squire::DEFAULT_PHP_LINE, $compose);
        $data = json_decode((string) file_get_contents($app . '/' . Squire::MANIFEST), true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayNotHasKey('php', $data, 'the manifest still has no php key');
    }

    public function testAnExplicitPhpFlagWins(): void
    {
        $app = $this->app();
        $result = $this->init($app, ['--only' => 'release,parity', '--php' => Squire::LEGACY_PHP_LINE]);

        self::assertSame(0, $result['exit'], $result['stdout']);
        $compose = (string) file_get_contents($app . '/docker-compose.squire.yml');
        self::assertStringContainsString('citadel/php-apache:' . Squire::LEGACY_PHP_LINE, $compose);
        self::assertStringNotContainsString('citadel/php-apache:' . Squire::DEFAULT_PHP_LINE, $compose);
        $data = json_decode((string) file_get_contents($app . '/' . Squire::MANIFEST), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(Squire::LEGACY_PHP_LINE, $data['php']);
    }

    public function testABadPhpLineIsRefused(): void
    {
        $app = $this->app();
        $result = $this->init($app, ['--only' => 'release,parity', '--php' => 'latest']);

        self::assertSame(2, $result['exit'], $result['stdout']);
        self::assertFileDoesNotExist($app . '/' . Squire::MANIFEST, 'a usage error writes nothing');
        self::assertFileDoesNotExist($app . '/docker-compose.squire.yml');
        self::assertFileDoesNotExist($app . '/build.sh');
    }
}

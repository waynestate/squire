<?php

declare(strict_types=1);

namespace Waynestate\Squire\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use Waynestate\Squire\Squire;
use Waynestate\Squire\Template\Catalog;
use Waynestate\Squire\Template\Template;

/**
 * Static guarantees about what ships: line endings, strict mode, pinned images, and no secrets.
 */
final class TemplateContentTest extends SquireTestCase
{
    /** @return array<string, array{string}> */
    public static function shellFiles(): array
    {
        $files = [];
        foreach (Catalog::all() as $template) {
            if (str_ends_with($template->destination, '.sh') || str_contains($template->destination, '.squire/fallback/')) {
                $files[$template->source()] = [$template->source()];
            }
        }

        return $files;
    }

    private function read(string $source): string
    {
        return (string) file_get_contents(Squire::templatesDir() . '/' . $source);
    }

    #[DataProvider('shellFiles')]
    public function testShellTemplatesAreLfAndStopOnError(string $source): void
    {
        $content = $this->read($source);

        self::assertStringNotContainsString("\r", $content, 'shell templates must be LF');
        self::assertMatchesRegularExpression('/^set -eu(o pipefail)?$|^set -u$|^set -euo pipefail$/m', $content, 'strict mode');
    }

    public function testBashScriptsUseStrictModeWithPipefail(): void
    {
        foreach (['common/scripts/release.sh', 'common/deployments/001-first-release.sh', 'common/scripts/parity.sh'] as $source) {
            self::assertStringContainsString("set -euo pipefail\n", $this->read($source), $source);
        }
    }

    public function testBuildShIsPosixShBecauseCitadelRunsItWithBinSh(): void
    {
        $content = $this->read('common/build.sh');

        self::assertStringStartsWith("#!/bin/sh\n", $content);
        self::assertStringNotContainsString('[[', $content);
        self::assertStringNotContainsString('local -a', $content);
    }

    public function testBuildShRunsNoComposerOrNpm(): void
    {
        $code = implode("\n", array_filter(explode("\n", $this->read('common/build.sh')), static fn(string $l): bool => !str_starts_with(ltrim($l), '#')));

        self::assertDoesNotMatchRegularExpression('/\b(composer|npm|pnpm|yarn)\s+(install|ci|update)\b/', $code);
    }

    public function testReleaseScriptNeverRunsComposerUpdateOrNpmInstall(): void
    {
        $code = implode("\n", array_filter(explode("\n", $this->read('common/scripts/release.sh')), static fn(string $l): bool => !str_starts_with(ltrim($l), '#')));

        self::assertDoesNotMatchRegularExpression('/(^|&&|;)\s*composer\s+update\b/m', $code);
        self::assertDoesNotMatchRegularExpression('/\bnpm\s+install\b/', $code);
        self::assertStringContainsString('--no-dev', $code);
    }

    public function testNoTemplateMountsTheDockerSocketOrRunsPrivileged(): void
    {
        foreach (Catalog::all() as $template) {
            $content = $this->read($template->source());
            self::assertStringNotContainsString('docker.sock', $content, $template->source());
            self::assertStringNotContainsString('privileged: true', $content, $template->source());
        }
    }

    public function testNoTemplateContainsAnythingThatLooksLikeARealSecret(): void
    {
        foreach (Catalog::all() as $template) {
            $content = $this->read($template->source());
            self::assertDoesNotMatchRegularExpression('/-----BEGIN [A-Z ]*PRIVATE KEY-----/', $content, $template->source());
            self::assertDoesNotMatchRegularExpression('/\b(ghp|glpat|xox[bp])-[A-Za-z0-9_-]{10,}/', $content, $template->source());
        }
    }

    public function testComposeFileFailsClosedOnMissingPasswordsAndPinsItsImages(): void
    {
        $compose = $this->read('common/docker-compose.squire.yml');

        self::assertStringContainsString('SQUIRE_DB_PASSWORD:?', $compose);
        self::assertStringContainsString('SQUIRE_DB_ROOT_PASSWORD:?', $compose);
        self::assertMatchesRegularExpression('#haproxy:3\.0-alpine@sha256:[a-f0-9]{64}#', $compose);
        self::assertMatchesRegularExpression('#mysql:5\.6\.51@sha256:[a-f0-9]{64}#', $compose);
        self::assertStringContainsString('127.0.0.1:${SQUIRE_DB_PORT', $compose, 'the database is bound to loopback only');
        self::assertStringContainsString('interval: 5m', $compose);
        self::assertStringContainsString('timeout: 15s', $compose);
    }

    public function testEnvExamplesHoldPlaceholdersOnly(): void
    {
        foreach (['raw', 'laravel'] as $target) {
            $env = $this->read($target . '/.env.squire.example');

            self::assertStringContainsString('SQUIRE_DB_PASSWORD=change-me-local-only', $env);
            self::assertStringContainsString('citadel/php-apache:8.4r5', $env);
        }
    }

    public function testTheProxyAddsTheHeadersCitadelsProxyAdds(): void
    {
        $config = $this->read('common/.squire/haproxy.cfg');

        self::assertStringContainsString('option forwardfor', $config);
        self::assertStringContainsString('http-request add-header X-Forwarded-Proto http', $config);
    }

    public function testPowerShellTemplatesDoNotNeedBash(): void
    {
        $ps = $this->read('common/scripts/parity.ps1');

        self::assertStringNotContainsString('bash -c', $ps);
        self::assertStringNotContainsString('wsl.exe', strtolower($ps));
    }

    public function testEveryCatalogEntryHasASourceFile(): void
    {
        foreach (Catalog::all() as $template) {
            self::assertFileExists(Squire::templatesDir() . '/' . $template->source());
            self::assertInstanceOf(Template::class, $template);
        }
    }
}

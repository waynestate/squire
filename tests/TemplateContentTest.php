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
        foreach (['common/scripts/release.sh'] as $source) {
            $code = implode("\n", array_filter(explode("\n", $this->read($source)), static fn(string $l): bool => !str_starts_with(ltrim($l), '#')));

            self::assertDoesNotMatchRegularExpression('/(^|&&|;)\s*composer\s+update\b/m', $code, $source);
            self::assertDoesNotMatchRegularExpression('/\bnpm\s+install\b/', $code, $source);
            self::assertStringContainsString('--no-dev', $code, $source);
        }
    }

    public function testTheCommonReleaseScriptHasTheFrontendInstallAndBuildStep(): void
    {
        $common = $this->read('common/scripts/release.sh');

        self::assertStringContainsString('step "frontend install and build"', $common);
        self::assertStringContainsString('npm ci $ignore_flag', $common);
        self::assertStringContainsString('corepack pnpm install --frozen-lockfile $ignore_flag', $common);
        self::assertStringContainsString('npm run "$SQUIRE_NPM_BUILD_SCRIPT"', $common);
        self::assertStringContainsString('corepack pnpm run "$SQUIRE_NPM_BUILD_SCRIPT"', $common);
        self::assertFileDoesNotExist(Squire::templatesDir() . '/vue/scripts/release.sh', 'one release script, for every app');
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
        foreach (['common/docker-compose.squire.yml', 'vue/docker-compose.squire.yml'] as $source) {
            $compose = $this->read($source);

            self::assertStringContainsString('SQUIRE_DB_PASSWORD:?', $compose, $source);
            self::assertStringContainsString('SQUIRE_DB_ROOT_PASSWORD:?', $compose, $source);
            self::assertMatchesRegularExpression('#haproxy:3\.0-alpine@sha256:[a-f0-9]{64}#', $compose, $source);
            self::assertMatchesRegularExpression('#mysql:5\.6\.51@sha256:[a-f0-9]{64}#', $compose, $source);
            self::assertStringContainsString('127.0.0.1:${SQUIRE_DB_PORT', $compose, $source . ': the database is bound to loopback only');
            self::assertStringContainsString('interval: 5m', $compose, $source);
            self::assertStringContainsString('timeout: 15s', $compose, $source);
        }
    }

    public function testTheVueComposePinsNode22InOnePlace(): void
    {
        $common = $this->read('common/docker-compose.squire.yml');
        $vue = $this->read('vue/docker-compose.squire.yml');

        self::assertStringNotContainsString('node:', $common);
        self::assertStringContainsString('image: ${SQUIRE_NODE_IMAGE:-node:22-bookworm-slim}', $vue);
        self::assertSame(1, substr_count($vue, 'node:22-bookworm-slim'), 'the Node image is pinned once, not repeated');
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

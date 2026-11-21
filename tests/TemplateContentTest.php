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
        foreach (['common/scripts/release.sh', 'common/deployments/001-first-release.sh'] as $source) {
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
        self::assertStringContainsString('npm ci --prefer-offline --no-audit --no-fund $ignore_flag', $common);
        self::assertStringContainsString('corepack pnpm install --frozen-lockfile $ignore_flag', $common);
        self::assertStringContainsString('npm run "$SQUIRE_NPM_BUILD_SCRIPT"', $common);
        self::assertStringContainsString('corepack pnpm run "$SQUIRE_NPM_BUILD_SCRIPT"', $common);
        self::assertStringContainsString('corepack yarn install --frozen-lockfile --non-interactive $ignore_flag', $common, 'Yarn 1');
        self::assertStringContainsString('corepack yarn install --immutable $yarn_skip', $common, 'Yarn 2 and later');
        self::assertStringContainsString('corepack yarn run "$SQUIRE_NPM_BUILD_SCRIPT"', $common);
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

    public function testTheParityKitTemplatesAreGone(): void
    {
        // The kit (compose file, proxy, parity scripts, env example) left Squire in 0.12.0; Parity runs Citadel locally.
        foreach (['common/docker-compose.squire.yml', 'vue/docker-compose.squire.yml', 'common/.squire/haproxy.cfg', 'common/scripts/parity.sh', 'common/scripts/parity.ps1', 'raw/.env.squire.example', 'laravel/.env.squire.example'] as $source) {
            self::assertFileDoesNotExist(Squire::templatesDir() . '/' . $source, $source);
        }
    }

    public function testEveryCatalogEntryHasASourceFile(): void
    {
        foreach (Catalog::all() as $template) {
            self::assertFileExists(Squire::templatesDir() . '/' . $template->source());
            self::assertInstanceOf(Template::class, $template);
        }
    }

    public function testTheGitattributesTemplateIsLfAndPinsEveryKind(): void
    {
        $content = $this->read('common/.gitattributes');

        self::assertStringNotContainsString("\r", $content, 'the .gitattributes template must be LF');
        foreach ([
            '* text=auto eol=lf',
            '*.sh text eol=lf',
            '*.conf text eol=lf',
            'Dockerfile text eol=lf',
            '*.Dockerfile text eol=lf',
            '*.ps1 text eol=crlf',
        ] as $line) {
            self::assertMatchesRegularExpression('/^' . preg_quote($line, '/') . '$/m', $content, $line);
        }
    }

    /**
     * The Vue layer's .npmrc carries the stronger lines: the age guard plus the install hardening, so an app
     * starting from Squire gets the same file WELD ships.
     */
    public function testTheVueNpmrcHoldsTheAgeGuardAndTheInstallHardening(): void
    {
        $content = $this->read('vue/.npmrc');

        self::assertStringNotContainsString("\r", $content, 'the Vue .npmrc must be LF');
        foreach ([
            'min-release-age=1',
            'ignore-scripts=true',
            'audit=true',
        ] as $line) {
            self::assertMatchesRegularExpression('/^' . preg_quote($line, '/') . '$/m', $content, $line);
        }
    }
}

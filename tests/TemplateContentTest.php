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
        self::assertStringContainsString('image: ${SQUIRE_NODE_IMAGE:-node:22.23.4-bookworm-slim@sha256:5ec23b7c622b00547753bc6ffd78085dd868a68aeb41c8e0a1418f033e13d96d}', $vue);
        self::assertSame(1, substr_count($vue, 'node:22.23.4-bookworm-slim'), 'the Node image is pinned once, not repeated');
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

    /**
     * Docker's embedded DNS answers on 127.0.0.11, but Podman's (aardvark-dns) answers on the network gateway, so a
     * fixed nameserver times out a minute after `up` and HAProxy takes the app down (503). The proxy reads the
     * engine's own /etc/resolv.conf instead (Parity 64bf3e8).
     */
    public function testTheProxyReadsTheEngineNameserverNotDockerLoopback(): void
    {
        $common = $this->read('common/.squire/haproxy.cfg');
        $deploy = (string) file_get_contents(dirname(__DIR__) . '/deploy/.squire/haproxy.cfg');

        foreach (['common/.squire/haproxy.cfg' => $common, 'deploy/.squire/haproxy.cfg' => $deploy] as $source => $config) {
            self::assertStringContainsString('parse-resolv-conf', $config, $source);
            self::assertStringNotContainsString('127.0.0.11', $config, $source);

            $defined = [];
            if (preg_match_all('/^resolvers\s+(\S+)/m', $config, $sections)) {
                foreach ($sections[1] as $name) {
                    $defined[$name] = true;
                }
            }
            self::assertNotEmpty($defined, $source . ': no resolvers section defined');

            $referenced = [];
            if (preg_match_all('/^\s*server\b.*\bresolvers\s+(\S+)/m', $config, $servers)) {
                foreach ($servers[1] as $name) {
                    $referenced[$name] = true;
                }
            }
            foreach (array_keys($referenced) as $name) {
                self::assertArrayHasKey($name, $defined, $source . ': server references undefined resolvers section "' . $name . '"');
            }
        }

        self::assertSame($common, $deploy, 'the deploy copy must be byte-identical to the template');
    }

    public function testPowerShellTemplatesDoNotNeedBash(): void
    {
        $ps = $this->read('common/scripts/parity.ps1');

        self::assertStringNotContainsString('bash -c', $ps);
        self::assertStringNotContainsString('wsl.exe', strtolower($ps));
    }

    /**
     * Windows PowerShell 5.1 under $ErrorActionPreference = 'Stop' throws on a native command's redirected stderr, so
     * `& docker image inspect missing *> $null` died with a NativeCommandError before the script's own message showed.
     * Every quiet native call goes through Invoke-NativeQuiet, which drops the preference to 'Continue' for itself.
     */
    public function testParityPs1RunsOnWindowsPowerShell51(): void
    {
        $ps = $this->read('common/scripts/parity.ps1');

        self::assertStringContainsString("\$ErrorActionPreference = 'Stop'", $ps);
        self::assertMatchesRegularExpression(
            "/function Invoke-NativeQuiet \{.*?\\\$ErrorActionPreference = 'Continue'\s+& \\\$Program @Arguments 2>\\\$null\s+\}/s",
            $ps,
        );
        // Outside the helper, no line runs a native command with stderr redirected.
        $outside = preg_replace('/function Invoke-NativeQuiet \{.*?\r?\n\}\r?\n/s', '', $ps) ?? '';
        $code = preg_replace('/^\s*#.*$/m', '', $outside) ?? '';
        self::assertDoesNotMatchRegularExpression('/(\*>|2>)\s*\$null/', $code);
        // Exit-Parity prints one plain line: Write-Error under 'Stop' throws and skips the exit.
        self::assertStringContainsString('[Console]::Error.WriteLine("parity: $Message")', $ps);
        self::assertStringNotContainsString('Write-Error', $code);
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
}

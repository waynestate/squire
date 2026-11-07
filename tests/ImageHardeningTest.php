<?php

declare(strict_types=1);

namespace Waynestate\Squire\Tests;

use Waynestate\Squire\Squire;
use Waynestate\Squire\Template\Catalog;

/**
 * The hardening the image files promise, checked in the files themselves. scripts/contract-image.sh checks the same
 * promises again inside the built image (SQUIRE-40), where a file can no longer lie about what it does.
 */
final class ImageHardeningTest extends SquireTestCase
{
    private function read(string $source): string
    {
        return (string) file_get_contents(Squire::templatesDir() . '/' . $source);
    }

    /** The file's non-comment, non-blank lines, trimmed. @return list<string> */
    private function directives(string $source, string $comment = '#'): array
    {
        $lines = [];
        foreach (explode("\n", $this->read($source)) as $line) {
            $line = trim($line);
            if ($line !== '' && !str_starts_with($line, $comment)) {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    private function catalogHas(string $destination): bool
    {
        foreach (Catalog::all() as $template) {
            if ($template->destination === $destination) {
                return true;
            }
        }

        return false;
    }

    // --- SQUIRE-9: Apache says less about itself ---------------------------------------------------------------------

    public function testTheApacheHardeningSetsTheThreeDirectives(): void
    {
        $directives = $this->directives('common/.squire/apache-hardening.conf');

        self::assertContains('ServerTokens Prod', $directives);
        self::assertContains('ServerSignature Off', $directives);
        self::assertContains('TraceEnable Off', $directives);
        self::assertTrue($this->catalogHas('.squire/apache-hardening.conf'), 'init writes the hardening file with the parity kit');
    }

    public function testTheFallbackBaseInstallsTheHardeningAfterDebiansSecurityConf(): void
    {
        $base = $this->read('common/.squire/base-fallback.Dockerfile');

        // zz- sorts after Debian's security.conf (ServerTokens OS, ServerSignature On), so these values win.
        self::assertStringContainsString('COPY apache-hardening.conf "$APACHE_CONFDIR/conf-available/zz-squire-hardening.conf"', $base);
        self::assertStringContainsString('a2enconf zz-squire-hardening', $base);
    }

    public function testTheAppImageLeavesApacheHardeningToTheBase(): void
    {
        // The app Dockerfile runs on the real Citadel image locally; hardening it there would fake a parity it lacks.
        self::assertStringNotContainsString('apache-hardening', $this->read('common/.squire/Dockerfile'));
    }

    // --- SQUIRE-10: production opcache, with a dev override ----------------------------------------------------------

    /** The value php.ini gives a setting, read by PHP itself. @param array<string, string> $env */
    private function iniValue(string $iniFile, string $setting, array $env = []): string
    {
        $process = proc_open(
            [PHP_BINARY, '-c', $iniFile, ...$this->opcacheFlag(), '-r', 'echo json_encode(ini_get($argv[1]));', $setting],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            ['PATH' => (string) getenv('PATH'), ...$env],
        );
        self::assertIsResource($process);
        $out = (string) stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        proc_close($process);

        return (string) json_decode($out);
    }

    /** Load opcache in the child when this PHP has it built but not enabled (the official images before 8.5). @return list<string> */
    private function opcacheFlag(): array
    {
        return extension_loaded('Zend OPcache') ? [] : ['-d', 'zend_extension=opcache'];
    }

    public function testPhpIniTurnsOpcacheOnWithProductionSettings(): void
    {
        $ini = $this->directives('common/.squire/php.ini', ';');

        self::assertContains('opcache.enable = 1', $ini);
        self::assertContains('opcache.enable_cli = 0', $ini);
        self::assertContains('opcache.validate_timestamps = ${SQUIRE_OPCACHE_VALIDATE_TIMESTAMPS:-0}', $ini, 'off in production, with an override');
        self::assertContains('opcache.revalidate_freq = 0', $ini);
        self::assertContains('opcache.memory_consumption = ${SQUIRE_OPCACHE_MEMORY:-128}', $ini);
        self::assertContains('opcache.max_accelerated_files = ${SQUIRE_OPCACHE_MAX_FILES:-20000}', $ini, 'room for Laravel and its vendor/');
        self::assertContains('opcache.interned_strings_buffer = 16', $ini);
    }

    public function testTheValidateTimestampsDefaultIsOffAndTheEnvironmentTurnsItOn(): void
    {
        $ini = Squire::templatesDir() . '/common/.squire/php.ini';
        if ($this->iniValue($ini, 'opcache.enable') === '') {
            self::markTestSkipped('this PHP has no opcache, so its settings cannot be read.');
        }

        self::assertSame('0', $this->iniValue($ini, 'opcache.validate_timestamps'));
        self::assertSame('1', $this->iniValue($ini, 'opcache.validate_timestamps', ['SQUIRE_OPCACHE_VALIDATE_TIMESTAMPS' => '1']));
        self::assertSame('20000', $this->iniValue($ini, 'opcache.max_accelerated_files'));
        self::assertSame('256', $this->iniValue($ini, 'opcache.memory_consumption', ['SQUIRE_OPCACHE_MEMORY' => '256']));
    }

    public function testTheFallbackBaseLoadsOpcacheWhenTheImageDoesNot(): void
    {
        self::assertStringContainsString("php -m | grep -qx 'Zend OPcache' || docker-php-ext-enable opcache", $this->read('common/.squire/base-fallback.Dockerfile'));
    }

    public function testTheParityComposeFilesTurnTheDevOverrideOn(): void
    {
        foreach (['common/docker-compose.squire.yml', 'vue/docker-compose.squire.yml'] as $source) {
            self::assertStringContainsString('SQUIRE_OPCACHE_VALIDATE_TIMESTAMPS: ${SQUIRE_OPCACHE_VALIDATE_TIMESTAMPS:-1}', $this->read($source), $source . ': a bind mount needs changed files seen');
        }
    }
}

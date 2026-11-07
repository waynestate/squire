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

    // --- SQUIRE-29: the web loses the shell, the CLI keeps it --------------------------------------------------------

    private const WEB_DISABLED = 'exec,passthru,shell_exec,system,pcntl_exec,phpinfo,show_source,highlight_file';

    /**
     * Run a PHP snippet under an ini file and return what it prints. The snippet reports which functions exist.
     *
     * @param array<string, string> $env
     */
    private function underIni(string $iniFile, string $code, array $env = []): string
    {
        $process = proc_open(
            [PHP_BINARY, '-c', $iniFile, '-r', $code],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            ['PATH' => (string) getenv('PATH'), ...$env],
        );
        self::assertIsResource($process);
        $out = (string) stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        proc_close($process);

        return trim($out);
    }

    /** The web's ini exactly as the app image builds it: php.ini, a blank line, php-web.ini. */
    private function webIni(): string
    {
        $file = $this->tempDir() . '/php-apache2handler.ini';
        file_put_contents($file, $this->read('common/.squire/php.ini') . "\n" . $this->read('common/.squire/php-web.ini'));

        return $file;
    }

    /** What a Laravel request, a Composer script and the health check reach for, and the shell functions. */
    private const PROBE = 'foreach (["exec", "shell_exec", "system", "passthru", "phpinfo", "proc_open", "popen", "fsockopen", "stream_socket_client", "file_get_contents"] as $f) { echo $f, "=", function_exists($f) ? "y" : "n", " "; }';

    public function testTheWebLosesTheShellFunctionsAndKeepsWhatLaravelUses(): void
    {
        $out = $this->underIni($this->webIni(), self::PROBE);

        foreach (['exec', 'shell_exec', 'system', 'passthru', 'phpinfo'] as $gone) {
            self::assertStringContainsString($gone . '=n', $out, $gone . ' is disabled on the web');
        }
        // Symfony Mailer's sendmail transport and Laravel's Process need proc_open; PHPMailer needs popen; the HTTP
        // clients and the health check need sockets.
        foreach (['proc_open', 'popen', 'fsockopen', 'stream_socket_client', 'file_get_contents'] as $kept) {
            self::assertStringContainsString($kept . '=y', $out, $kept . ' stays on the web');
        }
    }

    public function testTheCliKeepsEveryFunctionForComposerScriptsArtisanAndTheHealthCheck(): void
    {
        $out = $this->underIni(Squire::templatesDir() . '/common/.squire/php.ini', self::PROBE);

        foreach (['exec', 'shell_exec', 'system', 'passthru', 'proc_open', 'popen'] as $kept) {
            self::assertStringContainsString($kept . '=y', $out, $kept . ' stays in the CLI');
        }
    }

    public function testNoneDisablesNothingAnEmptyValueKeepsTheDefaultAndAListReplacesIt(): void
    {
        $web = $this->webIni();

        self::assertStringContainsString('exec=y', $this->underIni($web, self::PROBE, ['SQUIRE_PHP_DISABLE_FUNCTIONS' => 'none']), 'none is the off switch');
        // The parity compose file passes an empty value when .env.squire sets nothing; PHP must read it as the default.
        self::assertStringContainsString('exec=n', $this->underIni($web, self::PROBE, ['SQUIRE_PHP_DISABLE_FUNCTIONS' => '']), 'empty means the default');
        $custom = $this->underIni($web, self::PROBE, ['SQUIRE_PHP_DISABLE_FUNCTIONS' => 'popen']);
        self::assertStringContainsString('popen=n', $custom);
        self::assertStringContainsString('exec=y', $custom, 'the variable replaces the list, it does not add to it');
    }

    public function testTheAppImageBuildsTheWebIniFromBothFiles(): void
    {
        $dockerfile = $this->read('common/.squire/Dockerfile');

        self::assertStringContainsString('COPY .squire/php-web.ini /usr/local/etc/php/php-web.ini', $dockerfile);
        self::assertStringContainsString('{ cat /usr/local/etc/php/php.ini; echo; cat /usr/local/etc/php/php-web.ini; } > /usr/local/etc/php/php-apache2handler.ini', $dockerfile);
        // The web ini is built after php.ini lands, or it would freeze the base image's php.ini instead of the kit's.
        self::assertGreaterThan(strpos($dockerfile, 'COPY .squire/php.ini'), strpos($dockerfile, 'php-apache2handler.ini'));
        self::assertTrue($this->catalogHas('.squire/php-web.ini'), 'init writes php-web.ini with the parity kit');
    }

    public function testPhpIniSetsNoDisableClassesAndNoCliDisableList(): void
    {
        $ini = $this->directives('common/.squire/php.ini', ';');

        self::assertContains('disable_functions =', $ini, 'the CLI disables nothing');
        foreach ($ini as $line) {
            self::assertStringStartsNotWith('disable_classes', $line, 'PHP 8.5 removed disable_classes');
        }
    }

    public function testThePhpWebIniHoldsTheListAndTheComposeFilesPassTheSwitch(): void
    {
        self::assertContains('disable_functions = ${SQUIRE_PHP_DISABLE_FUNCTIONS:-' . self::WEB_DISABLED . '}', $this->directives('common/.squire/php-web.ini', ';'));
        foreach (['common/docker-compose.squire.yml', 'vue/docker-compose.squire.yml'] as $source) {
            // One list, in php-web.ini: compose passes the variable or an empty value, never a second copy.
            self::assertStringContainsString('SQUIRE_PHP_DISABLE_FUNCTIONS: ${SQUIRE_PHP_DISABLE_FUNCTIONS:-}', $this->read($source), $source);
        }
    }
}

<?php

declare(strict_types=1);

namespace Waynestate\Squire\Tests;

use Waynestate\Squire\Squire;
use Waynestate\Squire\Template\Catalog;

/**
 * The hardening the image files promise, checked in the files themselves. scripts/contract-image.sh checks the same
 * promises again inside the built image, where a file can no longer lie about what it does.
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

    // --- Apache says less about itself -------------------------------------------------------------------------------

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

    // --- production opcache, with a dev override ---------------------------------------------------------------------

    /** The value php.ini gives a setting, read by PHP itself. @param array<string, string> $env */
    private function iniValue(string $iniFile, string $setting, array $env = []): string
    {
        $process = proc_open(
            [...$this->withEnv($env), PHP_BINARY, '-c', $iniFile, ...$this->opcacheFlag(), '-r', 'echo json_encode(ini_get($argv[1]));', $setting],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            ['PATH' => (string) getenv('PATH')],
        );
        self::assertIsResource($process);
        $out = (string) stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        proc_close($process);

        return (string) json_decode($out);
    }

    /**
     * The child's variables, set through env(1): proc_open drops a variable whose value is empty, and the empty case
     * is one these tests must see. @param array<string, string> $env @return list<string>
     */
    private function withEnv(array $env): array
    {
        if ($env === []) {
            return [];
        }
        if (!is_executable('/usr/bin/env')) {
            self::markTestSkipped('these checks set variables through /usr/bin/env, which this machine lacks.');
        }
        $args = ['/usr/bin/env'];
        foreach ($env as $name => $value) {
            $args[] = $name . '=' . $value;
        }

        return $args;
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
        self::assertContains('opcache.validate_timestamps = ${SQUIRE_OPCACHE_VALIDATE:-1}', $ini, 'on unless the image says its code is baked in');
        self::assertContains('opcache.revalidate_freq = 2', $ini);
        self::assertContains('opcache.memory_consumption = ${SQUIRE_OPCACHE_MEMORY:-128}', $ini);
        self::assertContains('opcache.max_accelerated_files = ${SQUIRE_OPCACHE_MAX_FILES:-20000}', $ini, 'room for Laravel and its vendor/');
        self::assertContains('opcache.interned_strings_buffer = 16', $ini);
    }

    private function opcacheIni(): string
    {
        $ini = Squire::templatesDir() . '/common/.squire/php.ini';
        if ($this->iniValue($ini, 'opcache.enable') === '') {
            self::markTestSkipped('this PHP has no opcache, so its settings cannot be read.');
        }

        return $ini;
    }

    public function testCodePulledIntoARunningContainerIsCheckedEveryTwoSeconds(): void
    {
        $ini = $this->opcacheIni();

        // Citadel, a bind mount, and any target Squire cannot tell: the variable is unset, or 1 from the app image.
        self::assertSame('1', $this->iniValue($ini, 'opcache.validate_timestamps'));
        self::assertSame('1', $this->iniValue($ini, 'opcache.validate_timestamps', ['SQUIRE_OPCACHE_VALIDATE' => '1']));
        self::assertSame('2', $this->iniValue($ini, 'opcache.revalidate_freq'));
    }

    public function testAnEmptySwitchIsNotTheDefaultSoTheKitNeverPassesOne(): void
    {
        $ini = $this->opcacheIni();

        // PHP's ${NAME:-default} falls back only when NAME is unset: empty reads as off. Hence ARG ...=1 and compose by name.
        self::assertSame('', $this->iniValue($ini, 'opcache.validate_timestamps', ['SQUIRE_OPCACHE_VALIDATE' => '']));
    }

    public function testCodeBakedIntoAnImageIsNeverCheckedWhenTheSwitchSaysSo(): void
    {
        $ini = $this->opcacheIni();

        self::assertSame('0', $this->iniValue($ini, 'opcache.validate_timestamps', ['SQUIRE_OPCACHE_VALIDATE' => '0']));
    }

    public function testTheAppImageTakesTheSwitchAsABuildArgumentWithOneAsItsDefault(): void
    {
        $dockerfile = $this->read('common/.squire/Dockerfile');

        self::assertStringContainsString("ARG SQUIRE_OPCACHE_VALIDATE=1\n", $dockerfile, 'code pulled in place unless the build says it is baked in');
        self::assertStringContainsString('ENV SQUIRE_OPCACHE_VALIDATE=$SQUIRE_OPCACHE_VALIDATE', $dockerfile);
    }

    public function testTheOtherOpcacheSizesReadTheEnvironment(): void
    {
        $ini = $this->opcacheIni();

        self::assertSame('20000', $this->iniValue($ini, 'opcache.max_accelerated_files'));
        self::assertSame('256', $this->iniValue($ini, 'opcache.memory_consumption', ['SQUIRE_OPCACHE_MEMORY' => '256']));
    }

    public function testTheFallbackBaseLoadsOpcacheWhenTheImageDoesNot(): void
    {
        self::assertStringContainsString("php -m | grep -qx 'Zend OPcache' || docker-php-ext-enable opcache", $this->read('common/.squire/base-fallback.Dockerfile'));
    }

    public function testTheParityComposeFilesPassTheSwitchByNameOnly(): void
    {
        foreach (['common/docker-compose.squire.yml', 'vue/docker-compose.squire.yml'] as $source) {
            $compose = $this->read($source);
            self::assertStringContainsString("      SQUIRE_OPCACHE_VALIDATE:\n", $compose, $source . ': unset stays unset, so the image\'s 1 holds for the bind mount');
            self::assertStringNotContainsString('SQUIRE_OPCACHE_VALIDATE: ${', $compose, $source . ': no interpolation, which would pass an empty value');
        }
    }

    // --- the web loses the shell, the CLI keeps it -------------------------------------------------------------------

    private const WEB_DISABLED = 'exec,passthru,shell_exec,system,pcntl_exec,phpinfo,show_source,highlight_file';

    /**
     * Run a PHP snippet under an ini file and return what it prints. The snippet reports which functions exist.
     *
     * @param array<string, string> $env
     */
    private function underIni(string $iniFile, string $code, array $env = []): string
    {
        $process = proc_open(
            [...$this->withEnv($env), PHP_BINARY, '-c', $iniFile, '-r', $code],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            ['PATH' => (string) getenv('PATH')],
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

    public function testNoneDisablesNothingAnEmptyValueTooAndAListReplacesIt(): void
    {
        $web = $this->webIni();

        self::assertStringContainsString('exec=y', $this->underIni($web, self::PROBE, ['SQUIRE_PHP_DISABLE_FUNCTIONS' => 'none']), 'none is the off switch');
        // PHP falls back only when the variable is unset: empty disables nothing. Hence compose passes it by name only.
        self::assertStringContainsString('exec=y', $this->underIni($web, self::PROBE, ['SQUIRE_PHP_DISABLE_FUNCTIONS' => '']), 'empty is not the default');
        self::assertStringContainsString('exec=n', $this->underIni($web, self::PROBE), 'unset is');
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

    public function testPhpIniSendsTheSessionCookieHttpOnlySecureAndSameSiteLax(): void
    {
        // A raw PHP app that never calls helmet_boot() still gets a safe session cookie, and SESAME has nothing to
        // warn about. Lax, not Strict: a CAS ticket comes back as a top-level GET from the IdP's site and needs it.
        $ini = $this->directives('common/.squire/php.ini', ';');
        self::assertContains('session.cookie_httponly = 1', $ini);
        self::assertContains('session.cookie_secure = 1', $ini);
        self::assertContains('session.cookie_samesite = Lax', $ini);
        foreach ($this->directives('common/.squire/php-web.ini', ';') as $line) {
            self::assertStringStartsNotWith('session.cookie_', $line, 'the web ini never overrides the session cookie');
        }

        $out = $this->underIni($this->webIni(), 'echo ini_get("session.cookie_samesite"), "|", ini_get("session.cookie_httponly"), "|", ini_get("session.cookie_secure");');
        self::assertSame('Lax|1|1', $out, 'PHP itself reads the three settings from the web ini the image builds');
    }

    public function testThePhpWebIniHoldsTheListAndTheComposeFilesPassTheSwitch(): void
    {
        self::assertContains('disable_functions = ${SQUIRE_PHP_DISABLE_FUNCTIONS:-' . self::WEB_DISABLED . '}', $this->directives('common/.squire/php-web.ini', ';'));
        foreach (['common/docker-compose.squire.yml', 'vue/docker-compose.squire.yml'] as $source) {
            // One list, in php-web.ini: compose passes the variable by name only, never an empty value or a second copy.
            $compose = $this->read($source);
            self::assertStringContainsString("      SQUIRE_PHP_DISABLE_FUNCTIONS:\n", $compose, $source);
            self::assertStringNotContainsString('SQUIRE_PHP_DISABLE_FUNCTIONS: ${', $compose, $source);
        }
    }

    // --- every base is pinned by its multi-arch list digest ---------------------------------------------------------

    private const PINNED = '/@sha256:[0-9a-f]{64}$/';

    /** Local build names, never pulled: the real Citadel image you build yourself, the app image, Sail's base. */
    private const LOCAL = ['citadel/php-apache:8.4r5', 'sail-base'];

    private function repoFile(string $path): string
    {
        return (string) file_get_contents(dirname(__DIR__) . '/' . $path);
    }

    /**
     * Every image a file pulls: ARG *IMAGE defaults, FROM, COPY --from, compose image: and their ${VAR:-default}s,
     * and the composer image the build scripts run. Stage names and ${ARG} references are not pulls.
     *
     * @return list<string>
     */
    private function pulledImages(string $text): array
    {
        $found = [];
        preg_match_all('/^ARG\s+\w*IMAGE=(\S+)/m', $text, $m);
        $found = [...$found, ...$m[1]];
        preg_match_all('/^FROM\s+(\S+)/m', $text, $m);
        $found = [...$found, ...$m[1]];
        preg_match_all('/--from=(\S+)/', $text, $m);
        $found = [...$found, ...array_filter($m[1], static fn(string $r): bool => str_contains($r, ':'))];
        preg_match_all('/^\s*image:\s*(\S+)/m', $text, $m);
        foreach ($m[1] as $ref) {
            if (preg_match('/^\$\{\w+:-([^}]+)\}$/', $ref, $d) === 1) {
                $found[] = $d[1];
            } elseif (!str_starts_with($ref, '$') && !str_starts_with($ref, 'squire-local/')) {
                $found[] = $ref;
            }
        }
        preg_match_all('#(?:docker://|\s)((?:docker\.io/library/)?composer:[\w.]+\S*)#', $text, $m);
        $found = [...$found, ...$m[1]];

        return array_values(array_filter(
            array_map(static fn(string $r): string => rtrim($r, ')"'), $found),
            static fn(string $r): bool => !str_starts_with($r, '$') && !in_array($r, self::LOCAL, true),
        ));
    }

    /** @return iterable<string, array{string}> */
    public static function filesThatPullImages(): iterable
    {
        foreach ([
            'templates/common/.squire/base-fallback.Dockerfile',
            'templates/common/.squire/Dockerfile',
            'templates/common/.squire/portable.Dockerfile',
            'templates/common/docker-compose.squire.yml',
            'templates/vue/docker-compose.squire.yml',
            'images/oracle/parity.Dockerfile',
            'images/oracle/sail.Dockerfile',
            'scripts/oracle-image-build.sh',
            'scripts/oracle-image-publish.sh',
        ] as $path) {
            yield $path => [$path];
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('filesThatPullImages')]
    public function testEveryImageAFilePullsIsPinnedByDigest(string $path): void
    {
        // A file that pulls only local names (the app image on ${BASE_IMAGE}, Sail on sail-base) has nothing to pin.
        $this->addToAssertionCount(1);
        foreach ($this->pulledImages($this->repoFile($path)) as $ref) {
            self::assertMatchesRegularExpression(self::PINNED, $ref, $path . ': ' . $ref . ' moves under you without a digest');
        }
    }

    public function testTheFallbackBasePinsPhpNodeAndComposer(): void
    {
        $images = $this->pulledImages($this->read('common/.squire/base-fallback.Dockerfile'));

        foreach (['docker.io/library/php:8.4.', 'docker.io/library/node:22.', 'docker.io/library/composer:2.'] as $prefix) {
            self::assertNotEmpty(array_filter($images, static fn(string $r): bool => str_starts_with($r, $prefix)), $prefix . ' is pinned in the fallback base');
        }
    }

    /** @return iterable<string, array{string}> */
    public static function dockerfilesThatPullImages(): iterable
    {
        foreach ([
            'templates/common/.squire/base-fallback.Dockerfile',
            'templates/common/.squire/Dockerfile',
            'templates/common/.squire/portable.Dockerfile',
            'deploy/.squire/base-fallback.Dockerfile',
            'deploy/.squire/portable.Dockerfile',
            'images/oracle/parity.Dockerfile',
            'images/oracle/sail.Dockerfile',
        ] as $path) {
            yield $path => [$path];
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('dockerfilesThatPullImages')]
    public function testEveryImageADockerfilePullsIsNamedInFull(string $path): void
    {
        // Buildah and Podman refuse a short name such as composer:2 in COPY --from (and in FROM, unless an alias
        // happens to exist) where their short-name mode is enforcing. A full name works for Docker too.
        $text = $this->repoFile($path);
        $this->addToAssertionCount(1);
        foreach ($this->pulledImages($text) as $ref) {
            self::assertMatchesRegularExpression('#^[a-z0-9.-]+\.[a-z]+(:\d+)?/#', $ref, $path . ': ' . $ref . ' is a short name; write its registry too');
        }
    }

    public function testTheFallbackBaseTakesEveryImageAsABuildArgument(): void
    {
        // A pull-through proxy or mirror swaps the registry part with a build argument; an image named inline in
        // COPY --from could not be swapped without editing the file.
        $base = $this->read('common/.squire/base-fallback.Dockerfile');
        foreach (['PHP_IMAGE', 'NODE_IMAGE', 'COMPOSER_IMAGE'] as $arg) {
            self::assertMatchesRegularExpression('#^ARG ' . $arg . '=docker\.io/library/\S+@sha256:[0-9a-f]{64}$#m', $base, $arg . ' defaults to a full, pinned name');
        }
        self::assertStringContainsString('FROM ${COMPOSER_IMAGE} AS composer', $base);
        self::assertStringContainsString('COPY --from=composer /usr/bin/composer /usr/local/bin/composer', $base);
        self::assertDoesNotMatchRegularExpression('/--from=\S*:/', $base, 'COPY --from names a stage, never an image');
    }

    public function testTheOracleParityLinesArePinnedAndTheDefaultIsThe84Row(): void
    {
        $rows = [];
        foreach (explode("\n", $this->repoFile('images/oracle/php-images.txt')) as $line) {
            if (preg_match('/^(\d+\.\d+)\s+(\S+)$/', trim($line), $m) === 1) {
                $rows[$m[1]] = $m[2];
            }
        }

        self::assertSame(['8.3', '8.4', '8.5'], array_keys($rows));
        foreach ($rows as $line => $image) {
            self::assertStringStartsWith('docker.io/library/php:' . $line . '.', $image, 'named in full, for Buildah');
            self::assertStringContainsString('-cli-bookworm@', $image, 'the parity image is built on the CLI bookworm base');
            self::assertMatchesRegularExpression(self::PINNED, $image);
        }
        self::assertStringContainsString('ARG PHP_IMAGE=' . $rows['8.4'] . "\n", $this->repoFile('images/oracle/parity.Dockerfile'));
        foreach (['scripts/oracle-image-build.sh', 'scripts/oracle-image-publish.sh'] as $script) {
            $text = $this->repoFile($script);
            self::assertStringContainsString('images/oracle/php-images.txt', $text, $script . ' reads the pins');
            self::assertStringContainsString('PHP_IMAGE=', $text, $script . ' passes the pin');
            self::assertStringNotContainsString('PHP_LINE=', $text, $script . ' no longer builds from a moving tag');
        }
    }

    public function testTheOracleScriptsNeverPairATagAndADigestWithBuildahsDockerTransport(): void
    {
        // buildah from docker://name:tag@sha256:... fails with "invalid reference format" before anything is pulled,
        // which kept every Sail flavor of the oracle-images job from building. A plain full name pulls by the digest.
        foreach (['scripts/oracle-image-build.sh', 'scripts/oracle-image-publish.sh'] as $script) {
            self::assertDoesNotMatchRegularExpression('#docker://[^\s"]+:[^\s"@]+@sha256:#', $this->repoFile($script), $script);
        }
        self::assertStringContainsString(
            'from docker.io/library/composer:2.',
            $this->repoFile('scripts/oracle-image-publish.sh'),
            'the publish script names the Composer image in full',
        );
    }

    // --- PHP 8.4: the session ID length stays at PHP's defaults ------------------------------------------------------

    public function testPhpIniLeavesTheSessionIdLengthAtPhpsDefaults(): void
    {
        $ini = $this->directives('common/.squire/php.ini', ';');

        foreach ($ini as $line) {
            self::assertStringStartsNotWith('session.sid_length', $line, 'deprecated in PHP 8.4; PHP\'s default is 32 hex characters');
            self::assertStringStartsNotWith('session.sid_bits_per_character', $line, 'deprecated in PHP 8.4; 128 bits is the default');
        }
    }
}

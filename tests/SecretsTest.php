<?php

declare(strict_types=1);

namespace Waynestate\Squire\Tests;

use Waynestate\Squire\Squire;

/**
 * SQUIRE-18: secrets reach the app as files at run time and never sit in an image layer. The loader is one block,
 * the same in the release script and the fallback entrypoint; these tests run it in bash and read the image files.
 */
final class SecretsTest extends SquireTestCase
{
    private const RELEASE = 'common/scripts/release.sh';
    private const ENTRYPOINT = 'common/.squire/fallback/citadel-apache-entrypoint';

    private function read(string $source): string
    {
        return (string) file_get_contents(Squire::templatesDir() . '/' . $source);
    }

    private function block(string $source): string
    {
        $text = $this->read($source);
        $start = strpos($text, '# squire-secrets: begin');
        $end = strpos($text, '# squire-secrets: end');
        self::assertNotFalse($start, $source . ' has the secrets block');
        self::assertNotFalse($end, $source . ' closes the secrets block');

        return substr($text, $start, $end - $start);
    }

    /**
     * Run the block in bash with a secrets folder and print the named variables.
     *
     * @param array<string, string> $files
     * @param array<string, string> $env
     * @param list<string>          $names
     * @return array{0: string, 1: string} stdout, stderr
     */
    private function load(array $files, array $env, array $names): array
    {
        $dir = $this->tempDir() . '/secrets';
        mkdir($dir);
        foreach ($files as $name => $value) {
            file_put_contents($dir . '/' . $name, $value);
        }
        $print = '';
        foreach ($names as $name) {
            $print .= 'printf "%s=[%s]\n" ' . $name . ' "${' . $name . '-unset}"; ';
        }
        $script = 'set -euo pipefail' . "\n" . $this->block(self::RELEASE) . "\nsquire_load_secrets\n" . $print;
        $process = proc_open(
            ['bash', '-c', $script],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            ['PATH' => (string) getenv('PATH'), 'SQUIRE_SECRETS_DIR' => $dir, ...$env],
        );
        self::assertIsResource($process);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        self::assertSame(0, proc_close($process), $err);

        return [$out, $err];
    }

    private function requireBash(): void
    {
        if (!self::hasProgram('bash')) {
            self::markTestSkipped('bash is not installed; the release script needs it.');
        }
    }

    public function testEachSecretFileBecomesAVariableAndOnlyItsNameIsPrinted(): void
    {
        $this->requireBash();
        [$out, $err] = $this->load(
            ['DB_PASSWORD' => "s3cret-value\n", 'APP_KEY' => "base64:abc\r\n", 'ORACLE_PASSWORD' => 'no-newline'],
            [],
            ['DB_PASSWORD', 'APP_KEY', 'ORACLE_PASSWORD'],
        );

        self::assertStringContainsString('DB_PASSWORD=[s3cret-value]', $out, 'one trailing newline is trimmed');
        self::assertStringContainsString('APP_KEY=[base64:abc]', $out, 'a Windows line ending too');
        self::assertStringContainsString('ORACLE_PASSWORD=[no-newline]', $out);
        self::assertStringContainsString('DB_PASSWORD', $err, 'the log names what it loaded');
        self::assertStringNotContainsString('s3cret-value', $err, 'and never prints a value');
    }

    public function testTheEnvironmentWinsAndUnsafeNamesAreSkipped(): void
    {
        $this->requireBash();
        [$out] = $this->load(
            ['DB_PASSWORD' => 'from-file', 'PATH' => '/evil', 'LD_PRELOAD' => '/evil.so', 'BASH_ENV' => '/evil', 'not-a-name' => 'x', '1ST' => 'x'],
            ['DB_PASSWORD' => 'from-env'],
            ['DB_PASSWORD', 'LD_PRELOAD', 'BASH_ENV'],
        );

        self::assertStringContainsString('DB_PASSWORD=[from-env]', $out);
        self::assertStringContainsString('LD_PRELOAD=[unset]', $out);
        self::assertStringContainsString('BASH_ENV=[unset]', $out);
        self::assertStringNotContainsString('/evil', $out);
    }

    public function testNoSecretsFolderIsNotAnError(): void
    {
        $this->requireBash();
        $process = proc_open(
            ['bash', '-c', 'set -euo pipefail' . "\n" . $this->block(self::RELEASE) . "\nsquire_load_secrets && echo ok"],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            ['PATH' => (string) getenv('PATH'), 'SQUIRE_SECRETS_DIR' => $this->tempDir() . '/none'],
        );
        self::assertIsResource($process);
        self::assertSame("ok\n", stream_get_contents($pipes[1]));
        stream_get_contents($pipes[2]);
        self::assertSame(0, proc_close($process));
    }

    public function testTheReleaseScriptAndTheEntrypointCarryTheSameLoaderAndCallIt(): void
    {
        self::assertSame($this->block(self::RELEASE), $this->block(self::ENTRYPOINT), 'one loader, two copies kept identical');

        $release = $this->read(self::RELEASE);
        $settings = strpos($release, "squire_load_settings ./.env.squire\n");
        $secrets = strpos($release, "\nsquire_load_secrets\n");
        self::assertNotFalse($settings);
        self::assertNotFalse($secrets, 'the release loads secrets (its own environment is reset by su -l)');
        self::assertGreaterThan($settings, $secrets, 'after .env.squire, so SQUIRE_SECRETS_DIR can come from it');
        self::assertStringContainsString('SQUIRE_SECRETS_DIR', $release, 'the settings file may name the folder');

        $entrypoint = $this->read(self::ENTRYPOINT);
        $call = strpos($entrypoint, 'squire_load_secrets || exit 1');
        self::assertNotFalse($call, 'the web server inherits the secrets from the entrypoint');
        self::assertLessThan(strpos($entrypoint, 'exec "$@"'), $call);
    }

    public function testNoImageFileBakesASecretIntoALayer(): void
    {
        $secretish = '/\b(ARG|ENV)\s+\S*(PASSWORD|SECRET|TOKEN|APP_KEY|API_KEY|CREDENTIAL)/i';
        foreach (['common/.squire/Dockerfile', 'common/.squire/base-fallback.Dockerfile'] as $source) {
            self::assertDoesNotMatchRegularExpression($secretish, $this->read($source), $source . ': a build argument or ENV is a layer anyone with the image can read');
            self::assertStringNotContainsString('/run/secrets', $this->read($source), $source . ': secrets are mounted at run time, not copied in');
        }

        // The build context is build.sh and .squire/ only: .env, .env.squire and any secrets folder never reach it.
        $ignore = array_values(array_filter(array_map('trim', explode("\n", $this->read('common/.squire/Dockerfile.dockerignore'))), static fn(string $l): bool => $l !== '' && $l[0] !== '#'));
        self::assertSame('*', $ignore[0]);
        foreach ($ignore as $line) {
            self::assertStringNotContainsString('.env', $line, 'nothing re-includes an env file');
        }

        self::assertStringNotContainsString('.env', $this->read('common/build.sh'), 'build.sh runs with no app source and must never fetch or read secrets');
    }
}

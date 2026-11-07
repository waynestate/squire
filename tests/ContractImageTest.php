<?php

declare(strict_types=1);

namespace Waynestate\Squire\Tests;

use PHPUnit\Framework\Attributes\DataProvider;

/**
 * SQUIRE-40: the image contract checks the hardening in the running image, not only in the files. These tests keep
 * the driver (scripts/contract-image.sh) and the in-image checks (scripts/contract-image-checks.php) honest; the run
 * itself needs Docker and happens in CI and on a maintainer's machine.
 */
final class ContractImageTest extends SquireTestCase
{
    private function repoFile(string $path): string
    {
        return (string) file_get_contents(dirname(__DIR__) . '/' . $path);
    }

    public function testTheDriverMountsASecretProbesTheWebAndRunsTheChecksInTheContainer(): void
    {
        $driver = $this->repoFile('scripts/contract-image.sh');

        self::assertStringContainsString('cp "$REPO/scripts/contract-image-probe.php" "$FIXTURE/app.php"', $driver, 'the fixture app is the probe');
        self::assertStringContainsString(':"/run/secrets/$SECRET_NAME":ro', $driver, 'the secret is a read-only file mount, never a build argument');
        self::assertStringContainsString('php <"$REPO/scripts/contract-image-checks.php"', $driver, 'the checks run inside the container');
        self::assertStringContainsString('composer config scripts.post-install-cmd', $driver, 'a Composer script that shells out runs in the release');
        self::assertStringContainsString('docker history --no-trunc "$APP_TAG"', $driver, 'the layers are searched for the secret');
        self::assertStringNotContainsString('--build-arg "$SECRET', $driver);
        self::assertStringNotContainsString('-e "$SECRET_NAME', $driver, 'the secret reaches the container as a file only');
    }

    /** @return iterable<string, array{string}> */
    public static function promises(): iterable
    {
        yield 'SQUIRE-9 Server header' => ["\$server === 'Apache'"];
        yield 'SQUIRE-9 TRACE' => ["\$request('TRACE'"];
        yield 'SQUIRE-9 signature' => ["'<address>'"];
        yield 'expose_php' => ["'x-powered-by'"];
        yield 'SQUIRE-10 opcache on' => ["\$web['opcache']"];
        yield 'SQUIRE-10 validate_timestamps' => ["\$web['validate_timestamps']"];
        yield 'SQUIRE-29 web list' => ["'exec', 'passthru', 'shell_exec', 'system', 'pcntl_exec', 'phpinfo', 'show_source', 'highlight_file'"];
        yield 'SQUIRE-29 proc_open stays' => ["\$web['proc_open']"];
        yield 'SQUIRE-29 CLI keeps all' => ["ini_get('disable_functions')"];
        yield 'SQUIRE-18 secret on the web' => ["\$web['secret_md5']"];
        yield 'Composer script' => ['.contract-composer-script'];
    }

    #[DataProvider('promises')]
    public function testTheInImageChecksCoverEachPromise(string $needle): void
    {
        self::assertStringContainsString($needle, $this->repoFile('scripts/contract-image-checks.php'));
    }

    public function testTheChecksReportFailuresAndNeverPrintTheSecret(): void
    {
        // Run the checks with nothing listening on port 80: every web check must FAIL, and the exit code is 1.
        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__) . '/scripts/contract-image-checks.php'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->tempDir(),
            ['PATH' => (string) getenv('PATH'), 'CONTRACT_SECRET_NAME' => 'SQUIRE_CONTRACT_SECRET', 'CONTRACT_SECRET_MD5' => md5('never-printed'), 'CONTRACT_APP_DIR' => $this->tempDir()],
        );
        self::assertIsResource($process);
        $out = (string) stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        $exit = proc_close($process);

        if (str_contains($out, 'PASS: GET /up.php')) {
            self::markTestSkipped('something answers on 127.0.0.1:80 here, so the failure path cannot be shown.');
        }
        self::assertSame(1, $exit);
        self::assertStringContainsString('FAIL: GET /up.php answers 200', $out);
        self::assertStringContainsString('FAIL: the web sees the secret', $out);
        self::assertStringNotContainsString(md5('never-printed'), $out, 'the md5 is compared, never printed');
    }

    public function testTheProbeReportsTheSecretAsAnMd5Only(): void
    {
        $probe = $this->repoFile('scripts/contract-image-probe.php');

        self::assertStringContainsString("'secret_md5' => \$secret === false ? null : md5(\$secret)", $probe);
        self::assertSame(1, substr_count($probe, 'md5($secret)'), 'the one place the value is used is its md5');
        self::assertStringNotContainsString("'secret' =>", $probe);
    }
}

<?php

declare(strict_types=1);

namespace Waynestate\Squire\Tests;

use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The image contract checks the hardening in the running image, not only in the files. These tests keep
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

    public function testTheDriverChecksBothOpcacheCases(): void
    {
        $driver = $this->repoFile('scripts/contract-image.sh');

        // Pulled in place (the mounted fixture): the release notes that files are checked.
        self::assertStringContainsString('grep -q "opcache.validate_timestamps=1: the web server sees changed files by itself" "$TMPROOT/release.log"', $driver);
        // Baked in: a second app image built with the switch at 0, whose web never checks, and whose release warns.
        self::assertStringContainsString('--build-arg "SQUIRE_OPCACHE_VALIDATE=0"', $driver);
        self::assertStringContainsString('[ "$baked" = "0" ] || rc=1', $driver);
        self::assertStringContainsString('grep -q "opcache.validate_timestamps=0: Apache keeps serving" "$TMPROOT/baked-release.log"', $driver);
        self::assertStringContainsString('docker rmi "$APP_TAG" "$BAKED_TAG"', $driver, 'the baked image is removed with the other');
        self::assertStringContainsString("'revalidate_freq' => ini_get('opcache.revalidate_freq')", $this->repoFile('scripts/contract-image-probe.php'));
    }

    /** @return iterable<string, array{string}> */
    public static function promises(): iterable
    {
        yield 'Apache Server header' => ["\$server === 'Apache'"];
        yield 'Apache TRACE' => ["\$request('TRACE'"];
        yield 'Apache signature' => ["'<address>'"];
        yield 'expose_php' => ["'x-powered-by'"];
        yield 'opcache on' => ["\$web['opcache']"];
        yield 'opcache validate_timestamps' => ["\$web['validate_timestamps']"];
        yield 'opcache revalidate_freq' => ["\$web['revalidate_freq']"];
        yield 'web disable_functions list' => ["'exec', 'passthru', 'shell_exec', 'system', 'pcntl_exec', 'phpinfo', 'show_source', 'highlight_file'"];
        yield 'web keeps proc_open' => ["\$web['proc_open']"];
        yield 'CLI keeps every function' => ["ini_get('disable_functions')"];
        yield 'secret on the web' => ["\$web['secret_md5']"];
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

    public function testTheDriverRunsTheAppWithTheComposeFilesLeastPrivilege(): void
    {
        $driver = $this->repoFile('scripts/contract-image.sh');
        $compose = $this->repoFile('templates/common/docker-compose.squire.yml');

        // The app service's cap_add list sits under its S70 comment (MySQL has a shorter one of its own, and the
        // portable services add nothing back).
        self::assertSame(1, preg_match('/S70: least privilege\..*?\n\s+cap_drop: \[ALL\]\n\s+cap_add: \[([A-Z_, ]+)\]\n/s', $compose, $m), 'the app service drops every capability and adds a list back');
        $caps = array_map('trim', explode(',', $m[1]));
        self::assertContains('NET_BIND_SERVICE', $caps, 'the app service list, not MySQL\'s');
        self::assertSame(1, preg_match('/^RUN_LIMITS="([^"]+)"$/m', $driver, $limits), 'the driver keeps its flags in RUN_LIMITS');
        $flags = explode(' ', $limits[1]);
        $driverCaps = [];
        foreach ($flags as $flag) {
            if (str_starts_with($flag, '--cap-add=')) {
                $driverCaps[] = substr($flag, strlen('--cap-add='));
            }
        }
        self::assertSame($caps, $driverCaps, 'the driver adds back exactly what the compose file adds back, in its order');
        self::assertContains('--cap-drop=ALL', $flags);
        self::assertContains('--security-opt=no-new-privileges:true', $flags);
        self::assertContains('--pids-limit=256', $flags);
        self::assertContains('--memory=512m', $flags, 'the compose default for SQUIRE_APP_MEMORY');
        self::assertStringContainsString('mem_limit: ${SQUIRE_APP_MEMORY:-512m}', $compose);
        self::assertStringContainsString('pids_limit: 256', $compose);
        // Both app containers run with them: the mounted one and the baked one.
        self::assertSame(2, substr_count($driver, 'docker run -d $RUN_LIMITS'));
    }

    public function testABaseImageIsTakenAsFoundAndNeverPulled(): void
    {
        $driver = $this->repoFile('scripts/contract-image.sh');

        self::assertStringContainsString('--base-image=*) BASE_IMAGE="${arg#--base-image=}" ;;', $driver);
        self::assertStringContainsString('docker image inspect "$BASE_IMAGE" >/dev/null 2>&1', $driver, 'a missing image stops the run');
        self::assertStringContainsString("run 'parity image get' first", $driver, 'and says how to get Citadel\'s');
        self::assertStringContainsString('is $base_platform, not $PLATFORM', $driver, 'a base of another platform stops the run');
        self::assertStringNotContainsString('docker pull', $driver);
        self::assertStringNotContainsString('--pull', $driver);
        self::assertStringContainsString('CONTRACT_BASE="image"', $driver);
        self::assertStringContainsString('-e "CONTRACT_BASE=$CONTRACT_BASE"', $driver, 'the checks are told whose base it is');
        // The caller's image is never removed with the throwaway ones.
        self::assertStringContainsString('docker rmi "$APP_TAG" "$BAKED_TAG"', $driver);
        self::assertStringNotContainsString('docker rmi "$BASE_TAG"', $driver);
        self::assertStringNotContainsString('docker rmi "$BASE_IMAGE"', $driver);
        // The baked container starts through the image's own entrypoint, as Citadel starts it.
        self::assertStringNotContainsString('--entrypoint apache2-foreground', $driver);
    }

    /** @return iterable<string, array{array<string, string>, string}> */
    public static function bases(): iterable
    {
        yield 'the kit\'s fallback' => [[], 'FAIL: '];
        yield 'an image of the caller\'s' => [['CONTRACT_BASE' => 'image'], 'GAP: '];
    }

    /** @param array<string, string> $extraEnv */
    #[DataProvider('bases')]
    public function testTheBaseImagesOwnPromisesFailOnTheFallbackAndAreGapsElsewhere(array $extraEnv, string $prefix): void
    {
        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__) . '/scripts/contract-image-checks.php'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->tempDir(),
            ['PATH' => (string) getenv('PATH'), 'CONTRACT_SECRET_NAME' => 'SQUIRE_CONTRACT_SECRET', 'CONTRACT_SECRET_MD5' => md5('x'), 'CONTRACT_APP_DIR' => $this->tempDir()] + $extraEnv,
        );
        self::assertIsResource($process);
        $out = (string) stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        $exit = proc_close($process);

        if (str_contains($out, 'PASS: GET /up.php')) {
            self::markTestSkipped('something answers on 127.0.0.1:80 here, so the failure path cannot be shown.');
        }
        // The base image's: its Apache server config and its entrypoint.
        self::assertStringContainsString($prefix . 'the Server header is exactly "Apache"', $out);
        self::assertStringContainsString($prefix . 'TRACE answers 405', $out);
        self::assertStringContainsString($prefix . 'the web sees the secret', $out);
        // The kit's, on any base: a FAIL either way, so the run still fails.
        self::assertStringContainsString('FAIL: GET /up.php answers 200', $out);
        self::assertStringContainsString('FAIL: opcache is on for the web', $out);
        self::assertSame(1, $exit);
        if ($prefix === 'GAP: ') {
            self::assertStringContainsString("(the base image's to fix: no app file can change it)", $out);
            self::assertStringNotContainsString('FAIL: the Server header', $out);
        } else {
            self::assertStringNotContainsString('GAP: ', $out);
        }
    }

    public function testTheProbeReportsTheSecretAsAnMd5Only(): void
    {
        $probe = $this->repoFile('scripts/contract-image-probe.php');

        self::assertStringContainsString("'secret_md5' => \$secret === false ? null : md5(\$secret)", $probe);
        self::assertSame(1, substr_count($probe, 'md5($secret)'), 'the one place the value is used is its md5');
        self::assertStringNotContainsString("'secret' =>", $probe);
    }
}

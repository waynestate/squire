<?php

declare(strict_types=1);

namespace Waynestate\Squire\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use Waynestate\Squire\Squire;

/**
 * Runs the real shell templates with stub composer, curl, npm, corepack and proctor programs on PATH. Needs bash,
 * which the php:*-cli images have and the Alpine composer image does not; the tests skip themselves when it is missing.
 */
final class ReleaseScriptTest extends SquireTestCase
{
    private string $stubs = '';

    private string $log = '';

    protected function setUp(): void
    {
        if (!self::hasProgram('bash')) {
            self::markTestSkipped('bash is not installed.');
        }
        $this->stubs = $this->tempDir();
        $this->log = $this->stubs . '/calls.log';
        file_put_contents($this->log, '');

        $this->stub('composer', <<<'SH'
            #!/bin/sh
            echo "composer $*" >> "$STUB_LOG"
            if [ "$1" = "audit" ]; then exit "${STUB_AUDIT_EXIT:-0}"; fi
            if [ "$1" = "--version" ]; then echo "Composer version stub"; fi
            exit 0
            SH);
        $this->stub('curl', <<<'SH'
            #!/bin/sh
            echo "curl $*" >> "$STUB_LOG"
            printf '%s' "${STUB_HTTP_CODE:-200}"
            SH);
        $this->stub('npm', <<<'SH'
            #!/bin/sh
            echo "npm $*" >> "$STUB_LOG"
            if [ "$1" = "run" ] && [ "$2" = "build" ]; then exit "${STUB_NPM_BUILD_EXIT:-0}"; fi
            exit 0
            SH);
        $this->stub('corepack', <<<'SH'
            #!/bin/sh
            echo "corepack $*" >> "$STUB_LOG"
            if [ "$1" = "pnpm" ] && [ "$2" = "run" ] && [ "$3" = "build" ]; then exit "${STUB_COREPACK_BUILD_EXIT:-0}"; fi
            exit 0
            SH);
        $this->stub('proctor', <<<'SH'
            #!/bin/sh
            echo "proctor $*" >> "$STUB_LOG"
            exit "${STUB_PROCTOR_EXIT:-0}"
            SH);
    }

    private function stub(string $name, string $body): void
    {
        $lines = array_map(static fn(string $l): string => ltrim($l), explode("\n", $body));
        file_put_contents($this->stubs . '/' . $name, implode("\n", $lines) . "\n");
        chmod($this->stubs . '/' . $name, 0o755);
    }

    /** @return list<string> */
    private function calls(): array
    {
        return array_values(array_filter(explode("\n", (string) file_get_contents($this->log))));
    }

    /**
     * @param array<string, string> $env
     *
     * @return array{exit: int, stdout: string, stderr: string}
     */
    private function exec(string $app, string $script, array $env = [], string $interpreter = 'bash'): array
    {
        $path = $this->stubs . PATH_SEPARATOR . dirname(PHP_BINARY) . PATH_SEPARATOR . (string) getenv('PATH');
        $environment = [
            'PATH' => $path,
            'HOME' => $this->tempDir(),
            'STUB_LOG' => $this->log,
            'SQUIRE_ALLOW_ROOT' => '1',
            'SQUIRE_SMOKE_DELAY' => '0',
            'SQUIRE_SMOKE_RETRIES' => '2',
            ...$env,
        ];
        $process = proc_open([$interpreter, $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $app, $environment);
        self::assertIsResource($process);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['exit' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
    }

    public function testAReleaseInstallsAuditsSmokeTestsAndPrintsStepMarkers(): void
    {
        $app = $this->initializedApp();
        $result = $this->exec($app, 'scripts/release.sh');

        self::assertSame(0, $result['exit'], $result['stdout'] . $result['stderr']);
        self::assertStringContainsString('[squire] ==> step 1: check preconditions', $result['stdout']);
        self::assertStringContainsString('[squire] release ok', $result['stdout']);
        self::assertContains('composer install --no-dev --optimize-autoloader --no-interaction --no-progress --prefer-dist', $this->calls());
        self::assertContains('composer audit --locked --no-interaction', $this->calls());
        $curl = array_values(array_filter($this->calls(), static fn(string $c): bool => str_starts_with($c, 'curl ')));
        self::assertNotEmpty($curl);
        self::assertStringContainsString('http://127.0.0.1:80/up.php', $curl[0], 'raw PHP smoke-tests up.php');
    }

    public function testARunningTwiceGivesTheSameResult(): void
    {
        $app = $this->initializedApp();
        $first = $this->exec($app, 'scripts/release.sh');
        $second = $this->exec($app, 'scripts/release.sh');

        self::assertSame(0, $first['exit']);
        self::assertSame(0, $second['exit']);
        $markers = static fn(string $out): array => array_values(array_filter(
            explode("\n", $out),
            static fn(string $l): bool => str_contains($l, '==> step'),
        ));
        self::assertSame($markers($first['stdout']), $markers($second['stdout']));
    }

    public function testAMissingLockFileStopsTheReleaseBeforeAnythingInstalls(): void
    {
        $app = $this->initializedApp();
        unlink($app . '/composer.lock');
        $result = $this->exec($app, 'scripts/release.sh');

        self::assertSame(1, $result['exit']);
        self::assertStringContainsString('composer.lock not found', $result['stderr']);
        self::assertStringContainsString('FAILED at step 1', $result['stderr']);
        self::assertSame([], $this->calls());
    }

    public function testASmokeTestThatGetsNo200FailsTheRelease(): void
    {
        $result = $this->exec($this->initializedApp(), 'scripts/release.sh', ['STUB_HTTP_CODE' => '500']);

        self::assertSame(1, $result['exit']);
        self::assertStringContainsString('answered HTTP 500, expected 200', $result['stderr']);
    }

    public function testTheSmokeTestCanBeTurnedOffForTheFirstReleaseHook(): void
    {
        $app = $this->initializedApp();
        $result = $this->exec($app, 'deployments/001-first-release.sh', ['STUB_HTTP_CODE' => '500']);

        self::assertSame(0, $result['exit'], $result['stdout'] . $result['stderr']);
        self::assertStringContainsString('first-release hook finished', $result['stdout']);
        self::assertStringContainsString('skipped (SQUIRE_SMOKE=0', $result['stdout']);
    }

    public function testAnAuditFindingIsAReportUnlessToldToFail(): void
    {
        $app = $this->initializedApp();

        $report = $this->exec($app, 'scripts/release.sh', ['STUB_AUDIT_EXIT' => '1']);
        self::assertSame(0, $report['exit']);
        self::assertStringContainsString('composer audit exited 1 (report only', $report['stderr']);

        $strict = $this->exec($app, 'scripts/release.sh', ['STUB_AUDIT_EXIT' => '1', 'SQUIRE_COMPOSER_AUDIT' => 'fail']);
        self::assertSame(1, $strict['exit']);
    }

    public function testProctorIsAReportAndNeverAGate(): void
    {
        $app = $this->initializedApp();
        $result = $this->exec($app, 'scripts/release.sh', [
            'SQUIRE_PROCTOR_BIN' => $this->stubs . '/proctor',
            'SQUIRE_PROCTOR_POLICY' => '/policy.json',
            'STUB_PROCTOR_EXIT' => '1',
        ]);

        self::assertSame(0, $result['exit'], $result['stdout'] . $result['stderr']);
        self::assertStringContainsString('proctor reported failures (exit 1)', $result['stderr']);
        self::assertContains('proctor check --path . --policy /policy.json', $this->calls());

        $broken = $this->exec($app, 'scripts/release.sh', [
            'SQUIRE_PROCTOR_BIN' => $this->stubs . '/proctor',
            'SQUIRE_PROCTOR_POLICY' => '/policy.json',
            'STUB_PROCTOR_EXIT' => '2',
        ]);
        self::assertSame(0, $broken['exit']);
        self::assertStringContainsString('proctor itself failed (exit 2)', $broken['stderr']);
    }

    public function testProctorWithoutAPolicyIsSkippedWithAReason(): void
    {
        $result = $this->exec($this->initializedApp(), 'scripts/release.sh', ['SQUIRE_PROCTOR_BIN' => $this->stubs . '/proctor']);

        self::assertSame(0, $result['exit']);
        self::assertStringContainsString('SQUIRE_PROCTOR_POLICY is not set', $result['stdout']);
    }

    public function testLaravelRunsMigrateThenOptimizeAndSmokeTestsUp(): void
    {
        $app = $this->initializedApp(true);
        $this->put($app, '.env', "APP_ENV=testing\n");
        $this->put($app, 'artisan', "<?php\nfile_put_contents(getenv('STUB_LOG'), 'artisan ' . implode(' ', array_slice(\$argv, 1)) . \"\\n\", FILE_APPEND);\n");

        $result = $this->exec($app, 'scripts/release.sh');

        self::assertSame(0, $result['exit'], $result['stdout'] . $result['stderr']);
        $calls = $this->calls();
        $migrate = array_search('artisan migrate --force', $calls, true);
        $optimize = array_search('artisan optimize', $calls, true);
        self::assertIsInt($migrate);
        self::assertIsInt($optimize);
        self::assertLessThan($optimize, $migrate, 'migrate runs before the caches are built');
        $curl = array_values(array_filter($calls, static fn(string $c): bool => str_starts_with($c, 'curl ')));
        self::assertStringContainsString('http://127.0.0.1:80/up', $curl[0]);
        self::assertStringNotContainsString('up.php', $curl[0]);
        self::assertDirectoryExists($app . '/storage');
        self::assertDirectoryExists($app . '/bootstrap/cache');
    }

    public function testLaravelWithoutAnEnvFileStopsEarly(): void
    {
        $result = $this->exec($this->initializedApp(true), 'scripts/release.sh');

        self::assertSame(1, $result['exit']);
        self::assertStringContainsString('.env not found', $result['stderr']);
        self::assertNotContains('artisan migrate --force', $this->calls());
    }

    public function testFrontendPackagesInstallFromTheLockFileWithoutInstallScripts(): void
    {
        $app = $this->initializedVueApp();
        $this->put($app, 'package.json', '{"scripts":{"build":"vite build"}}');
        $this->put($app, 'package-lock.json', '{}');

        $result = $this->exec($app, 'scripts/release.sh');

        self::assertSame(0, $result['exit'], $result['stdout'] . $result['stderr']);
        $calls = $this->calls();
        self::assertContains('npm ci --ignore-scripts', $calls);
        self::assertContains('npm run build', $calls);
        self::assertLessThan((int) array_search('npm run build', $calls, true), (int) array_search('npm ci --ignore-scripts', $calls, true));
    }

    public function testPnpmLockSelectsPnpmInstallAndBuild(): void
    {
        $app = $this->initializedVueApp();
        $this->put($app, 'package.json', '{"scripts":{"build":"vite build"}}');
        $this->put($app, 'pnpm-lock.yaml', "lockfileVersion: '9.0'\n");

        $result = $this->exec($app, 'scripts/release.sh');

        self::assertSame(0, $result['exit'], $result['stdout'] . $result['stderr']);
        $calls = $this->calls();
        self::assertContains('corepack pnpm install --frozen-lockfile --ignore-scripts', $calls);
        self::assertContains('corepack pnpm run build', $calls);
        self::assertNotContains('npm ci --ignore-scripts', $calls);
        self::assertLessThan((int) array_search('corepack pnpm run build', $calls, true), (int) array_search('corepack pnpm install --frozen-lockfile --ignore-scripts', $calls, true));
    }

    public function testAPackageJsonWithoutALockFileIsRefusedNotInstalled(): void
    {
        $app = $this->initializedVueApp();
        $this->put($app, 'package.json', '{"scripts":{"build":"vite build"}}');

        $result = $this->exec($app, 'scripts/release.sh');

        self::assertSame(1, $result['exit']);
        self::assertStringContainsString('commit a lock file', $result['stderr']);
        self::assertNotContains('npm install', $this->calls());
        self::assertNotContains('npm ci --ignore-scripts', $this->calls());
    }

    public function testFrontendInstallCanBeSkippedWhenBuiltAssetsAreCommitted(): void
    {
        $app = $this->initializedVueApp();
        $this->put($app, 'package.json', '{}');

        $result = $this->exec($app, 'scripts/release.sh', ['SQUIRE_NPM' => 'skip']);

        self::assertSame(0, $result['exit']);
        self::assertSame([], array_filter($this->calls(), static fn(string $c): bool => str_starts_with($c, 'npm ') || str_starts_with($c, 'corepack ')));
    }

    public function testALaravelAppWithoutVueStillGetsTheFrontendBuild(): void
    {
        $app = $this->initializedApp(true);
        $this->put($app, '.env', "APP_ENV=testing
");
        $this->put($app, 'package.json', '{"devDependencies":{"vite":"^6.0.0"},"scripts":{"build":"vite build"}}');
        $this->put($app, 'package-lock.json', '{}');

        $result = $this->exec($app, 'scripts/release.sh');

        self::assertSame(0, $result['exit'], $result['stdout'] . $result['stderr']);
        $calls = $this->calls();
        self::assertContains('npm ci --ignore-scripts', $calls);
        self::assertContains('npm run build', $calls);
    }

    public function testAFailingFrontendBuildStopsTheRelease(): void
    {
        $app = $this->initializedVueApp();
        $this->put($app, 'package.json', '{"scripts":{"build":"vite build"}}');
        $this->put($app, 'package-lock.json', '{}');

        $result = $this->exec($app, 'scripts/release.sh', ['STUB_NPM_BUILD_EXIT' => '1']);

        self::assertSame(1, $result['exit']);
        self::assertStringContainsString('frontend install and build', $result['stderr']);
        self::assertContains('npm ci --ignore-scripts', $this->calls());
        self::assertContains('npm run build', $this->calls());
    }

    public function testAFailingPnpmBuildStopsTheRelease(): void
    {
        $app = $this->initializedVueApp();
        $this->put($app, 'package.json', '{"scripts":{"build":"vite build"}}');
        $this->put($app, 'pnpm-lock.yaml', "lockfileVersion: '9.0'\n");

        $result = $this->exec($app, 'scripts/release.sh', ['STUB_COREPACK_BUILD_EXIT' => '1']);

        self::assertSame(1, $result['exit']);
        self::assertStringContainsString('frontend install and build', $result['stderr']);
        self::assertContains('corepack pnpm install --frozen-lockfile --ignore-scripts', $this->calls());
        self::assertContains('corepack pnpm run build', $this->calls());
    }

    public function testRunningAsRootIsRefusedUnlessAllowed(): void
    {
        if (!function_exists('posix_geteuid') || posix_geteuid() !== 0) {
            self::markTestSkipped('Needs to run as root.');
        }
        $result = $this->exec($this->initializedApp(), 'scripts/release.sh', ['SQUIRE_ALLOW_ROOT' => '0']);

        self::assertSame(1, $result['exit']);
        self::assertStringContainsString('refusing to run as root', $result['stderr']);
    }

    public function testBadSettingsAreRejectedWithAMessage(): void
    {
        $result = $this->exec($this->initializedApp(), 'scripts/release.sh', ['SQUIRE_FRAMEWORK' => 'rails']);

        self::assertSame(1, $result['exit']);
        self::assertStringContainsString('SQUIRE_FRAMEWORK must be auto, laravel or raw', $result['stderr']);
    }

    public function testBuildShRefusesToRunBesideAppSource(): void
    {
        $dir = $this->tempDir();
        copy(Squire::templatesDir() . '/common/build.sh', $dir . '/build.sh');
        file_put_contents($dir . '/composer.json', '{}');

        $result = $this->exec($dir, 'build.sh', [], 'sh');

        self::assertSame(1, $result['exit']);
        self::assertStringContainsString('[squire-build] FAILED', $result['stderr']);
    }

    public function testBuildShRunsCleanlyAsRootInAnEmptyDirectory(): void
    {
        if (!function_exists('posix_geteuid') || posix_geteuid() !== 0) {
            self::markTestSkipped('build.sh requires root, as it does on Citadel.');
        }
        $dir = $this->tempDir();
        copy(Squire::templatesDir() . '/common/build.sh', $dir . '/build.sh');

        $result = $this->exec($dir, 'build.sh', [], 'sh');

        self::assertSame(0, $result['exit'], $result['stdout'] . $result['stderr']);
        self::assertStringContainsString('[squire-build] ==> build.sh finished', $result['stdout']);
    }

    /** @return array<string, array{string, string}> */
    public static function shellTemplates(): array
    {
        return [
            'build.sh' => ['common/build.sh', 'sh'],
            'hook' => ['common/deployments/001-first-release.sh', 'bash'],
            'release.sh' => ['common/scripts/release.sh', 'bash'],
            'parity.sh' => ['common/scripts/parity.sh', 'bash'],
            'fallback entrypoint' => ['common/.squire/fallback/citadel-apache-entrypoint', 'bash'],
            'fallback deploy' => ['common/.squire/fallback/citadel-deploy', 'bash'],
        ];
    }

    #[DataProvider('shellTemplates')]
    public function testEveryShellTemplateParses(string $file, string $shell): void
    {
        $process = proc_open([$shell, '-n', Squire::templatesDir() . '/' . $file], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $err = (string) stream_get_contents($pipes[2]);
        stream_get_contents($pipes[1]);

        self::assertSame(0, proc_close($process), $err);
    }
}

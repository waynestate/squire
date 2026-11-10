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
            echo "COMPOSER_CACHE_DIR=${COMPOSER_CACHE_DIR:-}" >> "$STUB_LOG"
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
            echo "npm_config_cache=${npm_config_cache:-}" >> "$STUB_LOG"
            if [ "$1" = "--version" ]; then echo "${STUB_NPM_VERSION:-10.9.2}"; exit 0; fi
            if [ "$1" = "run" ] && [ "$2" = "build" ]; then exit "${STUB_NPM_BUILD_EXIT:-0}"; fi
            exit 0
            SH);
        $this->stub('corepack', <<<'SH'
            #!/bin/sh
            echo "corepack $*" >> "$STUB_LOG"
            if [ "$2" = "run" ] && [ "$3" = "build" ]; then exit "${STUB_COREPACK_BUILD_EXIT:-0}"; fi
            exit 0
            SH);
        // A PROCTOR with `gate` by default; STUB_PROCTOR_GATE=0 plays an older one that only has `check`. The gate
        // writes its --record file the way the real one does, with the exit code inside so a test can tell runs apart.
        $this->stub('proctor', <<<'SH'
            #!/bin/sh
            echo "proctor $*" >> "$STUB_LOG"
            if [ "$1" = "--version" ]; then echo "proctor 0.6.0"; exit 0; fi
            if [ "$1" = "gate" ]; then
            if [ "${STUB_PROCTOR_GATE:-1}" = "0" ]; then echo 'Command "gate" is not defined.' >&2; exit 2; fi
            if [ "$2" = "--help" ]; then exit 0; fi
            prev=""; record=""
            for a in "$@"; do if [ "$prev" = "--record" ]; then record="$a"; fi; prev="$a"; done
            if [ -n "$record" ]; then printf '{"tool":"proctor","command":"gate","exit":%s}\n' "${STUB_PROCTOR_EXIT:-0}" > "$record"; fi
            fi
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
     * SQUIRE_PHP_RUNTIME is skip here so the gate arguments the other tests pin do not depend on the php running
     * the suite; the runtime tests set it, and a null value unsets a variable to reach the script's own default.
     *
     * @param array<string, string|null> $env
     *
     * @return array{exit: int, stdout: string, stderr: string}
     */
    private function exec(string $app, string $script, array $env = [], string $interpreter = 'bash'): array
    {
        $path = $this->stubs . PATH_SEPARATOR . dirname(PHP_BINARY) . PATH_SEPARATOR . (string) getenv('PATH');
        $environment = array_filter([
            'PATH' => $path,
            'HOME' => $this->tempDir(),
            'STUB_LOG' => $this->log,
            'SQUIRE_ALLOW_ROOT' => '1',
            'SQUIRE_SMOKE_DELAY' => '0',
            'SQUIRE_SMOKE_RETRIES' => '2',
            'SQUIRE_PHP_RUNTIME' => 'skip',
            ...$env,
        ], static fn(?string $value): bool => $value !== null);
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

    public function testAValueInTheSettingsFileIsUsed(): void
    {
        $app = $this->initializedApp();
        file_put_contents($app . '/.env.squire', "# a comment\n\nSQUIRE_COMPOSER_AUDIT=skip\n");
        $result = $this->exec($app, 'scripts/release.sh');

        self::assertSame(0, $result['exit'], $result['stdout'] . $result['stderr']);
        self::assertNotContains('composer audit --locked --no-interaction', $this->calls());
    }

    public function testQuotesAroundAValueInTheSettingsFileAreStripped(): void
    {
        $app = $this->initializedApp();
        file_put_contents($app . '/.env.squire', "SQUIRE_SMOKE_URL=\"http://127.0.0.1:80/quoted\"\r\nSQUIRE_COMPOSER_AUDIT='skip'\r\n");
        $result = $this->exec($app, 'scripts/release.sh');

        self::assertSame(0, $result['exit'], $result['stdout'] . $result['stderr']);
        self::assertStringContainsString('http://127.0.0.1:80/quoted ->', $result['stdout']);
        self::assertNotContains('composer audit --locked --no-interaction', $this->calls());
    }

    public function testTheEnvironmentWinsOverTheSettingsFile(): void
    {
        $app = $this->initializedApp();
        file_put_contents($app . '/.env.squire', "SQUIRE_COMPOSER_AUDIT=skip\n");
        $result = $this->exec($app, 'scripts/release.sh', ['SQUIRE_COMPOSER_AUDIT' => 'report']);

        self::assertSame(0, $result['exit'], $result['stdout'] . $result['stderr']);
        self::assertContains('composer audit --locked --no-interaction', $this->calls());
    }

    public function testAKeyThatIsNotAReleaseOptionInTheSettingsFileIsIgnored(): void
    {
        $app = $this->initializedApp();
        file_put_contents($app . '/.env.squire', "SQUIRE_DB_PASSWORD=secret\nSQUIRE_STEP_NO=99\nPATH=/nowhere\n");
        $this->stub('composer', "#!/bin/sh\necho \"composer $*\" >> \"\$STUB_LOG\"\necho \"leak=\${SQUIRE_DB_PASSWORD:-unset}\" >> \"\$STUB_LOG\"\nexit 0\n");
        $result = $this->exec($app, 'scripts/release.sh');

        self::assertSame(0, $result['exit'], $result['stdout'] . $result['stderr']);
        self::assertContains('leak=unset', $this->calls());
        self::assertStringContainsString('step 1: check preconditions', $result['stdout'], 'SQUIRE_STEP_NO stays internal');
    }

    public function testAValueWithCommandSubstitutionInTheSettingsFileStaysLiteral(): void
    {
        $app = $this->initializedApp();
        $marker = $app . '/pwned';
        file_put_contents($app . '/.env.squire', "SQUIRE_SMOKE_URL=http://127.0.0.1:80/\$(touch pwned)`touch pwned2`\${HOME}\n");
        $result = $this->exec($app, 'scripts/release.sh', ['SQUIRE_SMOKE_RETRIES' => '1']);

        self::assertFileDoesNotExist($marker);
        self::assertFileDoesNotExist($marker . '2');
        self::assertStringContainsString('http://127.0.0.1:80/$(touch pwned)`touch pwned2`${HOME} ->', $result['stdout']);
    }

    public function testEveryReleaseOptionIsInTheSettingsFileAllowList(): void
    {
        $script = (string) file_get_contents(Squire::templatesDir() . '/common/scripts/release.sh');
        preg_match_all('/^: "\$\{(SQUIRE_[A-Z_]+):=/m', $script, $defaults);
        self::assertNotEmpty($defaults[1]);
        foreach ($defaults[1] as $name) {
            if ($name === 'SQUIRE_APP_DIR') {
                continue;
            }
            self::assertMatchesRegularExpression('/\b' . $name . '( \||\))/', $script, "{$name} is missing from squire_load_settings");
        }
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

    public function testProctorGateIsAReportAndNeverStopsTheReleaseByDefault(): void
    {
        $app = $this->initializedApp();
        $result = $this->exec($app, 'scripts/release.sh', [
            'SQUIRE_PROCTOR_BIN' => $this->stubs . '/proctor',
            'SQUIRE_PROCTOR_POLICY' => '/policy.json',
            'STUB_PROCTOR_EXIT' => '1',
        ]);

        self::assertSame(0, $result['exit'], $result['stdout'] . $result['stderr']);
        self::assertStringContainsString('PROCTOR gate (report only, never stops the release)', $result['stdout']);
        self::assertStringContainsString('proctor gate: no-go (exit 1); the release goes on', $result['stderr']);
        self::assertContains('proctor gate --path . --policy /policy.json --record storage/squire/proctor-gate.json', $this->calls());
        self::assertNotContains('proctor check --path . --policy /policy.json', $this->calls(), 'a PROCTOR with the gate is never asked for check');

        $broken = $this->exec($app, 'scripts/release.sh', [
            'SQUIRE_PROCTOR_BIN' => $this->stubs . '/proctor',
            'SQUIRE_PROCTOR_POLICY' => '/policy.json',
            'STUB_PROCTOR_EXIT' => '2',
        ]);
        self::assertSame(0, $broken['exit']);
        self::assertStringContainsString('proctor gate could not decide (exit 2); the release goes on', $broken['stderr']);
    }

    public function testTheVerdictIsRecordedAndTheNextReleaseUsesItAsTheBaseline(): void
    {
        $app = $this->initializedApp();
        $env = ['SQUIRE_PROCTOR_BIN' => $this->stubs . '/proctor', 'SQUIRE_PROCTOR_POLICY' => '/policy.json'];

        $first = $this->exec($app, 'scripts/release.sh', $env);
        self::assertSame(0, $first['exit'], $first['stdout'] . $first['stderr']);
        self::assertStringContainsString('no baseline yet: this is the first gated release, so every failure blocks', $first['stdout']);
        self::assertStringContainsString('verdict recorded in storage/squire/proctor-gate.json', $first['stdout']);
        self::assertStringContainsString('proctor gate: go', $first['stdout']);
        self::assertFileExists($app . '/storage/squire/proctor-gate-baseline.json');
        self::assertSame("*\n", file_get_contents($app . '/storage/squire/.gitignore'), 'the record folder never reaches git');

        $second = $this->exec($app, 'scripts/release.sh', $env);
        self::assertSame(0, $second['exit'], $second['stdout'] . $second['stderr']);
        self::assertStringContainsString('baseline: storage/squire/proctor-gate-baseline.json', $second['stdout']);
        self::assertContains(
            'proctor gate --path . --policy /policy.json --record storage/squire/proctor-gate.json --baseline storage/squire/proctor-gate-baseline.json',
            $this->calls(),
        );
    }

    public function testOnlyAReleaseThatGoesOnMovesTheBaseline(): void
    {
        $app = $this->initializedApp();
        $env = ['SQUIRE_PROCTOR_BIN' => $this->stubs . '/proctor', 'SQUIRE_PROCTOR_POLICY' => '/policy.json'];
        $baseline = $app . '/storage/squire/proctor-gate-baseline.json';

        self::assertSame(0, $this->exec($app, 'scripts/release.sh', $env)['exit']);
        self::assertStringContainsString('"exit":0', (string) file_get_contents($baseline));

        $stopped = $this->exec($app, 'scripts/release.sh', [...$env, 'SQUIRE_PROCTOR' => 'enforce', 'STUB_PROCTOR_EXIT' => '1']);
        self::assertSame(1, $stopped['exit']);
        self::assertStringContainsString('"exit":1', (string) file_get_contents($app . '/storage/squire/proctor-gate.json'), 'the no-go is recorded');
        self::assertStringContainsString('"exit":0', (string) file_get_contents($baseline), 'a stopped release leaves the baseline alone');

        $reported = $this->exec($app, 'scripts/release.sh', [...$env, 'STUB_PROCTOR_EXIT' => '1']);
        self::assertSame(0, $reported['exit']);
        self::assertStringContainsString('"exit":1', (string) file_get_contents($baseline), 'a reported no-go went live, so it is the new baseline');

        $undecided = $this->exec($app, 'scripts/release.sh', [...$env, 'STUB_PROCTOR_EXIT' => '2']);
        self::assertSame(0, $undecided['exit']);
        self::assertStringContainsString('"exit":1', (string) file_get_contents($baseline), 'a gate that could not decide leaves the baseline alone');
    }

    public function testTheRecordFolderIsASetting(): void
    {
        $app = $this->initializedApp();
        $result = $this->exec($app, 'scripts/release.sh', [
            'SQUIRE_PROCTOR_BIN' => $this->stubs . '/proctor',
            'SQUIRE_PROCTOR_RECORD_DIR' => 'var/gate',
        ]);

        self::assertSame(0, $result['exit'], $result['stdout'] . $result['stderr']);
        self::assertFileExists($app . '/var/gate/proctor-gate.json');
        self::assertFileExists($app . '/var/gate/proctor-gate-baseline.json');
    }

    /** The one `proctor gate --path ...` call of the release (the `gate --help` probe is not it). */
    private function gateCall(): string
    {
        $gates = array_values(array_filter($this->calls(), static fn(string $c): bool => str_starts_with($c, 'proctor gate --path ')));
        self::assertCount(1, $gates, implode("\n", $this->calls()));

        return $gates[0];
    }

    public function testByDefaultTheGateGradesThePhpThatRunsTheRelease(): void
    {
        $app = $this->initializedApp();
        // A php that claims 8.4.27 when asked its version and is the real one for everything else: an actor
        // playing PHP, which Creed Bratton assures us is how he got most of his jobs.
        $this->stub('php', "#!/bin/sh\n"
            . "if [ \"\$1\" = \"-r\" ] && [ \"\$2\" = 'echo PHP_VERSION;' ]; then printf '%s' 8.4.27; exit 0; fi\n"
            . 'exec ' . escapeshellarg(PHP_BINARY) . " \"\$@\"\n");
        $result = $this->exec($app, 'scripts/release.sh', [
            'SQUIRE_PROCTOR_BIN' => $this->stubs . '/proctor',
            'SQUIRE_PROCTOR_POLICY' => '/policy.json',
            'SQUIRE_PHP_RUNTIME' => null,
        ]);

        self::assertSame(0, $result['exit'], $result['stdout'] . $result['stderr']);
        self::assertStringContainsString('gate: grading PHP 8.4.27', $result['stdout'] . $result['stderr']);
        self::assertStringContainsString(' --php-runtime 8.4.27', $this->gateCall());
    }

    public function testAnExplicitPhpRuntimeIsGraded(): void
    {
        $app = $this->initializedApp();
        $result = $this->exec($app, 'scripts/release.sh', [
            'SQUIRE_PROCTOR_BIN' => $this->stubs . '/proctor',
            'SQUIRE_PHP_RUNTIME' => '8.3.15',
        ]);

        self::assertSame(0, $result['exit'], $result['stdout'] . $result['stderr']);
        self::assertStringContainsString('gate: grading PHP 8.3.15', $result['stdout'] . $result['stderr']);
        self::assertStringContainsString(' --php-runtime 8.3.15', $this->gateCall());
    }

    public function testSkipLeavesTheRuntimeUngraded(): void
    {
        $app = $this->initializedApp();
        $result = $this->exec($app, 'scripts/release.sh', [
            'SQUIRE_PROCTOR_BIN' => $this->stubs . '/proctor',
            'SQUIRE_PHP_RUNTIME' => 'skip',
        ]);

        self::assertSame(0, $result['exit'], $result['stdout'] . $result['stderr']);
        self::assertStringContainsString('gate: SQUIRE_PHP_RUNTIME=skip, runtime not graded', $result['stdout'] . $result['stderr']);
        self::assertStringNotContainsString('--php-runtime', $this->gateCall());
    }

    public function testAPhpRuntimeThatIsNotThreePartsIsNotGraded(): void
    {
        $app = $this->initializedApp();
        $result = $this->exec($app, 'scripts/release.sh', [
            'SQUIRE_PROCTOR_BIN' => $this->stubs . '/proctor',
            'SQUIRE_PHP_RUNTIME' => '8.4',
        ]);

        self::assertSame(0, $result['exit'], $result['stdout'] . $result['stderr']);
        self::assertStringContainsString('gate: SQUIRE_PHP_RUNTIME is not X.Y.Z, runtime not graded', $result['stdout'] . $result['stderr']);
        self::assertStringNotContainsString('--php-runtime', $this->gateCall());
    }

    public function testAnOlderProctorWithoutTheGateFallsBackToCheckWithAClearMessage(): void
    {
        $app = $this->initializedApp();
        $result = $this->exec($app, 'scripts/release.sh', [
            'SQUIRE_PROCTOR_BIN' => $this->stubs . '/proctor',
            'SQUIRE_PROCTOR_POLICY' => '/policy.json',
            'STUB_PROCTOR_GATE' => '0',
            'STUB_PROCTOR_EXIT' => '1',
        ]);

        self::assertSame(0, $result['exit'], $result['stdout'] . $result['stderr']);
        self::assertStringContainsString('this PROCTOR (proctor 0.6.0) has no `proctor gate`, so the release runs `proctor check` instead', $result['stderr']);
        self::assertStringContainsString('Update waynestate/proctor to a release that has the gate', $result['stderr']);
        self::assertContains('proctor check --path . --policy /policy.json', $this->calls());
        self::assertStringContainsString('proctor reported failures (exit 1); the release goes on', $result['stderr']);
        self::assertFileDoesNotExist($app . '/storage/squire/proctor-gate.json', 'check writes no gate verdict');

        $enforced = $this->exec($app, 'scripts/release.sh', [
            'SQUIRE_PROCTOR' => 'enforce',
            'SQUIRE_PROCTOR_BIN' => $this->stubs . '/proctor',
            'STUB_PROCTOR_GATE' => '0',
            'STUB_PROCTOR_EXIT' => '1',
        ]);
        self::assertSame(1, $enforced['exit']);
        self::assertStringContainsString('proctor reported failures (exit 1)', $enforced['stderr']);
    }

    public function testTheOracleCheckRunsAndNeverFailsTheRelease(): void
    {
        $app = $this->initializedApp();
        $result = $this->exec($app, 'scripts/release.sh', [
            'ORACLE_USERNAME' => 'someuser',
            'ORACLE_PASSWORD' => 'somepass',
            'ORACLE_HOST' => '127.0.0.1',
            'ORACLE_PORT' => '1',
            'ORACLE_SERVICE_NAME' => 'someservice',
        ]);

        self::assertSame(0, $result['exit'], $result['stdout'] . $result['stderr']);
        self::assertStringContainsString('check Oracle (never fails the release)', $result['stdout']);
        self::assertStringContainsString('[oracle] Oracle did not answer', $result['stdout'], 'the real check runs and reports the closed port');
        self::assertStringContainsString('[squire] release ok', $result['stdout'], 'the release goes on past the finding');
    }

    public function testProctorFallsBackToItsOwnDefaultPolicyWhenCreedIsNotInstalled(): void
    {
        $result = $this->exec($this->initializedApp(), 'scripts/release.sh', ['SQUIRE_PROCTOR_BIN' => $this->stubs . '/proctor']);

        self::assertSame(0, $result['exit'], $result['stdout'] . $result['stderr']);
        self::assertSame(1, substr_count($result['stdout'], 'squire: CREED policy not installed; PROCTOR uses its own default policy'), 'the notice is printed exactly once');
        self::assertContains('proctor gate --path . --record storage/squire/proctor-gate.json', $this->calls());
        self::assertNotContains('proctor gate --path . --policy vendor/waynestate/creed/policy/policy.json --record storage/squire/proctor-gate.json', $this->calls());
    }

    public function testProctorPolicyDefaultsToThePolicyCreedShips(): void
    {
        $app = $this->initializedApp();
        $this->put($app, 'vendor/waynestate/creed/policy/policy.json', '{}');
        $result = $this->exec($app, 'scripts/release.sh', ['SQUIRE_PROCTOR_BIN' => $this->stubs . '/proctor']);

        self::assertSame(0, $result['exit'], $result['stdout'] . $result['stderr']);
        self::assertContains('proctor gate --path . --policy vendor/waynestate/creed/policy/policy.json --record storage/squire/proctor-gate.json', $this->calls());
    }

    public function testAnExplicitProctorPolicyBeatsTheDefault(): void
    {
        $app = $this->initializedApp();
        $this->put($app, 'vendor/waynestate/creed/policy/policy.json', '{}');
        $result = $this->exec($app, 'scripts/release.sh', [
            'SQUIRE_PROCTOR_BIN' => $this->stubs . '/proctor',
            'SQUIRE_PROCTOR_POLICY' => '/explicit/policy.json',
        ]);

        self::assertSame(0, $result['exit'], $result['stdout'] . $result['stderr']);
        self::assertContains('proctor gate --path . --policy /explicit/policy.json --record storage/squire/proctor-gate.json', $this->calls());
        self::assertNotContains('proctor gate --path . --policy vendor/waynestate/creed/policy/policy.json --record storage/squire/proctor-gate.json', $this->calls());
    }

    public function testProctorEnforceStopsTheReleaseOnFailures(): void
    {
        $app = $this->initializedApp();
        $result = $this->exec($app, 'scripts/release.sh', [
            'SQUIRE_PROCTOR' => 'enforce',
            'SQUIRE_PROCTOR_BIN' => $this->stubs . '/proctor',
            'SQUIRE_PROCTOR_POLICY' => '/policy.json',
            'STUB_PROCTOR_EXIT' => '1',
        ]);

        self::assertSame(1, $result['exit']);
        self::assertStringContainsString('PROCTOR gate (enforced, a no-go stops the release)', $result['stdout']);
        self::assertStringContainsString('proctor gate: no-go (exit 1). The release stops before the frontend build and the migrations', $result['stderr']);
        self::assertStringNotContainsString('frontend install and build', $result['stdout'], 'an enforced no-go stops before the build');
        self::assertStringNotContainsString('smoke test the health endpoint', $result['stdout'], 'an enforced no-go stops before the smoke test');
        self::assertContains('proctor gate --path . --policy /policy.json --record storage/squire/proctor-gate.json', $this->calls());
    }

    public function testProctorEnforcePassesWhenTheCheckPasses(): void
    {
        $app = $this->initializedApp();
        $result = $this->exec($app, 'scripts/release.sh', [
            'SQUIRE_PROCTOR' => 'enforce',
            'SQUIRE_PROCTOR_BIN' => $this->stubs . '/proctor',
            'SQUIRE_PROCTOR_POLICY' => '/policy.json',
        ]);

        self::assertSame(0, $result['exit'], $result['stdout'] . $result['stderr']);
        self::assertStringContainsString('proctor gate: go', $result['stdout']);
    }

    public function testProctorEnforceStopsTheReleaseWhenProctorItselfFails(): void
    {
        $result = $this->exec($this->initializedApp(), 'scripts/release.sh', [
            'SQUIRE_PROCTOR' => 'enforce',
            'SQUIRE_PROCTOR_BIN' => $this->stubs . '/proctor',
            'SQUIRE_PROCTOR_POLICY' => '/policy.json',
            'STUB_PROCTOR_EXIT' => '2',
        ]);

        self::assertSame(1, $result['exit']);
        self::assertStringContainsString('proctor gate could not decide (exit 2)', $result['stderr']);
    }

    public function testProctorEnforceDoesNotFailWhenTheCreedPolicyIsMissing(): void
    {
        $result = $this->exec($this->initializedApp(), 'scripts/release.sh', [
            'SQUIRE_PROCTOR' => 'enforce',
            'SQUIRE_PROCTOR_BIN' => $this->stubs . '/proctor',
        ]);

        self::assertSame(0, $result['exit'], $result['stdout'] . $result['stderr']);
        self::assertStringContainsString('PROCTOR gate (enforced, a no-go stops the release)', $result['stdout']);
        self::assertSame(1, substr_count($result['stdout'], 'squire: CREED policy not installed; PROCTOR uses its own default policy'), 'the notice is printed exactly once');
        self::assertContains('proctor gate --path . --record storage/squire/proctor-gate.json', $this->calls());
    }

    public function testProctorEnforceNeedsAnExecutableBin(): void
    {
        $app = $this->initializedApp();

        $badBin = $this->exec($app, 'scripts/release.sh', [
            'SQUIRE_PROCTOR' => 'enforce',
            'SQUIRE_PROCTOR_BIN' => $this->stubs . '/not-a-real-proctor',
            'SQUIRE_PROCTOR_POLICY' => '/policy.json',
        ]);
        self::assertSame(1, $badBin['exit']);
        self::assertStringContainsString('SQUIRE_PROCTOR_BIN is not an executable file', $badBin['stderr']);
    }

    public function testLaravelRunsMigrateThenOptimizeAndSmokeTestsUp(): void
    {
        $app = $this->initializedApp(true);
        $this->put($app, '.env', "APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=\nAPP_ENV=testing\n");
        $this->artisanStub($app);

        $result = $this->exec($app, 'scripts/release.sh');

        self::assertSame(0, $result['exit'], $result['stdout'] . $result['stderr']);
        $calls = $this->calls();
        $migrate = array_search('artisan migrate --force', $calls, true);
        $optimize = array_search('artisan optimize', $calls, true);
        $reload = array_search('artisan reload', $calls, true);
        self::assertIsInt($migrate);
        self::assertIsInt($optimize);
        self::assertIsInt($reload, 'a Laravel that answers reload --help gets its long-lived processes restarted');
        self::assertLessThan($optimize, $migrate, 'migrate runs before the caches are built');
        self::assertGreaterThan($optimize, $reload, 'the restart comes after the caches are built');
        self::assertNotContains('artisan queue:restart', $calls, 'reload covers the workers, so the fallback commands stay off');
        $gate = array_search('proctor gate --path . --record storage/squire/proctor-gate.json', $calls, true);
        self::assertIsInt($gate, 'PROCTOR on PATH is found and asked for the gate');
        self::assertLessThan($migrate, $gate, 'the gate decides before the migrations touch the database');
        $curl = array_values(array_filter($calls, static fn(string $c): bool => str_starts_with($c, 'curl ')));
        self::assertStringContainsString('http://127.0.0.1:80/up', $curl[0]);
        self::assertStringNotContainsString('up.php', $curl[0]);
        self::assertDirectoryExists($app . '/storage');
        self::assertDirectoryExists($app . '/bootstrap/cache');
    }

    /**
     * A logging artisan that answers every command, except the ones a test makes fail through STUB_RELOAD_HELP_EXIT,
     * STUB_QUEUE_RESTART_EXIT and STUB_SCHEDULE_INTERRUPT_EXIT.
     */
    private function artisanStub(string $app): void
    {
        $this->put($app, 'artisan', "<?php\n\$args = array_slice(\$argv, 1);\nfile_put_contents(getenv('STUB_LOG'), 'artisan ' . implode(' ', \$args) . \"\\n\", FILE_APPEND);\n\$command = \$args[0] ?? '';\n\$help = in_array('--help', \$args, true);\nif (\$command === 'reload' && \$help) { exit((int) (getenv('STUB_RELOAD_HELP_EXIT') ?: 0)); }\nif (\$command === 'queue:restart' && !\$help) { exit((int) (getenv('STUB_QUEUE_RESTART_EXIT') ?: 0)); }\nif (\$command === 'schedule:interrupt' && !\$help) { exit((int) (getenv('STUB_SCHEDULE_INTERRUPT_EXIT') ?: 0)); }\nexit(0);\n");
    }

    public function testWithoutReloadTheReleaseFallsBackToQueueRestartAndScheduleInterrupt(): void
    {
        $app = $this->initializedApp(true);
        $this->put($app, '.env', "APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=\nAPP_ENV=testing\n");
        $this->artisanStub($app);

        $result = $this->exec($app, 'scripts/release.sh', ['STUB_RELOAD_HELP_EXIT' => '1']);

        self::assertSame(0, $result['exit'], $result['stdout'] . $result['stderr']);
        $calls = $this->calls();
        $optimize = array_search('artisan optimize', $calls, true);
        $queueRestart = array_search('artisan queue:restart', $calls, true);
        $interrupt = array_search('artisan schedule:interrupt', $calls, true);
        self::assertIsInt($optimize);
        self::assertIsInt($queueRestart, 'without reload the release falls back to queue:restart');
        self::assertIsInt($interrupt, 'and to schedule:interrupt');
        self::assertGreaterThan($optimize, $queueRestart, 'both restarts come after the caches are built');
        self::assertLessThan($interrupt, $queueRestart, 'queue:restart first, then schedule:interrupt');
        self::assertFalse(in_array('artisan reload', $calls, true), 'a Laravel without reload is never asked for it');
    }

    public function testAFailingQueueRestartIsAWarningAndTheReleaseGoesOn(): void
    {
        $app = $this->initializedApp(true);
        $this->put($app, '.env', "APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=\nAPP_ENV=testing\n");
        $this->artisanStub($app);

        $result = $this->exec($app, 'scripts/release.sh', ['STUB_RELOAD_HELP_EXIT' => '1', 'STUB_QUEUE_RESTART_EXIT' => '3']);

        self::assertSame(0, $result['exit'], $result['stdout'] . $result['stderr']);
        self::assertStringContainsString('WARNING: php artisan queue:restart exited 3 (report only; the release goes on)', $result['stderr']);
        self::assertStringContainsString('[squire] release ok', $result['stdout'], 'the report-only step never fails the release');
        $calls = $this->calls();
        $queueRestart = array_search('artisan queue:restart', $calls, true);
        $interrupt = array_search('artisan schedule:interrupt', $calls, true);
        self::assertIsInt($queueRestart);
        self::assertIsInt($interrupt, 'the failing command does not stop the rest of the step');
        self::assertGreaterThan($queueRestart, $interrupt);
    }

    public function testTurningOffTheRestartSkipsEveryRestartCommand(): void
    {
        $app = $this->initializedApp(true);
        $this->put($app, '.env', "APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=\nAPP_ENV=testing\n");
        $this->artisanStub($app);

        $result = $this->exec($app, 'scripts/release.sh', ['SQUIRE_ARTISAN_RESTART' => '0']);

        self::assertSame(0, $result['exit'], $result['stdout'] . $result['stderr']);
        self::assertStringContainsString('skipped (SQUIRE_ARTISAN_RESTART=0)', $result['stdout']);
        self::assertSame([], array_filter($this->calls(), static fn(string $c): bool => str_starts_with($c, 'artisan reload') || str_starts_with($c, 'artisan queue:restart') || str_starts_with($c, 'artisan schedule:interrupt')));
    }

    public function testARestartSettingThatIsNotZeroOrOneStopsBeforeAnythingInstalls(): void
    {
        $app = $this->initializedApp(true);
        $this->put($app, '.env', "APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=\nAPP_ENV=testing\n");
        $this->artisanStub($app);

        $result = $this->exec($app, 'scripts/release.sh', ['SQUIRE_ARTISAN_RESTART' => '2']);

        self::assertSame(1, $result['exit']);
        self::assertStringContainsString("SQUIRE_ARTISAN_RESTART must be 0 or 1; got '2'", $result['stderr']);
        self::assertStringContainsString('FAILED at step 1', $result['stderr']);
        self::assertNotContains('composer install --no-dev --optimize-autoloader --no-interaction --no-progress --prefer-dist', $this->calls());
    }

    public function testARawReleaseNeverRestartsLongLivedProcesses(): void
    {
        $app = $this->initializedApp();

        $result = $this->exec($app, 'scripts/release.sh');

        self::assertSame(0, $result['exit'], $result['stdout'] . $result['stderr']);
        self::assertStringNotContainsString('laravel restart long-lived processes', $result['stdout']);
        self::assertSame([], array_filter($this->calls(), static fn(string $c): bool => str_starts_with($c, 'artisan ')));
    }

    /** A php on PATH that answers the opcache probe with $state and hands everything else to the real PHP. */
    private function stubOpcache(string $state): void
    {
        $this->stub('php', "#!/bin/sh\ncase \"\$*\" in *'Zend OPcache'*) printf '%s' '{$state}'; exit 0 ;; esac\nexec '" . PHP_BINARY . "' \"\$@\"");
    }

    public function testAFrozenOpcacheIsAWarningAfterAReleaseInPlaceAndNeverAFailure(): void
    {
        $this->stubOpcache('frozen');

        $result = $this->exec($this->initializedApp(), 'scripts/release.sh');

        self::assertSame(0, $result['exit'], $result['stdout'] . $result['stderr']);
        self::assertStringContainsString('check opcache (never fails the release)', $result['stdout']);
        self::assertStringContainsString('opcache.validate_timestamps=0: Apache keeps serving the PHP it compiled before this release', $result['stderr']);
        self::assertStringContainsString('SQUIRE_OPCACHE_VALIDATE=1', $result['stderr']);
    }

    public function testAFrozenOpcacheIsOnlyANoteInTheFirstReleaseHookBeforeApacheStarts(): void
    {
        $this->stubOpcache('frozen');

        $result = $this->exec($this->initializedApp(), 'scripts/release.sh', ['SQUIRE_SMOKE' => '0']);

        self::assertSame(0, $result['exit'], $result['stdout'] . $result['stderr']);
        self::assertStringContainsString('fine here, Apache starts after this hook', $result['stdout']);
        self::assertStringNotContainsString('opcache', $result['stderr']);
    }

    public function testAnOpcacheThatChecksFilesIsANote(): void
    {
        $this->stubOpcache('checks');

        $result = $this->exec($this->initializedApp(), 'scripts/release.sh');

        self::assertSame(0, $result['exit'], $result['stdout'] . $result['stderr']);
        self::assertStringContainsString('opcache.validate_timestamps=1: the web server sees changed files by itself', $result['stdout']);
    }

    public function testLaravelWithoutAnEnvFileStopsEarly(): void
    {
        $result = $this->exec($this->initializedApp(true), 'scripts/release.sh');

        self::assertSame(1, $result['exit']);
        self::assertStringContainsString('.env not found', $result['stderr']);
        self::assertNotContains('artisan migrate --force', $this->calls());
    }

    // --- the environment guard (Sourdough practice 4.14) -------------------------------------------------------------

    public function testALaravelReleaseWithoutAnAppKeyStopsBeforeAnythingInstalls(): void
    {
        $app = $this->initializedApp(true);
        $this->put($app, '.env', "APP_ENV=production\n");
        $this->artisanStub($app);

        $result = $this->exec($app, 'scripts/release.sh');

        self::assertSame(1, $result['exit']);
        self::assertStringContainsString('APP_KEY is not set', $result['stderr']);
        self::assertStringNotContainsString('frontend install and build', $result['stdout'], 'the guard stops the release before any step installs');
        self::assertNotContains('composer install --no-dev --optimize-autoloader --no-interaction --no-progress --prefer-dist', $this->calls());
    }

    public function testAnAppKeyInTheProcessEnvironmentPassesTheGuard(): void
    {
        $app = $this->initializedApp(true);
        $this->put($app, '.env', "APP_ENV=testing\n");
        $this->artisanStub($app);

        $result = $this->exec($app, 'scripts/release.sh', ['APP_KEY' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=']);

        self::assertSame(0, $result['exit'], $result['stdout'] . $result['stderr']);
        self::assertStringNotContainsString('APP_KEY is not set', $result['stderr']);
        self::assertStringContainsString('[squire] release ok', $result['stdout']);
    }

    public function testDebugLeftOnInProductionStopsTheRelease(): void
    {
        $app = $this->initializedApp(true);
        $this->put($app, '.env', "APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=\nAPP_ENV=production\nAPP_DEBUG=true\n");
        $this->artisanStub($app);

        $result = $this->exec($app, 'scripts/release.sh');

        self::assertSame(1, $result['exit']);
        self::assertStringContainsString("APP_DEBUG must be false in production (got 'true')", $result['stderr']);
        self::assertStringNotContainsString('frontend install and build', $result['stdout']);
        self::assertNotContains('artisan migrate --force', $this->calls());
    }

    public function testDebugOnOutsideProductionIsOnlyAWarning(): void
    {
        $app = $this->initializedApp(true);
        $this->put($app, '.env', "APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=\nAPP_ENV=staging\nAPP_DEBUG=true\n");
        $this->artisanStub($app);

        $result = $this->exec($app, 'scripts/release.sh');

        self::assertSame(0, $result['exit'], $result['stdout'] . $result['stderr']);
        self::assertStringContainsString("APP_ENV is 'staging', not production; the release goes on", $result['stderr']);
        self::assertStringNotContainsString('APP_DEBUG must be false', $result['stderr']);
        self::assertStringContainsString('[squire] release ok', $result['stdout']);
    }

    public function testQuotedEnvValuesAreStrippedByTheGuard(): void
    {
        $app = $this->initializedApp(true);
        $this->put($app, '.env', "APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=\nAPP_DEBUG=\"true\"\n");
        $this->artisanStub($app);

        $result = $this->exec($app, 'scripts/release.sh');

        self::assertSame(1, $result['exit']);
        self::assertStringContainsString("APP_DEBUG must be false in production (got 'true')", $result['stderr']);
    }

    public function testARawReleaseNeverRunsTheEnvironmentGuard(): void
    {
        $app = $this->initializedApp();

        $result = $this->exec($app, 'scripts/release.sh', ['APP_DEBUG' => 'true']);

        self::assertSame(0, $result['exit'], $result['stdout'] . $result['stderr']);
        self::assertStringNotContainsString('APP_KEY is not set', $result['stderr']);
        self::assertStringNotContainsString('APP_DEBUG must be false', $result['stderr']);
        self::assertStringNotContainsString('not production', $result['stderr']);
        self::assertStringContainsString('[squire] release ok', $result['stdout']);
    }

    public function testFrontendPackagesInstallFromTheLockFileWithoutInstallScripts(): void
    {
        $app = $this->initializedVueApp();
        $this->put($app, 'package.json', '{"scripts":{"build":"vite build"}}');
        $this->put($app, 'package-lock.json', '{}');

        $result = $this->exec($app, 'scripts/release.sh');

        self::assertSame(0, $result['exit'], $result['stdout'] . $result['stderr']);
        $calls = $this->calls();
        self::assertContains('npm ci --prefer-offline --no-audit --no-fund --ignore-scripts', $calls);
        self::assertContains('npm run build', $calls);
        self::assertLessThan((int) array_search('npm run build', $calls, true), (int) array_search('npm ci --prefer-offline --no-audit --no-fund --ignore-scripts', $calls, true));
    }

    // --- package download caches ---------------------------------------------------------------------------------------

    public function testTheDownloadCachesLiveInTheAppFolderByDefault(): void
    {
        $app = $this->npmApp();
        $result = $this->exec($app, 'scripts/release.sh');

        self::assertSame(0, $result['exit'], $result['stdout'] . $result['stderr']);
        self::assertStringContainsString('package download caches', $result['stdout']);
        $calls = $this->calls();
        $composerEnv = array_values(array_filter($calls, static fn(string $c): bool => str_starts_with($c, 'COMPOSER_CACHE_DIR=')));
        self::assertNotEmpty($composerEnv);
        self::assertStringEndsWith('/.cache/squire/composer', $composerEnv[0]);
        $npmEnv = array_values(array_filter($calls, static fn(string $c): bool => str_starts_with($c, 'npm_config_cache=')));
        self::assertNotEmpty($npmEnv);
        self::assertStringEndsWith('/.cache/squire/npm', $npmEnv[0]);
        self::assertDirectoryExists($app . '/.cache/squire/composer');
        self::assertDirectoryExists($app . '/.cache/squire/npm');
        self::assertSame("*\n", (string) file_get_contents($app . '/.cache/squire/.gitignore'), 'the cache folder never reaches git');
        self::assertSame("Require all denied\n", (string) file_get_contents($app . '/.cache/squire/.htaccess'), 'Apache never serves the cache folder');
    }

    public function testACacheDirTheHostSetWinsOverTheDefault(): void
    {
        $app = $this->initializedApp();
        $result = $this->exec($app, 'scripts/release.sh', ['COMPOSER_CACHE_DIR' => '/opt/host-composer-cache']);

        self::assertSame(0, $result['exit'], $result['stdout'] . $result['stderr']);
        self::assertContains('COMPOSER_CACHE_DIR=/opt/host-composer-cache', $this->calls());
    }

    public function testTurningTheCacheOffExportsNothingAndCreatesNoCacheFolder(): void
    {
        $app = $this->npmApp();
        $result = $this->exec($app, 'scripts/release.sh', ['SQUIRE_CACHE_DIR' => 'off']);

        self::assertSame(0, $result['exit'], $result['stdout'] . $result['stderr']);
        self::assertStringContainsString('skipped (SQUIRE_CACHE_DIR=off)', $result['stdout']);
        $exported = array_filter($this->calls(), static fn(string $c): bool => preg_match('/^(COMPOSER_CACHE_DIR|npm_config_cache)=./', $c) === 1);
        self::assertSame([], array_values($exported), 'neither installer sees a cache directory');
        self::assertDirectoryDoesNotExist($app . '/.cache/squire');
    }

    public function testNpmCiPrefersTheOfflineCacheAndSkipsItsAudit(): void
    {
        $result = $this->exec($this->npmApp(), 'scripts/release.sh');

        self::assertSame(0, $result['exit'], $result['stdout'] . $result['stderr']);
        self::assertContains('npm ci --prefer-offline --no-audit --no-fund --ignore-scripts', $this->calls());
    }

    // --- the install-script escape hatch and the release-age note ----------------------------------------------------

    private function npmApp(): string
    {
        $app = $this->initializedVueApp();
        $this->put($app, 'package.json', '{"scripts":{"build":"vite build"}}');
        $this->put($app, 'package-lock.json', '{}');

        return $app;
    }

    public function testOnlyTheNamedPackagesRunTheirInstallScriptsAfterTheInstall(): void
    {
        $result = $this->exec($this->npmApp(), 'scripts/release.sh', ['SQUIRE_NPM_REBUILD' => 'esbuild @parcel/watcher']);

        self::assertSame(0, $result['exit'], $result['stdout'] . $result['stderr']);
        $calls = $this->calls();
        $install = array_search('npm ci --prefer-offline --no-audit --no-fund --ignore-scripts', $calls, true);
        $rebuild = array_search('npm rebuild --ignore-scripts=false esbuild @parcel/watcher', $calls, true);
        self::assertNotFalse($install, 'the install itself still runs no scripts');
        self::assertNotFalse($rebuild, 'the named packages run theirs');
        self::assertGreaterThan($install, $rebuild);
        self::assertLessThan((int) array_search('npm run build', $calls, true), $rebuild, 'before the build that needs them');
    }

    public function testWithoutTheHatchNothingIsRebuilt(): void
    {
        $result = $this->exec($this->npmApp(), 'scripts/release.sh');

        self::assertSame(0, $result['exit'], $result['stdout'] . $result['stderr']);
        self::assertSame([], array_filter($this->calls(), static fn(string $c): bool => str_starts_with($c, 'npm rebuild')));
    }

    public function testThePnpmHatchRebuildsTheNamedPackages(): void
    {
        $app = $this->initializedVueApp();
        $this->put($app, 'package.json', '{"scripts":{"build":"vite build"}}');
        $this->put($app, 'pnpm-lock.yaml', "lockfileVersion: '9.0'\n");

        $result = $this->exec($app, 'scripts/release.sh', ['SQUIRE_NPM_REBUILD' => 'sharp']);

        self::assertSame(0, $result['exit'], $result['stdout'] . $result['stderr']);
        self::assertContains('corepack pnpm rebuild sharp', $this->calls());
    }

    /** @return iterable<string, array{string}> */
    public static function badRebuildNames(): iterable
    {
        yield 'a flag' => ['--unsafe-perm'];
        yield 'a version' => ['esbuild@0.25.0'];
        yield 'a path' => ['../evil'];
        yield 'a glob' => ['*'];
        yield 'a shell word' => ['esbuild;id'];
        yield 'capitals' => ['EsBuild'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badRebuildNames')]
    public function testARebuildValueThatIsNotAPackageNameStopsTheRelease(string $value): void
    {
        $result = $this->exec($this->npmApp(), 'scripts/release.sh', ['SQUIRE_NPM_REBUILD' => $value]);

        self::assertSame(1, $result['exit'], $result['stdout'] . $result['stderr']);
        self::assertStringContainsString('SQUIRE_NPM_REBUILD takes package names separated by spaces', $result['stderr']);
        self::assertSame([], array_filter($this->calls(), static fn(string $c): bool => str_starts_with($c, 'npm rebuild') || str_starts_with($c, 'npm ci')));
    }

    public function testAnNpmThatIgnoresMinReleaseAgeIsNoted(): void
    {
        $old = $this->exec($this->npmApp(), 'scripts/release.sh', ['STUB_NPM_VERSION' => '10.9.2']);
        self::assertSame(0, $old['exit'], $old['stdout'] . $old['stderr']);
        self::assertStringContainsString('npm 10.9.2 ignores min-release-age', $old['stdout'] . $old['stderr']);

        $new = $this->exec($this->npmApp(), 'scripts/release.sh', ['STUB_NPM_VERSION' => '11.10.0']);
        self::assertSame(0, $new['exit'], $new['stdout'] . $new['stderr']);
        self::assertStringNotContainsString('ignores min-release-age', $new['stdout'] . $new['stderr']);
    }

    public function testTheVueNpmrcKeepsOneDayAndDocumentsTheHatches(): void
    {
        $npmrc = (string) file_get_contents(Squire::templatesDir() . '/vue/.npmrc');

        self::assertMatchesRegularExpression('/^min-release-age=1$/m', $npmrc);
        self::assertStringContainsString('npm install --min-release-age=0', $npmrc, 'the deliberate way past the age');
        self::assertStringContainsString('SQUIRE_NPM_REBUILD', $npmrc, 'the way to let one package run its scripts');
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
        self::assertNotContains('npm ci --prefer-offline --no-audit --no-fund --ignore-scripts', $calls);
        self::assertLessThan((int) array_search('corepack pnpm run build', $calls, true), (int) array_search('corepack pnpm install --frozen-lockfile --ignore-scripts', $calls, true));
    }

    private const YARN_CLASSIC_LOCK = "# THIS IS AN AUTOGENERATED FILE. DO NOT EDIT THIS FILE DIRECTLY.\n# yarn lockfile v1\n\n\nvite@^6.0.0:\n  version \"6.0.0\"\n";

    private const YARN_BERRY_LOCK = "# This file is generated by running \"yarn install\" inside your project.\n\n__metadata:\n  version: 8\n  cacheKey: 10c0\n";

    /** A Vue app with a yarn.lock: Classic by default, Berry (with packageManager in package.json) when asked. */
    private function yarnApp(bool $berry, bool $packageManager = true): string
    {
        $app = $this->initializedVueApp();
        $manager = $berry && $packageManager ? '"packageManager": "yarn@4.5.3", ' : '';
        $this->put($app, 'package.json', '{' . $manager . '"scripts":{"build":"vite build"}}');
        $this->put($app, 'yarn.lock', $berry ? self::YARN_BERRY_LOCK : self::YARN_CLASSIC_LOCK);

        return $app;
    }

    public function testAClassicYarnLockSelectsYarnOneFrozenInstallAndBuild(): void
    {
        $result = $this->exec($this->yarnApp(false), 'scripts/release.sh');

        self::assertSame(0, $result['exit'], $result['stdout'] . $result['stderr']);
        $calls = $this->calls();
        $install = array_search('corepack yarn install --frozen-lockfile --non-interactive --ignore-scripts', $calls, true);
        $build = array_search('corepack yarn run build', $calls, true);
        self::assertNotFalse($install, 'Yarn 1 installs the lock exactly, without install scripts');
        self::assertNotFalse($build);
        self::assertLessThan($build, $install);
        self::assertSame([], array_filter($calls, static fn(string $c): bool => str_starts_with($c, 'npm ci') || str_starts_with($c, 'corepack pnpm')));
        self::assertStringNotContainsString('commit a lock file', $result['stderr'], 'a committed yarn.lock is a lock file');
    }

    public function testABerryYarnLockSelectsAnImmutableInstallThatSkipsBuilds(): void
    {
        $result = $this->exec($this->yarnApp(true), 'scripts/release.sh');

        self::assertSame(0, $result['exit'], $result['stdout'] . $result['stderr']);
        $calls = $this->calls();
        self::assertContains('corepack yarn install --immutable --mode=skip-build', $calls);
        self::assertContains('corepack yarn run build', $calls);
        self::assertNotContains('corepack yarn install --frozen-lockfile --non-interactive --ignore-scripts', $calls);
    }

    public function testABerryInstallRunsBuildsWhenInstallScriptsAreAllowed(): void
    {
        $result = $this->exec($this->yarnApp(true), 'scripts/release.sh', ['SQUIRE_NPM_IGNORE_SCRIPTS' => '0']);

        self::assertSame(0, $result['exit'], $result['stdout'] . $result['stderr']);
        self::assertContains('corepack yarn install --immutable', $this->calls());
    }

    public function testABerryLockWithoutPackageManagerIsRefusedBeforeAnyInstall(): void
    {
        $result = $this->exec($this->yarnApp(true, false), 'scripts/release.sh');

        self::assertSame(1, $result['exit'], $result['stdout'] . $result['stderr']);
        self::assertStringContainsString('yarn.lock is from Yarn 2 or later, but package.json has no "packageManager": "yarn@..." line', $result['stderr']);
        self::assertSame([], array_filter($this->calls(), static fn(string $c): bool => str_starts_with($c, 'corepack ')));
    }

    /** @return iterable<string, array{bool, string}> */
    public static function yarnRebuilds(): iterable
    {
        yield 'Yarn 1 has no rebuild, so npm rebuilds in node_modules' => [false, 'npm rebuild --ignore-scripts=false sharp esbuild'];
        yield 'Yarn 2 and later rebuild themselves' => [true, 'corepack yarn rebuild sharp esbuild'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('yarnRebuilds')]
    public function testTheYarnHatchRebuildsTheNamedPackagesBeforeTheBuild(bool $berry, string $rebuild): void
    {
        $result = $this->exec($this->yarnApp($berry), 'scripts/release.sh', ['SQUIRE_NPM_REBUILD' => 'sharp esbuild']);

        self::assertSame(0, $result['exit'], $result['stdout'] . $result['stderr']);
        $calls = $this->calls();
        self::assertContains($rebuild, $calls);
        self::assertLessThan((int) array_search('corepack yarn run build', $calls, true), (int) array_search($rebuild, $calls, true));
    }

    public function testAFailingYarnBuildStopsTheRelease(): void
    {
        $result = $this->exec($this->yarnApp(false), 'scripts/release.sh', ['STUB_COREPACK_BUILD_EXIT' => '1']);

        self::assertSame(1, $result['exit']);
        self::assertStringContainsString('frontend install and build', $result['stderr']);
        self::assertContains('corepack yarn run build', $this->calls());
    }

    public function testAPackageJsonWithoutALockFileIsRefusedNotInstalled(): void
    {
        $app = $this->initializedVueApp();
        $this->put($app, 'package.json', '{"scripts":{"build":"vite build"}}');

        $result = $this->exec($app, 'scripts/release.sh');

        self::assertSame(1, $result['exit']);
        self::assertStringContainsString('commit a lock file', $result['stderr']);
        self::assertNotContains('npm install', $this->calls());
        self::assertNotContains('npm ci --prefer-offline --no-audit --no-fund --ignore-scripts', $this->calls());
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
        $this->put($app, '.env', "APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=
APP_ENV=testing
");
        $this->put($app, 'package.json', '{"devDependencies":{"vite":"^6.0.0"},"scripts":{"build":"vite build"}}');
        $this->put($app, 'package-lock.json', '{}');

        $result = $this->exec($app, 'scripts/release.sh');

        self::assertSame(0, $result['exit'], $result['stdout'] . $result['stderr']);
        $calls = $this->calls();
        self::assertContains('npm ci --prefer-offline --no-audit --no-fund --ignore-scripts', $calls);
        self::assertContains('npm run build', $calls);
    }

    public function testARawAppWithABundlerAndPnpmGetsTheFrontendBuild(): void
    {
        $app = $this->initializedApp();
        $this->put($app, 'package.json', '{"devDependencies":{"esbuild":"^0.25.0"},"scripts":{"build":"esbuild src/app.js"}}');
        $this->put($app, 'pnpm-lock.yaml', "lockfileVersion: '9.0'
");

        $result = $this->exec($app, 'scripts/release.sh');

        self::assertSame(0, $result['exit'], $result['stdout'] . $result['stderr']);
        self::assertContains('corepack pnpm run build', $this->calls());
    }

    public function testAFailingFrontendBuildStopsTheRelease(): void
    {
        $app = $this->initializedVueApp();
        $this->put($app, 'package.json', '{"scripts":{"build":"vite build"}}');
        $this->put($app, 'package-lock.json', '{}');

        $result = $this->exec($app, 'scripts/release.sh', ['STUB_NPM_BUILD_EXIT' => '1']);

        self::assertSame(1, $result['exit']);
        self::assertStringContainsString('frontend install and build', $result['stderr']);
        self::assertContains('npm ci --prefer-offline --no-audit --no-fund --ignore-scripts', $this->calls());
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

        $badProctor = $this->exec($this->initializedApp(), 'scripts/release.sh', ['SQUIRE_PROCTOR' => 'loud']);
        self::assertSame(1, $badProctor['exit']);
        self::assertStringContainsString('SQUIRE_PROCTOR must be auto, skip or enforce', $badProctor['stderr']);
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

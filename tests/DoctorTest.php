<?php

declare(strict_types=1);

namespace Waynestate\Squire\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use Waynestate\Squire\Doctor\Doctor;
use Waynestate\Squire\Doctor\Finding;
use Waynestate\Squire\Doctor\Project;
use Waynestate\Squire\Doctor\Report;
use Waynestate\Squire\ToolError;

final class DoctorTest extends SquireTestCase
{
    /**
     * @param list<Finding> $findings
     *
     * @return list<string> "S12:fail" style keys
     */
    private function keys(array $findings): array
    {
        return array_map(static fn(Finding $f): string => $f->rule . ':' . $f->status, $findings);
    }

    /** @return list<Finding> */
    private function examine(string $app): array
    {
        return (new Doctor())->run(new Project($app));
    }

    public function testAFreshRawAppFromInitHasNoFailures(): void
    {
        $findings = $this->examine($this->initializedApp());

        $failures = array_filter($findings, static fn(Finding $f): bool => $f->status === Finding::FAIL);
        self::assertSame([], array_map(static fn(Finding $f): string => $f->rule . ' ' . $f->message, array_values($failures)));
        self::assertSame(0, Doctor::exitCode($findings));
    }

    public function testAFreshLaravelAppFromInitHasNoFailuresAndChecksTheLaravelRules(): void
    {
        $findings = $this->examine($this->initializedApp(true));
        $keys = $this->keys($findings);

        self::assertSame(0, Doctor::exitCode($findings));
        self::assertContains('S16:pass', $keys);
        self::assertContains('S17:pass', $keys);
    }

    public function testTheOnlyWarningOnAFreshAppIsTheUnpinnedCitadelImage(): void
    {
        $findings = $this->examine($this->initializedApp());
        $warnings = array_values(array_filter($findings, static fn(Finding $f): bool => $f->status === Finding::WARN));

        self::assertCount(1, $warnings);
        self::assertSame('S67', $warnings[0]->rule);
        self::assertStringContainsString('citadel/php-apache', $warnings[0]->message);
    }

    /** @return array<string, array{array<string, string>, string}> */
    public static function badApps(): array
    {
        $release = static fn(string $body): array => [
            'build.sh' => "#!/bin/sh\nset -eu\n",
            'deployments/001-first-release.sh' => "#!/usr/bin/env bash\nset -euo pipefail\n",
            'scripts/release.sh' => "#!/usr/bin/env bash\nset -euo pipefail\n" . $body,
        ];
        $good = "step() { echo \"==> \$*\"; }\n[ \"\$(id -u)\" -ne 0 ]\ncomposer install --no-dev --no-interaction\ncomposer audit\ncurl http://127.0.0.1/up\nproctor check || true\n";

        return [
            'composer update on the server' => [$release("composer update\n"), 'S12:fail'],
            'composer install keeps dev packages' => [$release("composer install --no-interaction\n"), 'S12:fail'],
            'no composer install at all' => [$release("echo hi\n"), 'S12:fail'],
            'npm install instead of npm ci' => [$release($good . "npm install\n"), 'S12:fail'],
            'a download piped into a shell' => [$release($good . "curl -s https://example.invalid/x | sh\n"), 'S4:fail'],
            'a script without set -e' => [['build.sh' => "#!/bin/sh\necho hi\n"] + $release($good), 'S3:warn'],
            'a bash script without pipefail' => [['scripts/release.sh' => "#!/usr/bin/env bash\nset -e\n" . $good] + $release(''), 'S3:warn'],
            'CRLF line endings' => [['build.sh' => "#!/bin/sh\r\nset -eu\r\n"] + $release($good), 'S8:fail'],
            'composer in build.sh' => [['build.sh' => "#!/bin/sh\nset -eu\ncomposer install\n"] + $release($good), 'S1:fail'],
            'sudo in a script' => [$release($good . "sudo chown x y\n"), 'S19:warn'],
            'no root check' => [$release("composer install --no-dev --no-interaction\n"), 'S19:warn'],
            'no audit' => [$release("composer install --no-dev --no-interaction\n"), 'S14:warn'],
            'no smoke test' => [$release("composer install --no-dev --no-interaction\n"), 'S21:warn'],
            'no step markers' => [$release("composer install --no-dev --no-interaction\n"), 'S22:warn'],
            'PROCTOR as a bare gate' => [$release($good . "\nproctor check --path .\n"), 'S20:fail'],
            'no PROCTOR' => [$release("composer install --no-dev --no-interaction\n"), 'S20:warn'],
            'composer may prompt' => [$release("composer install --no-dev\n"), 'S15:warn'],
            'a health check that runs artisan' => [$release($good) + ['health.php' => "<?php\nexec('php artisan about');\n"], 'S55:fail'],
            'no health check at all' => [$release($good), 'S55:warn'],
        ];
    }

    /**
     * @param array<string, string> $files
     */
    #[DataProvider('badApps')]
    public function testRulesFireOnBadApps(array $files, string $expected): void
    {
        $findings = $this->examine($this->app($files));

        self::assertContains($expected, $this->keys($findings), implode("\n", $this->keys($findings)));
    }

    public function testCommentsDoNotSatisfyARule(): void
    {
        $app = $this->app([
            'build.sh' => "#!/bin/sh\nset -eu\n# composer install is not run here\n",
            'scripts/release.sh' => "#!/usr/bin/env bash\nset -euo pipefail\n# composer install --no-dev, composer audit, /up, id -u, proctor\n",
        ]);
        $keys = $this->keys($this->examine($app));

        self::assertContains('S12:fail', $keys);
        self::assertContains('S14:warn', $keys);
        self::assertContains('S21:warn', $keys);
        self::assertContains('S1:pass', $keys);
    }

    public function testAMissingLockFileFails(): void
    {
        $app = $this->app();
        unlink($app . '/composer.lock');

        self::assertContains('S13:fail', $this->keys($this->examine($app)));
    }

    public function testMissingScriptsAreReportedAsAGap(): void
    {
        $keys = $this->keys($this->examine($this->app()));

        self::assertContains('S9:fail', $keys);
        self::assertContains('S9:warn', $keys);
    }

    public function testAuthJsonThatGitWouldTrackFails(): void
    {
        $app = $this->app(['auth.json' => '{}']);

        self::assertContains('S15:fail', $this->keys($this->examine($app)));
    }

    public function testMigrationsThatBreakOnMysql56Warn(): void
    {
        $app = $this->app([
            'database/migrations/2026_01_01_create_posts.php' => "<?php\n\$t->json('body');\n\$t->string('slug')->unique();\n",
        ], true);
        $findings = $this->examine($app);

        $messages = array_map(static fn(Finding $f): string => $f->rule . ':' . $f->status . ' ' . $f->message, $findings);
        self::assertContains('S18:warn', $this->keys($findings));
        self::assertNotEmpty(array_filter($messages, static fn(string $m): bool => str_contains($m, 'JSON columns')));
        self::assertNotEmpty(array_filter($messages, static fn(string $m): bool => str_contains($m, 'defaultStringLength(191)')));
    }

    public function testDefaultStringLength191SatisfiesTheIndexRule(): void
    {
        $app = $this->app([
            'database/migrations/2026_01_01_create_posts.php' => "<?php\n\$t->string('slug')->unique();\n",
            'app/Providers/AppServiceProvider.php' => "<?php\nSchema::defaultStringLength(191);\n",
        ], true);

        $messages = array_map(static fn(Finding $f): string => $f->status . ' ' . $f->message, $this->examine($app));
        self::assertContains('pass Schema::defaultStringLength(191) is set, so indexed strings fit MySQL 5.6.', $messages);
    }

    public function testDriftFromTheTemplatesIsAWarningAndNeverAFailure(): void
    {
        $app = $this->initializedApp();
        file_put_contents($app . '/scripts/release.sh', (string) file_get_contents($app . '/scripts/release.sh') . "\n# a team edit\n");

        $findings = $this->examine($app);

        $drift = array_values(array_filter($findings, static fn(Finding $f): bool => str_contains($f->message, 'is modified')));
        self::assertCount(1, $drift);
        self::assertSame(Finding::WARN, $drift[0]->status);
        self::assertSame(0, Doctor::exitCode($findings));
    }

    public function testExitCodeIsOneOnlyWhenSomethingFailed(): void
    {
        self::assertSame(0, Doctor::exitCode([Finding::warn('S1', 'x'), Finding::info('S2', 'y')]));
        self::assertSame(1, Doctor::exitCode([Finding::warn('S1', 'x'), Finding::fail('S2', 'y')]));
    }

    public function testTextAndJsonReportsAgree(): void
    {
        $findings = [Finding::fail('S12', 'bad', 'scripts/release.sh'), Finding::pass('S1', 'good')];

        $text = Report::text('.', $findings);
        self::assertStringContainsString('FAIL S12  bad [scripts/release.sh]', $text);
        self::assertStringContainsString('RESULT: fail (1 fail, 0 warn, 0 info, 1 pass)', $text);

        $json = json_decode(Report::json('.', $findings), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($json);
        self::assertSame('squire', $json['tool']);
        self::assertSame('fail', $json['result']);
        self::assertSame(1, $json['exit']);
        self::assertCount(2, $json['findings']);
    }

    public function testANonexistentPathIsAToolError(): void
    {
        $this->expectException(ToolError::class);
        new Project($this->tempDir() . '/missing');
    }
}

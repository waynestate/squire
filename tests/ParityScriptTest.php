<?php

declare(strict_types=1);

namespace Waynestate\Squire\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use Waynestate\Squire\Squire;

/**
 * Runs the real scripts/parity.sh with a stub docker on PATH that answers yes to everything, so `up` gets as far as
 * starting the stack without starting anything. Needs bash; the tests skip themselves when it is missing.
 */
final class ParityScriptTest extends SquireTestCase
{
    private const WARNING = "SQUIRE_PROJECT is the default 'squire-app'";

    private string $app = '';

    private string $stubs = '';

    protected function setUp(): void
    {
        if (!self::hasProgram('bash')) {
            self::markTestSkipped('bash is not installed.');
        }
        $this->stubs = $this->tempDir();
        file_put_contents($this->stubs . '/docker', "#!/bin/sh\necho \"docker \$*\" >> \"\$STUB_LOG\"\nexit 0\n");
        chmod($this->stubs . '/docker', 0o755);

        $this->app = $this->tempDir();
        mkdir($this->app . '/scripts');
        copy(Squire::templatesDir() . '/common/scripts/parity.sh', $this->app . '/scripts/parity.sh');
        file_put_contents($this->app . '/docker-compose.squire.yml', "services: {}\n");
    }

    /**
     * @param array<string, string> $env
     *
     * @return array{exit: int, stderr: string, log: string}
     */
    private function up(string $envFile, array $env = []): array
    {
        file_put_contents($this->app . '/.env.squire', $envFile);
        $log = $this->stubs . '/calls.log';
        file_put_contents($log, '');
        $process = proc_open(['bash', 'scripts/parity.sh', 'up'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->app, [
            'PATH' => $this->stubs . PATH_SEPARATOR . (string) getenv('PATH'),
            'STUB_LOG' => $log,
            ...$env,
        ]);
        self::assertIsResource($process);
        stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['exit' => proc_close($process), 'stderr' => $stderr, 'log' => (string) file_get_contents($log)];
    }

    /** @return iterable<string, array{string}> */
    public static function defaultNames(): iterable
    {
        yield 'the example as shipped' => ["SQUIRE_PROJECT=squire-app\nSQUIRE_HTTP_PORT=8080\n"];
        yield 'no name at all' => ["SQUIRE_HTTP_PORT=8080\n"];
        yield 'an empty name' => ["SQUIRE_PROJECT=\n"];
    }

    #[DataProvider('defaultNames')]
    public function testUpWarnsWhenTheAppIsOnTheSharedDefaultName(string $envFile): void
    {
        $run = $this->up($envFile);

        self::assertSame(0, $run['exit'], $run['stderr']);
        self::assertStringContainsString(self::WARNING, $run['stderr']);
        self::assertStringContainsString("give this app its own name in .env.squire", $run['stderr']);
        self::assertStringContainsString('compose --env-file .env.squire -f docker-compose.squire.yml up --build -d --wait', $run['log'], 'a warning, never a stop');
    }

    public function testAnAppWithItsOwnNameIsNotWarned(): void
    {
        $run = $this->up("SQUIRE_PROJECT=my-app\n");

        self::assertSame(0, $run['exit'], $run['stderr']);
        self::assertStringNotContainsString(self::WARNING, $run['stderr']);
    }

    public function testTheEnvironmentWinsOverTheSettingsFileAsItDoesForCompose(): void
    {
        self::assertStringNotContainsString(self::WARNING, $this->up("SQUIRE_PROJECT=squire-app\n", ['SQUIRE_PROJECT' => 'my-app'])['stderr']);
        self::assertStringContainsString(self::WARNING, $this->up("SQUIRE_PROJECT=my-app\n", ['SQUIRE_PROJECT' => 'squire-app'])['stderr']);
    }

    public function testTheWindowsTwinWarnsTheSameWay(): void
    {
        $ps = (string) file_get_contents(Squire::templatesDir() . '/common/scripts/parity.ps1');

        self::assertStringContainsString('$project = $env:SQUIRE_PROJECT', $ps);
        self::assertStringContainsString("Get-EnvValue -Name 'SQUIRE_PROJECT' -Default 'squire-app'", $ps);
        self::assertStringContainsString('Write-Warning "' . self::WARNING, $ps);
        self::assertStringContainsString("give this app its own name in \$EnvFile.", $ps);
    }

    public function testBothExamplesTellEachAppToTakeItsOwnName(): void
    {
        foreach (['raw', 'laravel'] as $target) {
            $example = (string) file_get_contents(Squire::templatesDir() . "/{$target}/.env.squire.example");
            self::assertStringContainsString('Give each app its own', $example, $target);
            self::assertStringContainsString("so the second app's release never runs", $example, $target);
        }
    }
}

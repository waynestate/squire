<?php

declare(strict_types=1);

namespace Waynestate\Squire\Tests;

use Waynestate\Squire\Squire;

final class ConsoleTest extends SquireTestCase
{
    public function testInitWritesTheScriptsAndExitsZero(): void
    {
        $app = $this->app();
        $result = $this->runSquire(['init', '--path=' . $app]);

        self::assertSame(0, $result['exit'], $result['stderr']);
        self::assertStringContainsString('created', $result['stdout']);
        self::assertStringContainsString('Target: raw', $result['stdout']);
        self::assertFileExists($app . '/scripts/release.sh');
        self::assertFileExists($app . '/' . Squire::MANIFEST);
    }

    public function testInitDetectsLaravelFromArtisan(): void
    {
        $result = $this->runSquire(['init', '--path=' . $this->app([], true)]);

        self::assertStringContainsString('Target: laravel', $result['stdout']);
    }

    public function testInitCheckExitsOneOnDriftAndZeroWhenCurrent(): void
    {
        $app = $this->app();

        $missing = $this->runSquire(['init', '--check', '--path=' . $app]);
        self::assertSame(1, $missing['exit']);
        self::assertStringContainsString('RESULT: drift', $missing['stdout']);

        $this->runSquire(['init', '--path=' . $app]);
        $current = $this->runSquire(['init', '--check', '--path=' . $app]);
        self::assertSame(0, $current['exit']);
        self::assertStringContainsString('RESULT: pass (0 of', $current['stdout']);

        file_put_contents($app . '/build.sh', "edited\n");
        $edited = $this->runSquire(['init', '--check', '--path=' . $app]);
        self::assertSame(1, $edited['exit']);
        self::assertStringContainsString('modified', $edited['stdout']);
    }

    public function testOnlyLimitsTheGroups(): void
    {
        $app = $this->app();
        $this->runSquire(['init', '--only=release', '--path=' . $app]);

        self::assertFileExists($app . '/build.sh');
        self::assertFileDoesNotExist($app . '/docker-compose.squire.yml');
    }

    public function testBadOptionsExitTwoNotOne(): void
    {
        $app = $this->app();

        self::assertSame(2, $this->runSquire(['init', '--target=django', '--path=' . $app])['exit']);
        self::assertSame(2, $this->runSquire(['init', '--only=nonsense', '--path=' . $app])['exit']);
        self::assertSame(2, $this->runSquire(['init', '--path=' . $app . '/missing'])['exit']);
        self::assertSame(2, $this->runSquire(['doctor', '--format=xml', '--path=' . $app])['exit']);
        self::assertSame(2, $this->runSquire(['doctor', '--path=' . $app . '/missing'])['exit']);
        self::assertSame(2, $this->runSquire(['nope'])['exit']);
    }

    public function testDoctorExitCodesFollowTheFindings(): void
    {
        $good = $this->initializedApp();
        $ok = $this->runSquire(['doctor', '--path=' . $good]);
        self::assertSame(0, $ok['exit'], $ok['stdout']);
        self::assertStringContainsString('RESULT: pass', $ok['stdout']);

        $bad = $this->app();
        $failed = $this->runSquire(['doctor', '--path=' . $bad]);
        self::assertSame(1, $failed['exit']);
        self::assertStringContainsString('RESULT: fail', $failed['stdout']);
    }

    public function testDoctorJsonIsValidJson(): void
    {
        $result = $this->runSquire(['doctor', '--format=json', '--path=' . $this->initializedApp()]);

        $data = json_decode($result['stdout'], true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        self::assertSame('pass', $data['result']);
        self::assertSame(Squire::VERSION, $data['version']);
    }

    public function testTheVersionIsPrinted(): void
    {
        $result = $this->runSquire(['--version']);

        self::assertStringContainsString('squire ' . Squire::VERSION, $result['stdout']);
    }
}

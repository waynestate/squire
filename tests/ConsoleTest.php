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

    public function testInitDetectsTheVueLayerFromPackageJson(): void
    {
        $app = $this->vueApp();
        $result = $this->runSquire(['init', '--path=' . $app]);

        self::assertSame(0, $result['exit'], $result['stderr']);
        self::assertStringContainsString('Target: raw', $result['stdout']);
        self::assertStringContainsString('Layer: vue', $result['stdout']);
        self::assertFileExists($app . '/.npmrc');
    }

    public function testInitDetectsTheVueLayerForLaravel(): void
    {
        $app = $this->vueApp(true);
        $result = $this->runSquire(['init', '--path=' . $app]);

        self::assertSame(0, $result['exit'], $result['stderr']);
        self::assertStringContainsString('Target: laravel', $result['stdout']);
        self::assertStringContainsString('Layer: vue', $result['stdout']);
        self::assertFileExists($app . '/.npmrc');
    }

    public function testInitDoesNotWriteTheVueLayerWithoutVue(): void
    {
        $app = $this->app();
        $result = $this->runSquire(['init', '--path=' . $app]);

        self::assertSame(0, $result['exit'], $result['stderr']);
        self::assertStringNotContainsString('Layer: vue', $result['stdout']);
        self::assertFileDoesNotExist($app . '/.npmrc');
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

    public function testUpdateExitsTwoWithoutAManifest(): void
    {
        $app = $this->app();

        $result = $this->runSquire(['update', '--path=' . $app]);

        self::assertSame(2, $result['exit']);
        self::assertStringContainsString('Run `squire init` first', $result['stderr']);
    }

    public function testUpdateBadOptionsExitTwo(): void
    {
        $app = $this->initializedApp();

        self::assertSame(2, $this->runSquire(['update', '--target=django', '--path=' . $app])['exit']);
        self::assertSame(2, $this->runSquire(['update', '--only=nonsense', '--path=' . $app])['exit']);
        self::assertSame(2, $this->runSquire(['update', '--path=' . $app . '/missing'])['exit']);
    }

    public function testUpdateExitsZeroWhenEverythingIsCurrent(): void
    {
        $app = $this->initializedApp();

        $result = $this->runSquire(['update', '--path=' . $app]);

        self::assertSame(0, $result['exit'], $result['stdout']);
        self::assertStringContainsString('Target: raw', $result['stdout']);
        self::assertStringContainsString('RESULT: pass (0 of', $result['stdout']);
    }

    public function testUpdateLeavesAnEditedFileAloneWhenTheTemplateHasNotChanged(): void
    {
        $app = $this->initializedApp();
        file_put_contents($app . '/build.sh', "edited\n");

        $result = $this->runSquire(['update', '--path=' . $app]);

        self::assertSame(0, $result['exit'], $result['stdout']);
        self::assertStringContainsString('edited, but the template has not changed', $result['stdout']);
        self::assertStringContainsString('edited', (string) file_get_contents($app . '/build.sh'));
    }

    public function testUpdateDryRunWritesNothing(): void
    {
        $app = $this->initializedApp();
        unlink($app . '/scripts/release.sh');

        $result = $this->runSquire(['update', '--dry-run', '--path=' . $app]);

        self::assertSame(0, $result['exit'], $result['stdout']);
        self::assertStringContainsString('Dry run: nothing will be written.', $result['stdout']);
        self::assertStringContainsString('restored', $result['stdout']);
        self::assertFileDoesNotExist($app . '/scripts/release.sh');
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

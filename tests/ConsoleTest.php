<?php

declare(strict_types=1);

namespace Waynestate\Squire\Tests;

use Waynestate\Squire\Console\UpdateCommand;
use Waynestate\Squire\Squire;

final class ConsoleTest extends SquireTestCase
{
    public function testUpdateOnlyHelpCallsImagesOptIn(): void
    {
        $description = (new UpdateCommand())->getDefinition()->getOption('only')->getDescription();

        self::assertStringContainsString('opt-in', $description);
        self::assertStringNotContainsString('deprecated', $description);
    }

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

    public function testInitSaysWhenTheReleaseWouldStopAtStepOne(): void
    {
        $app = $this->app();
        unlink($app . '/composer.lock');
        $result = $this->runSquire(['init', '--path=' . $app]);

        self::assertSame(0, $result['exit'], $result['stderr']);
        self::assertStringContainsString('this app has no composer.lock. Commit it before the first deploy', $result['stdout']);

        unlink($app . '/composer.json');
        $result = $this->runSquire(['init', '--path=' . $app]);
        self::assertStringContainsString('this app has no composer.json and no composer.lock. Commit both', $result['stdout']);
    }

    public function testInitStaysQuietAboutComposerWhenBothFilesAreThere(): void
    {
        $result = $this->runSquire(['init', '--path=' . $this->app()]);
        self::assertStringNotContainsString('Heads up', $result['stdout']);

        $app = $this->app();
        unlink($app . '/composer.lock');
        $result = $this->runSquire(['init', '--path=' . $app, '--only=images']);
        self::assertStringNotContainsString('Heads up', $result['stdout']);
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

    public function testInitWithTheStaticTargetWritesTheFrontControllerAndRecordsIt(): void
    {
        $app = $this->app();
        $result = $this->runSquire(['init', '--target=static', '--path=' . $app]);

        self::assertSame(0, $result['exit'], $result['stderr']);
        self::assertStringContainsString('Target: static', $result['stdout']);
        self::assertFileExists($app . '/public/index.php');
        self::assertFileExists($app . '/public/.htaccess');
        self::assertFileExists($app . '/public/up.php');
        self::assertFileExists($app . '/scripts/release.sh');
        $manifest = json_decode((string) file_get_contents($app . '/' . Squire::MANIFEST), true);
        self::assertSame('static', $manifest['target']);
    }

    public function testStaticCheckWithoutATargetKeepsTheRecordedTarget(): void
    {
        $app = $this->app();
        $this->runSquire(['init', '--target=static', '--path=' . $app]);

        $current = $this->runSquire(['init', '--check', '--path=' . $app]);
        self::assertSame(0, $current['exit'], $current['stdout']);
        self::assertStringContainsString('RESULT: pass (0 of', $current['stdout']);

        file_put_contents($app . '/public/index.php', (string) file_get_contents($app . '/public/index.php') . "// edited\n");
        $edited = $this->runSquire(['init', '--check', '--path=' . $app]);
        self::assertSame(1, $edited['exit']);
        self::assertStringContainsString('public/index.php', $edited['stdout']);
        self::assertStringContainsString('modified', $edited['stdout']);
    }

    public function testDetectionNeverPicksStaticForAPlainApp(): void
    {
        $app = $this->app();
        $result = $this->runSquire(['init', '--path=' . $app]);

        self::assertSame(0, $result['exit'], $result['stderr']);
        self::assertStringContainsString('Target: raw', $result['stdout']);
        self::assertStringNotContainsString("getenv('SQUIRE_STATIC_ROOT')", (string) file_get_contents($app . '/public/index.php'));
    }

    public function testOnlyLimitsTheGroups(): void
    {
        $app = $this->app();
        $this->runSquire(['init', '--only=release', '--path=' . $app]);

        self::assertFileExists($app . '/build.sh');
        self::assertFileDoesNotExist($app . '/.squire/Dockerfile');
    }

    public function testBareInitOnAnExistingKitDefaultsToTheRecordedGroups(): void
    {
        $app = $this->app();
        $this->runSquire(['init', '--only=release', '--path=' . $app]);
        self::assertFileDoesNotExist($app . '/.squire/Dockerfile');

        $result = $this->runSquire(['init', '--path=' . $app]);

        self::assertSame(0, $result['exit'], $result['stderr']);
        self::assertFileDoesNotExist($app . '/.squire/Dockerfile', 'the recorded groups win over the fresh-app default');
        $manifest = json_decode((string) file_get_contents($app . '/' . Squire::MANIFEST), true);
        self::assertSame(['release'], $manifest['groups']);
    }

    public function testExplicitGroupsStillWriteReleaseAndImages(): void
    {
        $app = $this->app();
        $result = $this->runSquire(['init', '--only=release,images', '--path=' . $app]);

        self::assertSame(0, $result['exit'], $result['stderr']);
        self::assertFileExists($app . '/build.sh');
        self::assertFileExists($app . '/.squire/Dockerfile');
        self::assertFileDoesNotExist($app . '/docker-compose.squire.yml', 'the parity kit left in 0.12.0');
        $manifest = json_decode((string) file_get_contents($app . '/' . Squire::MANIFEST), true);
        self::assertSame(['release', 'images'], $manifest['groups']);
    }

    public function testOnlyStillWinsOverTheRecordedGroups(): void
    {
        $app = $this->app();
        $this->runSquire(['init', '--only=release', '--path=' . $app]);

        $result = $this->runSquire(['init', '--only=images', '--path=' . $app]);

        self::assertSame(0, $result['exit'], $result['stderr']);
        self::assertFileExists($app . '/.squire/Dockerfile');
        $manifest = json_decode((string) file_get_contents($app . '/' . Squire::MANIFEST), true);
        self::assertSame(['images'], $manifest['groups']);
    }

    public function testOnlyParityIsRefusedWithAPointerAtParity(): void
    {
        foreach (['init', 'update'] as $command) {
            $app = $this->initializedApp();
            $result = $this->runSquire([$command, '--only=release,parity', '--path=' . $app]);

            self::assertSame(2, $result['exit'], $command . ': ' . $result['stdout']);
            self::assertStringContainsString('The parity group left Squire in 0.12.0', $result['stderr'] . $result['stdout']);
            self::assertStringContainsString('parity init --from-squire', $result['stderr'] . $result['stdout']);
            self::assertFileDoesNotExist($app . '/docker-compose.squire.yml');
        }
    }

    /**
     * An app initialized before 0.12.0 with the parity group: the record names `parity` and lists the kit's files.
     */
    private function oldKitApp(): string
    {
        $app = $this->initializedApp();
        $manifestPath = $app . '/' . Squire::MANIFEST;
        $manifest = json_decode((string) file_get_contents($manifestPath), true);
        $manifest['groups'] = ['release', 'parity'];
        $manifest['version'] = '0.11.2';
        foreach (['docker-compose.squire.yml' => "services: {}\n", 'scripts/parity.sh' => "#!/bin/sh\n", '.env.squire.example' => "APP_PORT=8080\n"] as $path => $body) {
            @mkdir(dirname($app . '/' . $path), 0777, true);
            file_put_contents($app . '/' . $path, $body);
            $manifest['files'][$path] = hash('sha256', $body);
        }
        file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

        return $app;
    }

    public function testUpdateReportsTheOldKitFilesAndDeletesNone(): void
    {
        $app = $this->oldKitApp();

        $update = $this->runSquire(['update', '--path=' . $app]);

        self::assertSame(0, $update['exit'], $update['stdout'] . $update['stderr']);
        self::assertStringContainsString('kit-leftover', $update['stdout']);
        self::assertStringContainsString('docker-compose.squire.yml', $update['stdout']);
        self::assertStringContainsString(Squire::PARITY_POINTER, $update['stdout']);
        self::assertStringContainsString('parity init --from-squire', Squire::PARITY_POINTER);
        self::assertStringContainsString(Squire::PARITY_URL, Squire::PARITY_POINTER);
        self::assertStringNotContainsString("\n", Squire::PARITY_POINTER, 'one line');
        foreach (['docker-compose.squire.yml', 'scripts/parity.sh', '.env.squire.example'] as $path) {
            self::assertFileExists($app . '/' . $path, $path . ' is never deleted');
        }

        $manifest = json_decode((string) file_get_contents($app . '/' . Squire::MANIFEST), true);
        self::assertSame(['release', 'images'], $manifest['groups'], 'the record\'s parity group reads as images');
        self::assertArrayNotHasKey('docker-compose.squire.yml', $manifest['files'], 'the kit files leave the record');
        self::assertArrayNotHasKey('scripts/parity.sh', $manifest['files']);
        self::assertArrayHasKey('.squire/Dockerfile', $manifest['files'], 'the image files stay');

        // A second update has nothing to report: the files are out of the record.
        $again = $this->runSquire(['update', '--path=' . $app]);
        self::assertSame(0, $again['exit'], $again['stdout']);
        self::assertStringNotContainsString('kit-leftover', $again['stdout']);
    }

    public function testInitPointsAtParityForAFreshApp(): void
    {
        $app = $this->app();
        $init = $this->runSquire(['init', '--only=release', '--path=' . $app]);
        self::assertSame(0, $init['exit'], $init['stderr']);
        self::assertStringNotContainsString(Squire::PARITY_POINTER, $init['stdout']);
        self::assertStringContainsString('Run it locally with Parity: parity init, then parity up (' . Squire::PARITY_URL . ').', $init['stdout']);

        $update = $this->runSquire(['update', '--path=' . $app]);
        self::assertStringNotContainsString('Parity:', $update['stdout']);

        // --check stays a plain drift report.
        $check = $this->runSquire(['init', '--check', '--path=' . $app]);
        self::assertStringNotContainsString('Parity:', $check['stdout']);
    }

    public function testDoctorFlagsLeftoverKitFilesAsInfo(): void
    {
        $left = $this->runSquire(['doctor', '--path=' . $this->oldKitApp()]);
        self::assertStringContainsString('INFO S35  Parity kit files left over: docker-compose.squire.yml', $left['stdout']);
        self::assertStringContainsString('then delete them; use Parity', $left['stdout']);
        self::assertStringContainsString(Squire::PARITY_URL, $left['stdout']);

        $app = $this->app();
        $this->runSquire(['init', '--only=release', '--path=' . $app]);
        $none = $this->runSquire(['doctor', '--path=' . $app]);
        self::assertStringContainsString('S35  No parity kit files left over.', $none['stdout']);
        self::assertStringNotContainsString('squire init --only=parity', $none['stdout']);
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

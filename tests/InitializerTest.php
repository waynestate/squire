<?php

declare(strict_types=1);

namespace Waynestate\Squire\Tests;

use Waynestate\Squire\Squire;
use Waynestate\Squire\Template\Catalog;
use Waynestate\Squire\Template\FileResult;
use Waynestate\Squire\Template\Initializer;
use Waynestate\Squire\Template\Manifest;
use Waynestate\Squire\ToolError;

final class InitializerTest extends SquireTestCase
{
    private function initializer(): Initializer
    {
        return new Initializer(new Catalog(Squire::templatesDir()));
    }

    public function testInitWritesEveryFileAndTheManifest(): void
    {
        $app = $this->app();
        $results = $this->initializer()->run($app, 'raw', ['release', 'parity'], false, false);

        foreach ($results as $result) {
            self::assertSame(FileResult::CREATED, $result->state, $result->path);
            self::assertFileExists($app . '/' . $result->path);
        }
        $manifest = Manifest::load($app);
        self::assertNotNull($manifest);
        self::assertSame('raw', $manifest->target);
        self::assertSame(Squire::VERSION, $manifest->version);
        self::assertCount(count($results), $manifest->files);
    }

    public function testRunningInitTwiceChangesNothing(): void
    {
        $app = $this->app();
        $this->initializer()->run($app, 'raw', ['release', 'parity'], false, false);
        $manifestBefore = (string) file_get_contents($app . '/' . Squire::MANIFEST);

        $second = $this->initializer()->run($app, 'raw', ['release', 'parity'], false, false);

        self::assertSame([FileResult::UNCHANGED], array_values(array_unique(array_map(static fn(FileResult $r): string => $r->state, $second))));
        self::assertSame($manifestBefore, (string) file_get_contents($app . '/' . Squire::MANIFEST));
    }

    public function testAnEditedFileIsLeftAloneUnlessForced(): void
    {
        $app = $this->app();
        $this->initializer()->run($app, 'raw', ['release'], false, false);
        file_put_contents($app . '/build.sh', "#!/bin/sh\n# my edit\n");

        $states = $this->states($this->initializer()->run($app, 'raw', ['release'], false, false));
        self::assertSame(FileResult::SKIPPED_MODIFIED, $states['build.sh']);
        self::assertStringContainsString('my edit', (string) file_get_contents($app . '/build.sh'));

        $forced = $this->states($this->initializer()->run($app, 'raw', ['release'], true, false));
        self::assertSame(FileResult::OVERWRITTEN, $forced['build.sh']);
        self::assertStringNotContainsString('my edit', (string) file_get_contents($app . '/build.sh'));
    }

    public function testAFileSquireDidNotWriteIsNeverOverwrittenWithoutForce(): void
    {
        $app = $this->app(['build.sh' => "#!/bin/sh\necho theirs\n"]);

        $states = $this->states($this->initializer()->run($app, 'raw', ['release'], false, false));

        self::assertSame(FileResult::SKIPPED_EXISTS, $states['build.sh']);
        self::assertStringContainsString('theirs', (string) file_get_contents($app . '/build.sh'));
        $manifest = Manifest::load($app);
        self::assertNotNull($manifest);
        self::assertArrayNotHasKey('build.sh', $manifest->files, 'a skipped file must not be recorded as written');
    }

    public function testAnUntouchedFileIsUpdatedWhenTheTemplateMovesOn(): void
    {
        $templates = $this->copyTemplates();
        $initializer = new Initializer(new Catalog($templates));
        $app = $this->app();
        $initializer->run($app, 'raw', ['release'], false, false);

        file_put_contents($templates . '/common/build.sh', "#!/bin/sh
# version two
");

        $check = $this->states($initializer->run($app, 'raw', ['release'], false, true));
        self::assertSame(FileResult::OUTDATED, $check['build.sh']);

        $update = $this->states($initializer->run($app, 'raw', ['release'], false, false));
        self::assertSame(FileResult::UPDATED, $update['build.sh']);
        self::assertStringContainsString('version two', (string) file_get_contents($app . '/build.sh'));
    }

    public function testCheckModeReportsMissingOkModifiedAndUntracked(): void
    {
        $app = $this->app();
        $missing = $this->states($this->initializer()->run($app, 'raw', ['release'], false, true));
        self::assertSame(FileResult::MISSING, $missing['build.sh']);
        self::assertFileDoesNotExist($app . '/build.sh', 'check mode must not write');
        self::assertFileDoesNotExist($app . '/' . Squire::MANIFEST);

        $this->initializer()->run($app, 'raw', ['release'], false, false);
        $ok = $this->states($this->initializer()->run($app, 'raw', ['release'], false, true));
        self::assertSame([FileResult::OK], array_values(array_unique($ok)));

        file_put_contents($app . '/build.sh', "changed\n");
        $modified = $this->states($this->initializer()->run($app, 'raw', ['release'], false, true));
        self::assertSame(FileResult::MODIFIED, $modified['build.sh']);

        unlink($app . '/' . Squire::MANIFEST);
        $untracked = $this->states($this->initializer()->run($app, 'raw', ['release'], false, true));
        self::assertSame(FileResult::UNTRACKED, $untracked['build.sh']);
    }

    public function testCrlfCheckoutsDoNotCountAsDrift(): void
    {
        $app = $this->app();
        $this->initializer()->run($app, 'raw', ['release'], false, false);
        $content = (string) file_get_contents($app . '/scripts/release.sh');
        file_put_contents($app . '/scripts/release.sh', str_replace("\n", "\r\n", $content));

        $states = $this->states($this->initializer()->run($app, 'raw', ['release'], false, true));

        self::assertSame(FileResult::OK, $states['scripts/release.sh']);
    }

    public function testScriptsAreMarkedExecutableOnPlatformsThatHaveTheBit(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            self::markTestSkipped('Windows has no executable bit.');
        }
        $app = $this->initializedApp();

        self::assertTrue(is_executable($app . '/build.sh'));
        self::assertTrue(is_executable($app . '/scripts/release.sh'));
        self::assertTrue(is_executable($app . '/deployments/001-first-release.sh'));
        self::assertFalse(is_executable($app . '/health.php'));
    }

    public function testALaravelAppGetsTheLaravelEnvFileAndNoRawHealthPage(): void
    {
        $app = $this->app([], true);
        $this->initializer()->run($app, 'laravel', ['release', 'parity'], false, false);

        self::assertFileDoesNotExist($app . '/public/up.php');
        self::assertStringContainsString('DB_HOST=mysql', (string) file_get_contents($app . '/.env.squire.example'));
    }

    public function testABrokenManifestIsAToolError(): void
    {
        $app = $this->app([Squire::MANIFEST => '{"nope":true}']);

        $this->expectException(ToolError::class);
        $this->initializer()->run($app, 'raw', ['release'], false, true);
    }

    public function testANonexistentAppPathIsAToolError(): void
    {
        $this->expectException(ToolError::class);
        $this->initializer()->run($this->tempDir() . '/missing', 'raw', ['release'], false, false);
    }

    public function testInitWritesTheVueLayerOnlyWhenVueIsDetected(): void
    {
        $plain = $this->app();
        $this->initializer()->run($plain, 'raw', ['release', 'parity'], false, false, Catalog::detectLayer($plain));
        self::assertFileDoesNotExist($plain . '/.npmrc');

        $vue = $this->vueApp();
        $results = $this->initializer()->run($vue, 'raw', ['release', 'parity'], false, false, Catalog::detectLayer($vue));
        self::assertFileExists($vue . '/.npmrc');
        self::assertStringContainsString('min-release-age=1', (string) file_get_contents($vue . '/.npmrc'));

        $manifest = Manifest::load($vue);
        self::assertNotNull($manifest);
        self::assertSame('vue', $manifest->layer);
        self::assertArrayHasKey('.npmrc', $manifest->files);
        self::assertCount(count($results), $manifest->files);
    }

    public function testInitWritesTheVueLayerForLaravelToo(): void
    {
        $app = $this->vueApp(true);
        $this->initializer()->run($app, 'laravel', ['release', 'parity'], false, false, Catalog::detectLayer($app));

        self::assertFileExists($app . '/.npmrc');
        self::assertFileDoesNotExist($app . '/public/up.php');
        self::assertStringContainsString('DB_HOST=mysql', (string) file_get_contents($app . '/.env.squire.example'));
    }

    public function testRunningVueInitTwiceChangesNothing(): void
    {
        $app = $this->vueApp();
        $this->initializer()->run($app, 'raw', ['release', 'parity'], false, false, Catalog::detectLayer($app));
        $manifestBefore = (string) file_get_contents($app . '/' . Squire::MANIFEST);

        $second = $this->initializer()->run($app, 'raw', ['release', 'parity'], false, false, Catalog::detectLayer($app));

        self::assertSame([FileResult::UNCHANGED], array_values(array_unique(array_map(static fn(FileResult $r): string => $r->state, $second))));
        self::assertSame($manifestBefore, (string) file_get_contents($app . '/' . Squire::MANIFEST));
    }
}

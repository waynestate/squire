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

    public function testAStaticAppGetsTheRawKitPlusTheFrontController(): void
    {
        $app = $this->app();
        $results = $this->initializer()->run($app, 'static', ['release', 'parity'], false, false);

        foreach ($results as $result) {
            self::assertSame(FileResult::CREATED, $result->state, $result->path);
            self::assertFileExists($app . '/' . $result->path);
        }
        self::assertFileExists($app . '/public/index.php');
        self::assertFileExists($app . '/public/.htaccess');
        self::assertFileExists($app . '/public/up.php');
        $manifest = Manifest::load($app);
        self::assertNotNull($manifest);
        self::assertSame('static', $manifest->target);
        self::assertCount(count($results), $manifest->files);
    }

    public function testStaticCheckPassesAfterInitAndReportsDriftAfterAnEdit(): void
    {
        $app = $this->app();
        $this->initializer()->run($app, 'static', ['release'], false, false);
        $ok = $this->states($this->initializer()->run($app, 'static', ['release'], false, true));
        self::assertSame([FileResult::OK], array_values(array_unique($ok)));

        file_put_contents($app . '/public/index.php', (string) file_get_contents($app . '/public/index.php') . "// edited\n");
        $modified = $this->states($this->initializer()->run($app, 'static', ['release'], false, true));
        self::assertSame(FileResult::MODIFIED, $modified['public/index.php']);
    }

    public function testStaticFrontControllerFilesAreNotExecutable(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            self::markTestSkipped('Windows has no executable bit.');
        }
        $app = $this->app();
        $this->initializer()->run($app, 'static', ['release'], false, false);

        self::assertFalse(is_executable($app . '/public/index.php'));
        self::assertFalse(is_executable($app . '/public/.htaccess'));
        self::assertTrue(is_executable($app . '/scripts/release.sh'));
    }

    public function testARawAppGetsTheFrontControllerAndItsHtaccess(): void
    {
        $app = $this->app();
        $states = $this->states($this->initializer()->run($app, 'raw', ['release'], false, false));

        self::assertSame(FileResult::CREATED, $states['public/index.php']);
        self::assertSame(FileResult::CREATED, $states['public/.htaccess']);
        self::assertStringContainsString("getenv('SQUIRE_APP_ENTRY')", (string) file_get_contents($app . '/public/index.php'));
        self::assertStringContainsString('RewriteRule ^(index|up)\.php$ - [L]', (string) file_get_contents($app . '/public/.htaccess'));
    }

    public function testARawAppsOwnIndexAndHtaccessAreNeverOverwrittenNotEvenByForce(): void
    {
        $own = "<?php
echo 'my own front door';
";
        $app = $this->app(['public/index.php' => $own, 'public/.htaccess' => "# mine
"]);

        foreach ([false, true] as $force) {
            $states = $this->states($this->initializer()->run($app, 'raw', ['release'], $force, false));
            self::assertSame(FileResult::APP_OWNED, $states['public/index.php']);
            self::assertSame(FileResult::APP_OWNED, $states['public/.htaccess']);
            self::assertSame($own, (string) file_get_contents($app . '/public/index.php'));
            self::assertSame("# mine
", (string) file_get_contents($app . '/public/.htaccess'));
        }

        // Not recorded, so update never touches it either, and --check counts it as the app's, not as drift.
        $manifest = Manifest::load($app);
        self::assertNotNull($manifest);
        self::assertArrayNotHasKey('public/index.php', $manifest->files);
        $checked = $this->initializer()->run($app, 'raw', ['release'], false, true);
        foreach ($checked as $result) {
            self::assertTrue($result->isCurrent(), $result->path . ' is ' . $result->state);
        }
    }

    public function testAFrontControllerSquireWroteIsTrackedLikeAnyOtherFile(): void
    {
        $app = $this->app();
        $this->initializer()->run($app, 'raw', ['release'], false, false);
        file_put_contents($app . '/public/index.php', (string) file_get_contents($app . '/public/index.php') . "// edited
");

        $checked = $this->states($this->initializer()->run($app, 'raw', ['release'], false, true));
        self::assertSame(FileResult::MODIFIED, $checked['public/index.php']);
        $again = $this->states($this->initializer()->run($app, 'raw', ['release'], false, false));
        self::assertSame(FileResult::SKIPPED_MODIFIED, $again['public/index.php']);
    }

    public function testAStaticAppsOwnIndexStillNeedsForceAsBefore(): void
    {
        $app = $this->app(['public/index.php' => "<?php
"]);
        $states = $this->states($this->initializer()->run($app, 'static', ['release'], false, false));

        self::assertSame(FileResult::SKIPPED_EXISTS, $states['public/index.php']);
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

    public function testInitInADeployLayerCreatesTheGitignoreWithAllThreeLines(): void
    {
        $layer = $this->deployLayer();
        $states = $this->states($this->initializer()->run($layer, 'raw', ['release', 'parity'], false, false));

        self::assertSame(FileResult::CREATED, $states['.gitignore']);
        $lines = file($layer . '/.gitignore', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        self::assertContains('/vendor/', $lines);
        self::assertContains('.env', $lines);
        self::assertContains('.env.squire', $lines);
        $manifest = Manifest::load($layer);
        self::assertNotNull($manifest);
        self::assertArrayNotHasKey('.gitignore', $manifest->files, 'the app may edit the file, so Squire records no hash');
    }

    public function testInitAppendsOnlyTheMissingLinesToAnExistingGitignore(): void
    {
        $layer = $this->deployLayer(['.gitignore' => "/vendor/\n# mine\nnode_modules/\n"]);
        $before = (string) file_get_contents($layer . '/.gitignore');

        $states = $this->states($this->initializer()->run($layer, 'raw', ['release'], false, false));

        self::assertSame(FileResult::UPDATED, $states['.gitignore']);
        $after = (string) file_get_contents($layer . '/.gitignore');
        self::assertStringStartsWith($before, $after, 'existing lines keep their order and wording');
        self::assertStringEndsWith(".env\n.env.squire\n", $after, 'the missing lines are appended, in order');
    }

    public function testInitIsIdempotentOnTheGitignore(): void
    {
        $layer = $this->deployLayer(['.gitignore' => "/vendor/\n"]);
        $this->initializer()->run($layer, 'raw', ['release'], false, false);
        $afterFirst = (string) file_get_contents($layer . '/.gitignore');

        $states = $this->states($this->initializer()->run($layer, 'raw', ['release'], false, false));

        self::assertSame(FileResult::UNCHANGED, $states['.gitignore']);
        self::assertSame($afterFirst, (string) file_get_contents($layer . '/.gitignore'), 'a second run changes nothing');
    }

    public function testCheckReportsAGitignoreMissingLinesAsDrift(): void
    {
        $layer = $this->deployLayer(['.gitignore' => "/vendor/\n"]);

        $checked = $this->states($this->initializer()->run($layer, 'raw', ['release'], false, true));
        self::assertSame(FileResult::MODIFIED, $checked['.gitignore']);
        self::assertSame("/vendor/\n", (string) file_get_contents($layer . '/.gitignore'), 'check mode must not write');

        $this->initializer()->run($layer, 'raw', ['release'], false, false);
        $ok = $this->states($this->initializer()->run($layer, 'raw', ['release'], false, true));
        self::assertSame(FileResult::OK, $ok['.gitignore']);
    }

    public function testInitAtTheRepositoryRootLeavesTheAppsGitignoreAlone(): void
    {
        foreach ([false, true] as $laravel) {
            $app = $this->app(['.gitignore' => "# the app's own\nstorage/\n"], $laravel);
            mkdir($app . '/.git');
            $before = (string) file_get_contents($app . '/.gitignore');

            $states = $this->states($this->initializer()->run($app, $laravel ? 'laravel' : 'raw', ['release', 'parity'], false, false));

            self::assertSame($before, (string) file_get_contents($app . '/.gitignore'), 'the repository root belongs to the app');
            self::assertArrayNotHasKey('.gitignore', $states, 'Squire reports nothing it did not do');
            $manifest = Manifest::load($app);
            self::assertNotNull($manifest);
            self::assertArrayNotHasKey('.gitignore', $manifest->files);
        }
    }
}

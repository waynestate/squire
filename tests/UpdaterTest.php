<?php

declare(strict_types=1);

namespace Waynestate\Squire\Tests;

use Waynestate\Squire\Squire;
use Waynestate\Squire\Template\Catalog;
use Waynestate\Squire\Template\FileResult;
use Waynestate\Squire\Template\Initializer;
use Waynestate\Squire\Template\Manifest;
use Waynestate\Squire\Template\Updater;
use Waynestate\Squire\ToolError;

final class UpdaterTest extends SquireTestCase
{
    /**
     * An app initialized from a private copy of the templates, so the tests can move a template on.
     *
     * @return array{0: string, 1: Updater, 2: string} [app, updater, templates]
     */
    private function movableApp(): array
    {
        $templates = $this->copyTemplates();
        $catalog = new Catalog($templates);
        $app = $this->app();
        (new Initializer($catalog))->run($app, 'raw', ['release', 'parity'], false, false);

        return [$app, new Updater($catalog), $templates];
    }

    /**
     * A Vue app initialized from a private copy of the templates.
     *
     * @return array{0: string, 1: Updater, 2: string} [app, updater, templates]
     */
    private function movableVueApp(): array
    {
        $templates = $this->copyTemplates();
        $catalog = new Catalog($templates);
        $app = $this->vueApp();
        (new Initializer($catalog))->run($app, 'raw', ['release', 'parity'], false, false, Catalog::detectLayer($app));

        return [$app, new Updater($catalog), $templates];
    }

    public function testAnUntouchedFileIsUpdatedWhenTheTemplateMovesOn(): void
    {
        [$app, $updater, $templates] = $this->movableApp();
        file_put_contents($templates . '/common/build.sh', "#!/bin/sh\n# version two\n");

        $results = $updater->run($app, 'raw', ['release', 'parity'], false);
        $states = $this->states($results);

        self::assertSame(FileResult::UPDATED, $states['build.sh']);
        self::assertStringContainsString('version two', (string) file_get_contents($app . '/build.sh'));
        self::assertSame(FileResult::CURRENT, $states['health.php'], 'the other files stay current');
        $manifest = Manifest::load($app);
        self::assertNotNull($manifest);
        self::assertSame(Manifest::hash((string) file_get_contents($templates . '/common/build.sh')), $manifest->files['build.sh']);
    }

    public function testAnEditedFileIsLeftAloneAndShownAsADiff(): void
    {
        [$app, $updater, $templates] = $this->movableApp();
        $manifestBefore = Manifest::load($app);
        self::assertNotNull($manifestBefore);
        $recordedBefore = $manifestBefore->files['build.sh'];
        file_put_contents($app . '/build.sh', "#!/bin/sh\n# my edit\n");
        file_put_contents($templates . '/common/build.sh', "#!/bin/sh\n# version two\n");

        $results = $updater->run($app, 'raw', ['release', 'parity'], false);
        $states = $this->states($results);

        self::assertSame(FileResult::EDITED, $states['build.sh']);
        self::assertStringContainsString('my edit', (string) file_get_contents($app . '/build.sh'), 'the edited file is left alone');
        $edited = null;
        foreach ($results as $result) {
            if ($result->path === 'build.sh') {
                $edited = $result;
            }
        }
        self::assertNotNull($edited);
        self::assertNotNull($edited->diff);
        self::assertStringContainsString('--- a/build.sh (template)', $edited->diff);
        self::assertStringContainsString('+++ b/build.sh (on disk)', $edited->diff);
        self::assertStringContainsString('-# version two', $edited->diff, 'what the template has that the disk does not');
        self::assertStringContainsString('+# my edit', $edited->diff, 'what the disk has that the template does not');
        $manifest = Manifest::load($app);
        self::assertNotNull($manifest);
        self::assertSame($recordedBefore, $manifest->files['build.sh'], 'the record of an edited file is not touched');
    }

    public function testAnUnchangedTemplateIsCurrent(): void
    {
        [$app, $updater] = $this->movableApp();
        $manifestBefore = (string) file_get_contents($app . '/' . Squire::MANIFEST);

        $results = $updater->run($app, 'raw', ['release', 'parity'], false);

        self::assertSame([FileResult::CURRENT], array_values(array_unique(array_map(static fn(FileResult $r): string => $r->state, $results))));
        self::assertSame($manifestBefore, (string) file_get_contents($app . '/' . Squire::MANIFEST), 'nothing written, no manifest churn');
    }

    public function testAnEditedFileWithAnUnchangedTemplateIsCurrent(): void
    {
        [$app, $updater] = $this->movableApp();
        file_put_contents($app . '/build.sh', "#!/bin/sh\n# my edit\n");

        $results = $updater->run($app, 'raw', ['release', 'parity'], false);
        $states = $this->states($results);

        self::assertSame(FileResult::CURRENT, $states['build.sh']);
        self::assertSame(0, Updater::exitCode($results), 'the template has not moved, so there is nothing to merge');
    }

    public function testAMissingFileIsRestored(): void
    {
        [$app, $updater, $templates] = $this->movableApp();
        unlink($app . '/build.sh');

        $results = $updater->run($app, 'raw', ['release', 'parity'], false);
        $states = $this->states($results);

        self::assertSame(FileResult::RESTORED, $states['build.sh']);
        self::assertFileExists($app . '/build.sh');
        $manifest = Manifest::load($app);
        self::assertNotNull($manifest);
        self::assertSame(Manifest::hash((string) file_get_contents($templates . '/common/build.sh')), $manifest->files['build.sh']);
    }

    public function testDryRunWritesNothing(): void
    {
        [$app, $updater, $templates] = $this->movableApp();
        file_put_contents($app . '/health.php', "<?php\n// my edit\n");
        file_put_contents($templates . '/common/health.php', "<?php\n// template two\n");
        unlink($app . '/scripts/release.sh');
        file_put_contents($templates . '/common/build.sh', "#!/bin/sh\n# version two\n");
        $manifestBefore = (string) file_get_contents($app . '/' . Squire::MANIFEST);
        $editedBefore = (string) file_get_contents($app . '/health.php');

        $results = $updater->run($app, 'raw', ['release', 'parity'], true);
        $states = $this->states($results);

        self::assertSame(FileResult::UPDATED, $states['build.sh']);
        self::assertSame(FileResult::EDITED, $states['health.php']);
        self::assertSame(FileResult::RESTORED, $states['scripts/release.sh']);
        self::assertStringNotContainsString('version two', (string) file_get_contents($app . '/build.sh'));
        self::assertSame($editedBefore, (string) file_get_contents($app . '/health.php'));
        self::assertFileDoesNotExist($app . '/scripts/release.sh');
        self::assertSame($manifestBefore, (string) file_get_contents($app . '/' . Squire::MANIFEST));
    }

    public function testFilesSquireDidNotWriteAreUntouched(): void
    {
        $templates = $this->copyTemplates();
        $catalog = new Catalog($templates);
        $app = $this->app(['build.sh' => "#!/bin/sh\necho theirs\n"]);
        (new Initializer($catalog))->run($app, 'raw', ['release'], false, false);
        $manifest = Manifest::load($app);
        self::assertNotNull($manifest);
        self::assertArrayNotHasKey('build.sh', $manifest->files, 'init skipped the file, so it is not in the record');

        file_put_contents($templates . '/common/build.sh', "#!/bin/sh\n# version two\n");
        $results = (new Updater($catalog))->run($app, 'raw', ['release'], false);
        $states = $this->states($results);

        self::assertArrayNotHasKey('build.sh', $states, 'a file SQUIRE did not write is not in the plan');
        self::assertStringContainsString('theirs', (string) file_get_contents($app . '/build.sh'));
    }

    public function testCrlfDoesNotCountAsAnEdit(): void
    {
        [$app, $updater, $templates] = $this->movableApp();
        $content = (string) file_get_contents($app . '/scripts/release.sh');
        file_put_contents($app . '/scripts/release.sh', str_replace("\n", "\r\n", $content));
        file_put_contents($templates . '/common/scripts/release.sh', $content . "# version two\n");

        $states = $this->states($updater->run($app, 'raw', ['release'], false));

        self::assertSame(FileResult::UPDATED, $states['scripts/release.sh'], 'init treats CRLF as equal, so update must too');
    }

    public function testOnlyLimitsTheGroups(): void
    {
        [$app, $updater, $templates] = $this->movableApp();
        file_put_contents($templates . '/common/build.sh', "#!/bin/sh\n# version two\n");
        file_put_contents($templates . '/common/docker-compose.squire.yml', "# version two\n");

        $states = $this->states($updater->run($app, 'raw', ['release'], false));

        self::assertSame(FileResult::UPDATED, $states['build.sh']);
        self::assertArrayNotHasKey('docker-compose.squire.yml', $states, 'parity is out of scope for this run');
    }

    public function testUpdateWithoutAManifestIsAToolError(): void
    {
        $app = $this->app();

        $this->expectException(ToolError::class);
        (new Updater(new Catalog(Squire::templatesDir())))->run($app, 'raw', ['release'], false);
    }

    public function testUpdateNeverTouchesTheGitignoreItOnlyNotesAHole(): void
    {
        $layer = $this->deployLayer(['.gitignore' => "/vendor/\n# the team's line\n"]);
        $catalog = new Catalog(Squire::templatesDir());
        (new Initializer($catalog))->run($layer, 'raw', ['release', 'parity'], false, false);
        file_put_contents($layer . '/.gitignore', "/vendor/\n# the team's line\n");
        $before = (string) file_get_contents($layer . '/.gitignore');

        $results = (new Updater($catalog))->run($layer, 'raw', ['release', 'parity'], false);

        self::assertSame($before, (string) file_get_contents($layer . '/.gitignore'), "an edited .gitignore is the app's; update leaves it alone");
        $notes = array_values(array_filter($results, static fn(FileResult $r): bool => $r->path === '.gitignore'));
        self::assertCount(1, $notes);
        self::assertSame(FileResult::CURRENT, $notes[0]->state, 'a note is not drift');
        self::assertStringContainsString('.env.squire', $notes[0]->detail);
        self::assertSame(0, Updater::exitCode($results));
    }

    public function testExitCodeIsOneWhenAnEditedFileDiffers(): void
    {
        self::assertSame(0, Updater::exitCode([
            new FileResult('a', FileResult::CURRENT, ''),
            new FileResult('b', FileResult::UPDATED, ''),
            new FileResult('c', FileResult::RESTORED, ''),
        ]));
        self::assertSame(1, Updater::exitCode([
            new FileResult('a', FileResult::CURRENT, ''),
            new FileResult('b', FileResult::EDITED, '', "--- a/b\n"),
        ]));
    }

    public function testTheVueLayerIsUpdatedWhenTheTemplateMovesOn(): void
    {
        [$app, $updater, $templates] = $this->movableVueApp();
        file_put_contents($templates . '/vue/.npmrc', "# version two\nmin-release-age=2\n");

        $results = $updater->run($app, 'raw', ['release', 'parity'], false, Catalog::detectLayer($app));
        $states = $this->states($results);

        self::assertSame(FileResult::UPDATED, $states['.npmrc']);
        self::assertStringContainsString('version two', (string) file_get_contents($app . '/.npmrc'));
        self::assertSame(FileResult::CURRENT, $states['build.sh'], 'the target files stay current');
        $manifest = Manifest::load($app);
        self::assertNotNull($manifest);
        self::assertSame('vue', $manifest->layer);
        self::assertSame(Manifest::hash((string) file_get_contents($templates . '/vue/.npmrc')), $manifest->files['.npmrc']);
    }
}

<?php

declare(strict_types=1);

namespace Waynestate\Squire\Tests;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Waynestate\Squire\Squire;

/**
 * bin/docs-site.php and the docs kit: the generator against a small docs tree, run the way a repo's CI runs it.
 */
final class DocsSiteTest extends SquireTestCase
{
    private static function generator(): string
    {
        return dirname(Squire::templatesDir()) . '/bin/docs-site.php';
    }

    /** @return array{code: int, stdout: string, stderr: string} */
    private function invoke(array $args): array
    {
        $process = proc_open(
            array_merge([PHP_BINARY, self::generator()], $args),
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['code' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
    }

    /** A temp copy of the docs-site fixture with the generator and kit synced in. */
    private function repo(): string
    {
        $dir = $this->tempDir();
        $this->copyTree(dirname(__DIR__) . '/tests/fixtures/docs-site', $dir);
        $result = $this->invoke(['--sync-to=' . $dir]);
        self::assertSame(0, $result['code'], $result['stderr']);

        return $dir;
    }

    private function copyTree(string $from, string $to): void
    {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($from, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (! $file instanceof SplFileInfo || ! $file->isFile()) {
                continue;
            }
            $relative = ltrim(str_replace('\\', '/', substr($file->getPathname(), strlen($from))), '/');
            $this->put($to, $relative, (string) file_get_contents($file->getPathname()));
        }
    }

    private function page(string $dir, string $path): string
    {
        self::assertFileExists($dir . '/site/docs/' . $path . '.html');

        return (string) file_get_contents($dir . '/site/docs/' . $path . '.html');
    }

    public function testBuildWritesOnePagePerDoc(): void
    {
        $dir = $this->repo();
        $result = $this->invoke(['--root=' . $dir]);
        self::assertSame(0, $result['code'], $result['stderr']);
        self::assertFileExists($dir . '/site/docs/index.html');
        self::assertFileExists($dir . '/site/docs/guides/start.html');
        self::assertFileDoesNotExist($dir . '/site/docs/_private.html');
    }

    public function testPagesHaveLandmarksAndSkipLink(): void
    {
        $dir = $this->repo();
        $this->invoke(['--root=' . $dir]);
        $html = $this->page($dir, 'index');
        self::assertStringContainsString('<html lang="en">', $html);
        self::assertStringContainsString('<a class="wsu-skip-link" href="#main">Skip to content</a>', $html);
        self::assertStringContainsString('<main id="main">', $html);
        self::assertStringContainsString('aria-label="Documentation"', $html);
        self::assertStringContainsString('aria-label="On this page"', $html);
        self::assertStringContainsString('class="docs-footer"', $html);
    }

    public function testCurrentPageIsMarkedInNav(): void
    {
        $dir = $this->repo();
        $this->invoke(['--root=' . $dir]);
        self::assertMatchesRegularExpression('/href="\.\.\/docs\/index\.html" aria-current="page"/', $this->page($dir, 'index'));
        self::assertMatchesRegularExpression('/href="\.\.\/\.\.\/docs\/guides\/start\.html" aria-current="page"/', $this->page($dir, 'guides/start'));
        self::assertDoesNotMatchRegularExpression('/href="\.\.\/docs\/index\.html" aria-current/', $this->page($dir, 'guides/start'));
    }

    public function testStampComesFromChangelogHeadNotUnreleased(): void
    {
        $dir = $this->repo();
        $this->invoke(['--root=' . $dir]);
        $html = $this->page($dir, 'index');
        self::assertStringContainsString('<meta name="docs-version" content="1.2.3">', $html);
        self::assertStringContainsString('<span data-docs-version>1.2.3</span>', $html);
        self::assertStringNotContainsString('unreleased', $html);
    }

    public function testPrevNextFollowNavOrder(): void
    {
        $dir = $this->repo();
        $this->invoke(['--root=' . $dir]);
        $index = $this->page($dir, 'index');
        self::assertStringContainsString('docs-pager__link--next', $index);
        self::assertStringContainsString('docs-pager__title">Start', $index);
        self::assertStringNotContainsString('Previous', $index);
        $start = $this->page($dir, 'guides/start');
        self::assertStringNotContainsString('docs-pager__link--next', $start);
        self::assertStringContainsString('docs-pager__title">Overview', $start);
        self::assertStringContainsString('Previous', $start);
    }

    public function testOnThisPageListsH2AndH3(): void
    {
        $dir = $this->repo();
        $this->invoke(['--root=' . $dir]);
        $html = $this->page($dir, 'guides/start');
        self::assertStringContainsString('aria-label="On this page"', $html);
        self::assertStringContainsString('<a href="#setup">Setup</a>', $html);
        self::assertStringContainsString('<a href="#install">Install</a>', $html);
    }

    public function testMarkdownLinksBecomeHtmlLinks(): void
    {
        $dir = $this->repo();
        $this->invoke(['--root=' . $dir]);
        self::assertStringContainsString('<a href="guides/start.html">the start guide</a>', $this->page($dir, 'index'));
        self::assertStringContainsString('<a href="../index.html#top-heading">the overview</a>', $this->page($dir, 'guides/start'));
    }

    public function testBrokenInternalLinkIsAFinding(): void
    {
        $dir = $this->repo();
        $this->invoke(['--root=' . $dir]);
        file_put_contents($dir . '/docs/guides/start.md', (string) file_get_contents($dir . '/docs/guides/start.md') . "\nSee [a ghost](ghost.md).\n");
        $result = $this->invoke(['--root=' . $dir, '--check']);
        self::assertSame(1, $result['code']);
        self::assertStringContainsString('ghost.md', $result['stderr']);
        self::assertStringContainsString('does not exist', $result['stderr']);
    }

    public function testExternalLinksGetNoopener(): void
    {
        $dir = $this->repo();
        $this->invoke(['--root=' . $dir]);
        self::assertStringContainsString('<a rel="noopener" href="https://example.com/">the example</a>', $this->page($dir, 'guides/start'));
    }

    public function testTablesAreWrappedInAScrollRegion(): void
    {
        $dir = $this->repo();
        $this->invoke(['--root=' . $dir]);
        self::assertStringContainsString(
            '<div class="docs-table" role="region" aria-label="Table" tabindex="0"><table>',
            $this->page($dir, 'guides/start'),
        );
    }

    public function testPrivateDocsAreSkipped(): void
    {
        $dir = $this->repo();
        $this->invoke(['--root=' . $dir]);
        self::assertFileDoesNotExist($dir . '/site/docs/_private.html');
        self::assertStringNotContainsString('_private', $this->page($dir, 'index'));
    }

    public function testOutputIsDeterministic(): void
    {
        $dir = $this->repo();
        $this->invoke(['--root=' . $dir]);
        $firstIndex = (string) file_get_contents($dir . '/site/docs/index.html');
        $firstStart = (string) file_get_contents($dir . '/site/docs/guides/start.html');
        unlink($dir . '/site/docs/index.html');
        unlink($dir . '/site/docs/guides/start.html');
        $this->invoke(['--root=' . $dir]);
        self::assertSame($firstIndex, (string) file_get_contents($dir . '/site/docs/index.html'));
        self::assertSame($firstStart, (string) file_get_contents($dir . '/site/docs/guides/start.html'));
    }

    public function testCheckPassesAfterBuild(): void
    {
        $dir = $this->repo();
        self::assertSame(0, $this->invoke(['--root=' . $dir])['code']);
        $result = $this->invoke(['--root=' . $dir, '--check']);
        self::assertSame(0, $result['code'], $result['stderr']);
    }

    public function testCheckFailsWhenASourceChanges(): void
    {
        $dir = $this->repo();
        $this->invoke(['--root=' . $dir]);
        file_put_contents($dir . '/docs/index.md', (string) file_get_contents($dir . '/docs/index.md') . "\nA new line.\n");
        $result = $this->invoke(['--root=' . $dir, '--check']);
        self::assertSame(1, $result['code']);
        self::assertStringContainsString('stale:', $result['stderr']);
        self::assertStringContainsString('out of date', $result['stderr']);
    }

    public function testCheckFailsOnALeftoverGeneratedPage(): void
    {
        $dir = $this->repo();
        $this->invoke(['--root=' . $dir]);
        $this->put($dir, 'site/docs/ghost.html', "<!DOCTYPE html>\n<!-- Generated by bin/docs-site.php from docs/ghost.md. Do not edit. -->\n");
        $result = $this->invoke(['--root=' . $dir, '--check']);
        self::assertSame(1, $result['code']);
        self::assertStringContainsString('no longer generated', $result['stderr']);
    }

    public function testCheckFailsOnKitDrift(): void
    {
        $dir = $this->repo();
        $this->invoke(['--root=' . $dir]);
        file_put_contents($dir . '/site/assets/docs.css', (string) file_get_contents($dir . '/site/assets/docs.css') . "/* tampered */\n");
        $result = $this->invoke(['--root=' . $dir, '--check']);
        self::assertSame(1, $result['code']);
        self::assertStringContainsString('differs from Squire', $result['stderr']);
    }

    public function testPageMissingFromNavIsAFinding(): void
    {
        $dir = $this->repo();
        $this->put($dir, 'docs/orphan.md', "# Orphan\n\nNot in the nav.\n");
        $result = $this->invoke(['--root=' . $dir, '--check']);
        self::assertSame(1, $result['code']);
        self::assertStringContainsString('not in nav.json', $result['stderr']);
    }

    public function testNavPathWithoutAFileIsAFinding(): void
    {
        $dir = $this->repo();
        $this->put($dir, 'docs/nav.json', "{\n  \"title\": \"Demo\",\n  \"items\": [\n    { \"title\": \"Overview\", \"path\": \"index\" },\n    { \"title\": \"Ghost\", \"path\": \"ghost\" }\n  ]\n}\n");
        $result = $this->invoke(['--root=' . $dir, '--check']);
        self::assertSame(1, $result['code']);
        self::assertStringContainsString('nav.json points at docs/ghost.md', $result['stderr']);
    }

    public function testSyncCopiesGeneratorAndKit(): void
    {
        $dir = $this->tempDir();
        $result = $this->invoke(['--sync-to=' . $dir]);
        self::assertSame(0, $result['code'], $result['stderr']);
        self::assertFileExists($dir . '/bin/docs-site.php');
        self::assertFileExists($dir . '/site/assets/docs.css');
        self::assertFileExists($dir . '/site/assets/docs.js');
    }

    public function testKitHashesMatchSquireKit(): void
    {
        $source = (string) file_get_contents(self::generator());
        $kit = dirname(Squire::templatesDir()) . '/kit/docs';
        foreach (['assets/docs.css', 'assets/docs.js'] as $relative) {
            $hash = hash('sha256', str_replace("\r\n", "\n", (string) file_get_contents($kit . '/' . basename($relative))));
            self::assertStringContainsString("'{$relative}' => '{$hash}'", $source, "Run php bin/docs-site.php --rehash after editing {$relative}.");
        }
    }

    public function testNoDashesInGeneratedPages(): void
    {
        $dir = $this->repo();
        $this->invoke(['--root=' . $dir]);
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir . '/site/docs', FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (! $file instanceof SplFileInfo || strtolower($file->getExtension()) !== 'html') {
                continue;
            }
            self::assertDoesNotMatchRegularExpression('/[\x{2013}\x{2014}]/u', (string) file_get_contents($file->getPathname()), $file->getPathname());
        }
    }
}

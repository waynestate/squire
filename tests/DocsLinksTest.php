<?php

declare(strict_types=1);

namespace Waynestate\Squire\Tests;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Waynestate\Squire\Squire;

/**
 * The docs builder's link gate: every content link in a built page must reach a built page (with a real fragment
 * when the link carries one) or a file the site serves, run the way a repo's CI runs the builder.
 */
final class DocsLinksTest extends SquireTestCase
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

    /** Adds a docs page under docs/ and a nav entry for it, so the build stays whole. */
    private function guidePage(string $dir, string $path, string $title, string $content): void
    {
        $this->put($dir, 'docs/' . $path . '.md', $content);
        /** @var array<string, mixed> $nav */
        $nav = json_decode((string) file_get_contents($dir . '/docs/nav.json'), true);
        $nav['items'][] = ['title' => $title, 'path' => $path];
        $this->put($dir, 'docs/nav.json', json_encode($nav, JSON_PRETTY_PRINT) . "\n");
    }

    public function testBrokenFragmentLinkIsAFinding(): void
    {
        $dir = $this->repo();
        $this->guidePage($dir, 'guides/a', 'A', "# A\n\nSee [the ghost](b.html#nope).\n");
        $this->guidePage($dir, 'guides/b', 'B', "# B\n\n## Real\n\nText.\n");
        $result = $this->invoke(['--root=' . $dir]);
        self::assertSame(1, $result['code']);
        self::assertStringContainsString('finding: docs/guides/a.md links to b.html#nope but no heading there has that id', $result['stderr']);
    }

    public function testFragmentLinkToAnExistingHeadingPasses(): void
    {
        $dir = $this->repo();
        $this->guidePage($dir, 'guides/a', 'A', "# A\n\nSee [the real section](b.html#real).\n");
        $this->guidePage($dir, 'guides/b', 'B', "# B\n\n## Real\n\nText.\n");
        $result = $this->invoke(['--root=' . $dir]);
        self::assertSame(0, $result['code'], $result['stderr']);
        self::assertStringNotContainsString('finding:', $result['stderr']);
    }

    public function testSameFragmentLinkToMissingIdIsAFinding(): void
    {
        $dir = $this->repo();
        $this->guidePage($dir, 'guides/a', 'A', "# A\n\n## Real\n\nJump to [the ghost](#nope).\n");
        $result = $this->invoke(['--root=' . $dir]);
        self::assertSame(1, $result['code']);
        self::assertStringContainsString('finding: docs/guides/a.md links to #nope but no heading there has that id', $result['stderr']);
    }

    public function testLinkToAFileOutsideDocsIsAFinding(): void
    {
        $dir = $this->repo();
        $this->guidePage($dir, 'guides/a', 'A', "# A\n\nSee [the readme](../../README.md) for the story.\n");
        $result = $this->invoke(['--root=' . $dir]);
        self::assertSame(1, $result['code']);
        self::assertStringContainsString('finding: docs/guides/a.md links to ../../README.md but the site does not serve that file', $result['stderr']);
    }

    public function testExternalAndMailtoLinksAreSkipped(): void
    {
        $dir = $this->repo();
        $this->guidePage($dir, 'guides/a', 'A', "# A\n\n[web](https://example.com/x), [mail](mailto:knight@example.com) and [cdn](//cdn.example.com/x.css) stay put.\n");
        $result = $this->invoke(['--root=' . $dir]);
        self::assertSame(0, $result['code'], $result['stderr']);
        self::assertStringNotContainsString('finding:', $result['stderr']);
    }

    public function testRelativeLinkToASiteFileMustExist(): void
    {
        $dir = $this->repo();
        $this->guidePage($dir, 'guides/a', 'A', "# A\n\nDownload [the notes](notes.pdf) before you start.\n");
        $result = $this->invoke(['--root=' . $dir]);
        self::assertSame(1, $result['code']);
        self::assertStringContainsString('finding: docs/guides/a.md links to notes.pdf but the site does not serve that file', $result['stderr']);
        $this->put($dir, 'site/docs/guides/notes.pdf', "%PDF-1.4 a stand-in for a real file.\n");
        $again = $this->invoke(['--root=' . $dir]);
        self::assertSame(0, $again['code'], $again['stderr']);
        self::assertStringNotContainsString('finding:', $again['stderr']);
    }
}

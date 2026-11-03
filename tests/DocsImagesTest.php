<?php

declare(strict_types=1);

namespace Waynestate\Squire\Tests;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Waynestate\Squire\Squire;

/**
 * The docs builder's image gate: every image a page references must exist under docs/, is copied byte for byte
 * into site/docs/ so the built page's relative src still resolves, and --check fails when a copy drifts, run the
 * way a repo's CI runs the builder.
 */
final class DocsImagesTest extends SquireTestCase
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

    public function testReferencedImagesAreCopied(): void
    {
        $dir = $this->repo();
        $svg = "<svg xmlns=\"http://www.w3.org/2000/svg\" width=\"8\" height=\"8\"></svg>\n";
        $png = "\x89PNG\r\n\x1a\nsquire-bytes";
        $gif = "GIF89a";
        $this->put($dir, 'docs/readme/x.svg', $svg);
        $this->put($dir, 'docs/guides/y.png', $png);
        $this->put($dir, 'docs/guides/z.gif', $gif);
        $this->guidePage($dir, 'guides/pics', 'Pics', "# Pics\n\n![The map](../readme/x.svg)\n\n![Inline](y.png)\n\n<img src=\"z.gif\" alt=\"Raw HTML\">\n");
        $result = $this->invoke(['--root=' . $dir]);
        self::assertSame(0, $result['code'], $result['stderr']);
        self::assertSame($svg, (string) file_get_contents($dir . '/site/docs/readme/x.svg'));
        self::assertSame($png, (string) file_get_contents($dir . '/site/docs/guides/y.png'));
        self::assertSame($gif, (string) file_get_contents($dir . '/site/docs/guides/z.gif'));
    }

    public function testMissingImageIsAFinding(): void
    {
        $dir = $this->repo();
        $this->guidePage($dir, 'a', 'A', "# A\n\n![ghost](../x.png)\n");
        $result = $this->invoke(['--root=' . $dir]);
        self::assertSame(1, $result['code']);
        self::assertStringContainsString('finding: docs/a.md references image ../x.png but docs/x.png does not exist', $result['stderr']);
    }

    public function testUnreferencedImagesAreNotCopied(): void
    {
        $dir = $this->repo();
        $svg = "<svg xmlns=\"http://www.w3.org/2000/svg\" width=\"8\" height=\"8\"></svg>\n";
        $this->put($dir, 'docs/readme/x.svg', $svg);
        $this->put($dir, 'docs/readme/unused.svg', $svg);
        $this->guidePage($dir, 'guides/pics', 'Pics', "# Pics\n\n![The map](../readme/x.svg)\n");
        $result = $this->invoke(['--root=' . $dir]);
        self::assertSame(0, $result['code'], $result['stderr']);
        self::assertFileExists($dir . '/site/docs/readme/x.svg');
        self::assertFileDoesNotExist($dir . '/site/docs/readme/unused.svg');

        // The page stops referencing the image; a plain build still removes nothing.
        $this->put($dir, 'docs/guides/pics.md', "# Pics\n\nNo pictures.\n");
        $result = $this->invoke(['--root=' . $dir]);
        self::assertSame(0, $result['code'], $result['stderr']);
        self::assertFileExists($dir . '/site/docs/readme/x.svg');
    }

    public function testCheckFailsWhenACopiedImageDiffers(): void
    {
        $dir = $this->repo();
        $svg = "<svg xmlns=\"http://www.w3.org/2000/svg\" width=\"8\" height=\"8\"></svg>\n";
        $this->put($dir, 'docs/readme/x.svg', $svg);
        $this->guidePage($dir, 'guides/pics', 'Pics', "# Pics\n\n![The map](../readme/x.svg)\n");
        $result = $this->invoke(['--root=' . $dir]);
        self::assertSame(0, $result['code'], $result['stderr']);

        file_put_contents($dir . '/site/docs/readme/x.svg', $svg . 'tampered');
        $result = $this->invoke(['--root=' . $dir, '--check']);
        self::assertSame(1, $result['code']);
        self::assertStringContainsString('stale: site/docs/readme/x.svg (out of date)', $result['stderr']);
    }

    public function testCheckFailsWhenACopiedImageIsMissing(): void
    {
        $dir = $this->repo();
        $svg = "<svg xmlns=\"http://www.w3.org/2000/svg\" width=\"8\" height=\"8\"></svg>\n";
        $this->put($dir, 'docs/readme/x.svg', $svg);
        $this->guidePage($dir, 'guides/pics', 'Pics', "# Pics\n\n![The map](../readme/x.svg)\n");
        $result = $this->invoke(['--root=' . $dir]);
        self::assertSame(0, $result['code'], $result['stderr']);

        unlink($dir . '/site/docs/readme/x.svg');
        $result = $this->invoke(['--root=' . $dir, '--check']);
        self::assertSame(1, $result['code']);
        self::assertStringContainsString('stale: site/docs/readme/x.svg (missing)', $result['stderr']);
    }

    public function testDataUriAndAbsoluteSrcAreSkipped(): void
    {
        $dir = $this->repo();
        $this->guidePage($dir, 'guides/pics', 'Pics', "# Pics\n\n![data](data:image/gif;base64,R0lGODlhAQABAAAAACw=)\n\n![absolute](/assets/logo.png)\n\n![web](https://example.com/x.png)\n\n<img src=\"//cdn.example.com/x.png\" alt=\"Protocol relative\">\n");
        $result = $this->invoke(['--root=' . $dir]);
        self::assertSame(0, $result['code'], $result['stderr']);
        self::assertFileDoesNotExist($dir . '/site/docs/assets/logo.png');
    }
}

<?php

declare(strict_types=1);

namespace Waynestate\Squire\Tests;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Waynestate\Squire\Squire;

/**
 * The front-page version stamp: an element in a top-level site/*.html page that carries data-docs-version holds the
 * same version the docs header shows ({{version}}, the first released CHANGELOG heading). A plain run writes it and
 * --check reports a stale one, so a release can no longer leave the front page a version behind the docs.
 */
final class DocsFrontStampTest extends SquireTestCase
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

    /** A temp copy of the docs-site fixture (CHANGELOG stamp 1.2.3) with the generator and kit synced in, built once. */
    private function repo(): string
    {
        $dir = $this->tempDir();
        $from = dirname(__DIR__) . '/tests/fixtures/docs-site';
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($from, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (! $file instanceof SplFileInfo || ! $file->isFile()) {
                continue;
            }
            $relative = ltrim(str_replace('\\', '/', substr($file->getPathname(), strlen($from))), '/');
            $this->put($dir, $relative, (string) file_get_contents($file->getPathname()));
        }
        $result = $this->invoke(['--sync-to=' . $dir]);
        self::assertSame(0, $result['code'], $result['stderr']);
        $result = $this->invoke(['--root=' . $dir]);
        self::assertSame(0, $result['code'], $result['stderr']);

        return $dir;
    }

    private const FRONT = "<!doctype html>\n<html lang=\"en\">\n<head><title>Demo</title></head>\n<body>\n"
        . "<header>\n  <span class=\"brand__version\" data-docs-version>0.9</span>\n"
        . "  <p class=\"meta\"><span class=\"label\">Rev</span> <span data-docs-version=\"\" title=\"Version\">0.9.1</span></p>\n"
        . "</header>\n<main id=\"main\"><p>Version 0.9 is the old one. <code>data-docs-version</code> in prose stays.</p></main>\n"
        . "</body>\n</html>\n";

    private const FRONT_STAMPED = "<!doctype html>\n<html lang=\"en\">\n<head><title>Demo</title></head>\n<body>\n"
        . "<header>\n  <span class=\"brand__version\" data-docs-version>1.2.3</span>\n"
        . "  <p class=\"meta\"><span class=\"label\">Rev</span> <span data-docs-version=\"\" title=\"Version\">1.2.3</span></p>\n"
        . "</header>\n<main id=\"main\"><p>Version 0.9 is the old one. <code>data-docs-version</code> in prose stays.</p></main>\n"
        . "</body>\n</html>\n";

    public function testAPlainRunStampsTheFrontPageWithTheDocsVersion(): void
    {
        $dir = $this->repo();
        $this->put($dir, 'site/index.html', self::FRONT);
        $result = $this->invoke(['--root=' . $dir]);
        self::assertSame(0, $result['code'], $result['stderr']);
        self::assertStringContainsString('wrote site/index.html', $result['stdout']);
        self::assertSame(self::FRONT_STAMPED, (string) file_get_contents($dir . '/site/index.html'));
        // A second run has nothing to write.
        $again = $this->invoke(['--root=' . $dir]);
        self::assertSame(0, $again['code'], $again['stderr']);
        self::assertStringNotContainsString('wrote site/index.html', $again['stdout']);
    }

    public function testCheckReportsAStaleFrontPageAndWritesNothing(): void
    {
        $dir = $this->repo();
        $this->put($dir, 'site/index.html', self::FRONT);
        $result = $this->invoke(['--root=' . $dir, '--check']);
        self::assertSame(1, $result['code']);
        self::assertStringContainsString('stale: site/index.html (out of date)', $result['stderr']);
        self::assertSame(self::FRONT, (string) file_get_contents($dir . '/site/index.html'));
        $this->put($dir, 'site/index.html', self::FRONT_STAMPED);
        $result = $this->invoke(['--root=' . $dir, '--check']);
        self::assertSame(0, $result['code'], $result['stderr']);
        self::assertStringNotContainsString('site/index.html', $result['stderr']);
    }

    public function testEveryTopLevelPageIsStampedButNothingDeeper(): void
    {
        $dir = $this->repo();
        $this->put($dir, 'site/proof.html', self::FRONT);
        $this->put($dir, 'site/tools/inner.html', self::FRONT);
        $result = $this->invoke(['--root=' . $dir]);
        self::assertSame(0, $result['code'], $result['stderr']);
        self::assertSame(self::FRONT_STAMPED, (string) file_get_contents($dir . '/site/proof.html'));
        self::assertSame(self::FRONT, (string) file_get_contents($dir . '/site/tools/inner.html'));
    }

    public function testAPageWithoutTheAttributeIsNeverRewritten(): void
    {
        $dir = $this->repo();
        $plain = "<!doctype html>\r\n<html lang=\"en\"><body><span class=\"brand__version\">0.9</span></body></html>\r\n";
        $this->put($dir, 'site/index.html', $plain);
        $result = $this->invoke(['--root=' . $dir]);
        self::assertSame(0, $result['code'], $result['stderr']);
        self::assertStringNotContainsString('site/index.html', $result['stdout']);
        self::assertSame($plain, (string) file_get_contents($dir . '/site/index.html'));
        $result = $this->invoke(['--root=' . $dir, '--check']);
        self::assertSame(0, $result['code'], $result['stderr']);
    }

    public function testTheStampFollowsTheChangelog(): void
    {
        $dir = $this->repo();
        $this->put($dir, 'site/index.html', self::FRONT_STAMPED);
        $changelog = (string) file_get_contents($dir . '/CHANGELOG.md');
        $this->put($dir, 'CHANGELOG.md', str_replace("## Unreleased\n\nNothing yet.\n", "## Unreleased\n\nNothing yet.\n\n## [1.3.0] - 2026-02-01\n\nA newer release.\n", $changelog));
        $result = $this->invoke(['--root=' . $dir, '--check']);
        self::assertSame(1, $result['code']);
        self::assertStringContainsString('stale: site/index.html (out of date)', $result['stderr']);
        $result = $this->invoke(['--root=' . $dir]);
        self::assertSame(0, $result['code'], $result['stderr']);
        $html = (string) file_get_contents($dir . '/site/index.html');
        self::assertStringContainsString('<span class="brand__version" data-docs-version>1.3.0</span>', $html);
        self::assertStringContainsString('<span data-docs-version="" title="Version">1.3.0</span>', $html);
    }

    public function testAnElementWithMarkupInsideIsAFinding(): void
    {
        $dir = $this->repo();
        $html = "<!doctype html>\n<html lang=\"en\"><body><span data-docs-version><b>0.9</b></span></body></html>\n";
        $this->put($dir, 'site/index.html', $html);
        $result = $this->invoke(['--root=' . $dir, '--check']);
        self::assertSame(1, $result['code']);
        self::assertStringContainsString('finding: site/index.html: a data-docs-version element holds markup; it must hold only the version text', $result['stderr']);
        self::assertSame($html, (string) file_get_contents($dir . '/site/index.html'));
    }
}

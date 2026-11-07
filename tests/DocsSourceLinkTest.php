<?php

declare(strict_types=1);

namespace Waynestate\Squire\Tests;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Waynestate\Squire\Squire;

/**
 * The optional "source" and "mirrors" keys in docs/nav.json: the Source link in the header and footer, the
 * Mirrors list beside it, and the finding that keeps every repo URL an https value instead of a hard-coded one.
 */
final class DocsSourceLinkTest extends SquireTestCase
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

    /** Overwrite docs/nav.json with the fixture's nav plus the given top-level keys. */
    private function navWith(string $dir, array $extra): void
    {
        $nav = json_decode((string) file_get_contents($dir . '/docs/nav.json'), true);
        foreach ($extra as $key => $value) {
            $nav[$key] = $value;
        }
        $this->put($dir, 'docs/nav.json', json_encode($nav, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    }

    public function testSourceLinkRendersFromNavJson(): void
    {
        $dir = $this->repo();
        $this->navWith($dir, ['source' => 'https://example.com/canonical']);
        $result = $this->invoke(['--root=' . $dir]);
        self::assertSame(0, $result['code'], $result['stderr']);
        $html = $this->page($dir, 'index');
        self::assertStringContainsString('<a class="docs-header__home" rel="noopener" href="https://example.com/canonical">Source</a>', $html);
        self::assertStringContainsString('<a rel="noopener" href="https://example.com/canonical">Source</a>', $html);
        $start = $this->page($dir, 'guides/start');
        self::assertStringContainsString('<a class="docs-header__home" rel="noopener" href="https://example.com/canonical">Source</a>', $start);
    }

    public function testMirrorsRenderInTheFooter(): void
    {
        $dir = $this->repo();
        $this->navWith($dir, [
            'source' => 'https://example.com/canonical',
            'mirrors' => [
                ['title' => 'GitHub', 'url' => 'https://example.com/mirror'],
                ['title' => 'GitLab', 'url' => 'https://example.com/other-mirror'],
            ],
        ]);
        $result = $this->invoke(['--root=' . $dir]);
        self::assertSame(0, $result['code'], $result['stderr']);
        $html = $this->page($dir, 'index');
        $footer = substr($html, (int) strrpos($html, '<footer class="docs-footer">'));
        self::assertStringContainsString('Mirrors:', $footer);
        self::assertStringContainsString('<a rel="noopener" href="https://example.com/mirror">GitHub</a>', $footer);
        self::assertStringContainsString('<a rel="noopener" href="https://example.com/other-mirror">GitLab</a>', $footer);
        $header = substr($html, 0, (int) strpos($html, '</header>'));
        self::assertStringNotContainsString('Mirrors', $header);
    }

    public function testNoSourceKeysRenderNoLink(): void
    {
        $dir = $this->repo();
        $result = $this->invoke(['--root=' . $dir]);
        self::assertSame(0, $result['code'], $result['stderr']);
        $html = $this->page($dir, 'index');
        self::assertStringNotContainsString('Source', $html);
        self::assertStringNotContainsString('Mirrors', $html);
    }

    public function testNonHttpsSourceIsAFinding(): void
    {
        $dir = $this->repo();
        $this->navWith($dir, ['source' => 'http://example.com/canonical']);
        $result = $this->invoke(['--root=' . $dir, '--check']);
        self::assertSame(1, $result['code']);
        self::assertStringContainsString('finding: nav.json source must be an https URL', $result['stderr']);
        $this->navWith($dir, ['mirrors' => [['title' => 'GitHub', 'url' => 'http://example.com/mirror']]]);
        $result = $this->invoke(['--root=' . $dir, '--check']);
        self::assertSame(1, $result['code']);
        self::assertStringContainsString('finding: nav.json source must be an https URL', $result['stderr']);
    }

    public function testNoRepoUrlIsHardCodedInTheBuilder(): void
    {
        // git.wayne.edu is a public address since the canonical home moved there (ADR-0021), so the builder may
        // be pointed at it through nav.json; what stays forbidden is a hard-coded host, whatever the instance.
        $source = (string) file_get_contents(self::generator());
        foreach (['github.com', 'nougat', 'snu.gs'] as $host) {
            self::assertStringNotContainsString($host, $source, "bin/docs-site.php must not name {$host}.");
        }
    }
}

<?php

declare(strict_types=1);

namespace Waynestate\Squire\Tests;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Waynestate\Squire\Squire;

/**
 * The docs builder's GitHub task lists: each checkbox sits in a label with its item's own text, so it has an
 * accessible name (axe label rule) and a nested list or a second paragraph stays outside the label.
 */
final class DocsTaskListTest extends SquireTestCase
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

    /** Builds the repo, failing on a non-zero exit or a finding, and returns one built page. */
    private function page(string $dir, string $path): string
    {
        $result = $this->invoke(['--root=' . $dir]);
        self::assertSame(0, $result['code'], $result['stderr']);
        self::assertStringNotContainsString('finding:', $result['stderr']);

        return (string) file_get_contents($dir . '/site/docs/' . $path . '.html');
    }

    public function testTaskCheckboxIsLabelledByItsItem(): void
    {
        $dir = $this->repo();
        $this->guidePage($dir, 'guides/tasks', 'Tasks', "# Tasks\n\n- [ ] **Protect** the `main` branch\n- [x] Done already\n- Plain item\n");
        $html = $this->page($dir, 'guides/tasks');
        self::assertStringContainsString('<li><label class="docs-task"><input disabled="" type="checkbox"> <strong>Protect</strong> the <code>main</code> branch</label></li>', $html);
        self::assertStringContainsString('<li><label class="docs-task"><input checked="" disabled="" type="checkbox"> Done already</label></li>', $html);
        self::assertStringContainsString('<li>Plain item</li>', $html);
    }

    public function testNestedAndLooseTaskItemsLabelOnlyTheirOwnText(): void
    {
        $dir = $this->repo();
        $this->guidePage($dir, 'guides/tasks', 'Tasks', "# Tasks\n\n- [ ] Parent\n  - [x] Child\n\n- [ ] Loose\n\n  More text.\n");
        $html = $this->page($dir, 'guides/tasks');
        self::assertMatchesRegularExpression('#<label class="docs-task"><input disabled="" type="checkbox"> Parent</label></p>\s*<ul>#', $html);
        self::assertStringContainsString('<label class="docs-task"><input checked="" disabled="" type="checkbox"> Child</label>', $html);
        self::assertStringContainsString('<label class="docs-task"><input disabled="" type="checkbox"> Loose</label></p>', $html);
        self::assertSame(substr_count($html, 'type="checkbox"'), substr_count($html, '<label class="docs-task">'));
    }
}

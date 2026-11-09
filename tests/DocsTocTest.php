<?php

declare(strict_types=1);

namespace Waynestate\Squire\Tests;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Waynestate\Squire\Squire;

/**
 * The docs builder's "On this page" list: every entry carries docs-toc__item, and an h3 entry adds the --sub modifier,
 * so the kit's item rules and a theme's reach sub-items too.
 */
final class DocsTocTest extends SquireTestCase
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

    public function testSubItemsCarryTheBaseClassAndTheModifier(): void
    {
        $dir = $this->repo();
        $this->guidePage($dir, 'guides/toc', 'Toc', "# Toc\n\n## First\n\nText.\n\n### Deeper\n\nText.\n\n## Second\n\nText.\n");
        $html = $this->page($dir, 'guides/toc');
        self::assertStringContainsString('<li class="docs-toc__item"><a href="#first">First</a></li>', $html);
        self::assertStringContainsString('<li class="docs-toc__item docs-toc__item--sub"><a href="#deeper">Deeper</a></li>', $html);
        self::assertStringContainsString('<li class="docs-toc__item"><a href="#second">Second</a></li>', $html);
        self::assertStringNotContainsString('<li class="docs-toc__item--sub">', $html);
    }

    /** The "#" permalink shares a span with the heading's last word, so the kit can keep the pair on one line. */
    public function testPermalinkRidesWithTheLastWord(): void
    {
        $dir = $this->repo();
        $this->guidePage(
            $dir,
            'guides/tail',
            'Tail',
            "# Tail\n\n## Two words here\n\nText.\n\n## Run `composer ci`\n\nText.\n\n## Ends in *some emphasis*\n\nText.\n\n## Raw <sup>new</sup>\n\nText.\n",
        );
        $html = $this->page($dir, 'guides/tail');
        $link = static fn(string $id): string => '<a class="docs-heading-link" aria-label="Link to this section" href="#' . $id . '">#</a>';

        self::assertStringContainsString('<h1 id="tail"><span class="docs-heading-tail"><span>Tail</span>' . $link('tail') . '</span></h1>', $html);
        self::assertStringContainsString('<h2 id="two-words-here">Two words <span class="docs-heading-tail"><span>here</span>' . $link('two-words-here') . '</span></h2>', $html);
        self::assertStringContainsString('<h2 id="run-composer-ci">Run <span class="docs-heading-tail"><code>composer ci</code>' . $link('run-composer-ci') . '</span></h2>', $html);
        self::assertStringContainsString('<h2 id="ends-in-some-emphasis">Ends in <span class="docs-heading-tail"><em>some emphasis</em>' . $link('ends-in-some-emphasis') . '</span></h2>', $html);
        // A heading that ends in raw HTML keeps the plain append: a span there could straddle a closing tag.
        self::assertStringContainsString('<sup>new</sup><a class="docs-heading-link"', $html);
        // The "On this page" text stays the heading's own words.
        self::assertStringContainsString('<a href="#two-words-here">Two words here</a>', $html);
    }
}

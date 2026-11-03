<?php

declare(strict_types=1);

namespace Waynestate\Squire\Tests;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Waynestate\Squire\Squire;

/**
 * The docs builder's GitHub alert callouts: a blockquote whose first paragraph opens with a marker line alone
 * ([!NOTE], [!TIP], [!WARNING] or [!DANGER], case-insensitive) becomes an aside with a visible label; every other
 * blockquote stays as written.
 */
final class DocsCalloutsTest extends SquireTestCase
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

    public function testNoteCalloutRendersAsideWithLabel(): void
    {
        $dir = $this->repo();
        $this->guidePage($dir, 'guides/callouts', 'Callouts', "# Callouts\n\n> [!NOTE]\n> This is a note.\n");
        $html = $this->page($dir, 'guides/callouts');
        self::assertStringContainsString('<aside class="docs-callout docs-callout--note" role="note">', $html);
        self::assertStringContainsString('<p class="docs-callout__label">Note</p>', $html);
        self::assertStringContainsString('<p>This is a note.</p>', $html);
        self::assertStringNotContainsString('<blockquote', $html);
    }

    public function testAllFourKindsGetTheirClass(): void
    {
        $dir = $this->repo();
        $content = "# Kinds\n\n"
            . "> [!NOTE]\n> A note.\n\n"
            . "> [!TIP]\n> A tip.\n\n"
            . "> [!WARNING]\n> A warning.\n\n"
            . "> [!DANGER]\n> A danger.\n\n"
            . "> [!tIp]\n> Mixed case counts.\n";
        $this->guidePage($dir, 'guides/kinds', 'Kinds', $content);
        $html = $this->page($dir, 'guides/kinds');
        foreach (['note' => 'Note', 'tip' => 'Tip', 'warning' => 'Warning', 'danger' => 'Danger'] as $kind => $label) {
            self::assertStringContainsString('<aside class="docs-callout docs-callout--' . $kind . '" role="note">', $html);
            self::assertStringContainsString('<p class="docs-callout__label">' . $label . '</p>', $html);
        }
        self::assertStringContainsString('<p>Mixed case counts.</p>', $html);
    }

    public function testPlainBlockquoteIsUntouched(): void
    {
        $dir = $this->repo();
        $this->guidePage($dir, 'guides/plain', 'Plain', "# Plain\n\n> Just a quotation.\n>\n> From someone.\n");
        $html = $this->page($dir, 'guides/plain');
        self::assertStringContainsString('<blockquote>', $html);
        self::assertStringContainsString('<p>Just a quotation.</p>', $html);
        self::assertStringNotContainsString('docs-callout', $html);
    }

    public function testMarkerIsRemovedFromTheBody(): void
    {
        $dir = $this->repo();
        $content = "# Marker\n\n"
            . "> [!NOTE]\n> The body keeps the note.\n\n"
            . "> [!TIP]\n>\n> The body starts below the marker.\n";
        $this->guidePage($dir, 'guides/marker', 'Marker', $content);
        $html = $this->page($dir, 'guides/marker');
        self::assertStringNotContainsString('[!NOTE]', $html);
        self::assertStringNotContainsString('[!TIP]', $html);
        self::assertStringContainsString('<p>The body keeps the note.</p>', $html);
        self::assertStringContainsString('<p>The body starts below the marker.</p>', $html);
        self::assertStringNotContainsString('<p></p>', $html);
    }

    public function testCalloutKeepsInlineMarkdown(): void
    {
        $dir = $this->repo();
        $content = "# Inline\n\n"
            . "> [!WARNING]\n> Read the **bold** words, the `code` span and [the overview](../index.html).\n";
        $this->guidePage($dir, 'guides/inline', 'Inline', $content);
        $html = $this->page($dir, 'guides/inline');
        self::assertStringContainsString('<strong>bold</strong>', $html);
        self::assertStringContainsString('<code>code</code>', $html);
        self::assertStringContainsString('<a href="../index.html">the overview</a>', $html);
    }

    public function testUnknownMarkerStaysABlockquote(): void
    {
        $dir = $this->repo();
        $content = "# Unknown\n\n"
            . "> [!INFO]\n> Not a real kind.\n\n"
            . "> [!NOTE extra]\n> Not a marker line.\n";
        $this->guidePage($dir, 'guides/unknown', 'Unknown', $content);
        $html = $this->page($dir, 'guides/unknown');
        self::assertStringContainsString('<blockquote>', $html);
        self::assertStringContainsString('[!INFO]', $html);
        self::assertStringContainsString('[!NOTE extra]', $html);
        self::assertStringNotContainsString('docs-callout', $html);
    }
}

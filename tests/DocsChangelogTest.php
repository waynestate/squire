<?php

declare(strict_types=1);

namespace Waynestate\Squire\Tests;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Waynestate\Squire\Squire;

/**
 * The docs builder's changelog page: when docs/nav.json lists a page whose path is "changelog", the builder owns
 * docs/changelog.md, mirrored from CHANGELOG.md, and checks it like any other owned file.
 */
final class DocsChangelogTest extends SquireTestCase
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

    /** Points the nav at the generated changelog page and sets the repo's CHANGELOG.md. */
    private function withChangelog(string $dir, string $changelog): void
    {
        $this->put($dir, 'CHANGELOG.md', $changelog);
        /** @var array<string, mixed> $nav */
        $nav = json_decode((string) file_get_contents($dir . '/docs/nav.json'), true);
        $nav['items'][] = ['title' => 'Changelog', 'path' => 'changelog'];
        $this->put($dir, 'docs/nav.json', json_encode($nav, JSON_PRETTY_PRINT) . "\n");
    }

    public function testChangelogPageIsGeneratedFromChangelogMd(): void
    {
        $dir = $this->repo();
        $this->withChangelog($dir, "# Changelog\n\nAll notable changes to the demo are recorded here.\n\n## Unreleased\n\n- Added: a second feature.\n\n## [1.0.0] - 2026-01-01\n\nFirst release, with `composer install`.\n");
        $result = $this->invoke(['--root=' . $dir]);
        self::assertSame(0, $result['code'], $result['stderr']);
        self::assertStringContainsString('wrote docs/changelog.md', $result['stdout']);
        self::assertSame(
            "---\n"
            . "title: \"Changelog\"\n"
            . "description: All notable changes to this project.\n"
            . "---\n"
            . "\n"
            . "<!-- Generated from CHANGELOG.md by bin/docs-site.php. Edit CHANGELOG.md, not this file. -->\n"
            . "\n"
            . "# Changelog\n"
            . "\n"
            . "All notable changes to the demo are recorded here.\n"
            . "\n"
            . "## Unreleased\n"
            . "\n"
            . "- Added: a second feature.\n"
            . "\n"
            . "## [1.0.0] - 2026-01-01\n"
            . "\n"
            . "First release, with `composer install`.\n",
            (string) file_get_contents($dir . '/docs/changelog.md'),
        );
    }

    public function testChangelogPageHasOneH1(): void
    {
        $dir = $this->repo();
        $this->withChangelog($dir, "# Version history\n\n## Unreleased\n\n- One.\n");
        $result = $this->invoke(['--root=' . $dir]);
        self::assertSame(0, $result['code'], $result['stderr']);
        $html = (string) file_get_contents($dir . '/site/docs/changelog.html');
        self::assertSame(1, substr_count($html, '<h1'));
        self::assertStringContainsString('<h1 id="changelog"><span class="docs-heading-tail"><span>Changelog</span><a ', $html);
    }

    public function testGeneratedPageIsBuiltIntoTheSite(): void
    {
        $dir = $this->repo();
        $this->withChangelog($dir, "# Changelog\n\n## Unreleased\n\nNothing yet.\n\n## 0.5.0 (2026-11-03)\n\n- First tagged release.\n");
        $result = $this->invoke(['--root=' . $dir]);
        self::assertSame(0, $result['code'], $result['stderr']);
        self::assertFileExists($dir . '/site/docs/changelog.html');
        $html = (string) file_get_contents($dir . '/site/docs/changelog.html');
        self::assertStringContainsString('<h2 id="unreleased">', $html);
        self::assertStringContainsString('<h2 id="0-5-0-2026-11-03">', $html);
        self::assertStringContainsString('docs-version', $html);
    }

    public function testCheckFailsWhenChangelogMdChanges(): void
    {
        $dir = $this->repo();
        $this->withChangelog($dir, "# Changelog\n\n## Unreleased\n\n- One.\n");
        self::assertSame(0, $this->invoke(['--root=' . $dir])['code']);
        $this->put($dir, 'CHANGELOG.md', "# Changelog\n\n## Unreleased\n\n- One.\n- Two.\n");
        $result = $this->invoke(['--root=' . $dir, '--check']);
        self::assertSame(1, $result['code']);
        self::assertStringContainsString('stale: docs/changelog.md (out of date)', $result['stderr']);
    }

    public function testCheckFailsWhenGeneratedPageIsMissing(): void
    {
        $dir = $this->repo();
        $this->withChangelog($dir, "# Changelog\n\n## Unreleased\n\n- One.\n");
        self::assertSame(0, $this->invoke(['--root=' . $dir])['code']);
        unlink($dir . '/docs/changelog.md');
        $result = $this->invoke(['--root=' . $dir, '--check']);
        self::assertSame(1, $result['code']);
        self::assertStringContainsString('stale: docs/changelog.md (missing)', $result['stderr']);
    }

    public function testPlainRunRewritesStalePage(): void
    {
        $dir = $this->repo();
        $this->withChangelog($dir, "# Changelog\n\n## Unreleased\n\n- One.\n");
        self::assertSame(0, $this->invoke(['--root=' . $dir])['code']);
        $this->put($dir, 'CHANGELOG.md', "# Changelog\n\n## Unreleased\n\n- One.\n- Two.\n");
        $result = $this->invoke(['--root=' . $dir]);
        self::assertSame(0, $result['code'], $result['stderr']);
        self::assertStringContainsString('wrote docs/changelog.md', $result['stdout']);
        self::assertStringContainsString('- Two.', (string) file_get_contents($dir . '/docs/changelog.md'));
        self::assertStringContainsString('Two.', (string) file_get_contents($dir . '/site/docs/changelog.html'));
    }

    public function testNoChangelogInNavMeansNoFile(): void
    {
        $dir = $this->repo();
        $result = $this->invoke(['--root=' . $dir]);
        self::assertSame(0, $result['code'], $result['stderr']);
        self::assertFileDoesNotExist($dir . '/docs/changelog.md');
        self::assertStringNotContainsString('wrote docs/changelog.md', $result['stdout']);
        self::assertStringNotContainsString('finding:', $result['stderr']);
    }

    public function testNavEntryWithoutChangelogMdIsAFinding(): void
    {
        $dir = $this->repo();
        /** @var array<string, mixed> $nav */
        $nav = json_decode((string) file_get_contents($dir . '/docs/nav.json'), true);
        $nav['items'][] = ['title' => 'Changelog', 'path' => 'changelog'];
        $this->put($dir, 'docs/nav.json', json_encode($nav, JSON_PRETTY_PRINT) . "\n");
        unlink($dir . '/CHANGELOG.md');
        $plain = $this->invoke(['--root=' . $dir]);
        self::assertSame(1, $plain['code']);
        self::assertStringContainsString('finding: nav.json lists changelog but CHANGELOG.md does not exist', $plain['stderr']);
        $check = $this->invoke(['--root=' . $dir, '--check']);
        self::assertSame(1, $check['code']);
        self::assertStringContainsString('finding: nav.json lists changelog but CHANGELOG.md does not exist', $check['stderr']);
        self::assertFileDoesNotExist($dir . '/docs/changelog.md');
    }

    public function testRelativeLinksAreRewrittenOrDropped(): void
    {
        $changelog = "# Changelog\n\n## Unreleased\n\n"
            . "- See [the readme](README.md) and [the guide](guides/start.md#setup).\n"
            . "- External [news](https://example.com/news) stays.\n";

        // Without a source: relative links lose their link and keep their label.
        $dir = $this->repo();
        $this->withChangelog($dir, $changelog);
        $result = $this->invoke(['--root=' . $dir]);
        self::assertSame(0, $result['code'], $result['stderr']);
        $page = (string) file_get_contents($dir . '/docs/changelog.md');
        self::assertStringContainsString('See the readme and the guide.', $page);
        self::assertStringContainsString('[news](https://example.com/news)', $page);
        self::assertStringNotContainsString('](README.md)', $page);
        self::assertStringNotContainsString('](guides/start.md#setup)', $page);

        // With a source: relative links point at the repo's blob URLs.
        $dir = $this->repo();
        $this->withChangelog($dir, $changelog);
        /** @var array<string, mixed> $nav */
        $nav = json_decode((string) file_get_contents($dir . '/docs/nav.json'), true);
        $nav['source'] = 'https://git.example.com/acme/demo';
        $this->put($dir, 'docs/nav.json', json_encode($nav, JSON_PRETTY_PRINT) . "\n");
        $result = $this->invoke(['--root=' . $dir]);
        self::assertSame(0, $result['code'], $result['stderr']);
        $page = (string) file_get_contents($dir . '/docs/changelog.md');
        self::assertStringContainsString('[the readme](https://git.example.com/acme/demo/-/blob/main/README.md)', $page);
        self::assertStringContainsString('[the guide](https://git.example.com/acme/demo/-/blob/main/guides/start.md#setup)', $page);
    }

    public function testGithubSourceUsesGithubBlobPath(): void
    {
        $changelog = "# Changelog\n\n## Unreleased\n\n- See [the readme](README.md).\n";
        $dir = $this->repo();
        $this->withChangelog($dir, $changelog);
        /** @var array<string, mixed> $nav */
        $nav = json_decode((string) file_get_contents($dir . '/docs/nav.json'), true);
        $nav['source'] = 'https://github.com/acme/demo';
        $this->put($dir, 'docs/nav.json', json_encode($nav, JSON_PRETTY_PRINT) . "\n");
        $result = $this->invoke(['--root=' . $dir]);
        self::assertSame(0, $result['code'], $result['stderr']);
        $page = (string) file_get_contents($dir . '/docs/changelog.md');
        self::assertStringContainsString('[the readme](https://github.com/acme/demo/blob/main/README.md)', $page);
        self::assertStringNotContainsString('/-/blob/', $page);
    }

    public function testCodeSpansAndFencesAreNotRewritten(): void
    {
        $changelog = "# Changelog\n\n## Unreleased\n\n"
            . "- An example: `[x](docs/a.md)` stays as written.\n"
            . "\n"
            . "```\n[y](docs/b.md)\n```\n"
            . "\n"
            . "- Plain [z](docs/c.md) moves.\n";
        $dir = $this->repo();
        $this->withChangelog($dir, $changelog);
        /** @var array<string, mixed> $nav */
        $nav = json_decode((string) file_get_contents($dir . '/docs/nav.json'), true);
        $nav['source'] = 'https://git.example.com/acme/demo';
        $this->put($dir, 'docs/nav.json', json_encode($nav, JSON_PRETTY_PRINT) . "\n");
        $result = $this->invoke(['--root=' . $dir]);
        self::assertSame(0, $result['code'], $result['stderr']);
        $page = (string) file_get_contents($dir . '/docs/changelog.md');
        self::assertStringContainsString('`[x](docs/a.md)`', $page);
        self::assertStringContainsString("```\n[y](docs/b.md)\n```\n", $page);
        self::assertStringContainsString('[z](https://git.example.com/acme/demo/-/blob/main/docs/c.md)', $page);
        self::assertStringNotContainsString('/blob/main/docs/a.md', $page);
        self::assertStringNotContainsString('/blob/main/docs/b.md', $page);
    }

    public function testGenerationIsByteStable(): void
    {
        $dir = $this->repo();
        $this->withChangelog($dir, "# Changelog\n\n## Unreleased\n\n- One.\n\n## [1.0.0] - 2026-01-01\n\n- First.\n");
        $first = $this->invoke(['--root=' . $dir]);
        self::assertSame(0, $first['code'], $first['stderr']);
        $page = (string) file_get_contents($dir . '/docs/changelog.md');
        $second = $this->invoke(['--root=' . $dir]);
        self::assertSame(0, $second['code'], $second['stderr']);
        self::assertStringNotContainsString('wrote docs/changelog.md', $second['stdout']);
        self::assertSame($page, (string) file_get_contents($dir . '/docs/changelog.md'));
        $check = $this->invoke(['--root=' . $dir, '--check']);
        self::assertSame(0, $check['code'], $check['stderr']);
    }

    public function testBothVersionHeadingStylesAreKept(): void
    {
        $dir = $this->repo();
        $this->withChangelog($dir, "# Changelog\n\n## Unreleased\n\n- Pending.\n\n## [Unreleased]\n\n- Also pending.\n\n## 0.5.0 (2026-11-03)\n\n- Tagged.\n\n## [0.1.9] - 2026-11-03\n\n- Older.\n");
        $result = $this->invoke(['--root=' . $dir]);
        self::assertSame(0, $result['code'], $result['stderr']);
        $page = (string) file_get_contents($dir . '/docs/changelog.md');
        foreach (['## Unreleased', '## [Unreleased]', '## 0.5.0 (2026-11-03)', '## [0.1.9] - 2026-11-03'] as $heading) {
            self::assertStringContainsString($heading . "\n", $page);
        }
        $html = (string) file_get_contents($dir . '/site/docs/changelog.html');
        self::assertStringContainsString('<h2 id="unreleased">', $html);
        self::assertStringContainsString('<h2 id="unreleased-1">', $html);
        self::assertStringContainsString('<h2 id="0-5-0-2026-11-03">', $html);
        self::assertStringContainsString('<h2 id="0-1-9-2026-11-03">', $html);
    }
}

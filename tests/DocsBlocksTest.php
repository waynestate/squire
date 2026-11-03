<?php

declare(strict_types=1);

namespace Waynestate\Squire\Tests;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Waynestate\Squire\Squire;

/**
 * The docs builder's generated blocks: the marker plumbing, docs/docs.json and the json-table type, run the way a
 * repo's CI runs the builder.
 */
final class DocsBlocksTest extends SquireTestCase
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

    /** The fake CLI: php bin/demo prints bin/demo-data.json, or fails when it is missing. */
    private function cli(string $dir): void
    {
        $this->put($dir, 'bin/demo', "#!/usr/bin/env php\n<?php\n\n\$file = __DIR__.'/demo-data.json';\nif (! is_file(\$file)) {\n    fwrite(STDERR, \"no data\\n\");\n    exit(3);\n}\necho (string) file_get_contents(\$file);\n");
    }

    /** The rows the fake CLI prints, under the "hops" key. */
    private function data(string $dir, array $rows): void
    {
        $this->put($dir, 'bin/demo-data.json', json_encode(['hops' => $rows], JSON_PRETTY_PRINT) . "\n");
    }

    /** Writes docs/docs.json with the given blocks, by name. */
    private function docsJson(string $dir, array $blocks): void
    {
        $this->put($dir, 'docs/docs.json', json_encode(['blocks' => $blocks], JSON_PRETTY_PRINT) . "\n");
    }

    /** A json-table block that reads the fake CLI's "hops" list. */
    private function jsonTable(array $columns): array
    {
        return ['type' => 'json-table', 'run' => 'php bin/demo', 'path' => 'hops', 'columns' => $columns];
    }

    /** A reference page holding one generated block region, added to the nav. */
    private function blockPage(string $dir, string $name = 'hops', string $content = 'stale content.'): void
    {
        $this->put($dir, 'docs/reference/commands.md', "# Commands\n\n<!-- generated:{$name} -->\n{$content}\n<!-- /generated -->\n");
        $this->navItem($dir, 'reference/commands', 'Commands');
    }

    /** Adds a page to the fixture's nav.json. */
    private function navItem(string $dir, string $path, string $title): void
    {
        /** @var array<string, mixed> $nav */
        $nav = json_decode((string) file_get_contents($dir . '/docs/nav.json'), true);
        $nav['items'][] = ['title' => $title, 'path' => $path];
        $this->put($dir, 'docs/nav.json', json_encode($nav, JSON_PRETTY_PRINT) . "\n");
    }

    public function testJsonTableRendersColumnsAndCodeCells(): void
    {
        $dir = $this->repo();
        $this->cli($dir);
        $this->data($dir, [
            ['id' => 'login', 'code' => 'booster login', 'summary' => 'Sign in', 'live' => true, 'tags' => ['cas', 'saml']],
            ['id' => 'hops', 'code' => 'booster hops', 'summary' => 'Show the hops', 'live' => false],
            ['id' => 'sync', 'summary' => 'Sync data'],
        ]);
        $this->blockPage($dir);
        $this->docsJson($dir, ['hops' => $this->jsonTable([
            ['Hop', 'id'],
            ['Command', 'code', 'code'],
            ['What it does', 'summary'],
            ['Live', 'live'],
            ['Tags', 'tags'],
        ])]);
        $result = $this->invoke(['--root=' . $dir]);
        self::assertSame(0, $result['code'], $result['stderr']);
        $source = (string) file_get_contents($dir . '/docs/reference/commands.md');
        $expected = "| Hop | Command | What it does | Live | Tags |\n"
            . '| --- | --- | --- | --- | --- |' . "\n"
            . '| login | `booster login` | Sign in | yes | cas, saml |' . "\n"
            . '| hops | `booster hops` | Show the hops | no |  |' . "\n"
            . '| sync |  | Sync data |  |  |';
        self::assertStringContainsString($expected, $source);
        $html = $this->page($dir, 'reference/commands');
        self::assertStringContainsString('<table>', $html);
        self::assertStringContainsString('booster login', $html);
    }

    public function testPipesInCellsAreEscaped(): void
    {
        $dir = $this->repo();
        $this->cli($dir);
        $this->data($dir, [
            ['id' => 'mix', 'summary' => 'Mix | merge'],
            ['id' => "line one\nline two", 'summary' => 'plain'],
        ]);
        $this->blockPage($dir);
        $this->docsJson($dir, ['hops' => $this->jsonTable([['Hop', 'id'], ['What it does', 'summary']])]);
        $result = $this->invoke(['--root=' . $dir]);
        self::assertSame(0, $result['code'], $result['stderr']);
        $source = (string) file_get_contents($dir . '/docs/reference/commands.md');
        self::assertStringContainsString('| mix | Mix \| merge |', $source);
        self::assertStringContainsString('| line one line two | plain |', $source);
    }

    public function testBuildRewritesAStaleBlockInTheSource(): void
    {
        $dir = $this->repo();
        $this->cli($dir);
        $this->data($dir, [['id' => 'login', 'code' => 'booster login', 'summary' => 'Sign in']]);
        $this->blockPage($dir, 'hops', 'stale content.');
        $this->docsJson($dir, ['hops' => $this->jsonTable([
            ['Hop', 'id'],
            ['Command', 'code', 'code'],
            ['What it does', 'summary'],
        ])]);
        $result = $this->invoke(['--root=' . $dir]);
        self::assertSame(0, $result['code'], $result['stderr']);
        $source = (string) file_get_contents($dir . '/docs/reference/commands.md');
        self::assertStringNotContainsString('stale content.', $source);
        self::assertStringContainsString('<!-- generated:hops -->', $source);
        self::assertStringContainsString('| login | `booster login` | Sign in |', $source);
        self::assertStringContainsString('<!-- /generated -->', $source);
        self::assertStringContainsString('booster login', $this->page($dir, 'reference/commands'));
        $again = $this->invoke(['--root=' . $dir]);
        self::assertSame(0, $again['code'], $again['stderr']);
        self::assertSame($source, (string) file_get_contents($dir . '/docs/reference/commands.md'));
    }

    public function testCheckFailsWhenABlockIsStale(): void
    {
        $dir = $this->repo();
        $this->cli($dir);
        $this->data($dir, [['id' => 'login', 'code' => 'booster login', 'summary' => 'Sign in']]);
        $this->blockPage($dir, 'hops', 'stale content.');
        $this->docsJson($dir, ['hops' => $this->jsonTable([
            ['Hop', 'id'],
            ['Command', 'code', 'code'],
            ['What it does', 'summary'],
        ])]);
        self::assertSame(0, $this->invoke(['--root=' . $dir])['code']);
        $this->data($dir, [['id' => 'logout', 'code' => 'booster logout', 'summary' => 'Sign out']]);
        $result = $this->invoke(['--root=' . $dir, '--check']);
        self::assertSame(1, $result['code']);
        self::assertStringContainsString('stale: docs/reference/commands.md (generated block "hops" is out of date)', $result['stderr']);
        // --check reports the stale block without writing: the source still holds the previous hop list.
        $source = (string) file_get_contents($dir . '/docs/reference/commands.md');
        self::assertStringContainsString('| login | `booster login` | Sign in |', $source);
        self::assertStringNotContainsString('logout', $source);
    }

    public function testCheckPassesWhenBlocksAreCurrent(): void
    {
        $dir = $this->repo();
        $this->cli($dir);
        $this->data($dir, [['id' => 'login', 'code' => 'booster login', 'summary' => 'Sign in']]);
        $this->blockPage($dir, 'hops', 'stale content.');
        $this->docsJson($dir, ['hops' => $this->jsonTable([
            ['Hop', 'id'],
            ['Command', 'code', 'code'],
            ['What it does', 'summary'],
        ])]);
        self::assertSame(0, $this->invoke(['--root=' . $dir])['code']);
        $result = $this->invoke(['--root=' . $dir, '--check']);
        self::assertSame(0, $result['code'], $result['stderr']);
        self::assertStringNotContainsString('stale:', $result['stderr']);
        self::assertStringNotContainsString('finding:', $result['stderr']);
    }

    public function testUnknownBlockNameIsAFinding(): void
    {
        $dir = $this->repo();
        $this->blockPage($dir, 'ghost', 'whatever.');
        $this->docsJson($dir, []);
        $result = $this->invoke(['--root=' . $dir, '--check']);
        self::assertSame(1, $result['code']);
        self::assertStringContainsString('finding:', $result['stderr']);
        self::assertStringContainsString('places a generated block "ghost" that docs.json does not define', $result['stderr']);
    }

    public function testUnplacedBlockIsAFinding(): void
    {
        $dir = $this->repo();
        $this->docsJson($dir, ['hops' => $this->jsonTable([['Hop', 'id']])]);
        $result = $this->invoke(['--root=' . $dir, '--check']);
        self::assertSame(1, $result['code']);
        self::assertStringContainsString('defines a generated block "hops" that no page places', $result['stderr']);
    }

    public function testUnclosedBlockIsAFinding(): void
    {
        $dir = $this->repo();
        $this->cli($dir);
        $this->data($dir, [['id' => 'login', 'summary' => 'Sign in']]);
        $this->put($dir, 'docs/reference/commands.md', "# Commands\n\n<!-- generated:hops -->\nnever closed.\n");
        $this->navItem($dir, 'reference/commands', 'Commands');
        $this->docsJson($dir, ['hops' => $this->jsonTable([['Hop', 'id']])]);
        $result = $this->invoke(['--root=' . $dir, '--check']);
        self::assertSame(1, $result['code']);
        self::assertStringContainsString('opens a generated block "hops" without a closing marker', $result['stderr']);
    }

    public function testFailingCommandIsExit2(): void
    {
        $dir = $this->repo();
        $this->cli($dir);
        $this->put($dir, 'bin/demo-data.json', "not json\n");
        $this->blockPage($dir, 'hops', 'stale content.');
        $this->docsJson($dir, ['hops' => $this->jsonTable([['Hop', 'id']])]);
        $result = $this->invoke(['--root=' . $dir, '--check']);
        self::assertSame(2, $result['code']);
        self::assertStringContainsString('php bin/demo', $result['stderr']);
    }

    public function testDuplicatePlacementIsAFinding(): void
    {
        $dir = $this->repo();
        $this->cli($dir);
        $this->data($dir, [['id' => 'login', 'summary' => 'Sign in']]);
        $this->put($dir, 'docs/reference/commands.md', "# Commands\n\n<!-- generated:hops -->\nFirst.\n<!-- /generated -->\n\nMore.\n\n<!-- generated:hops -->\nSecond.\n<!-- /generated -->\n");
        $this->navItem($dir, 'reference/commands', 'Commands');
        $this->docsJson($dir, ['hops' => $this->jsonTable([['Hop', 'id']])]);
        $result = $this->invoke(['--root=' . $dir, '--check']);
        self::assertSame(1, $result['code']);
        self::assertStringContainsString('is placed more than once', $result['stderr']);
        self::assertStringContainsString('"hops"', $result['stderr']);
    }

    public function testUnimplementedTypeIsAFinding(): void
    {
        $dir = $this->repo();
        $this->blockPage($dir, 'commands', 'stale content.');
        $this->docsJson($dir, ['commands' => ['type' => 'bogus', 'run' => 'php bin/demo']]);
        $result = $this->invoke(['--root=' . $dir, '--check']);
        self::assertSame(1, $result['code']);
        self::assertStringContainsString('finding: block type bogus is not implemented', $result['stderr']);
    }
}

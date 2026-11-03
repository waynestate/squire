<?php

declare(strict_types=1);

namespace Waynestate\Squire\Tests;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Waynestate\Squire\Squire;

/**
 * The docs builder's version stamp gates: composer.json's version field, --require-release, --require-tag, and the
 * docs-version meta on every built page.
 */
final class DocsTagTest extends SquireTestCase
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

    /** Runs git with the given arguments inside the dir. */
    /** @param list<string> $args */
    private function git(string $dir, array $args): array
    {
        $process = @proc_open(array_merge(['git'], $args), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $dir);
        self::assertIsResource($process);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['code' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
    }

    /** Commits what is staged in the dir without relying on a global committer identity. */
    private function commit(string $dir): void
    {
        $result = $this->git($dir, ['-c', 'user.email=a@b', '-c', 'user.name=t', 'commit', '-m', 'init']);
        self::assertSame(0, $result['code'], $result['stderr']);
    }

    private function requireGit(): void
    {
        $process = @proc_open(['git', '--version'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (! is_resource($process)) {
            self::markTestSkipped('git is not installed.');
        }
        stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
    }

    public function testComposerVersionMismatchIsAFinding(): void
    {
        $dir = $this->repo();
        $this->put($dir, 'composer.json', '{"name":"acme/demo","version":"1.2.0"}' . "\n");
        $result = $this->invoke(['--root=' . $dir]);
        self::assertSame(1, $result['code']);
        self::assertStringContainsString('finding: composer.json version 1.2.0 does not match the docs stamp 1.2.3', $result['stderr']);
        $this->put($dir, 'composer.json', '{"name":"acme/demo","version":"1.2.3"}' . "\n");
        $result = $this->invoke(['--root=' . $dir]);
        self::assertSame(0, $result['code'], $result['stderr']);
        self::assertStringNotContainsString('does not match the docs stamp', $result['stderr']);
    }

    public function testRequireTagFlagComparesTheLatestTag(): void
    {
        $this->requireGit();
        $dir = $this->repo();
        $this->git($dir, ['init']);
        $this->git($dir, ['add', '-A']);
        $this->commit($dir);
        // The flag is off by default: a repo with no version tags builds clean.
        $result = $this->invoke(['--root=' . $dir]);
        self::assertSame(0, $result['code'], $result['stderr']);
        self::assertStringNotContainsString('require-tag', $result['stderr']);
        // A tag matching the docs stamp passes the check.
        $this->git($dir, ['tag', 'v1.2.3']);
        $result = $this->invoke(['--root=' . $dir, '--require-tag']);
        self::assertSame(0, $result['code'], $result['stderr']);
        self::assertStringNotContainsString('latest tag', $result['stderr']);
        // A newer tag appears: the stamp no longer matches the latest tag.
        $this->git($dir, ['tag', 'v1.6.0']);
        $result = $this->invoke(['--root=' . $dir, '--require-tag']);
        self::assertSame(1, $result['code']);
        self::assertStringContainsString('finding: the docs stamp 1.2.3 does not match the latest tag 1.6.0', $result['stderr']);
    }

    public function testRequireTagWithNoTagsIsAFinding(): void
    {
        $this->requireGit();
        $dir = $this->repo();
        $this->git($dir, ['init']);
        $this->git($dir, ['add', '-A']);
        $this->commit($dir);
        $result = $this->invoke(['--root=' . $dir, '--require-tag']);
        self::assertSame(1, $result['code']);
        self::assertStringContainsString('finding: --require-tag: the repo has no version tags', $result['stderr']);
    }

    public function testRequireReleaseWithoutAHeadingIsAFinding(): void
    {
        $dir = $this->repo();
        // The fixture changelog has a released heading, so the flag passes as is.
        $result = $this->invoke(['--root=' . $dir, '--require-release']);
        self::assertSame(0, $result['code'], $result['stderr']);
        self::assertStringNotContainsString('no released heading', $result['stderr']);
        // Only Unreleased remains: the flag now fails.
        $this->put($dir, 'CHANGELOG.md', "# Changelog\n\nAll notable changes to this project.\n\n## Unreleased\n\nNothing yet.\n");
        $result = $this->invoke(['--root=' . $dir, '--check', '--require-release']);
        self::assertSame(1, $result['code']);
        self::assertStringContainsString('finding: CHANGELOG.md has no released heading', $result['stderr']);
    }

    public function testEveryPageCarriesTheDocsVersionMeta(): void
    {
        $dir = $this->repo();
        $result = $this->invoke(['--root=' . $dir]);
        self::assertSame(0, $result['code'], $result['stderr']);
        $pages = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir . '/site/docs', FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo && $file->isFile() && strtolower($file->getExtension()) === 'html') {
                $pages[] = $file->getPathname();
            }
        }
        self::assertNotEmpty($pages);
        foreach ($pages as $page) {
            $html = (string) file_get_contents($page);
            self::assertStringContainsString('<meta name="docs-version" content="1.2.3">', $html, basename($page));
        }
    }
}

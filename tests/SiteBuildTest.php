<?php

declare(strict_types=1);

namespace Waynestate\Squire\Tests;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * The `squire site build` command (ADR-0023, slice 3): it reads the site pin, exports the pinned tag from a local
 * library repository, lays the site's own docs/ and site/ folders over the export, runs the docs and agent-ready
 * generators, and replaces the output folder with the built site plus a sha256 manifest.json. Every test builds a
 * throwaway library repo tagged v1.0.0 and a site repo pinning it, then drives bin/squire as a subprocess.
 */
final class SiteBuildTest extends SquireTestCase
{
    private const FIXTURES = __DIR__ . '/fixtures/site-generators';

    public function test_build_writes_public_and_manifest(): void
    {
        [$site, $lib] = $this->pair();
        // The site repo authors one file over the library's: the site copy must win in the build.
        $this->put($site, 'site/assets/wayne-ui/wayne-ui.css', "/* authored by the site */\n");

        $result = $this->runSquire(['site', 'build', '--root=' . $site, '--repo=' . $lib]);

        self::assertSame(0, $result['exit'], $result['stderr']);
        // The generators hand their own output straight through; the command's line is the last one.
        self::assertMatchesRegularExpression('/^Built \d+ files from enterprise-apps\/demo v1\.0\.0 into public\.$/', self::lastLine($result['stdout']));
        $manifest = json_decode((string) file_get_contents($site . '/public/manifest.json'), true);
        self::assertIsArray($manifest);
        self::assertSame('enterprise-apps/demo', $manifest['library']);
        self::assertSame('v1.0.0', $manifest['tag']);
        self::assertIsArray($manifest['files']);
        self::assertArrayNotHasKey('manifest.json', $manifest['files']);
        $paths = array_keys($manifest['files']);
        $sorted = $paths;
        sort($sorted);
        self::assertSame($sorted, $paths);
        foreach ($paths as $path) {
            self::assertStringNotContainsString('\\', $path);
            self::assertFileExists($site . '/public/' . $path);
        }
        foreach (['index.html', 'llms.txt', 'docs/index.html'] as $expected) {
            self::assertArrayHasKey($expected, $manifest['files']);
        }
        self::assertSame("/* authored by the site */\n", (string) file_get_contents($site . '/public/assets/wayne-ui/wayne-ui.css'));
    }

    public function test_manifest_sha256_matches_files(): void
    {
        [$site, $lib] = $this->pair();

        $result = $this->runSquire(['site', 'build', '--root=' . $site, '--repo=' . $lib]);

        self::assertSame(0, $result['exit'], $result['stderr']);
        $manifest = json_decode((string) file_get_contents($site . '/public/manifest.json'), true);
        self::assertIsArray($manifest);
        $actual = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($site . '/public', FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo || !$file->isFile()) {
                continue;
            }
            $relative = ltrim(str_replace('\\', '/', substr($file->getPathname(), strlen($site . '/public'))), '/');
            if ($relative === 'manifest.json') {
                continue;
            }
            $actual[$relative] = 'sha256:' . hash('sha256', (string) file_get_contents($file->getPathname()));
        }
        ksort($actual);
        self::assertSame($actual, $manifest['files']);
    }

    public function test_build_bad_pin_exits_2(): void
    {
        [$site, $lib] = $this->pair();
        $this->put($site, 'site.json', '{not json' . "\n");

        $result = $this->runSquire(['site', 'build', '--root=' . $site, '--repo=' . $lib]);

        self::assertSame(2, $result['exit']);
        self::assertStringContainsString('site.json', $result['stderr']);
        self::assertDirectoryDoesNotExist($site . '/public');
    }

    public function test_build_missing_tag_exits_2(): void
    {
        [$site, $lib] = $this->pair();
        $this->put($site, 'site.json', json_encode([
            'library' => 'enterprise-apps/demo',
            'tag' => 'v9.9.9',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

        $result = $this->runSquire(['site', 'build', '--root=' . $site, '--repo=' . $lib]);

        self::assertSame(2, $result['exit']);
        self::assertStringContainsString('v9.9.9', $result['stderr']);
        self::assertDirectoryDoesNotExist($site . '/public');
    }

    public function test_default_action_is_build(): void
    {
        [$site, $lib] = $this->pair();

        $result = $this->runSquire(['site', '--root=' . $site, '--repo=' . $lib]);

        self::assertSame(0, $result['exit'], $result['stderr']);
        self::assertMatchesRegularExpression('/^Built \d+ files from enterprise-apps\/demo v1\.0\.0 into public\.$/', self::lastLine($result['stdout']));
        self::assertFileExists($site . '/public/manifest.json');
    }

    /** The last non-empty line of a chunk of output; the generators' pass-through lines precede the command's own. */
    private static function lastLine(string $output): string
    {
        $lines = array_reverse(array_values(array_filter(explode("\n", $output), static fn(string $line): bool => trim($line) !== '')));

        return $lines === [] ? '' : $lines[0];
    }

    public function test_unknown_action_exits_2(): void
    {
        [$site, $lib] = $this->pair();

        $result = $this->runSquire(['site', 'deploy', '--root=' . $site, '--repo=' . $lib]);

        self::assertSame(2, $result['exit']);
        self::assertStringContainsString('deploy', $result['stderr']);
    }

    /**
     * A site repo whose site.json pins the library at v1.0.0, and the library itself.
     *
     * @return array{0: string, 1: string} [site repo, library repo]
     */
    private function pair(): array
    {
        $lib = $this->library();
        $site = $this->tempDir();
        $this->put($site, 'site.json', json_encode([
            'library' => 'enterprise-apps/demo',
            'tag' => 'v1.0.0',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

        return [$site, $lib];
    }

    /**
     * The throwaway library: the site-generators fixture with both kits synced in, committed and tagged v1.0.0, so
     * the exported tree carries the docs source and every kit file the generators check for.
     */
    private function library(): string
    {
        $this->requireGit();
        $dir = $this->tempDir();
        $this->copyTree(self::FIXTURES, $dir);
        $squire = dirname(__DIR__);
        $docs = $this->invoke([PHP_BINARY, $squire . '/bin/docs-site.php', '--sync-to=' . $dir]);
        self::assertSame(0, $docs['code'], $docs['stderr']);
        $ready = $this->invoke([PHP_BINARY, $squire . '/bin/agent-ready.php', '--sync-to=' . $dir]);
        self::assertSame(0, $ready['code'], $ready['stderr']);
        $this->git($dir, ['init']);
        $this->git($dir, ['add', '-A']);
        $this->commit($dir, 'v1.0.0');
        $this->git($dir, ['tag', 'v1.0.0']);

        return $dir;
    }

    private function copyTree(string $from, string $to): void
    {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($from, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo || !$file->isFile()) {
                continue;
            }
            $relative = ltrim(str_replace('\\', '/', substr($file->getPathname(), strlen($from))), '/');
            $this->put($to, $relative, (string) file_get_contents($file->getPathname()));
        }
    }

    /**
     * Runs one command and returns its exit code and output, for the kit setup the command under test does not do.
     *
     * @param list<string> $command
     *
     * @return array{code: int, stdout: string, stderr: string}
     */
    private function invoke(array $command): array
    {
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['code' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
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
    private function commit(string $dir, string $message): void
    {
        $result = $this->git($dir, [
            '-c', 'user.name=Test',
            '-c', 'user.email=test@example.invalid',
            '-c', 'commit.gpgsign=false',
            'commit', '-m', $message,
        ]);
        self::assertSame(0, $result['code'], $result['stderr']);
    }

    private function requireGit(): void
    {
        $process = @proc_open(['git', '--version'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            self::markTestSkipped('git is not installed.');
        }
        stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
    }
}

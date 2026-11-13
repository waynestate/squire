<?php

declare(strict_types=1);

namespace Waynestate\Squire\Tests;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * The `squire site build --check` option (ADR-0023, slice 4): it builds into a temp folder and byte-compares the
 * built site plus the manifest it would write with the committed output folder, printing one line per finding
 * (missing, extra, drift) and writing nothing. Every test builds a throwaway library repo tagged v1.0.0 and a
 * site repo pinning it, then drives bin/squire as a subprocess.
 */
final class SiteCheckTest extends SquireTestCase
{
    private const FIXTURES = __DIR__ . '/fixtures/site-generators';

    public function test_check_clean_exits_0(): void
    {
        [$site, $lib] = $this->pair();

        $build = $this->runSquire(['site', 'build', '--root=' . $site, '--repo=' . $lib]);
        self::assertSame(0, $build['exit'], $build['stderr']);

        $result = $this->runSquire(['site', 'build', '--check', '--root=' . $site, '--repo=' . $lib]);

        self::assertSame(0, $result['exit'], $result['stdout'] . $result['stderr']);
        // The generators hand their own output straight through; the command's line is the last one.
        self::assertMatchesRegularExpression('/^Site output is current: \d+ file\(s\)\.$/', self::lastLine($result['stdout']));
    }

    public function test_check_drift_exits_1(): void
    {
        [$site, $lib] = $this->pair();

        $build = $this->runSquire(['site', 'build', '--root=' . $site, '--repo=' . $lib]);
        self::assertSame(0, $build['exit'], $build['stderr']);
        $this->flipByte($site . '/public/index.html');

        $result = $this->runSquire(['site', 'build', '--check', '--root=' . $site, '--repo=' . $lib]);

        self::assertSame(1, $result['exit'], $result['stdout'] . $result['stderr']);
        self::assertStringContainsString('drift: index.html', $result['stdout']);
    }

    public function test_check_missing_out_file_reports_drift(): void
    {
        [$site, $lib] = $this->pair();

        $build = $this->runSquire(['site', 'build', '--root=' . $site, '--repo=' . $lib]);
        self::assertSame(0, $build['exit'], $build['stderr']);
        self::assertTrue(unlink($site . '/public/llms.txt'));

        $result = $this->runSquire(['site', 'build', '--check', '--root=' . $site, '--repo=' . $lib]);

        self::assertSame(1, $result['exit'], $result['stdout'] . $result['stderr']);
        self::assertStringContainsString('missing: llms.txt', $result['stdout']);
    }

    public function test_check_extra_file_reported(): void
    {
        [$site, $lib] = $this->pair();

        $build = $this->runSquire(['site', 'build', '--root=' . $site, '--repo=' . $lib]);
        self::assertSame(0, $build['exit'], $build['stderr']);
        $this->put($site, 'public/stray.txt', "A file the build does not know.\n");

        $result = $this->runSquire(['site', 'build', '--check', '--root=' . $site, '--repo=' . $lib]);

        self::assertSame(1, $result['exit'], $result['stdout'] . $result['stderr']);
        self::assertStringContainsString('extra: stray.txt', $result['stdout']);
    }

    public function test_check_never_writes(): void
    {
        [$site, $lib] = $this->pair();

        $build = $this->runSquire(['site', 'build', '--root=' . $site, '--repo=' . $lib]);
        self::assertSame(0, $build['exit'], $build['stderr']);
        $this->flipByte($site . '/public/index.html');
        $before = $this->snapshot($site);

        $result = $this->runSquire(['site', 'build', '--check', '--root=' . $site, '--repo=' . $lib]);

        self::assertSame(1, $result['exit'], $result['stdout'] . $result['stderr']);
        // Every file under the site root keeps its mtime and bytes, and no new file appears.
        self::assertSame($before, $this->snapshot($site));
    }

    /** Changes exactly one byte of the file, so a byte comparison sees the difference. */
    private function flipByte(string $path): void
    {
        $bytes = (string) file_get_contents($path);
        self::assertNotSame('', $bytes);
        $bytes[0] = chr(ord($bytes[0]) ^ 1);
        self::assertNotFalse(file_put_contents($path, $bytes));
    }

    /**
     * Every file under $dir as relative path => [mtime, sha256], for proving a check wrote nothing.
     *
     * @return array<string, array{int, string}>
     */
    private function snapshot(string $dir): array
    {
        $state = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo || !$file->isFile()) {
                continue;
            }
            $relative = ltrim(str_replace('\\', '/', substr($file->getPathname(), strlen($dir))), '/');
            $state[$relative] = [filemtime($file->getPathname()), hash('sha256', (string) file_get_contents($file->getPathname()))];
        }
        ksort($state);

        return $state;
    }

    /** The last non-empty line of a chunk of output; the generators' pass-through lines precede the command's own. */
    private static function lastLine(string $output): string
    {
        $lines = array_reverse(array_values(array_filter(explode("\n", $output), static fn(string $line): bool => trim($line) !== '')));

        return $lines === [] ? '' : $lines[0];
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

<?php

declare(strict_types=1);

namespace Waynestate\Squire\Tests;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * The vendor side of `squire site build` (ADR-0023, slice 5): docs.json blocks that run commands run from the
 * library as the site repo installed it with Composer (<root>/vendor/waynestate/<name>), and the pin's files key
 * copies the site's own data into the build tree. Every test builds a throwaway library repo tagged v1.0.0 and a
 * site repo pinning waynestate/demo, then drives bin/squire as a subprocess.
 */
final class SiteVendorTest extends SquireTestCase
{
    private const FIXTURES = __DIR__ . '/fixtures/site-generators';

    public function test_block_command_runs_from_vendor(): void
    {
        $site = $this->site();
        $lib = $this->library($site . '/vendor/waynestate/demo', true);
        // tool.php lives only in the installed library's working tree, never in the exported tag.
        $this->put($lib, 'tool.php', "<?php\n\necho json_encode(['rows' => [['cwd' => getcwd(), 'note' => 'ran from the installed library']]]);\n");

        $result = $this->runSquire(['site', 'build', '--root=' . $site]);

        self::assertSame(0, $result['exit'], $result['stderr']);
        // The table cell holds the command's getcwd(), so the built page shows the vendor path, not the temp export.
        $html = str_replace('\\', '/', (string) file_get_contents($site . '/public/docs/index.html'));
        self::assertStringContainsString('vendor/waynestate/demo', $html);
    }

    public function test_block_command_missing_vendor_exits_2(): void
    {
        $site = $this->site();
        $lib = $this->library($this->tempDir(), true);

        $result = $this->runSquire(['site', 'build', '--root=' . $site, '--repo=' . $lib]);

        self::assertSame(2, $result['exit']);
        self::assertStringContainsString('tool', $result['stderr']);
        self::assertStringContainsString('composer require waynestate/demo:1.0.0', $result['stderr']);
        self::assertDirectoryDoesNotExist($site . '/public');
    }

    public function test_files_key_copies_into_tree(): void
    {
        // The pin lists a data file outside site/ and the data folder under the site's own site/.
        $site = $this->site(
            ['files' => ['data/policy.json', 'site/data']],
            ['data/policy.json' => '{"floor": "^8.3"}' . "\n", 'site/data/policy.json' => '{"owned": "by the site"}' . "\n"],
        );
        $lib = $this->library($this->tempDir());

        $result = $this->runSquire(['site', 'build', '--root=' . $site, '--repo=' . $lib]);

        self::assertSame(0, $result['exit'], $result['stderr']);
        self::assertSame('{"owned": "by the site"}' . "\n", (string) file_get_contents($site . '/public/data/policy.json'));
    }

    public function test_files_key_outside_root_exits_2(): void
    {
        $site = $this->site(['files' => ['../escape.txt']]);
        $lib = $this->library($this->tempDir());

        $result = $this->runSquire(['site', 'build', '--root=' . $site, '--repo=' . $lib]);

        self::assertSame(2, $result['exit']);
        self::assertStringContainsString('../escape.txt', $result['stderr']);
        self::assertDirectoryDoesNotExist($site . '/public');
    }

    public function test_theme_only_docs_json_builds_without_vendor(): void
    {
        $site = $this->site();
        $lib = $this->library($this->tempDir());

        $result = $this->runSquire(['site', 'build', '--root=' . $site, '--repo=' . $lib]);

        self::assertSame(0, $result['exit'], $result['stderr']);
        self::assertFileExists($site . '/public/manifest.json');
    }

    /**
     * A site repo pinning waynestate/demo at v1.0.0.
     *
     * @param array<string, mixed> $pin the whole site.json, library and tag filled in when absent
     * @param array<string, string> $files extra files in the site repo, relative path to content
     */
    private function site(array $pin = [], array $files = []): string
    {
        $dir = $this->tempDir();
        $pin = ['library' => 'waynestate/demo', 'tag' => 'v1.0.0'] + $pin;
        $this->put($dir, 'site.json', json_encode($pin, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        foreach ($files as $path => $content) {
            $this->put($dir, $path, $content);
        }

        return $dir;
    }

    /**
     * A throwaway library: the site-generators fixture with both kits synced in, committed and tagged v1.0.0, so
     * the exported tree carries the docs source and every kit file the generators check for. With $withBlock, the
     * library's docs.json gains a json-table block named "tool" that runs php tool.php, and its index page gains
     * the matching region.
     */
    private function library(string $dir, bool $withBlock = false): string
    {
        $this->requireGit();
        $this->copyTree(self::FIXTURES, $dir);
        $squire = dirname(__DIR__);
        $docs = $this->invoke([PHP_BINARY, $squire . '/bin/docs-site.php', '--sync-to=' . $dir]);
        self::assertSame(0, $docs['code'], $docs['stderr']);
        $ready = $this->invoke([PHP_BINARY, $squire . '/bin/agent-ready.php', '--sync-to=' . $dir]);
        self::assertSame(0, $ready['code'], $ready['stderr']);
        if ($withBlock) {
            $this->put(
                $dir,
                'docs/docs.json',
                <<<'JSON'
{
    "theme": {},
    "blocks": {
        "tool": {
            "type": "json-table",
            "run": "php tool.php",
            "path": "rows",
            "columns": [
                ["Working directory", "cwd"],
                ["Note", "note"]
            ]
        }
    }
}
JSON,
            );
            $this->put($dir, 'docs/index.md', "# Top heading\n\nThe demo project, in one place. Open [the start guide](guides/start.md) when you are ready.\n\n## About\n\nThis page has an h2 so the \"On this page\" list is not empty.\n\n## Tool\n\n<!-- generated:tool -->\nplaceholder\n<!-- /generated -->\n");
        }
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

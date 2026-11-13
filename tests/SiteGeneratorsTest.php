<?php

declare(strict_types=1);

namespace Waynestate\Squire\Tests;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Waynestate\Squire\Site\AgentReadyGenerator;
use Waynestate\Squire\Site\DocsGenerator;

/**
 * The src/Site generator drivers (ADR-0023, batch 0): DocsGenerator and AgentReadyGenerator run Squire's own
 * bin/docs-site.php and bin/agent-ready.php against a root directory and hand back their exit codes unchanged.
 * Every test works on a temp copy of the site-generators fixture, so the fixture itself stays clean.
 */
final class SiteGeneratorsTest extends SquireTestCase
{
    private const FIXTURES = __DIR__ . '/fixtures/site-generators';

    /**
     * A temp copy of the fixture repo with both kits synced in, the way a real repo gets them: the docs kit
     * (site/assets/docs.css, site/assets/docs.js) and the agent-ready kit (site/assets/agent-ready.css,
     * site/assets/agent-ready.js). The sync runs the scripts directly, because the drivers under test only build
     * and check.
     */
    private function repo(): string
    {
        $dir = $this->tempDir();
        $this->copyTree(self::FIXTURES, $dir);
        $squire = dirname(__DIR__);
        $docs = $this->invoke([PHP_BINARY, $squire . '/bin/docs-site.php', '--sync-to=' . $dir]);
        self::assertSame(0, $docs['code'], $docs['stderr']);
        $ready = $this->invoke([PHP_BINARY, $squire . '/bin/agent-ready.php', '--sync-to=' . $dir]);
        self::assertSame(0, $ready['code'], $ready['stderr']);

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
     * Runs one command and returns its exit code and output, for the kit setup the drivers do not cover.
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

    public function test_docs_generator_writes_fixture(): void
    {
        $dir = $this->repo();

        $code = DocsGenerator::run($dir);

        self::assertSame(0, $code);
        self::assertFileExists($dir . '/site/docs/index.html');
        self::assertFileExists($dir . '/site/docs/guides/start.html');
    }

    public function test_docs_generator_check_clean(): void
    {
        $dir = $this->repo();
        self::assertSame(0, DocsGenerator::run($dir));

        self::assertSame(0, DocsGenerator::run($dir, ['check' => true]));
    }

    public function test_docs_generator_check_drift(): void
    {
        $dir = $this->repo();
        self::assertSame(0, DocsGenerator::run($dir));
        $page = $dir . '/site/docs/index.html';
        file_put_contents($page, (string) file_get_contents($page) . "\n<!-- tampered -->\n");

        self::assertSame(1, DocsGenerator::run($dir, ['check' => true]));
    }

    public function test_agentready_generator_writes_fixture(): void
    {
        $dir = $this->repo();

        $code = AgentReadyGenerator::run($dir);

        self::assertSame(0, $code);
        foreach (['llms.txt', 'llms-full.txt', 'robots.txt', 'sitemap.xml', 'index.md', 'guide.md'] as $relative) {
            self::assertFileExists($dir . '/site/' . $relative, $relative);
        }
    }

    public function test_agentready_check_drift(): void
    {
        $dir = $this->repo();
        self::assertSame(0, AgentReadyGenerator::run($dir));
        $llms = $dir . '/site/llms.txt';
        file_put_contents($llms, (string) file_get_contents($llms) . "tampered\n");

        self::assertSame(1, AgentReadyGenerator::run($dir, ['check' => true]));
    }
}

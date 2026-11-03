<?php

declare(strict_types=1);

namespace Waynestate\Squire\Tests;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Waynestate\Squire\Squire;

/**
 * The --examples gate: the squire drills the moves the docs promise, so no reader learns a command that does not work.
 */
final class DocsExamplesTest extends SquireTestCase
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

    /** Overwrites the fixture's start page so the gate has no untagged shell block to report. */
    private function quietStart(string $dir): void
    {
        $this->put($dir, 'docs/guides/start.md', "# Start\n\nSquire ships a docs builder.\n");
    }

    /** Writes docs/docs.json with no blocks and the given examples config. */
    private function examplesJson(string $dir, array $examples = []): void
    {
        $config = ['blocks' => (object) [], 'examples' => $examples === [] ? (object) [] : $examples];
        $this->put($dir, 'docs/docs.json', json_encode($config, JSON_PRETTY_PRINT) . "\n");
    }

    /** The demo fixture folder: a tiny PHP CLI and a seed file. */
    private function demoDir(string $dir): void
    {
        $this->put($dir, 'demo/tool.php', self::tool());
        $this->put($dir, 'demo/seed.txt', "seed\n");
    }

    /** Writes docs/examples/<name>.md verbatim. */
    private function examplePage(string $dir, string $name, string $page): void
    {
        $this->put($dir, 'docs/examples/' . $name . '.md', $page);
    }

    /** Runs the examples gate against a temp repo. */
    private function examples(string $dir): array
    {
        return $this->invoke(['--root=' . $dir, '--examples']);
    }

    /** Four skipped shell blocks, grouped into three reasons. */
    private function skipPage(): string
    {
        return "# Skips\n"
            . "\n"
            . '<!-- docs-test: skip needs network -->' . "\n"
            . "```sh\n"
            . 'curl example.com' . "\n"
            . "```\n"
            . "\n"
            . '<!-- docs-test: skip Needs Network -->' . "\n"
            . "```console\n"
            . 'wget example.com' . "\n"
            . "```\n"
            . "\n"
            . '<!-- docs-test: skip needs Docker, slow -->' . "\n"
            . "```bash\n"
            . 'docker compose up' . "\n"
            . "```\n"
            . "\n"
            . '<!-- docs-test: skip local only -->' . "\n"
            . "```shell\n"
            . 'make local' . "\n"
            . "```\n";
    }

    /** The fake CLI the examples drive: each subcommand prints or fails in a predictable way. */
    private static function tool(): string
    {
        return "<?php\n"
            . "\n"
            . "// The fake CLI the tested examples drive: each subcommand prints or fails in a predictable way.\n"
            . "\n"
            . "\$name = \$argv[1] ?? '';\n"
            . "\$args = array_slice(\$argv, 2);\n"
            . "\n"
            . "switch (\$name) {\n"
            . "    case 'print-cwd':\n"
            . '        echo getcwd(), "\n";' . "\n"
            . '        break;' . "\n"
            . "    case 'read':\n"
            . '        echo (string) file_get_contents($args[0]);' . "\n"
            . '        break;' . "\n"
            . "    case 'write':\n"
            . '        file_put_contents($args[0], "written\n");' . "\n"
            . '        break;' . "\n"
            . "    case 'echo-out':\n"
            . '        echo implode(" ", $args), "\n";' . "\n"
            . '        break;' . "\n"
            . "    case 'echo-err':\n"
            . '        fwrite(STDERR, implode(" ", $args) . "\n");' . "\n"
            . '        break;' . "\n"
            . "    case 'fail':\n"
            . '        fwrite(STDERR, "boom\n");' . "\n"
            . '        exit((int) ($args[0] ?? 2));' . "\n"
            . "    case 'sleep':\n"
            . '        usleep((int) round(((float) ($args[0] ?? 1)) * 1000000));' . "\n"
            . '        break;' . "\n"
            . "    case 'list':\n"
            . "        foreach (scandir('.') ?: [] as \$entry) {\n"
            . "            if (\$entry === '.' || \$entry === '..') {\n"
            . '                continue;' . "\n"
            . '            }' . "\n"
            . '            echo $entry, "\n";' . "\n"
            . '        }' . "\n"
            . '        break;' . "\n"
            . "    case 'env':\n"
            . "        \$value = getenv(\$args[0] ?? '');" . "\n"
            . '        if ($value === false) {' . "\n"
            . '            exit(4);' . "\n"
            . '        }' . "\n"
            . '        echo $value, "\n";' . "\n"
            . '        break;' . "\n"
            . "    case 'touch-env':" . "\n"
            . "        file_put_contents(getenv(\$args[0] ?? ''), 'ran');" . "\n"
            . '        break;' . "\n"
            . '    default:' . "\n"
            . '        fwrite(STDERR, "unknown command {$name}\n");' . "\n"
            . '        exit(64);' . "\n"
            . '}' . "\n";
    }

    public function testPassingExampleExitsZero(): void
    {
        $dir = $this->repo();
        $this->quietStart($dir);
        $this->demoDir($dir);
        $this->examplesJson($dir, ['cwd' => 'demo']);
        $this->examplePage($dir, 'pass', "# Pass\n"
            . "\n"
            . "```bash run\n"
            . '$ php tool.php echo-out hello world' . "\n"
            . "```\n"
            . "\n"
            . "```output\n"
            . 'hello world' . "\n"
            . "```\n");
        $result = $this->examples($dir);
        self::assertSame(0, $result['code'], $result['stderr']);
        self::assertSame("Examples: 1 run, 0 skipped\n", $result['stdout']);
        self::assertStringNotContainsString('example:', $result['stderr']);
    }

    public function testWrongOutputIsAFindingWithDiff(): void
    {
        $dir = $this->repo();
        $this->quietStart($dir);
        $this->demoDir($dir);
        $this->examplesJson($dir, ['cwd' => 'demo']);
        $this->examplePage($dir, 'wrong', "# Wrong\n"
            . "\n"
            . "```bash run\n"
            . '$ php tool.php echo-out line one' . "\n"
            . '$ php tool.php echo-out line two' . "\n"
            . "```\n"
            . "\n"
            . "```output\n"
            . 'line one' . "\n"
            . 'line three' . "\n"
            . "```\n");
        $result = $this->examples($dir);
        self::assertSame(1, $result['code']);
        self::assertStringContainsString('example: docs/examples/wrong.md:3 output differs', $result['stderr']);
        self::assertStringContainsString('example: - line three', $result['stderr']);
        self::assertStringContainsString('example: + line two', $result['stderr']);
    }

    public function testUnexpectedExitCodeFails(): void
    {
        $dir = $this->repo();
        $this->quietStart($dir);
        $this->demoDir($dir);
        $this->examplesJson($dir, ['cwd' => 'demo']);
        $this->examplePage($dir, 'fail', "# Fail\n"
            . "\n"
            . "```bash run\n"
            . '$ php tool.php fail 7' . "\n"
            . "```\n"
            . "\n"
            . "```output\n"
            . 'boom' . "\n"
            . "```\n");
        $result = $this->examples($dir);
        self::assertSame(1, $result['code']);
        self::assertStringContainsString('example: docs/examples/fail.md:3 exited 7, expected 0', $result['stderr']);
        self::assertStringNotContainsString('output differs', $result['stderr']);
    }

    public function testExitOneIsAcceptedWhenTagged(): void
    {
        $dir = $this->repo();
        $this->quietStart($dir);
        $this->demoDir($dir);
        $this->examplesJson($dir, ['cwd' => 'demo']);
        $this->examplePage($dir, 'exit-one', "# Exit one\n"
            . "\n"
            . "```bash run exit=1\n"
            . '$ php tool.php fail 1' . "\n"
            . "```\n"
            . "\n"
            . "```output\n"
            . 'boom' . "\n"
            . "```\n");
        $result = $this->examples($dir);
        self::assertSame(0, $result['code'], $result['stderr']);
        self::assertSame("Examples: 1 run, 0 skipped\n", $result['stdout']);
    }

    public function testUntaggedShellBlockIsAFinding(): void
    {
        $dir = $this->repo();
        $this->quietStart($dir);
        $this->examplePage($dir, 'untagged', "# Untagged\n"
            . "\n"
            . "```sh\n"
            . 'composer install' . "\n"
            . "```\n");
        $result = $this->examples($dir);
        self::assertSame(1, $result['code']);
        self::assertStringContainsString('example: docs/examples/untagged.md:3 shell block without run or a skip reason', $result['stderr']);
        self::assertSame("Examples: 0 run, 0 skipped\n", $result['stdout']);
    }

    public function testSkipWithoutAReasonIsAFinding(): void
    {
        $dir = $this->repo();
        $this->quietStart($dir);
        $this->examplePage($dir, 'skipless', "# Skipless\n"
            . "\n"
            . '<!-- docs-test: skip -->' . "\n"
            . "```sh\n"
            . 'composer install' . "\n"
            . "```\n");
        $result = $this->examples($dir);
        self::assertSame(1, $result['code']);
        self::assertStringContainsString('example: docs/examples/skipless.md:3 skip without a reason', $result['stderr']);
    }

    public function testSkipsAreCountedAndGrouped(): void
    {
        $dir = $this->repo();
        $this->quietStart($dir);
        $this->examplePage($dir, 'skips', $this->skipPage());
        $result = $this->examples($dir);
        self::assertSame(0, $result['code'], $result['stderr']);
        self::assertSame("Examples: 0 run, 4 skipped (reasons: needs network 2, local only 1, needs Docker 1)\n", $result['stdout']);
    }

    public function testMaxSkipsIsEnforced(): void
    {
        $dir = $this->repo();
        $this->quietStart($dir);
        $this->examplesJson($dir, ['maxSkips' => 3]);
        $this->examplePage($dir, 'skips', $this->skipPage());
        $result = $this->examples($dir);
        self::assertSame(1, $result['code']);
        self::assertStringContainsString('example: docs.json maxSkips 3 exceeded: 4 skipped', $result['stderr']);
    }

    public function testAliasRunsTheRepoScript(): void
    {
        $dir = $this->repo();
        $this->quietStart($dir);
        $this->demoDir($dir);
        $this->put($dir, 'bin/booster', "<?php\n"
            . "\n"
            . "// The alias target: prints what the alias passed it.\n"
            . "\n"
            . "echo 'booster ', implode(' ', array_slice(\$argv, 1)), \"\n\";\n");
        $this->examplesJson($dir, ['cwd' => 'demo', 'alias' => ['booster' => 'php bin/booster']]);
        $this->examplePage($dir, 'alias', "# Alias\n"
            . "\n"
            . "```bash run\n"
            . '$ booster hello world' . "\n"
            . "```\n"
            . "\n"
            . "```output\n"
            . 'booster hello world' . "\n"
            . "```\n");
        $result = $this->examples($dir);
        self::assertSame(0, $result['code'], $result['stderr']);
        self::assertSame("Examples: 1 run, 0 skipped\n", $result['stdout']);
    }

    public function testExamplesRunInATempCopyOfTheFixture(): void
    {
        $dir = $this->repo();
        $this->quietStart($dir);
        $this->demoDir($dir);
        $this->put($dir, 'demo/vendor/junk.txt', "junk\n");
        $this->examplesJson($dir, ['cwd' => 'demo']);
        $this->examplePage($dir, 'copy', "# Copy\n"
            . "\n"
            . "```bash run\n"
            . '$ php tool.php list' . "\n"
            . "```\n"
            . "\n"
            . "```output\n"
            . 'seed.txt' . "\n"
            . 'tool.php' . "\n"
            . "```\n"
            . "\n"
            . "```bash run\n"
            . '$ php tool.php write out.txt' . "\n"
            . '$ php tool.php read out.txt' . "\n"
            . "```\n"
            . "\n"
            . "```output\n"
            . 'written' . "\n"
            . "```\n");
        $result = $this->examples($dir);
        self::assertSame(0, $result['code'], $result['stderr']);
        self::assertFileDoesNotExist($dir . '/demo/out.txt');
    }

    public function testCwdAndRootArePlaceholders(): void
    {
        $dir = $this->repo();
        $this->quietStart($dir);
        $this->demoDir($dir);
        $this->examplesJson($dir, ['cwd' => 'demo', 'env' => ['ROOT' => $dir]]);
        $this->examplePage($dir, 'paths', "# Paths\n"
            . "\n"
            . "```bash run\n"
            . '$ php tool.php print-cwd' . "\n"
            . '$ php tool.php env ROOT' . "\n"
            . "```\n"
            . "\n"
            . "```output\n"
            . '<cwd>' . "\n"
            . '<root>' . "\n"
            . "```\n");
        $result = $this->examples($dir);
        self::assertSame(0, $result['code'], $result['stderr']);
    }

    public function testNormalizeRulesApply(): void
    {
        $dir = $this->repo();
        $this->quietStart($dir);
        $this->demoDir($dir);
        $this->examplesJson($dir, ['cwd' => 'demo', 'normalize' => [['/build-\\d+/', 'build-N']]]);
        $this->examplePage($dir, 'normalize', "# Normalize\n"
            . "\n"
            . "```bash run\n"
            . '$ php tool.php echo-out build-42' . "\n"
            . "```\n"
            . "\n"
            . "```output\n"
            . 'build-N' . "\n"
            . "```\n");
        $result = $this->examples($dir);
        self::assertSame(0, $result['code'], $result['stderr']);
    }

    public function testTimeoutIsAFinding(): void
    {
        $dir = $this->repo();
        $this->quietStart($dir);
        $this->demoDir($dir);
        $this->examplesJson($dir, ['cwd' => 'demo', 'timeout' => 1]);
        $this->examplePage($dir, 'timeout', "# Timeout\n"
            . "\n"
            . "```bash run\n"
            . '$ php tool.php sleep 3' . "\n"
            . "```\n");
        $result = $this->examples($dir);
        self::assertSame(1, $result['code']);
        self::assertStringContainsString('example: docs/examples/timeout.md:3 timed out after 1 seconds', $result['stderr']);
    }

    public function testGeneratedBlocksAreIgnored(): void
    {
        $dir = $this->repo();
        $this->quietStart($dir);
        $this->examplePage($dir, 'generated', "# Generated\n"
            . "\n"
            . '<!-- generated:json-table -->' . "\n"
            . "```sh\n"
            . 'composer install' . "\n"
            . "```\n"
            . '<!-- /generated -->' . "\n");
        $result = $this->examples($dir);
        self::assertSame(0, $result['code'], $result['stderr']);
        self::assertSame("Examples: 0 run, 0 skipped\n", $result['stdout']);
    }

    public function testCheckDoesNotRunExamples(): void
    {
        $dir = $this->repo();
        $this->demoDir($dir);
        $this->examplesJson($dir, ['cwd' => 'demo', 'env' => ['MARKER' => $dir . '/marker.txt']]);
        $this->put($dir, 'docs/guides/start.md', "# Get started\n"
            . "\n"
            . 'Run the example below.' . "\n"
            . "\n"
            . "```bash run\n"
            . '$ php tool.php touch-env MARKER' . "\n"
            . "```\n");
        $build = $this->invoke(['--root=' . $dir]);
        self::assertSame(0, $build['code'], $build['stderr']);
        $check = $this->invoke(['--root=' . $dir, '--check']);
        self::assertSame(0, $check['code'], $check['stderr']);
        self::assertStringContainsString('Docs site is current', $check['stdout']);
        self::assertFileDoesNotExist($dir . '/marker.txt');
    }
}

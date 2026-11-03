<?php

declare(strict_types=1);

namespace Waynestate\Squire\Tests;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Waynestate\Squire\Squire;

/**
 * The docs builder's two-way command and option coverage gate for a symfony-commands block.
 */
final class DocsCoverageTest extends SquireTestCase
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

    /** The fake CLI: php bin/demo prints bin/demo-data.json, or fails when it is missing. */
    private function cli(string $dir): void
    {
        $this->put($dir, 'bin/demo', "#!/usr/bin/env php\n<?php\n\n\$file = __DIR__.'/demo-data.json';\nif (! is_file(\$file)) {\n    fwrite(STDERR, \"no data\\n\");\n    exit(3);\n}\necho (string) file_get_contents(\$file);\n");
    }

    /** The Symfony Console list output the fake CLI prints. */
    private function commands(string $dir, array $commands): void
    {
        $this->put($dir, 'bin/demo-data.json', json_encode(['application' => ['name' => 'Demo', 'version' => '1.0.0'], 'commands' => $commands], JSON_PRETTY_PRINT) . "\n");
    }

    /** Writes docs/docs.json with the given blocks, by name. */
    private function docsJson(string $dir, array $blocks): void
    {
        $this->put($dir, 'docs/docs.json', json_encode(['blocks' => $blocks], JSON_PRETTY_PRINT) . "\n");
    }

    /** A symfony-commands block that reads the fake CLI's list output. */
    private function symfonyCommands(array $extra = []): array
    {
        return ['type' => 'symfony-commands', 'run' => 'php bin/demo', 'cli' => 'demo', 'level' => 3] + $extra;
    }

    /** Adds a page to the fixture's nav.json. */
    private function navItem(string $dir, string $path, string $title): void
    {
        /** @var array<string, mixed> $nav */
        $nav = json_decode((string) file_get_contents($dir . '/docs/nav.json'), true);
        $nav['items'][] = ['title' => $title, 'path' => $path];
        $this->put($dir, 'docs/nav.json', json_encode($nav, JSON_PRETTY_PRINT) . "\n");
    }

    /** The command set every test uses: demo plan (with --force) and demo sweep (no options). */
    private function standardCommands(): array
    {
        return [
            [
                'name' => 'plan',
                'description' => 'Plans the rollout',
                'usage' => ['plan'],
                'help' => '',
                'hidden' => false,
                'definition' => [
                    'arguments' => [],
                    'options' => [
                        'force' => ['name' => '--force', 'shortcut' => null, 'accept_value' => false, 'is_value_required' => false, 'is_multiple' => false, 'description' => 'Force it', 'default' => false],
                    ],
                ],
            ],
            ['name' => 'sweep', 'description' => 'Sweeps the floor', 'usage' => ['sweep'], 'help' => '', 'hidden' => false, 'definition' => ['arguments' => [], 'options' => []]],
        ];
    }

    /** A reference page holding the generated block region plus hand-written content, added to the nav. */
    private function blockPage(string $dir, string $extra): void
    {
        $this->put($dir, 'docs/reference/commands.md', "# Commands\n\n<!-- generated:commands -->\nstale content.\n<!-- /generated -->\n\n{$extra}\n");
        $this->navItem($dir, 'reference/commands', 'Commands');
    }

    /** A reference page with hand-written content only, added to the nav. */
    private function plainPage(string $dir, string $content): void
    {
        $this->put($dir, 'docs/reference/commands.md', $content . "\n");
        $this->navItem($dir, 'reference/commands', 'Commands');
    }

    public function testUnknownCommandMentionIsAFinding(): void
    {
        $dir = $this->repo();
        $this->cli($dir);
        $this->commands($dir, $this->standardCommands());
        $this->blockPage($dir, "Run `demo nope` to find out.");
        $this->docsJson($dir, ['commands' => $this->symfonyCommands()]);
        $result = $this->invoke(['--root=' . $dir]);
        self::assertSame(1, $result['code']);
        self::assertStringContainsString('finding: docs/reference/commands.md mentions "demo nope" but the CLI has no command nope.', $result['stderr']);
        $result = $this->invoke(['--root=' . $dir, '--check']);
        self::assertSame(1, $result['code']);
        self::assertStringContainsString('finding: docs/reference/commands.md mentions "demo nope" but the CLI has no command nope.', $result['stderr']);
    }

    public function testUnknownOptionForACommandIsAFinding(): void
    {
        $dir = $this->repo();
        $this->cli($dir);
        $this->commands($dir, $this->standardCommands());
        $this->blockPage($dir, "Run `demo plan --nope` to find out.");
        $this->docsJson($dir, ['commands' => $this->symfonyCommands()]);
        $result = $this->invoke(['--root=' . $dir]);
        self::assertSame(1, $result['code']);
        self::assertStringContainsString('finding: docs/reference/commands.md uses demo plan --nope, which plan does not accept.', $result['stderr']);
        $result = $this->invoke(['--root=' . $dir, '--check']);
        self::assertSame(1, $result['code']);
        self::assertStringContainsString('finding: docs/reference/commands.md uses demo plan --nope, which plan does not accept.', $result['stderr']);
    }

    public function testApplicationOptionsAreAccepted(): void
    {
        $dir = $this->repo();
        $this->cli($dir);
        $this->commands($dir, $this->standardCommands());
        $this->blockPage($dir, "Run `demo plan --quiet` to keep it short.");
        $this->docsJson($dir, ['commands' => $this->symfonyCommands()]);
        $result = $this->invoke(['--root=' . $dir]);
        self::assertSame(0, $result['code'], $result['stderr']);
        self::assertStringNotContainsString('finding:', $result['stderr']);
        $result = $this->invoke(['--root=' . $dir, '--check']);
        self::assertSame(0, $result['code'], $result['stderr']);
        self::assertStringNotContainsString('finding:', $result['stderr']);
    }

    public function testUndocumentedCommandIsAFinding(): void
    {
        $dir = $this->repo();
        $this->cli($dir);
        $this->commands($dir, $this->standardCommands());
        $this->plainPage($dir, "# Commands\n\nRun `demo plan --force` when you mean it.");
        $this->docsJson($dir, ['commands' => $this->symfonyCommands()]);
        $result = $this->invoke(['--root=' . $dir]);
        self::assertSame(1, $result['code']);
        self::assertStringContainsString('finding: command "sweep" is in the CLI but not documented.', $result['stderr']);
        $result = $this->invoke(['--root=' . $dir, '--check']);
        self::assertSame(1, $result['code']);
        self::assertStringContainsString('finding: command "sweep" is in the CLI but not documented.', $result['stderr']);
    }

    public function testSkippedFenceIsNotScanned(): void
    {
        $dir = $this->repo();
        $this->cli($dir);
        $this->commands($dir, $this->standardCommands());
        $this->blockPage($dir, "```shell docs-test: skip\n\$ demo nope --nope\n```\n");
        $this->docsJson($dir, ['commands' => $this->symfonyCommands()]);
        $result = $this->invoke(['--root=' . $dir]);
        self::assertSame(0, $result['code'], $result['stderr']);
        self::assertStringNotContainsString('finding:', $result['stderr']);
        $result = $this->invoke(['--root=' . $dir, '--check']);
        self::assertSame(0, $result['code'], $result['stderr']);
        self::assertStringNotContainsString('finding:', $result['stderr']);
    }

    public function testPlainProseIsNeverScanned(): void
    {
        $dir = $this->repo();
        $this->cli($dir);
        $this->commands($dir, $this->standardCommands());
        $this->blockPage($dir, "The demo nope command plans nothing, and demo plan --nope does nothing either.");
        $this->docsJson($dir, ['commands' => $this->symfonyCommands()]);
        $result = $this->invoke(['--root=' . $dir]);
        self::assertSame(0, $result['code'], $result['stderr']);
        self::assertStringNotContainsString('finding:', $result['stderr']);
        $result = $this->invoke(['--root=' . $dir, '--check']);
        self::assertSame(0, $result['code'], $result['stderr']);
        self::assertStringNotContainsString('finding:', $result['stderr']);
    }

    public function testCleanDocsPass(): void
    {
        $dir = $this->repo();
        $this->cli($dir);
        $this->commands($dir, $this->standardCommands());
        $this->blockPage($dir, "Use `demo plan --force` when you mean it, and `demo sweep` to finish.\n\n```\n\$ demo plan --force\n\$ vendor/bin/demo sweep\n```\n");
        $this->docsJson($dir, ['commands' => $this->symfonyCommands()]);
        $result = $this->invoke(['--root=' . $dir]);
        self::assertSame(0, $result['code'], $result['stderr']);
        self::assertStringNotContainsString('finding:', $result['stderr']);
        $result = $this->invoke(['--root=' . $dir, '--check']);
        self::assertSame(0, $result['code'], $result['stderr']);
        self::assertStringNotContainsString('finding:', $result['stderr']);
    }
}

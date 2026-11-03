<?php

declare(strict_types=1);

namespace Waynestate\Squire\Tests;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Waynestate\Squire\Squire;

/**
 * The docs builder's symfony-commands and env generated blocks, run the way a repo's CI runs the builder.
 */
final class DocsSymfonyBlockTest extends SquireTestCase
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

    /** An env block that scans src for APP_ variables. */
    private function env(array $extra = []): array
    {
        return ['type' => 'env', 'scan' => ['src'], 'prefix' => 'APP_'] + $extra;
    }

    /** The fake source tree the env block scans. */
    private function code(string $dir, string $config, string $nested = ''): void
    {
        $this->put($dir, 'src/Config.php', $config);
        if ($nested !== '') {
            $this->put($dir, 'src/Deep/Nested.php', $nested);
        }
    }

    /** A reference page holding one generated block region, added to the nav. */
    private function blockPage(string $dir, string $name = 'commands', string $content = 'stale content.'): void
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

    public function testCommandsBlockIsGeneratedFromListJson(): void
    {
        $dir = $this->repo();
        $this->cli($dir);
        $this->commands($dir, [
            [
                'name' => 'app:cache',
                'description' => 'Clears the cache',
                'usage' => ['app:cache clear [path]'],
                'help' => 'Clears the cache.',
                'hidden' => false,
                'definition' => [
                    'arguments' => [
                        'path' => ['name' => 'path', 'is_required' => false, 'is_array' => false, 'description' => 'The cache pool', 'default' => null],
                    ],
                    'options' => [
                        'path' => ['name' => '--path', 'shortcut' => '-p', 'accept_value' => true, 'is_value_required' => true, 'is_multiple' => false, 'description' => 'Base path', 'default' => '.'],
                        'force' => ['name' => '--force', 'shortcut' => null, 'accept_value' => false, 'is_value_required' => false, 'is_multiple' => false, 'description' => 'Force it', 'default' => false],
                        'help' => ['name' => '--help', 'shortcut' => null, 'accept_value' => false, 'is_value_required' => false, 'is_multiple' => false, 'description' => 'Displays help', 'default' => false],
                    ],
                ],
            ],
            [
                'name' => 'app:seed',
                'description' => 'Seeds the database',
                'usage' => ['app:seed'],
                'help' => '',
                'hidden' => false,
                'definition' => ['arguments' => [], 'options' => []],
            ],
        ]);
        $this->blockPage($dir);
        $this->docsJson($dir, ['commands' => $this->symfonyCommands()]);
        $result = $this->invoke(['--root=' . $dir]);
        self::assertSame(0, $result['code'], $result['stderr']);
        $source = (string) file_get_contents($dir . '/docs/reference/commands.md');
        $expected = "| Command | What it does |\n"
            . '| --- | --- |' . "\n"
            . '| `demo app:cache` | Clears the cache |' . "\n"
            . '| `demo app:seed` | Seeds the database |' . "\n"
            . "\n"
            . '### `demo app:cache`' . "\n"
            . "\n"
            . 'Clears the cache' . "\n"
            . "\n"
            . 'Usage: `demo app:cache clear [path]`' . "\n"
            . "\n"
            . '| Argument | Required | Meaning |' . "\n"
            . '| --- | --- | --- |' . "\n"
            . '| `path` | no | The cache pool |' . "\n"
            . "\n"
            . '| Option | Meaning | Default |' . "\n"
            . '| --- | --- | --- |' . "\n"
            . '| `-p`, `--path=VALUE` | Base path | `.` |' . "\n"
            . '| `--force` | Force it |  |' . "\n"
            . "\n"
            . '### `demo app:seed`' . "\n"
            . "\n"
            . 'Seeds the database' . "\n"
            . "\n"
            . 'Usage: `demo app:seed`';
        self::assertStringContainsString($expected, $source);
        self::assertStringNotContainsString('stale content.', $source);
        $html = $this->page($dir, 'reference/commands');
        self::assertStringContainsString('<table>', $html);
        self::assertStringContainsString('demo app:cache', $html);
    }

    public function testHiddenAndBuiltinCommandsAreSkipped(): void
    {
        $dir = $this->repo();
        $this->cli($dir);
        $this->commands($dir, [
            ['name' => 'help', 'description' => 'Help command', 'usage' => ['help [command_name]'], 'help' => '', 'hidden' => false, 'definition' => ['arguments' => [], 'options' => []]],
            ['name' => 'list', 'description' => 'List commands', 'usage' => ['list'], 'help' => '', 'hidden' => false, 'definition' => ['arguments' => [], 'options' => []]],
            ['name' => 'completion', 'description' => 'Dump shell completion script', 'usage' => ['completion'], 'help' => '', 'hidden' => false, 'definition' => ['arguments' => [], 'options' => []]],
            ['name' => '_complete', 'description' => 'Internal command', 'usage' => ['_complete'], 'help' => '', 'hidden' => false, 'definition' => ['arguments' => [], 'options' => []]],
            ['name' => 'app:ghost', 'description' => 'A hidden command', 'usage' => ['app:ghost'], 'help' => '', 'hidden' => true, 'definition' => ['arguments' => [], 'options' => []]],
            ['name' => 'app:real', 'description' => 'The real command', 'usage' => ['app:real'], 'help' => '', 'hidden' => false, 'definition' => ['arguments' => [], 'options' => []]],
        ]);
        $this->blockPage($dir);
        $this->docsJson($dir, ['commands' => $this->symfonyCommands()]);
        $result = $this->invoke(['--root=' . $dir]);
        self::assertSame(0, $result['code'], $result['stderr']);
        $source = (string) file_get_contents($dir . '/docs/reference/commands.md');
        self::assertStringContainsString('| `demo app:real` | The real command |', $source);
        self::assertStringNotContainsString('`demo help`', $source);
        self::assertStringNotContainsString('`demo list`', $source);
        self::assertStringNotContainsString('`demo completion`', $source);
        self::assertStringNotContainsString('`demo _complete`', $source);
        self::assertStringNotContainsString('`demo app:ghost`', $source);
        self::assertStringNotContainsString('A hidden command', $source);
    }

    public function testOptionsTableShowsShortcutValueAndDefault(): void
    {
        $dir = $this->repo();
        $this->cli($dir);
        $this->commands($dir, [
            [
                'name' => 'app:mix',
                'description' => 'Mixes things',
                'usage' => ['app:mix'],
                'help' => '',
                'hidden' => false,
                'definition' => [
                    'arguments' => [],
                    'options' => [
                        'path' => ['name' => '--path', 'shortcut' => '-p', 'accept_value' => true, 'is_value_required' => true, 'is_multiple' => false, 'description' => 'Where to look', 'default' => '.'],
                        'dry-run' => ['name' => '--dry-run', 'shortcut' => '-n', 'accept_value' => false, 'is_value_required' => false, 'is_multiple' => false, 'description' => 'Do not write', 'default' => false],
                        'loud' => ['name' => '--loud', 'shortcut' => null, 'accept_value' => false, 'is_value_required' => false, 'is_multiple' => false, 'description' => 'Loudly', 'default' => false],
                        'count' => ['name' => '--count', 'shortcut' => null, 'accept_value' => true, 'is_value_required' => false, 'is_multiple' => false, 'description' => 'How many', 'default' => 3],
                    ],
                ],
            ],
        ]);
        $this->blockPage($dir);
        $this->docsJson($dir, ['commands' => $this->symfonyCommands()]);
        $result = $this->invoke(['--root=' . $dir]);
        self::assertSame(0, $result['code'], $result['stderr']);
        $source = (string) file_get_contents($dir . '/docs/reference/commands.md');
        self::assertStringContainsString('| `-p`, `--path=VALUE` | Where to look | `.` |', $source);
        self::assertStringContainsString('| `-n`, `--dry-run` | Do not write |  |', $source);
        self::assertStringContainsString('| `--loud` | Loudly |  |', $source);
        self::assertStringContainsString('| `--count=VALUE` | How many | `3` |', $source);
    }

    public function testApplicationOptionsAreListedOnce(): void
    {
        $dir = $this->repo();
        $this->cli($dir);
        $appOptions = [
            'help' => ['name' => '--help', 'shortcut' => null, 'accept_value' => false, 'is_value_required' => false, 'is_multiple' => false, 'description' => 'Displays help', 'default' => false],
            'quiet' => ['name' => '--quiet', 'shortcut' => '-q', 'accept_value' => false, 'is_value_required' => false, 'is_multiple' => false, 'description' => 'No output', 'default' => false],
            'no-interaction' => ['name' => '--no-interaction', 'shortcut' => '-n', 'accept_value' => false, 'is_value_required' => false, 'is_multiple' => false, 'description' => 'Do not interact', 'default' => false],
        ];
        $this->commands($dir, [
            [
                'name' => 'app:one',
                'description' => 'First',
                'usage' => ['app:one'],
                'help' => '',
                'hidden' => false,
                'definition' => ['arguments' => [], 'options' => $appOptions + ['own' => ['name' => '--own-one', 'shortcut' => null, 'accept_value' => false, 'is_value_required' => false, 'is_multiple' => false, 'description' => 'Ours', 'default' => false]]],
            ],
            ['name' => 'app:two', 'description' => 'Second', 'usage' => ['app:two'], 'help' => '', 'hidden' => false, 'definition' => ['arguments' => [], 'options' => $appOptions]],
        ]);
        $this->blockPage($dir);
        $this->docsJson($dir, ['commands' => $this->symfonyCommands()]);
        $result = $this->invoke(['--root=' . $dir]);
        self::assertSame(0, $result['code'], $result['stderr']);
        $source = (string) file_get_contents($dir . '/docs/reference/commands.md');
        self::assertStringContainsString('| `--own-one` | Ours |  |', $source);
        self::assertStringContainsString('### Options every command accepts', $source);
        self::assertStringContainsString('| `--help` | Displays help |  |', $source);
        self::assertStringContainsString('| `-q`, `--quiet` | No output |  |', $source);
        self::assertStringContainsString('| `-n`, `--no-interaction` | Do not interact |  |', $source);
        self::assertSame(1, substr_count($source, '`--help`'));
        self::assertSame(1, substr_count($source, '`--quiet`'));
        self::assertSame(1, substr_count($source, '`--no-interaction`'));
    }

    public function testPipesInCellsAreEscaped(): void
    {
        $dir = $this->repo();
        $this->cli($dir);
        $this->commands($dir, [
            ['name' => 'app:mix', 'description' => 'Mix | merge', 'usage' => ['app:mix'], 'help' => '', 'hidden' => false, 'definition' => ['arguments' => [], 'options' => []]],
            ['name' => 'app:fold', 'description' => "line one\nline two", 'usage' => ['app:fold'], 'help' => '', 'hidden' => false, 'definition' => ['arguments' => [], 'options' => []]],
        ]);
        $this->blockPage($dir);
        $this->docsJson($dir, ['commands' => $this->symfonyCommands()]);
        $result = $this->invoke(['--root=' . $dir]);
        self::assertSame(0, $result['code'], $result['stderr']);
        $source = (string) file_get_contents($dir . '/docs/reference/commands.md');
        self::assertStringContainsString('| `demo app:mix` | Mix \| merge |', $source);
        self::assertStringContainsString('| `demo app:fold` | line one line two |', $source);
        self::assertStringNotContainsString("line one\nline two", $source);
    }

    public function testEnvBlockListsVariablesWithDescriptions(): void
    {
        $dir = $this->repo();
        $this->code(
            $dir,
            "<?php\n\nreturn [\n    'name' => getenv('APP_NAME'),\n    'env' => \$_ENV['APP_ENV'],\n    'debug' => \$env['APP_DEBUG'],\n];\n",
            "<?php\n\nreturn getenv('APP_DEEP');\n",
        );
        $this->blockPage($dir, 'environment');
        $this->docsJson($dir, ['environment' => $this->env(['describe' => [
            'APP_NAME' => ['The app name', 'Demo'],
            'APP_ENV' => ['The environment', 'production'],
            'APP_DEBUG' => ['Debug mode', 'false'],
            'APP_DEEP' => ['Only in the nested file', null],
        ]])]);
        $result = $this->invoke(['--root=' . $dir]);
        self::assertSame(0, $result['code'], $result['stderr']);
        $source = (string) file_get_contents($dir . '/docs/reference/commands.md');
        $expected = "| Variable | Meaning | Default | Read in |\n"
            . '| --- | --- | --- | --- |' . "\n"
            . '| APP_DEBUG | Debug mode | false | src/Config.php |' . "\n"
            . '| APP_DEEP | Only in the nested file |  | src/Deep/Nested.php |' . "\n"
            . '| APP_ENV | The environment | production | src/Config.php |' . "\n"
            . '| APP_NAME | The app name | Demo | src/Config.php |';
        self::assertStringContainsString($expected, $source);
        $html = $this->page($dir, 'reference/commands');
        self::assertStringContainsString('APP_NAME', $html);
        self::assertStringContainsString('src/Deep/Nested.php', $html);
    }

    public function testEnvVariableWithoutDescriptionIsAFinding(): void
    {
        $dir = $this->repo();
        $this->code($dir, "<?php\n\nreturn [getenv('APP_NAME'), getenv('APP_GHOST')];\n");
        $this->blockPage($dir, 'environment');
        $this->docsJson($dir, ['environment' => $this->env(['describe' => ['APP_NAME' => ['The app name', 'Demo']]])]);
        $result = $this->invoke(['--root=' . $dir, '--check']);
        self::assertSame(1, $result['code']);
        self::assertStringContainsString('finding:', $result['stderr']);
        self::assertStringContainsString('env block "environment": APP_GHOST is read in src/Config.php but has no describe entry.', $result['stderr']);
    }

    public function testDescribedEnvVariableNotReadInCodeIsAFinding(): void
    {
        $dir = $this->repo();
        $this->code($dir, "<?php\n\nreturn getenv('APP_NAME');\n");
        $this->blockPage($dir, 'environment');
        $this->docsJson($dir, ['environment' => $this->env(['describe' => [
            'APP_NAME' => ['The app name', 'Demo'],
            'APP_PHANTOM' => ['Never read', 'nope'],
        ]])]);
        $result = $this->invoke(['--root=' . $dir, '--check']);
        self::assertSame(1, $result['code']);
        self::assertStringContainsString('finding:', $result['stderr']);
        self::assertStringContainsString('env block "environment": describe lists APP_PHANTOM, which no scanned file reads.', $result['stderr']);
    }

    public function testIgnoredEnvNamesAreSkipped(): void
    {
        $dir = $this->repo();
        $this->code($dir, "<?php\n\nreturn [getenv('APP_NAME'), getenv('APP_SECRET'), getenv('APP_CACHE_DRIVER')];\n");
        $this->blockPage($dir, 'environment');
        $this->docsJson($dir, ['environment' => $this->env([
            'ignore' => ['APP_SECRET', 'APP_CACHE*'],
            'describe' => ['APP_NAME' => ['The app name', 'Demo']],
        ])]);
        $result = $this->invoke(['--root=' . $dir]);
        self::assertSame(0, $result['code'], $result['stderr']);
        $source = (string) file_get_contents($dir . '/docs/reference/commands.md');
        self::assertStringContainsString('| APP_NAME | The app name | Demo | src/Config.php |', $source);
        self::assertStringNotContainsString('APP_SECRET', $source);
        self::assertStringNotContainsString('APP_CACHE_DRIVER', $source);
        self::assertStringNotContainsString('finding:', $result['stderr']);
    }
}

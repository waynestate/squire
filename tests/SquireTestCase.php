<?php

declare(strict_types=1);

namespace Waynestate\Squire\Tests;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Waynestate\Squire\Squire;
use Waynestate\Squire\Template\Catalog;
use Waynestate\Squire\Template\FileResult;
use Waynestate\Squire\Template\Initializer;

/**
 * Shared helpers: temporary apps, and a way to run bin/squire as a subprocess.
 */
abstract class SquireTestCase extends TestCase
{
    /** @var list<string> */
    private array $tempDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            self::removeTree($dir);
        }
        $this->tempDirs = [];
    }

    /** A new empty directory that is removed after the test. */
    protected function tempDir(): string
    {
        $dir = sys_get_temp_dir() . '/squire-test-' . bin2hex(random_bytes(6));
        mkdir($dir, 0o755, true);
        $this->tempDirs[] = $dir;

        return $dir;
    }

    /**
     * A minimal app: composer.json, composer.lock and a .gitignore, ready for `init`.
     *
     * @param array<string, string> $extra relative path => content
     */
    protected function app(array $extra = [], bool $laravel = false): string
    {
        $dir = $this->tempDir();
        file_put_contents($dir . '/composer.json', '{"name":"test/app","require":{"php":"^8.3"}}' . "\n");
        file_put_contents($dir . '/composer.lock', '{"packages":[],"packages-dev":[]}' . "\n");
        file_put_contents($dir . '/.gitignore', ".env\n.env.squire\n/vendor/\n");
        file_put_contents($dir . '/.gitattributes', "* text=auto eol=lf\n*.sh text eol=lf\n");
        if ($laravel) {
            file_put_contents($dir . '/artisan', "#!/usr/bin/env php\n<?php\n");
        }
        foreach ($extra as $path => $content) {
            $this->put($dir, $path, $content);
        }

        return $dir;
    }

    /**
     * A deploy layer inside a git repository: the repository root carries a .git, the layer sits below it in its own
     * folder, so SQUIRE manages the layer's .gitignore. The .git is a plain directory; no git binary is needed.
     *
     * @param array<string, string> $extra relative path => content
     */
    protected function deployLayer(array $extra = [], bool $laravel = false): string
    {
        $repo = $this->tempDir();
        mkdir($repo . '/.git');
        $dir = $repo . '/deploy';
        mkdir($dir);
        file_put_contents($dir . '/composer.json', '{"name":"test/app","require":{"php":"^8.3"}}' . "\n");
        file_put_contents($dir . '/composer.lock', '{"packages":[],"packages-dev":[]}' . "\n");
        file_put_contents($dir . '/.gitattributes', "* text=auto eol=lf\n*.sh text eol=lf\n");
        if ($laravel) {
            file_put_contents($dir . '/artisan', "#!/usr/bin/env php\n<?php\n");
        }
        foreach ($extra as $path => $content) {
            $this->put($dir, $path, $content);
        }

        return $dir;
    }

    protected function put(string $dir, string $path, string $content): void
    {
        $full = $dir . '/' . $path;
        if (!is_dir(dirname($full))) {
            mkdir(dirname($full), 0o755, true);
        }
        file_put_contents($full, $content);
    }

    /** An app with every SQUIRE file written into it. */
    protected function initializedApp(bool $laravel = false): string
    {
        $dir = $this->app([], $laravel);
        (new Initializer(new Catalog(Squire::templatesDir())))->run($dir, $laravel ? 'laravel' : 'raw', ['release', 'parity'], false, false);

        return $dir;
    }

    /** A minimal Vue app: package.json has vue in dependencies. */
    protected function vueApp(bool $laravel = false, array $extra = []): string
    {
        return $this->app($extra + ['package.json' => '{"dependencies":{"vue":"^3.0.0"}}' . "\n"], $laravel);
    }

    /** A Vue app with every SQUIRE file written into it. */
    protected function initializedVueApp(bool $laravel = false): string
    {
        $dir = $this->vueApp($laravel);
        (new Initializer(new Catalog(Squire::templatesDir())))->run($dir, $laravel ? 'laravel' : 'raw', ['release', 'parity'], false, false, Catalog::detectLayer($dir));

        return $dir;
    }

    /** Initialize a git repository in the app and stage the given paths. Returns false when git is unavailable. */
    protected function gitInit(string $dir, array $paths = []): bool
    {
        $process = @proc_open(['git', 'init'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $dir);
        if (!is_resource($process)) {
            return false;
        }
        stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($process) !== 0) {
            return false;
        }

        foreach ($paths as $path) {
            $process = @proc_open(['git', 'add', '--', $path], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $dir);
            if (!is_resource($process)) {
                return false;
            }
            stream_get_contents($pipes[1]);
            stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            if (proc_close($process) !== 0) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<FileResult> $results
     *
     * @return array<string, string> destination => state
     */
    protected function states(array $results): array
    {
        $states = [];
        foreach ($results as $result) {
            $states[$result->path] = $result->state;
        }

        return $states;
    }

    /** A private copy of the bundled templates, so a test can move a template on. */
    protected function copyTemplates(): string
    {
        $target = $this->tempDir() . '/templates';
        $source = Squire::templatesDir();
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo && $file->isFile()) {
                $relative = ltrim(str_replace('\\', '/', substr($file->getPathname(), strlen($source))), '/');
                $this->put($target, $relative, (string) file_get_contents($file->getPathname()));
            }
        }

        return $target;
    }

    /**
     * Runs bin/squire and returns the exit code and output.
     *
     * @param list<string> $args
     *
     * @return array{exit: int, stdout: string, stderr: string}
     */
    protected function runSquire(array $args): array
    {
        $cmd = [PHP_BINARY, dirname(__DIR__) . '/bin/squire', ...$args];
        $process = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['exit' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
    }

    protected static function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            if (!$item instanceof \SplFileInfo) {
                continue;
            }
            $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }

    /** Whether a program is on PATH. */
    protected static function hasProgram(string $name): bool
    {
        $result = proc_open(['sh', '-c', 'command -v "$0"', $name], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($result)) {
            return false;
        }
        stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);

        return proc_close($result) === 0;
    }
}

<?php

declare(strict_types=1);

namespace Waynestate\Squire\Doctor;

use Waynestate\Squire\ToolError;

/**
 * Read-only view of the app being examined. Checks read through it so no check can write to, or leave, the app.
 */
final class Project
{
    /** @var array<string, string|null> */
    private array $cache = [];

    public function __construct(public readonly string $path)
    {
        if (!is_dir($path)) {
            throw new ToolError(sprintf('Not a directory: %s', $path));
        }
    }

    public function has(string $relative): bool
    {
        return is_file($this->path . '/' . $relative);
    }

    /** The raw file content, or null when the file does not exist. */
    public function read(string $relative): ?string
    {
        if (!array_key_exists($relative, $this->cache)) {
            $file = $this->path . '/' . $relative;
            $content = is_file($file) ? file_get_contents($file) : false;
            $this->cache[$relative] = $content === false ? null : $content;
        }

        return $this->cache[$relative];
    }

    /**
     * The file's code: comment-only lines and blank lines are removed, so a rule is judged on what runs and not on
     * what a comment says.
     */
    public function code(string $relative): string
    {
        $content = $this->read($relative);
        if ($content === null) {
            return '';
        }

        $lines = [];
        foreach (preg_split('/\R/', $content) ?: [] as $line) {
            $trimmed = ltrim($line);
            if ($trimmed === '' || $trimmed[0] === '#') {
                continue;
            }
            $lines[] = $line;
        }

        return implode("\n", $lines);
    }

    /**
     * Files directly inside a directory, as paths relative to the app.
     *
     * @return list<string>
     */
    public function filesIn(string $directory): array
    {
        $dir = $this->path . '/' . $directory;
        if (!is_dir($dir)) {
            return [];
        }

        $files = [];
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..' && is_file($dir . '/' . $entry)) {
                $files[] = $directory . '/' . $entry;
            }
        }
        sort($files);

        return $files;
    }

    /**
     * Every file below a directory with one of the given extensions, as paths relative to the app. Capped, so a
     * runaway directory cannot make the doctor slow.
     *
     * @param list<string> $extensions
     *
     * @return list<string>
     */
    public function filesUnder(string $directory, array $extensions, int $limit = 2000): array
    {
        $root = $this->path . '/' . $directory;
        if (!is_dir($root)) {
            return [];
        }

        $found = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || !$file->isFile()) {
                continue;
            }
            if (in_array(strtolower($file->getExtension()), $extensions, true)) {
                $found[] = $directory . '/' . ltrim(str_replace('\\', '/', substr($file->getPathname(), strlen($root))), '/');
            }
            if (count($found) >= $limit) {
                break;
            }
        }
        sort($found);

        return $found;
    }

    public function isLaravel(): bool
    {
        return $this->has('artisan');
    }

    /** @return array<string, mixed>|null decoded composer.json, or null when absent or invalid */
    public function composerJson(): ?array
    {
        $raw = $this->read('composer.json');
        $data = $raw === null ? null : json_decode($raw, true);

        return is_array($data) ? $data : null;
    }

    /** Whether composer.json requires the package in `require` or `require-dev`. */
    public function requires(string $package): bool
    {
        $composer = $this->composerJson();
        if ($composer === null) {
            return false;
        }
        foreach (['require', 'require-dev'] as $section) {
            $packages = $composer[$section] ?? null;
            if (is_array($packages) && array_key_exists($package, $packages)) {
                return true;
            }
        }

        return false;
    }

    /** The release script's path, looking in the places SQUIRE and common practice put it. */
    public function releaseScript(): ?string
    {
        foreach (['scripts/release.sh', 'release.sh', 'bin/release.sh', 'deploy/release.sh'] as $candidate) {
            if ($this->has($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Every shell script the doctor knows about: build.sh, the deployments hooks, and the release script.
     *
     * @return list<string>
     */
    public function shellScripts(): array
    {
        $scripts = [];
        if ($this->has('build.sh')) {
            $scripts[] = 'build.sh';
        }
        foreach ($this->filesIn('deployments') as $hook) {
            $scripts[] = $hook;
        }
        $release = $this->releaseScript();
        if ($release !== null) {
            $scripts[] = $release;
        }

        return array_values(array_unique($scripts));
    }
}

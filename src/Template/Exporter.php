<?php

declare(strict_types=1);

namespace Waynestate\Squire\Template;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Waynestate\Squire\Squire;
use Waynestate\Squire\ToolError;

/**
 * Exports a generated, checked copy of SQUIRE's templates for a project that cannot `composer require` SQUIRE from an
 * internal repository (Sourdough, until an internal Packagist exists).
 *
 * The copy is committed by the consumer and verified with `--check`, which re-derives the export and reports any
 * drift. No hand edits: refresh with `bin/export-templates.php --to=<dir>`.
 */
final class Exporter
{
    public function __construct(private readonly Catalog $catalog) {}

    /**
     * The fresh export: relative path (from the export directory) => content.
     *
     * @return array<string, string>
     */
    public function freshExport(): array
    {
        $files = [];
        foreach (Catalog::TARGETS as $kind) {
            foreach ($this->catalog->select($kind, Catalog::GROUPS) as $template) {
                $files[$this->relative($kind, $template)] = $this->catalog->content($template);
            }
        }
        $files['catalog.json'] = $this->catalogJson();
        $files['manifest.json'] = $this->manifestJson($files);
        ksort($files);

        return $files;
    }

    /**
     * Write the fresh export into $dir. Returns the files written, relative to $dir.
     *
     * @return list<string>
     */
    public function export(string $dir): array
    {
        $files = $this->freshExport();
        $written = [];
        foreach ($files as $relative => $content) {
            $this->writeFile($dir . '/' . $relative, $content, $this->modeFor($relative));
            $written[] = $relative;
        }
        sort($written);

        return $written;
    }

    /**
     * Compare an existing export at $dir with a fresh export. Returns the drifted files, relative to $dir.
     *
     * @return list<string>
     */
    public function check(string $dir): array
    {
        if (!is_dir($dir)) {
            throw new ToolError(sprintf('Not a directory: %s', $dir));
        }

        $fresh = $this->freshExport();
        $drifted = [];
        foreach ($fresh as $relative => $content) {
            $path = $dir . '/' . $relative;
            if (!is_file($path)) {
                $drifted[] = $relative . ' (missing)';
                continue;
            }
            $disk = file_get_contents($path);
            if ($disk === false) {
                throw new ToolError(sprintf('Cannot read %s.', $path));
            }
            if (Catalog::normalize($disk) !== $content) {
                $drifted[] = $relative;
            }
        }

        $known = array_map(static fn(string $relative): string => $dir . '/' . $relative, array_keys($fresh));
        foreach ($this->listFiles($dir) as $file) {
            if (!in_array($file, $known, true)) {
                $drifted[] = ltrim(str_replace('\\', '/', substr($file, strlen($dir))), '/') . ' (unexpected)';
            }
        }
        sort($drifted);

        return $drifted;
    }

    /** The SQUIRE version recorded in the manifest: git describe, then composer.json, then the code constant. */
    public function version(): string
    {
        $git = $this->gitDescribe();
        if ($git !== null && $git !== '') {
            return $git;
        }
        $composer = $this->composerVersion();
        if ($composer !== null && $composer !== '') {
            return $composer;
        }

        return Squire::VERSION;
    }

    /**
     * @return array<string, bool> relative path => executable
     */
    private function executableMap(): array
    {
        $map = [];
        foreach (Catalog::TARGETS as $kind) {
            foreach ($this->catalog->select($kind, Catalog::GROUPS) as $template) {
                $map[$this->relative($kind, $template)] = $template->executable;
            }
        }

        return $map;
    }

    private function relative(string $kind, Template $template): string
    {
        return $kind . '/' . $template->destination;
    }

    private function modeFor(string $relative): int
    {
        return ($this->executableMap()[$relative] ?? false) ? 0o755 : 0o644;
    }

    private function modeString(string $relative): string
    {
        return ($this->executableMap()[$relative] ?? false) ? '755' : '644';
    }

    private function catalogJson(): string
    {
        $data = [];
        foreach (Catalog::TARGETS as $kind) {
            $files = [];
            foreach ($this->catalog->select($kind, Catalog::GROUPS) as $template) {
                $files[] = [
                    'path' => $template->destination,
                    'group' => $template->group,
                    'executable' => $template->executable,
                ];
            }
            $data[$kind] = $files;
        }

        return $this->encode($data, 'catalog.json');
    }

    /**
     * @param array<string, string> $files relative path => content
     */
    private function manifestJson(array $files): string
    {
        $manifestFiles = [];
        foreach ($files as $relative => $content) {
            if ($relative === 'catalog.json' || $relative === 'manifest.json') {
                continue;
            }
            $manifestFiles[$relative] = [
                'sha256' => Manifest::hash($content),
                'mode' => $this->modeString($relative),
            ];
        }
        ksort($manifestFiles);

        return $this->encode([
            'squire' => 'Generated by SQUIRE. Do not edit: refresh with `bin/export-templates.php --to=<dir>` and verify with `--check`.',
            'version' => $this->version(),
            'files' => $manifestFiles,
        ], 'manifest.json');
    }

    /**
     * @param array<string, mixed> $data
     */
    private function encode(array $data, string $what): string
    {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new ToolError(sprintf('Cannot encode %s.', $what));
        }

        return $json . "\n";
    }

    private function writeFile(string $path, string $content, int $mode): void
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0o755, true) && !is_dir($dir)) {
            throw new ToolError(sprintf('Cannot create directory %s.', $dir));
        }
        if (file_put_contents($path, $content) === false) {
            throw new ToolError(sprintf('Cannot write %s.', $path));
        }
        chmod($path, $mode);
    }

    /**
     * @return list<string> absolute paths of every file under $dir
     */
    private function listFiles(string $dir): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo || !$file->isFile()) {
                continue;
            }
            $path = str_replace('\\', '/', $file->getPathname());
            if (str_contains($path, '/.git/')) {
                continue;
            }
            $files[] = $file->getPathname();
        }
        sort($files);

        return $files;
    }

    private function gitDescribe(): ?string
    {
        $process = @proc_open(
            ['git', 'describe', '--tags', '--always'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->repoRoot(),
        );
        if (!is_resource($process)) {
            return null;
        }
        $out = (string) stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);
        if ($code !== 0) {
            return null;
        }
        $out = trim($out);

        return $out === '' ? null : $out;
    }

    private function composerVersion(): ?string
    {
        $path = $this->repoRoot() . '/composer.json';
        if (!is_file($path)) {
            return null;
        }
        $raw = file_get_contents($path);
        $data = $raw === false ? null : json_decode($raw, true);
        if (!is_array($data) || !isset($data['version']) || !is_string($data['version'])) {
            return null;
        }

        return $data['version'];
    }

    private function repoRoot(): string
    {
        return dirname(Squire::templatesDir());
    }
}

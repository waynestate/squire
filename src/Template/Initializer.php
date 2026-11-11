<?php

declare(strict_types=1);

namespace Waynestate\Squire\Template;

use Waynestate\Squire\Squire;
use Waynestate\Squire\ToolError;

/**
 * Writes the templates into an app, or reports how an app has drifted from them.
 *
 * "Depend, don't copy": the app keeps the files (Citadel needs them in the repository), but the manifest remembers
 * what Squire wrote, so an update never overwrites a file the team edited, and `--check` says which files fell behind.
 */
final class Initializer
{
    public function __construct(
        private readonly Catalog $catalog,
        private readonly TemplateWriter $writer = new TemplateWriter(),
        private readonly Gitignore $gitignore = new Gitignore(),
    ) {}

    /**
     * @param list<string> $groups
     * @param string|null  $php the PHP line to render on, when the caller names one
     *
     * @return list<FileResult>
     */
    public function run(string $appPath, string $target, array $groups, bool $force, bool $check, ?string $layer = null, ?string $php = null): array
    {
        if (!is_dir($appPath)) {
            throw new ToolError(sprintf('Not a directory: %s', $appPath));
        }

        $manifest = Manifest::load($appPath);
        $recorded = $manifest->files ?? [];
        $templates = $this->catalog->select($target, $groups, $layer);

        // The line the files are rendered on: the caller's choice wins, then the app's recorded line, then the
        // legacy line for an app Squire has already written and the default line for a fresh one.
        $phpLine = $php ?? $manifest->php ?? ($manifest !== null ? Squire::LEGACY_PHP_LINE : Squire::DEFAULT_PHP_LINE);

        $results = [];
        $written = $recorded;

        foreach ($templates as $template) {
            $content = $this->catalog->content($template, $phpLine);
            $newHash = Manifest::hash($content);
            $path = $appPath . '/' . $template->destination;
            $previous = $recorded[$template->destination] ?? null;

            if (!is_file($path)) {
                if ($check) {
                    $results[] = new FileResult($template->destination, FileResult::MISSING, 'not in the app');
                    continue;
                }
                $this->writer->write($path, $content, $template->executable);
                $written[$template->destination] = $newHash;
                $results[] = new FileResult($template->destination, FileResult::CREATED, 'written from the template');
                continue;
            }

            $disk = file_get_contents($path);
            if ($disk === false) {
                throw new ToolError(sprintf('Cannot read %s.', $path));
            }
            $diskHash = Manifest::hash($disk);

            if ($diskHash === $newHash) {
                $written[$template->destination] = $newHash;
                $results[] = new FileResult(
                    $template->destination,
                    $check ? FileResult::OK : FileResult::UNCHANGED,
                    'matches the template',
                );
                continue;
            }

            if ($previous !== null && $diskHash === $previous) {
                if ($check) {
                    $results[] = new FileResult($template->destination, FileResult::OUTDATED, 'untouched, but the template has changed; run `squire init` to update it');
                    continue;
                }
                $this->writer->write($path, $content, $template->executable);
                $written[$template->destination] = $newHash;
                $results[] = new FileResult($template->destination, FileResult::UPDATED, 'updated to the current template');
                continue;
            }

            if ($previous === null && $template->appMayOwn) {
                // The app brought its own (a raw app's index.php, say). It stays the app's: not drift, not recorded,
                // and not overwritten, --force or no --force. The squire holds the door; the knight picks the room.
                $results[] = new FileResult(
                    $template->destination,
                    FileResult::APP_OWNED,
                    "the app's own file; Squire writes this one only where there is none, and never overwrites it",
                );
                continue;
            }

            $state = $previous !== null ? FileResult::MODIFIED : FileResult::UNTRACKED;
            $detail = $previous !== null
                ? 'edited since Squire wrote it'
                : 'exists, but Squire did not write it';

            if ($check) {
                $results[] = new FileResult($template->destination, $state, $detail);
                continue;
            }
            if (!$force) {
                $results[] = new FileResult(
                    $template->destination,
                    $previous !== null ? FileResult::SKIPPED_MODIFIED : FileResult::SKIPPED_EXISTS,
                    $detail . '; left alone (use --force to overwrite)',
                );
                continue;
            }
            $this->writer->write($path, $content, $template->executable);
            $written[$template->destination] = $newHash;
            $results[] = new FileResult($template->destination, FileResult::OVERWRITTEN, $detail . '; overwritten by --force');
        }

        // The .gitignore is not a template: Squire appends the missing lines and records no hash, because once the
        // team edits it, it is theirs. In a directory below the repository root it is part of the layer Squire wrote.
        $gitignoreResult = $this->gitignore->ensure($appPath, $this->writer, $check);
        if ($gitignoreResult !== null) {
            $results[] = $gitignoreResult;
        }

        if (!$check) {
            // Record the line for a new app, or when the caller named one. An existing app keeps whatever it
            // recorded, nothing included, for a kit Squire wrote before the php key existed.
            $recordedPhp = $manifest === null ? $phpLine : ($php ?? $manifest->php);
            (new Manifest(Squire::version(), $target, $groups, $written, $layer, $recordedPhp))->save($appPath);
        }

        return $results;
    }
}

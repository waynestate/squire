<?php

declare(strict_types=1);

namespace Waynestate\Squire\Template;

use Waynestate\Squire\Squire;
use Waynestate\Squire\ToolError;

/**
 * Writes the templates into an app, or reports how an app has drifted from them.
 *
 * "Depend, don't copy": the app keeps the files (Citadel needs them in the repository), but the manifest remembers
 * what SQUIRE wrote, so an update never overwrites a file the team edited, and `--check` says which files fell behind.
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
     *
     * @return list<FileResult>
     */
    public function run(string $appPath, string $target, array $groups, bool $force, bool $check, ?string $layer = null): array
    {
        if (!is_dir($appPath)) {
            throw new ToolError(sprintf('Not a directory: %s', $appPath));
        }

        $manifest = Manifest::load($appPath);
        $recorded = $manifest->files ?? [];
        $templates = $this->catalog->select($target, $groups, $layer);

        $results = [];
        $written = $recorded;

        foreach ($templates as $template) {
            $content = $this->catalog->content($template);
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
                ? 'edited since SQUIRE wrote it'
                : 'exists, but SQUIRE did not write it';

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

        // The .gitignore is not a template: SQUIRE appends the missing lines and records no hash, because once the
        // team edits it, it is theirs. In a directory below the repository root it is part of the layer SQUIRE wrote.
        $gitignoreResult = $this->gitignore->ensure($appPath, $this->writer, $check);
        if ($gitignoreResult !== null) {
            $results[] = $gitignoreResult;
        }

        if (!$check) {
            (new Manifest(Squire::version(), $target, $groups, $written, $layer))->save($appPath);
        }

        return $results;
    }
}

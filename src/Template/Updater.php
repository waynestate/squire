<?php

declare(strict_types=1);

namespace Waynestate\Squire\Template;

use Waynestate\Squire\Squire;
use Waynestate\Squire\ToolError;

/**
 * Brings the files Squire wrote up to the current templates, and lands the files a newer release added.
 *
 * The manifest is the source of truth for the files Squire wrote: a file the team never touched takes the new
 * template; a file they edited is left alone and shown as a diff, because only they can merge it. A template file
 * a newer release added to a recorded group is written, recorded in the manifest and printed as added. Files
 * Squire did not write are never touched.
 */
final class Updater
{
    public function __construct(
        private readonly Catalog $catalog,
        private readonly TemplateWriter $writer = new TemplateWriter(),
    ) {}

    /**
     * @param list<string> $groups
     *
     * @return list<FileResult>
     */
    public function run(string $appPath, string $target, array $groups, bool $dryRun, ?string $layer = null): array
    {
        if (!is_dir($appPath)) {
            throw new ToolError(sprintf('Not a directory: %s', $appPath));
        }

        $manifest = Manifest::load($appPath);
        if ($manifest === null) {
            throw new ToolError(sprintf('%s not found in %s. Run `squire init` first.', Squire::MANIFEST, $appPath));
        }

        $selected = $this->catalog->select($target, $groups, $layer);

        // The app's recorded line. A kit without the key predates it: its record was written on the legacy line,
        // so render there and an update never moves the app. If the record matches the default line's rendering
        // instead, the app is left on that line: moving it would be the very thing an update must not do.
        $phpLine = $manifest->php ?? $this->recordedLine($manifest, $selected);
        $byDestination = [];
        foreach ($selected as $template) {
            $byDestination[$template->destination] = $template;
        }
        $known = [];
        foreach (Catalog::all() as $template) {
            $known[$template->destination] = true;
        }

        $results = [];
        $written = $manifest->files;

        $files = $manifest->files;
        ksort($files);
        foreach ($files as $destination => $recordedHash) {
            $template = $byDestination[$destination] ?? null;
            if ($template === null) {
                if (isset($known[$destination])) {
                    continue; // outside this run's target or groups
                }
                $results[] = new FileResult($destination, FileResult::CURRENT, 'no longer in the template set; left as is');
                continue;
            }

            $content = $this->catalog->content($template, $phpLine);
            $newHash = Manifest::hash($content);
            $path = $appPath . '/' . $destination;

            if (!is_file($path)) {
                if (!$dryRun) {
                    $this->writer->write($path, $content, $template->executable);
                    $written[$destination] = $newHash;
                }
                $results[] = new FileResult(
                    $destination,
                    FileResult::RESTORED,
                    $dryRun ? 'missing; would be written from the template' : 'missing; written from the template',
                );
                continue;
            }

            $disk = file_get_contents($path);
            if ($disk === false) {
                throw new ToolError(sprintf('Cannot read %s.', $path));
            }
            $diskHash = Manifest::hash($disk);

            // The app's edit was taken upstream into the template: nothing to merge, adopt the new hash.
            if ($diskHash === $newHash) {
                if (!$dryRun && $recordedHash !== $newHash) {
                    $written[$destination] = $newHash;
                }
                $results[] = new FileResult($destination, FileResult::CURRENT, 'matches the current template');
                continue;
            }

            if ($newHash === $recordedHash) {
                $results[] = new FileResult(
                    $destination,
                    FileResult::CURRENT,
                    $diskHash === $recordedHash ? 'matches the template' : 'edited, but the template has not changed',
                );
                continue;
            }

            if ($diskHash === $recordedHash) {
                if (!$dryRun) {
                    $this->writer->write($path, $content, $template->executable);
                    $written[$destination] = $newHash;
                }
                $results[] = new FileResult(
                    $destination,
                    FileResult::UPDATED,
                    $dryRun ? 'untouched; would be updated to the current template' : 'updated to the current template',
                );
                continue;
            }

            $results[] = new FileResult(
                $destination,
                FileResult::EDITED,
                'edited since Squire wrote it, and the template has changed',
                Diff::unified($content, $disk, 'a/' . $destination . ' (template)', 'b/' . $destination . ' (on disk)'),
            );
        }

        // A newer release may have added a file to a group the kit records: write it, record it, and say so.
        // A file that is on disk but not in the record is the app's own: never touched, never reported.
        $added = [];
        foreach ($selected as $template) {
            if (isset($manifest->files[$template->destination])) {
                continue;
            }
            if (is_file($appPath . '/' . $template->destination)) {
                continue;
            }
            $content = $this->catalog->content($template, $phpLine);
            if (!$dryRun) {
                $this->writer->write($appPath . '/' . $template->destination, $content, $template->executable);
                $written[$template->destination] = Manifest::hash($content);
            }
            $added[$template->destination] = new FileResult(
                $template->destination,
                FileResult::ADDED,
                $dryRun ? 'not in the record; would be written from the template' : 'not in the record; written from the template',
            );
        }
        ksort($added);
        foreach ($added as $result) {
            $results[] = $result;
        }

        // The .gitignore never sits in the record, so update cannot touch it: once the team edits it, it is theirs.
        // It can still point out a hole in the wall, because the local secrets file must stay out of git.
        $gitignorePath = $appPath . '/.gitignore';
        $gitignore = null;
        if (is_file($gitignorePath)) {
            $gitignore = file_get_contents($gitignorePath);
            if ($gitignore === false) {
                throw new ToolError(sprintf('Cannot read %s.', $gitignorePath));
            }
        }
        if ($gitignore !== null && in_array('.env.squire', Gitignore::missingLines($gitignore), true)) {
            $results[] = new FileResult(
                '.gitignore',
                FileResult::CURRENT,
                'note: .env.squire is not ignored by .gitignore; add the line so the local secrets stay out of git',
            );
        }

        // Save when the files moved, or when the record still names an older release: a kit whose files already
        // match the newer release must not keep the old version.
        if (!$dryRun && ($written !== $manifest->files || $manifest->version !== Squire::version())) {
            (new Manifest(Squire::version(), $manifest->target, $manifest->groups, $written, $manifest->layer, $manifest->php))->save($appPath);
        }

        return $results;
    }

    /**
     * The line a kit without a php key was written on, read from its recorded hashes: the legacy line unless the
     * record matches the default line's rendering, in which case the default line.
     *
     * @param list<Template> $templates
     */
    private function recordedLine(Manifest $manifest, array $templates): string
    {
        foreach ([Squire::LEGACY_PHP_LINE, Squire::DEFAULT_PHP_LINE] as $line) {
            $matches = true;
            foreach ($templates as $template) {
                $recorded = $manifest->files[$template->destination] ?? null;
                if ($recorded !== null && $recorded !== Manifest::hash($this->catalog->content($template, $line))) {
                    $matches = false;
                    break;
                }
            }
            if ($matches) {
                return $line;
            }
        }

        return Squire::LEGACY_PHP_LINE;
    }

    /**
     * 0 when nothing is left for a human, 1 when an edited file still differs from the template.
     *
     * @param list<FileResult> $results
     */
    public static function exitCode(array $results): int
    {
        foreach ($results as $result) {
            if ($result->state === FileResult::EDITED) {
                return 1;
            }
        }

        return 0;
    }
}

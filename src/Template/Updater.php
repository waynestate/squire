<?php

declare(strict_types=1);

namespace Waynestate\Squire\Template;

use Waynestate\Squire\Squire;
use Waynestate\Squire\ToolError;

/**
 * Brings the files SQUIRE wrote up to the current templates.
 *
 * The manifest is the source of truth: `update` only looks at the files it lists. A file the team never touched
 * takes the new template; a file they edited is left alone and shown as a diff, because only they can merge it.
 * Files SQUIRE did not write are never touched.
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

            $content = $this->catalog->content($template);
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
                'edited since SQUIRE wrote it, and the template has changed',
                Diff::unified($content, $disk, 'a/' . $destination . ' (template)', 'b/' . $destination . ' (on disk)'),
            );
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

        if (!$dryRun && $written !== $manifest->files) {
            (new Manifest(Squire::version(), $manifest->target, $manifest->groups, $written, $manifest->layer))->save($appPath);
        }

        return $results;
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

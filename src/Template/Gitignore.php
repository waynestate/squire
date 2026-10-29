<?php

declare(strict_types=1);

namespace Waynestate\Squire\Template;

use Waynestate\Squire\ToolError;

/**
 * The .gitignore SQUIRE keeps honest in a directory it writes into: a deploy layer below the repository root.
 *
 * It only ever adds a line that is missing; it never moves, edits or deletes one the app wrote, because the moment
 * the team adds a line of their own the file is theirs to keep. The repository root's .gitignore is always the app's:
 * a squire minds the gate he was given, not the castle's.
 */
final class Gitignore
{
    /** The lines every managed .gitignore must carry, in the order they are appended. */
    public const LINES = ['/vendor/', '.env', '.env.squire'];

    /**
     * Whether SQUIRE tends to this directory's .gitignore: yes when the directory sits below the root of a git
     * checkout (a deploy layer inside a bigger repository), no when it is the root itself or no repository is above.
     */
    public static function isManaged(string $dir): bool
    {
        $current = rtrim($dir, '/\\');
        if (self::hasDotGit($current)) {
            return false; // the repository root's .gitignore is the app's
        }
        $depth = 0;
        while ($depth < 64) {
            $parent = dirname($current);
            if ($parent === $current) {
                return false; // walked to the filesystem root without finding a repository
            }
            if (self::hasDotGit($parent)) {
                return true;
            }
            $current = $parent;
            $depth++;
        }

        return false;
    }

    /**
     * The required lines that are missing from the content, in the order they would be appended.
     *
     * @return list<string>
     */
    public static function missingLines(?string $content): array
    {
        $have = [];
        foreach (preg_split('/\R/', $content ?? '') ?: [] as $line) {
            $have[trim($line)] = true;
        }

        return array_values(array_filter(self::LINES, static fn(string $line): bool => !isset($have[$line])));
    }

    /**
     * Make sure the directory's .gitignore carries every required line, appending only what is missing.
     * Returns null when the directory is not managed, so the caller reports nothing for the file.
     */
    public function ensure(string $dir, TemplateWriter $writer, bool $check): ?FileResult
    {
        if (!self::isManaged($dir)) {
            return null;
        }

        $path = $dir . '/.gitignore';
        $disk = null;
        if (is_file($path)) {
            $disk = file_get_contents($path);
            if ($disk === false) {
                throw new ToolError(sprintf('Cannot read %s.', $path));
            }
        }

        $missing = self::missingLines($disk);
        if ($missing === []) {
            return new FileResult('.gitignore', $check ? FileResult::OK : FileResult::UNCHANGED, 'ignores vendor and the local env files');
        }

        if ($check) {
            return new FileResult(
                '.gitignore',
                $disk === null ? FileResult::MISSING : FileResult::MODIFIED,
                $disk === null
                    ? 'not in the app; Squire would create it ignoring ' . implode(', ', $missing)
                    : 'does not ignore ' . implode(', ', $missing) . '; run `squire init` to add the lines',
            );
        }

        $content = $disk ?? '';
        if ($content !== '' && !str_ends_with($content, "\n")) {
            $content .= "\n";
        }
        foreach ($missing as $line) {
            $content .= $line . "\n";
        }
        $writer->write($path, $content, false);

        return new FileResult(
            '.gitignore',
            $disk === null ? FileResult::CREATED : FileResult::UPDATED,
            $disk === null
                ? 'created, ignoring ' . implode(', ', $missing)
                : 'appended ' . implode(', ', $missing) . ' to the existing lines',
        );
    }

    private static function hasDotGit(string $dir): bool
    {
        return is_dir($dir . '/.git') || is_file($dir . '/.git');
    }
}

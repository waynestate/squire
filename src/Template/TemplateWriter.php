<?php

declare(strict_types=1);

namespace Waynestate\Squire\Template;

use Waynestate\Squire\ToolError;

/**
 * Writes one template file into an app: the directory, the content, and the executable bit where the platform has one.
 * Shared by `init` and `update`, so both write a file the same way.
 */
final class TemplateWriter
{
    public function write(string $path, string $content, bool $executable): void
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0o755, true) && !is_dir($dir)) {
            throw new ToolError(sprintf('Cannot create directory %s.', $dir));
        }
        if (file_put_contents($path, $content) === false) {
            throw new ToolError(sprintf('Cannot write %s.', $path));
        }
        if ($executable) {
            // Windows ignores the mode; Git needs `git update-index --chmod=+x`, which init prints a hint about.
            chmod($path, 0o755);
        }
    }
}

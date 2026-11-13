<?php

declare(strict_types=1);

namespace Waynestate\Squire\Site;

/**
 * Drives bin/docs-site.php against a root directory and returns its exit code unchanged: 0 when the docs site is
 * built or already clean, 1 when --check found drift or findings, 2 when the tool itself could not run. This is
 * the docs half of `squire site build` (ADR-0023, batch 0); the command that wraps it comes in a later slice.
 * The script's own stdout and stderr pass straight through to the caller, so a build driven from here reads like
 * a direct run of the script.
 */
final class DocsGenerator
{
    private function __construct() {}

    /**
     * Runs the docs-site generator against $root, the way a repo's CI runs it: Squire's PHP, the script from
     * Squire's own package directory, the root passed as --root, and the working directory set to the root.
     *
     * @param array{check?: bool, block-dir?: string} $options check passes --check, which writes nothing and
     *        reports drift instead; block-dir passes --block-dir, the folder docs.json block commands run from
     */
    public static function run(string $root, array $options = []): int
    {
        $script = dirname(__DIR__, 2) . '/bin/docs-site.php';
        if (!is_file($script)) {
            fwrite(STDERR, 'squire site: bin/docs-site.php is missing from Squire; the docs generator cannot run.' . "\n");

            return 2;
        }
        if (!is_dir($root)) {
            fwrite(STDERR, 'squire site: the folder ' . $root . ' does not exist.' . "\n");

            return 2;
        }

        return self::runScript($script, $root, $options);
    }

    /**
     * Launches the generator with PHP_BINARY, cwd $root, and its stdout and stderr handed to the caller's
     * streams. A generator that cannot even start is a tool error (2), the same code the scripts themselves use
     * when they fail.
     *
     * @param array{check?: bool, block-dir?: string} $options
     */
    private static function runScript(string $script, string $root, array $options): int
    {
        $command = [PHP_BINARY, $script, '--root=' . $root];
        if ((bool) ($options['check'] ?? false)) {
            $command[] = '--check';
        }
        $blockDir = $options['block-dir'] ?? null;
        if (is_string($blockDir) && $blockDir !== '') {
            $command[] = '--block-dir=' . $blockDir;
        }
        $process = @proc_open(
            $command,
            [1 => ['file', 'php://stdout', 'w'], 2 => ['file', 'php://stderr', 'w']],
            $pipes,
            $root,
        );
        if (!is_resource($process)) {
            fwrite(STDERR, 'squire site: cannot start ' . basename($script) . ' against ' . $root . '.' . "\n");

            return 2;
        }

        return proc_close($process);
    }
}

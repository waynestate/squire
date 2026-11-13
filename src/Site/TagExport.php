<?php

declare(strict_types=1);

namespace Waynestate\Squire\Site;

use Waynestate\Squire\ToolError;

/**
 * Exports the full tree of a pinned tag from a local git repository into a folder. ADR-0023.
 *
 * Reads the tree with ls-tree and each blob with cat-file instead of git archive: archive drops the files that
 * .gitattributes marks export-ignore (CHANGELOG.md, README.md), and the site build needs them. No network: it only
 * reads the repository path it is given.
 */
final class TagExport
{
    private function __construct() {}

    /**
     * Writes every file of $tag in $repo under $destDir at its tree path, creating folders. Returns the number of
     * files written. Skips submodule entries (mode 160000) and keeps symlink entries as regular files holding the
     * link text.
     */
    public static function export(string $repo, string $tag, string $destDir): int
    {
        $commit = self::resolve($repo, $tag);
        if (!is_dir($destDir) && !mkdir($destDir, 0o755, true) && !is_dir($destDir)) {
            throw new ToolError(sprintf('Cannot create directory %s.', $destDir));
        }
        $written = 0;
        foreach (self::lsTree($repo, $commit) as [$type, $sha, $path]) {
            if ($type !== 'blob') {
                continue; // submodule (commit) and other non-blob entries have no content to write
            }
            $content = self::catBlob($repo, $sha);
            self::writeFile($destDir, $path, $content);
            $written++;
        }

        return $written;
    }

    /** Resolves $tag to its commit. Throws when the tag does not exist. */
    private static function resolve(string $repo, string $tag): string
    {
        $result = self::run(['git', '-C', $repo, 'rev-parse', '--verify', '--quiet', $tag . '^' . '{commit}']);
        if ($result['code'] !== 0 || trim($result['stdout']) === '') {
            throw new ToolError(sprintf('Unknown tag %s in %s.', $tag, $repo));
        }

        return trim($result['stdout']);
    }

    /**
     * The blobs of $commit: [type, sha, path] per entry, in tree order.
     *
     * @return list<array{string, string, string}>
     */
    private static function lsTree(string $repo, string $commit): array
    {
        $result = self::run(['git', '-C', $repo, 'ls-tree', '-r', '-z', $commit]);
        if ($result['code'] !== 0) {
            throw new ToolError(sprintf('Cannot list the tree of %s in %s: %s', $commit, $repo, trim($result['stderr'])));
        }
        $entries = [];
        foreach (explode("\0", $result['stdout']) as $line) {
            if ($line === '') {
                continue;
            }
            // One entry per NUL-separated record: "<mode> SP <type> SP <sha> TAB <path>".
            $tab = strpos($line, "\t");
            if ($tab === false) {
                throw new ToolError(sprintf('Unexpected ls-tree record in %s: %s', $repo, $line));
            }
            $meta = explode(' ', substr($line, 0, $tab));
            if (count($meta) !== 3) {
                throw new ToolError(sprintf('Unexpected ls-tree record in %s: %s', $repo, $line));
            }
            $entries[] = [$meta[1], $meta[2], substr($line, $tab + 1)];
        }

        return $entries;
    }

    private static function catBlob(string $repo, string $sha): string
    {
        $result = self::run(['git', '-C', $repo, 'cat-file', 'blob', $sha]);
        if ($result['code'] !== 0) {
            throw new ToolError(sprintf('Cannot read blob %s in %s: %s', $sha, $repo, trim($result['stderr'])));
        }

        return $result['stdout'];
    }

    private static function writeFile(string $destDir, string $path, string $content): void
    {
        $full = $destDir . '/' . $path;
        $dir = dirname($full);
        if (!is_dir($dir) && !mkdir($dir, 0o755, true) && !is_dir($dir)) {
            throw new ToolError(sprintf('Cannot create directory %s.', $dir));
        }
        if (file_put_contents($full, $content) === false) {
            throw new ToolError(sprintf('Cannot write %s.', $full));
        }
    }

    /** Runs a command with an argument array (never a shell string) and captures its output. */
    /**
     * @param list<string> $command
     *
     * @return array{code: int, stdout: string, stderr: string}
     */
    private static function run(array $command): array
    {
        $process = @proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            throw new ToolError(sprintf('Cannot run %s.', $command[0]));
        }
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['code' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
    }
}

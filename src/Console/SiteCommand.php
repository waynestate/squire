<?php

declare(strict_types=1);

namespace Waynestate\Squire\Console;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Waynestate\Squire\Site\AgentReadyGenerator;
use Waynestate\Squire\Site\DocsGenerator;
use Waynestate\Squire\Site\Pin;
use Waynestate\Squire\Site\TagExport;
use Waynestate\Squire\ToolError;

/**
 * `squire site build`: reads the site repo's pin, exports the pinned tag of the library from a local git
 * repository, lays the site's own docs/ and site/ folders over the export, runs the docs and agent-ready
 * generators, and replaces the output folder with the built site plus a sha256 manifest.json (ADR-0023).
 */
final class SiteCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('site')
            ->setDescription('Builds the docs site and agent-ready files from the pinned library tag, with a sha256 manifest')
            ->addArgument('action', InputArgument::OPTIONAL, 'The site action to run; build for now.', 'build')
            ->addOption('root', null, InputOption::VALUE_REQUIRED, 'Path to the site repository.', '.')
            ->addOption('pin', null, InputOption::VALUE_REQUIRED, 'The site pin, relative to --root.', 'site.json')
            ->addOption('out', null, InputOption::VALUE_REQUIRED, 'The output folder, relative to --root.', 'public')
            ->addOption('repo', null, InputOption::VALUE_REQUIRED, 'A local git repository of the library; when empty, vendor/waynestate/NAME under --root, NAME being the part of the library after the last slash.')
            ->addOption('base', null, InputOption::VALUE_REQUIRED, 'Accepted for a later remote fetch; unused for now.')
            ->addOption('check', null, InputOption::VALUE_NONE, 'Builds into a temp folder and compares it with the output folder, printing one line per finding and writing nothing.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $errors = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        $action = (string) $input->getArgument('action');
        $root = rtrim(str_replace('\\', '/', (string) $input->getOption('root')), '/');
        $outOption = (string) $input->getOption('out');
        $repoOption = (string) $input->getOption('repo');
        $check = (bool) $input->getOption('check');

        try {
            if ($action !== 'build') {
                throw new ToolError(sprintf('Unknown site action "%s"; the only action is build.', $action));
            }
            if (!is_dir($root)) {
                throw new ToolError(sprintf('Path "%s" is not a directory.', $root));
            }
            $pin = Pin::fromFile($root . '/' . ltrim(str_replace('\\', '/', (string) $input->getOption('pin')), '/'));
            $library = $pin->library();
            $name = str_contains($library, '/') ? substr($library, strrpos($library, '/') + 1) : $library;
            $repo = $repoOption !== '' ? str_replace('\\', '/', $repoOption) : $root . '/vendor/waynestate/' . $name;
            if (!self::isGitRepo($repo)) {
                throw new ToolError(sprintf('No git repository for %s at %s. Pass --repo=<path> to point at the library.', $library, $repo));
            }
            $out = self::inside($root, $outOption);
            if ($out === null) {
                throw new ToolError(sprintf('--out "%s" must name a folder inside --root.', $outOption));
            }

            $tmp = sys_get_temp_dir() . '/squire-site-' . bin2hex(random_bytes(6));
            $files = [];
            try {
                TagExport::export($repo, $pin->tag(), $tmp);
                // The site repo's own authored files win over the exported ones.
                self::copyOver($root . '/docs', $tmp . '/docs');
                self::copyOver($root . '/site', $tmp . '/site');
                // The pin's files: data the site owns outside docs/ and site/, laid over the export too.
                self::copyPinFiles($root, $tmp, $pin->files());
                // Blocks that run commands need the library as the site repo installed it with Composer.
                $blockDir = self::blockCommandsDir($tmp, $root, $name, $pin);
                $docsExit = DocsGenerator::run($tmp, $blockDir === null ? [] : ['block-dir' => $blockDir]);
                if ($docsExit !== 0) {
                    throw new ToolError(sprintf('The docs generator failed with exit %d; see its output above.', $docsExit));
                }
                $readyExit = AgentReadyGenerator::run($tmp);
                if ($readyExit !== 0) {
                    throw new ToolError(sprintf('The agent-ready generator failed with exit %d; see its output above.', $readyExit));
                }
                if (!is_dir($tmp . '/site')) {
                    throw new ToolError('The build produced no site/ folder; nothing to write.');
                }
                if ($check) {
                    // Compare the fresh build plus the manifest it would write with the committed output; write nothing.
                    return self::checkTree($output, $tmp . '/site', $out, $pin);
                }
                if (is_dir($out)) {
                    self::removeTree($out);
                } elseif (is_file($out) && unlink($out) === false) {
                    throw new ToolError(sprintf('Cannot remove %s.', $out));
                }
                self::copyOver($tmp . '/site', $out);
                $files = self::manifestFiles($out);
                $manifest = json_encode([
                    'library' => $pin->library(),
                    'tag' => $pin->tag(),
                    'files' => $files,
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
                if (file_put_contents($out . '/manifest.json', $manifest) === false) {
                    throw new ToolError(sprintf('Cannot write %s.', $out . '/manifest.json'));
                }
            } finally {
                self::removeTree($tmp);
            }

            $output->writeln(sprintf('Built %d files from %s %s into %s.', count($files), $pin->library(), $pin->tag(), $outOption));

            return 0;
        } catch (ToolError $e) {
            $errors->writeln($e->getMessage());

            return 2;
        }
    }

    /** Whether $path is a git repository: a folder carrying a .git folder or file. */
    private static function isGitRepo(string $path): bool
    {
        return is_dir($path) && (is_dir($path . '/.git') || is_file($path . '/.git'));
    }

    /**
     * Copies every file under $src into $dst, keeping relative paths and creating folders. Files already in $dst
     * are overwritten, so the caller sets the precedence by the order of the calls. A missing $src is a no-op.
     */
    private static function copyOver(string $src, string $dst): void
    {
        if (!is_dir($src)) {
            return;
        }
        $scan = static function (string $path, string $prefix) use (&$scan, $dst): void {
            $entries = scandir($path);
            if ($entries === false) {
                throw new ToolError(sprintf('Cannot read %s.', $path));
            }
            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $full = $path . '/' . $entry;
                $relative = $prefix === '' ? $entry : $prefix . '/' . $entry;
                if (is_dir($full)) {
                    $scan($full, $relative);
                    continue;
                }
                if (!is_file($full)) {
                    continue;
                }
                $target = $dst . '/' . $relative;
                $dir = dirname($target);
                if (!is_dir($dir) && !mkdir($dir, 0o755, true) && !is_dir($dir)) {
                    throw new ToolError(sprintf('Cannot create directory %s.', $dir));
                }
                if (copy($full, $target) === false) {
                    throw new ToolError(sprintf('Cannot copy %s to %s.', $full, $target));
                }
            }
        };
        $scan($src, '');
    }

    /**
     * Copies each path the pin's files key names from the site repo into the export tree (ADR-0023, slice 5): a
     * file, or a folder copied whole, so the site's own data wins over exported files at the same path. A path that
     * leaves the root or does not exist is a tool error naming it.
     *
     * @param list<string> $entries
     */
    private static function copyPinFiles(string $root, string $tmp, array $entries): void
    {
        foreach ($entries as $entry) {
            $normalized = str_replace('\\', '/', $entry);
            $source = self::inside($root, $normalized);
            if ($source === null) {
                throw new ToolError(sprintf('The pin\'s files entry "%s" must name a path inside the site repo.', $entry));
            }
            if (!is_file($source) && !is_dir($source)) {
                throw new ToolError(sprintf('The pin\'s files entry "%s" does not exist in the site repo.', $entry));
            }
            $relative = self::relativeUnder($root, $normalized);
            if (is_dir($source)) {
                self::copyOver($source, $tmp . '/' . $relative);
                continue;
            }
            $target = $tmp . '/' . $relative;
            $dir = dirname($target);
            if (!is_dir($dir) && !mkdir($dir, 0o755, true) && !is_dir($dir)) {
                throw new ToolError(sprintf('Cannot create directory %s.', $dir));
            }
            if (copy($source, $target) === false) {
                throw new ToolError(sprintf('Cannot copy %s to %s.', $source, $target));
            }
        }
    }

    /**
     * The folder docs.json block commands run from (ADR-0023, slice 5): when the exported docs.json has a block
     * with a non-empty run command, the library as the site repo installed it with Composer,
     * <root>/vendor/waynestate/<name>; a missing folder is a tool error naming the blocks and the composer
     * require line. Null for a docs.json without run commands: a theme-only build needs no vendor.
     */
    private static function blockCommandsDir(string $tmp, string $root, string $name, Pin $pin): ?string
    {
        $path = $tmp . '/docs/docs.json';
        if (!is_file($path)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($path), true);
        $blocks = is_array($data) && isset($data['blocks']) && is_array($data['blocks']) ? $data['blocks'] : [];
        $running = [];
        foreach ($blocks as $blockName => $block) {
            if (is_array($block) && isset($block['run']) && is_string($block['run']) && trim($block['run']) !== '') {
                $running[] = (string) $blockName;
            }
        }
        if ($running === []) {
            return null;
        }
        $dir = $root . '/vendor/waynestate/' . $name;
        if (!is_dir($dir)) {
            $version = str_starts_with($pin->tag(), 'v') ? substr($pin->tag(), 1) : $pin->tag();
            throw new ToolError(sprintf(
                'docs.json runs block commands (%s), so the site repo needs the library installed: composer require waynestate/%s:%s.',
                implode(', ', $running),
                $name,
                $version,
            ));
        }

        return $dir;
    }

    /**
     * The path of $relative under $root once the . and .. segments are resolved, exactly as inside() resolves
     * them; the caller must have checked inside() first. Forward slashes only.
     */
    private static function relativeUnder(string $root, string $relative): string
    {
        $prefix = '';
        if (preg_match('/^([A-Za-z]:\/)/', $root, $matches) === 1) {
            $prefix = $matches[1];
        } elseif (str_starts_with($root, '/')) {
            $prefix = '/';
        }
        $body = substr($root, strlen($prefix));
        $segments = [];
        foreach (explode('/', $body . '/' . $relative) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($segments);
                continue;
            }
            $segments[] = $segment;
        }
        $rootSegments = array_values(array_filter(explode('/', $body), static fn(string $segment): bool => $segment !== '' && $segment !== '.'));

        return implode('/', array_slice($segments, count($rootSegments)));
    }

    /**
     * The --check comparison (ADR-0023, slice 4): the freshly built site folder plus the manifest it would write,
     * byte-compared with the committed output folder. One line per finding, paths sorted and in forward slashes:
     * "missing: <path>" for a file the build has that the output lacks, "extra: <path>" for a file the output has
     * that the build lacks, "drift: <path>" for the same path with different bytes. Exit 1 when any finding is
     * printed, 0 otherwise. Never writes, deletes or touches anything under the root.
     */
    private static function checkTree(OutputInterface $output, string $freshDir, string $out, Pin $pin): int
    {
        $files = self::manifestFiles($freshDir);
        $manifest = json_encode([
            'library' => $pin->library(),
            'tag' => $pin->tag(),
            'files' => $files,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        $fresh = $files;
        $fresh['manifest.json'] = 'sha256:' . hash('sha256', $manifest);
        ksort($fresh);
        $committed = is_dir($out) ? self::treeFiles($out) : [];
        $paths = array_unique(array_merge(array_keys($fresh), array_keys($committed)));
        sort($paths);
        $findings = [];
        foreach ($paths as $path) {
            if (!isset($committed[$path])) {
                $findings[] = 'missing: ' . $path;
            } elseif (!isset($fresh[$path])) {
                $findings[] = 'extra: ' . $path;
            } elseif ($fresh[$path] !== $committed[$path]) {
                $findings[] = 'drift: ' . $path;
            }
        }
        if ($findings !== []) {
            foreach ($findings as $finding) {
                $output->writeln($finding);
            }

            return 1;
        }
        $output->writeln(sprintf('Site output is current: %d file(s).', count($fresh)));

        return 0;
    }

    /**
     * The manifest entries for a built site folder: every file except manifest.json itself, as a sorted map of
     * forward-slash path to sha256 digest.
     *
     * @return array<string, string>
     */
    private static function manifestFiles(string $out): array
    {
        $files = self::treeFiles($out);
        unset($files['manifest.json']);

        return $files;
    }

    /**
     * Every file under $dir as a sorted map of forward-slash relative path to sha256 digest, manifest.json
     * included.
     *
     * @return array<string, string>
     */
    private static function treeFiles(string $dir): array
    {
        $files = [];
        $scan = static function (string $path, string $prefix) use (&$scan, &$files): void {
            $entries = scandir($path);
            if ($entries === false) {
                throw new ToolError(sprintf('Cannot read %s.', $path));
            }
            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $full = $path . '/' . $entry;
                $relative = $prefix === '' ? $entry : $prefix . '/' . $entry;
                if (is_dir($full)) {
                    $scan($full, $relative);
                    continue;
                }
                if (!is_file($full)) {
                    continue;
                }
                $content = file_get_contents($full);
                if ($content === false) {
                    throw new ToolError(sprintf('Cannot read %s.', $relative));
                }
                $files[$relative] = 'sha256:' . hash('sha256', $content);
            }
        };
        $scan($dir, '');
        ksort($files);

        return $files;
    }

    /**
     * Resolves $relative against $root and returns the joined path with the root's prefix kept: a Windows drive
     * letter (C: or c:, followed by /), a leading / for an absolute root, or nothing for a relative root; forward
     * slashes only. Null when $relative is absolute (starts with / or a drive letter), names the root itself, or
     * climbs to the root or above through .. segments. Purely lexical: the output may not exist yet.
     */
    public static function inside(string $root, string $relative): ?string
    {
        if (str_starts_with($relative, '/') || preg_match('/^[A-Za-z]:/', $relative) === 1) {
            return null;
        }
        $prefix = '';
        if (preg_match('/^([A-Za-z]:\/)/', $root, $matches) === 1) {
            $prefix = $matches[1];
        } elseif (str_starts_with($root, '/')) {
            $prefix = '/';
        }
        $body = substr($root, strlen($prefix));
        $segments = [];
        foreach (explode('/', $body . '/' . $relative) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($segments);
                continue;
            }
            $segments[] = $segment;
        }
        $rootSegments = array_values(array_filter(explode('/', $body), static fn(string $segment): bool => $segment !== '' && $segment !== '.'));
        if (count($segments) <= count($rootSegments) || array_slice($segments, 0, count($rootSegments)) !== $rootSegments) {
            return null;
        }

        return $prefix . implode('/', $segments);
    }

    /** Removes a folder tree; a missing folder is fine. */
    private static function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            if (!$item instanceof SplFileInfo) {
                continue;
            }
            $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }
}

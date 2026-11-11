<?php

declare(strict_types=1);

namespace Waynestate\Squire\Console;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Waynestate\Squire\Facts\Regions;
use Waynestate\Squire\Facts\Snapshot;
use Waynestate\Squire\ToolError;

/**
 * `squire facts`: fills the marked squire-facts regions from the committed .squire/facts.json snapshot, or with
 * --check reports the drift for CI without writing a byte (D-109). The walk is deliberately simple: vendor and
 * friends are nobody's business here.
 */
final class FactsCommand extends Command
{
    /** @var list<string> the extensions that may carry markers */
    private const EXTENSIONS = ['md', 'html', 'htm', 'js', 'mjs', 'cjs', 'css', 'yml', 'yaml'];

    protected function configure(): void
    {
        $this
            ->setName('facts')
            ->setDescription('Fills the squire-facts regions from .squire/facts.json (--check: report drift, write nothing)')
            ->addOption('check', null, InputOption::VALUE_NONE, 'Write nothing; report regions that are out of date. Exit 1 on drift.')
            ->addOption('path', null, InputOption::VALUE_REQUIRED, 'Path to the folder holding .squire/facts.json.', '.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $errors = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        $check = (bool) $input->getOption('check');
        $root = (string) $input->getOption('path');

        try {
            if (!is_dir($root)) {
                throw new ToolError(sprintf('Path "%s" is not a directory.', $root));
            }

            // Walk once, parse only what could hold a marker, keep what actually does.
            /** @var list<array{rel: string, abs: string, ext: string, content: string, regions: list<array{key: string, line: int, indent: string, bodyStart: int, bodyEnd: int}>}> $files */
            $files = [];
            foreach ($this->candidateFiles($root) as $file) {
                $content = file_get_contents($file['abs']);
                if ($content === false) {
                    throw new ToolError(sprintf('Cannot read %s.', $file['rel']));
                }
                if (!str_contains($content, 'squire-facts:')) {
                    continue;
                }
                $regions = Regions::parse($content, $file['ext'], $file['rel']);
                if ($regions === []) {
                    continue;
                }
                $files[] = $file + ['content' => $content, 'regions' => $regions];
            }

            $snapshotFile = $root . '/.squire/facts.json';
            if ($files === []) {
                if (!is_file($snapshotFile)) {
                    $output->writeln('squire facts: no .squire/facts.json and no regions; nothing to do');
                    return 0;
                }
                $output->writeln($check ? 'squire facts: 0 region(s) in step' : 'squire facts: filled 0 region(s) in 0 file(s)');
                return 0;
            }

            $snapshot = Snapshot::load($root);

            // A key the snapshot does not know about is a tool error (exit 2), not a finding: the machine points
            // at the exact spot and waits for a human.
            foreach ($files as $file) {
                foreach ($file['regions'] as $region) {
                    if (!$snapshot->has($region['key'])) {
                        throw new ToolError(sprintf('%s:%d: squire-facts region "%s" has no key in .squire/facts.json.', $file['rel'], $region['line'], $region['key']));
                    }
                }
            }

            $total = 0;
            $filled = 0;
            $written = 0;
            $drifted = [];
            foreach ($files as $file) {
                $total += count($file['regions']);
                $result = Regions::fill($file['content'], $file['ext'], $snapshot->all());
                if ($result['changed'] === []) {
                    continue;
                }
                $filled += count($result['changed']);
                $written++;
                foreach ($result['changed'] as $changed) {
                    $drifted[] = sprintf('%s:%d: %s is out of date (run vendor/bin/squire facts)', $file['rel'], $changed['line'], $changed['key']);
                }
                if (!$check && file_put_contents($file['abs'], $result['content']) === false) {
                    throw new ToolError(sprintf('Cannot write %s.', $file['rel']));
                }
            }

            if ($check) {
                if ($drifted !== []) {
                    foreach ($drifted as $line) {
                        $output->writeln($line);
                    }
                    return 1;
                }
                $output->writeln(sprintf('squire facts: %d region(s) in step', $total));
                return 0;
            }

            $output->writeln(sprintf('squire facts: filled %d region(s) in %d file(s)', $filled, $written));

            return 0;
        } catch (ToolError $e) {
            $errors->writeln($e->getMessage());

            return 2;
        }
    }

    /**
     * Every file under $root with a marker-capable extension, in path order. Skips vendor, node_modules, .git and
     * site, plus any other dot-folder except .github and .gitlab.
     *
     * @return list<array{rel: string, abs: string, ext: string}>
     */
    private function candidateFiles(string $root): array
    {
        $found = [];
        $scan = function (string $path, string $prefix) use (&$scan, &$found): void {
            $entries = scandir($path);
            if ($entries === false) {
                throw new ToolError(sprintf('Cannot read %s.', $prefix === '' ? $path : $prefix));
            }
            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $relative = $prefix === '' ? $entry : $prefix . '/' . $entry;
                $full = $path . '/' . $entry;
                if (is_dir($full) && !is_link($full)) {
                    if ($this->skippedFolder($entry)) {
                        continue;
                    }
                    $scan($full, $relative);
                    continue;
                }
                if (!is_file($full)) {
                    continue;
                }
                $extension = strtolower(pathinfo($entry, PATHINFO_EXTENSION));
                if (in_array($extension, self::EXTENSIONS, true)) {
                    $found[] = ['rel' => $relative, 'abs' => $full, 'ext' => $extension];
                }
            }
        };
        $scan($root, '');
        usort($found, static fn(array $a, array $b): int => strcmp($a['rel'], $b['rel']));

        return $found;
    }

    /** Whether the walk stays out of this folder: vendor, node_modules, .git, site, and any other dot-folder. */
    private function skippedFolder(string $name): bool
    {
        if (in_array($name, ['vendor', 'node_modules', '.git', 'site'], true)) {
            return true;
        }

        return str_starts_with($name, '.') && !in_array($name, ['.github', '.gitlab'], true);
    }
}

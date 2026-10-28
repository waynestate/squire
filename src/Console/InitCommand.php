<?php

declare(strict_types=1);

namespace Waynestate\Squire\Console;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Waynestate\Squire\Squire;
use Waynestate\Squire\Template\Catalog;
use Waynestate\Squire\Template\FileResult;
use Waynestate\Squire\Template\Initializer;
use Waynestate\Squire\Template\Template;
use Waynestate\Squire\ToolError;

/**
 * `squire init`: write the Citadel scripts into an app, or with --check report how they drifted.
 */
final class InitCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('init')
            ->setDescription('Write the standard Citadel scripts into an app, or report drift with --check.')
            ->addOption('path', null, InputOption::VALUE_REQUIRED, 'Path to the application.', '.')
            ->addOption('target', null, InputOption::VALUE_REQUIRED, 'raw or laravel (default: laravel when an artisan file exists).')
            ->addOption('only', null, InputOption::VALUE_REQUIRED, 'Comma-separated groups to write: release, parity.', 'release,parity')
            ->addOption('check', null, InputOption::VALUE_NONE, 'Write nothing; report files that are missing, outdated or edited. Exit 1 on drift.')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Overwrite files that were edited or that SQUIRE did not write.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $path = rtrim((string) $input->getOption('path'), '/\\');
        if ($path === '') {
            $path = '.';
        }
        $check = (bool) $input->getOption('check');
        $force = (bool) $input->getOption('force');

        $layer = null;
        try {
            if (!is_dir($path)) {
                throw new ToolError(sprintf('Not a directory: %s', $path));
            }
            $targetOption = $input->getOption('target');
            $target = is_string($targetOption) && $targetOption !== '' ? $targetOption : Catalog::detectTarget($path);
            $groups = array_values(array_filter(array_map('trim', explode(',', (string) $input->getOption('only'))), static fn(string $g): bool => $g !== ''));
            if ($groups === []) {
                throw new ToolError('--only needs at least one group: release, parity.');
            }

            $layer = Catalog::detectLayer($path);
            $results = (new Initializer(new Catalog(Squire::templatesDir())))->run($path, $target, $groups, $force, $check, $layer);
        } catch (ToolError $e) {
            $this->errorOutput($output)->writeln($e->getMessage());

            return 2;
        }

        foreach ($results as $result) {
            $output->writeln(sprintf('%-17s %s  (%s)', $result->state, $result->path, $result->detail));
        }

        $drift = array_filter($results, static fn(FileResult $r): bool => !$r->isCurrent());

        if ($check) {
            $output->writeln(sprintf('RESULT: %s (%d of %d files need attention)', $drift === [] ? 'pass' : 'drift', count($drift), count($results)));

            return $drift === [] ? 0 : 1;
        }

        $output->writeln(sprintf('Target: %s. Wrote %s.', $target, Squire::MANIFEST));
        if ($layer !== null) {
            $output->writeln(sprintf('Layer: %s.', $layer));
        }
        $executables = array_map(
            static fn(Template $t): string => $t->destination,
            array_filter((new Catalog(Squire::templatesDir()))->select($target, $groups, $layer), static fn(Template $t): bool => $t->executable),
        );
        $output->writeln('Next: git update-index --chmod=+x ' . implode(' ', $executables));
        $output->writeln('      (Git on Windows does not record the executable bit; Citadel also marks deployments/ and Quick Update scripts executable.)');

        return 0;
    }

    private function errorOutput(OutputInterface $output): OutputInterface
    {
        return $output instanceof \Symfony\Component\Console\Output\ConsoleOutputInterface ? $output->getErrorOutput() : $output;
    }
}

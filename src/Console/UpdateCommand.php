<?php

declare(strict_types=1);

namespace Waynestate\Squire\Console;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Waynestate\Squire\Squire;
use Waynestate\Squire\Template\Catalog;
use Waynestate\Squire\Template\FileResult;
use Waynestate\Squire\Template\Manifest;
use Waynestate\Squire\Template\Updater;
use Waynestate\Squire\ToolError;

/**
 * `squire update`: take the new templates for the files the team never edited; show a diff for the ones they did.
 * Never interactive: it runs the same in CI and on a laptop.
 */
final class UpdateCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('update')
            ->setDescription('Take new template versions for files SQUIRE wrote that were never edited; show a diff for the edited ones.')
            ->addOption('path', null, InputOption::VALUE_REQUIRED, 'Path to the application.', '.')
            ->addOption('target', null, InputOption::VALUE_REQUIRED, 'raw, laravel or static (default: the target recorded in .squire.json).')
            ->addOption('only', null, InputOption::VALUE_REQUIRED, 'Comma-separated groups to update: release, parity. (default: the groups recorded in .squire.json)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Print the plan; write nothing.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $path = rtrim((string) $input->getOption('path'), '/\\');
        if ($path === '') {
            $path = '.';
        }
        $dryRun = (bool) $input->getOption('dry-run');

        $layer = null;
        try {
            if (!is_dir($path)) {
                throw new ToolError(sprintf('Not a directory: %s', $path));
            }
            $manifest = Manifest::load($path);
            if ($manifest === null) {
                throw new ToolError(sprintf('%s not found in %s. Run `squire init` first.', Squire::MANIFEST, $path));
            }
            $targetOption = $input->getOption('target');
            $target = is_string($targetOption) && $targetOption !== '' ? $targetOption : $manifest->target;
            $onlyOption = $input->getOption('only');
            if (is_string($onlyOption) && $onlyOption !== '') {
                $groups = array_values(array_filter(array_map('trim', explode(',', $onlyOption)), static fn(string $g): bool => $g !== ''));
                if ($groups === []) {
                    throw new ToolError('--only needs at least one group: release, parity.');
                }
            } else {
                $groups = $manifest->groups !== [] ? $manifest->groups : Catalog::GROUPS;
            }

            $layer = $manifest->layer ?? Catalog::detectLayer($path);
            $results = (new Updater(new Catalog(Squire::templatesDir())))->run($path, $target, $groups, $dryRun, $layer);
        } catch (ToolError $e) {
            $this->errorOutput($output)->writeln($e->getMessage());

            return 2;
        }

        if ($dryRun) {
            $output->writeln('Dry run: nothing will be written.');
        }
        $output->writeln(sprintf('Target: %s.', $target));
        if ($layer !== null) {
            $output->writeln(sprintf('Layer: %s.', $layer));
        }
        foreach ($results as $result) {
            $output->writeln(sprintf('%-21s %s  (%s)', $result->state, $result->path, $result->detail));
            if ($result->diff !== null) {
                $output->write($result->diff);
            }
        }

        $edited = array_filter($results, static fn(FileResult $r): bool => $r->state === FileResult::EDITED);
        $output->writeln(sprintf('RESULT: %s (%d of %d files need attention)', $edited === [] ? 'pass' : 'drift', count($edited), count($results)));

        return Updater::exitCode($results);
    }

    private function errorOutput(OutputInterface $output): OutputInterface
    {
        return $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
    }
}

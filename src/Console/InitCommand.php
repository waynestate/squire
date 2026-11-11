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
use Waynestate\Squire\Template\Manifest;
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
            ->addOption('target', null, InputOption::VALUE_REQUIRED, 'raw, laravel or static (default: the target recorded in .squire.json, else laravel when an artisan file exists; static must be asked for).')
            ->addOption('only', null, InputOption::VALUE_REQUIRED, 'Comma-separated groups to write: release, parity (opt-in: Parity runs Citadel locally now). (default: the groups recorded in .squire.json, else release)')
            ->addOption('check', null, InputOption::VALUE_NONE, 'Write nothing; report files that are missing, outdated or edited. Exit 1 on drift.')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Overwrite files that were edited or that Squire did not write.');
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
            // An app Squire already initialized keeps its recorded target and groups: detection cannot see a
            // static app, so a plain folder would read as raw and the front controller would slip past --check,
            // and a bare `init` should complete the kit with its recorded groups instead of rewriting it as
            // the fresh-app default.
            $manifest = Manifest::load($path);

            $targetOption = $input->getOption('target');
            if (is_string($targetOption) && $targetOption !== '') {
                $target = $targetOption;
            } else {
                $target = $manifest !== null ? $manifest->target : Catalog::detectTarget($path);
            }

            $onlyOption = $input->getOption('only');
            if (is_string($onlyOption) && $onlyOption !== '') {
                $groups = array_values(array_filter(array_map('trim', explode(',', $onlyOption)), static fn(string $g): bool => $g !== ''));
                if ($groups === []) {
                    throw new ToolError('--only needs at least one group: release, parity.');
                }
            } else {
                // A fresh app gets the default groups (release only): parity is opt-in, because Parity runs the
                // app locally now (D-082) and the kit should not ship a second local-run path by default.
                $groups = $manifest !== null && $manifest->groups !== [] ? $manifest->groups : Catalog::DEFAULT_GROUPS;
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
        if (in_array(Template::GROUP_PARITY, $groups, true)) {
            $output->writeln(Squire::PARITY_POINTER);
        } else {
            // The kit no longer ships a local-run path by default (D-082): point at Parity, which runs the app locally.
            $output->writeln(sprintf('Run it locally with Parity: parity init --from-squire, then parity up (%s).', Squire::PARITY_URL));
        }

        // The release script stops at step 1 without these two files, so the first deploy would fail. Say so now,
        // not in the deployment log. Found by the 2026-11-05 proof run: 31 of 82 Wayne State apps had no lock file.
        $missing = array_values(array_filter(['composer.json', 'composer.lock'], static fn(string $f): bool => !is_file($path . '/' . $f)));
        if (in_array('release', $groups, true) && $missing !== []) {
            $output->writeln(sprintf(
                'Heads up: scripts/release.sh needs composer.json and composer.lock in the app root, and this app has no %s. Commit %s before the first deploy, or the release stops at step 1. An app with no dependencies: `composer init`, then `composer update`.',
                implode(' and no ', $missing),
                count($missing) === 1 ? 'it' : 'both',
            ));
        }

        return 0;
    }

    private function errorOutput(OutputInterface $output): OutputInterface
    {
        return $output instanceof \Symfony\Component\Console\Output\ConsoleOutputInterface ? $output->getErrorOutput() : $output;
    }
}

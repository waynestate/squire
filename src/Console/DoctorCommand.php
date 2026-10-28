<?php

declare(strict_types=1);

namespace Waynestate\Squire\Console;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Waynestate\Squire\Doctor\Doctor;
use Waynestate\Squire\Doctor\Project;
use Waynestate\Squire\Doctor\Report;
use Waynestate\Squire\ToolError;

/**
 * `squire doctor`: check an app's release setup against the S-rules.
 */
final class DoctorCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('doctor')
            ->setDescription("Check an app's Citadel release setup against the SQUIRE rules (S1 to S78).")
            ->addOption('path', null, InputOption::VALUE_REQUIRED, 'Path to the application.', '.')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output format: text or json.', 'text');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $errors = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;

        $format = (string) $input->getOption('format');
        if (!in_array($format, ['text', 'json'], true)) {
            $errors->writeln(sprintf('Invalid format "%s". Allowed values: text, json.', $format));

            return 2;
        }

        $path = (string) $input->getOption('path');

        try {
            $findings = (new Doctor())->run(new Project($path));
        } catch (ToolError $e) {
            $errors->writeln($e->getMessage());

            return 2;
        }

        $output->write($format === 'json' ? Report::json($path, $findings) : Report::text($path, $findings), false, OutputInterface::OUTPUT_RAW);

        return Doctor::exitCode($findings);
    }
}

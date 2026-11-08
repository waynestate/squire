<?php

declare(strict_types=1);

namespace Waynestate\Squire\Console;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
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
            ->setDescription("Check an app's Citadel release setup against the Squire rules (S1 to S78).")
            ->addArgument('topic', InputArgument::OPTIONAL, 'Optional topic. Use `oracle` to check the Oracle connection settings.')
            ->addOption('path', null, InputOption::VALUE_REQUIRED, 'Path to the application.', '.')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output format: text or json.', 'text');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $errors = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;

        $topic = (string) $input->getArgument('topic');
        if ($topic !== '') {
            return $this->runTopic($topic, $input, $output, $errors);
        }

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

    /**
     * Runs one named topic. Today the only topic is `oracle`, which runs the same plain-language Oracle
     * connection check that scripts/release.sh runs on Citadel, from the developer's terminal.
     */
    private function runTopic(string $topic, InputInterface $input, OutputInterface $output, OutputInterface $errors): int
    {
        if ($topic !== 'oracle') {
            $errors->writeln(sprintf('Unknown topic "%s". Allowed topics: oracle.', $topic));

            return 2;
        }

        $format = (string) $input->getOption('format');
        if ($format !== 'text') {
            $errors->writeln('The oracle topic prints text only.');

            return 2;
        }

        $settings = $this->oracleSettings((string) $input->getOption('path'));
        if ($output->isVerbose()) {
            $settings['ORACLE_CHECK_VERBOSE'] = '1';
        }

        require_once dirname(__DIR__, 2) . '/templates/common/oracle-check.php';
        $writeLine = static function (string $line) use ($output): void {
            $output->writeln($line);
        };

        return \SquireOracleCheck::run($settings, null, $writeLine); // @phpstan-ignore class.notFound (loaded by the require_once above; the class is not autoloadable)
    }

    /**
     * The ORACLE_* settings for the oracle topic: the process environment plus the app's own .env file, where a
     * setting already present in the real environment always wins over the file. The .env is parsed, never sourced:
     * simple KEY=value lines, comments and blank lines ignored, one pair of surrounding quotes stripped.
     *
     * @return array<string, string>
     */
    private function oracleSettings(string $path): array
    {
        /** @var array<string, string> $settings */
        $settings = getenv();
        $file = $path . '/.env';
        if (!is_file($file)) {
            return $settings;
        }

        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $position = strpos($line, '=');
            if ($position === false) {
                continue;
            }
            $name = trim(substr($line, 0, $position));
            $value = trim(substr($line, $position + 1));
            if ($name === '' || isset($settings[$name])) {
                continue;
            }
            $length = strlen($value);
            if ($length >= 2 && ($value[0] === '"' || $value[0] === "'") && $value[$length - 1] === $value[0]) {
                $value = substr($value, 1, $length - 2);
            }
            $settings[$name] = $value;
        }

        return $settings;
    }
}

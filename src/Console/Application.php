<?php

declare(strict_types=1);

namespace Waynestate\Squire\Console;

use Symfony\Component\Console\Application as SymfonyApplication;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Waynestate\Squire\Squire;

/**
 * The squire CLI. An unknown command exits 2 (a tool error), so exit 1 always means "the app has findings".
 */
final class Application extends SymfonyApplication
{
    public function __construct()
    {
        parent::__construct(Squire::TOOL, Squire::version());
        $this->addCommand(new InitCommand());
        $this->addCommand(new UpdateCommand());
        $this->addCommand(new DoctorCommand());
        $this->setCatchExceptions(true);
    }

    public function doRun(InputInterface $input, OutputInterface $output): int
    {
        $name = $this->getCommandName($input);
        if ($name !== null && $name !== '' && !$this->has($name)) {
            $errors = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
            $errors->writeln(sprintf('Command "%s" is not defined.', $name));

            return 2;
        }

        return parent::doRun($input, $output);
    }
}

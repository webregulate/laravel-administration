<?php

namespace WebRegulate\LaravelAdministration\Classes\VersionHandler;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

/**
 * Console (artisan command) implementation of the version update context.
 */
class ConsoleVersionUpdateContext extends VersionUpdateContext
{
    public function __construct(private Command $command)
    {
    }

    public function line(string $message = ''): void
    {
        $this->command->line($message);
    }

    public function info(string $message): void
    {
        $this->command->info($message);
    }

    public function warn(string $message): void
    {
        $this->command->warn($message);
    }

    public function error(string $message): void
    {
        $this->command->error($message);
    }

    public function runProcess(array $command, ?string $workingDirectory = null, int $timeout = 600): bool
    {
        $process = new Process($command, $workingDirectory ?? base_path());
        $process->setTimeout($timeout);

        $process->run(function ($type, $buffer) {
            // Stream stdout/stderr straight through to the console as it arrives
            $this->command->getOutput()->write($buffer);
        });

        return $process->isSuccessful();
    }
}

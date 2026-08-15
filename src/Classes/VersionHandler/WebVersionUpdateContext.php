<?php

namespace WebRegulate\LaravelAdministration\Classes\VersionHandler;

use Closure;
use Symfony\Component\Process\Process;

class WebVersionUpdateContext extends VersionUpdateContext
{
    protected string $output = '';
    protected ?Closure $onOutput;

    public function __construct(?Closure $onOutput = null)
    {
        $this->onOutput = $onOutput;
    }

    /**
     * Get the full buffered output.
     */
    public function getOutput(): string
    {
        return $this->output;
    }

    protected function append(string $message): void
    {
        $this->output .= $message;

        if ($this->onOutput !== null) {
            ($this->onOutput)($this->output);
        }
    }

    public function line(string $message = ''): void
    {
        $this->append($message . PHP_EOL);
    }

    public function info(string $message): void
    {
        $this->append($message . PHP_EOL);
    }

    public function warn(string $message): void
    {
        $this->append('[warning] ' . $message . PHP_EOL);
    }

    public function error(string $message): void
    {
        $this->append('[error] ' . $message . PHP_EOL);
    }

    public function runProcess(array $command, ?string $workingDirectory = null, int $timeout = 600): bool
    {
        $process = new Process($command, $workingDirectory ?? base_path());
        $process->setTimeout($timeout);

        $process->run(function ($type, $buffer) {
            $this->append($buffer);
        });

        return $process->isSuccessful();
    }
}

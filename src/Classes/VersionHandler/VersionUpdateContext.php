<?php

namespace WebRegulate\LaravelAdministration\Classes\VersionHandler;

abstract class VersionUpdateContext
{
    abstract public function line(string $message = ''): void;

    abstract public function info(string $message): void;

    abstract public function warn(string $message): void;

    abstract public function error(string $message): void;

    /**
     * @param array<int, string> $command
     */
    abstract public function runProcess(array $command, ?string $workingDirectory = null, int $timeout = 600): bool;
}
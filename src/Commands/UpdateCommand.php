<?php

namespace WebRegulate\LaravelAdministration\Commands;

use Illuminate\Console\Command;
use WebRegulate\LaravelAdministration\Classes\VersionHandler\VersionHandler;
use WebRegulate\LaravelAdministration\Classes\VersionHandler\ConsoleVersionUpdateContext;

class UpdateCommand extends Command
{
    protected $signature = 'wrla:update {--yes : Explicitly approve application file upgrades after Composer updates}';

    protected $description = 'Run Composer, upgrade WRLA application files with consent, and clear cached application state.';

    public function handle()
    {
        $versionHandler = new VersionHandler(new ConsoleVersionUpdateContext($this));

        return $versionHandler->runComposerUpdate()
            && $versionHandler->runUpgrade($this->input?->isInteractive() ?? false, (bool) ($this->input?->getOption('yes') ?? false))
            && $versionHandler->runOptimizeClear()
            ? self::SUCCESS
            : self::FAILURE;
    }
}
<?php

namespace WebRegulate\LaravelAdministration\Commands;

use Illuminate\Console\Command;
use WebRegulate\LaravelAdministration\Classes\VersionHandler\VersionHandler;
use WebRegulate\LaravelAdministration\Classes\VersionHandler\ConsoleVersionUpdateContext;

class UpdateCommand extends Command
{
    protected $signature = 'wrla:update';

    protected $description = 'Run composer update for the WRLA package and clear cached application state.';

    public function handle()
    {
        $versionHandler = new VersionHandler(new ConsoleVersionUpdateContext($this));

        return $versionHandler->runComposerUpdate() && $versionHandler->runOptimizeClear()
            ? self::SUCCESS
            : self::FAILURE;
    }
}
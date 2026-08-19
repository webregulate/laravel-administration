<?php

namespace WebRegulate\LaravelAdministration\Livewire\DevTools;

use LivewireUI\Modal\ModalComponent;
use WebRegulate\LaravelAdministration\Classes\VersionHandler\BackgroundUpdateProcess;
use WebRegulate\LaravelAdministration\Classes\VersionHandler\VersionHandler;
use WebRegulate\LaravelAdministration\Classes\VersionHandler\WebVersionUpdateContext;
use WebRegulate\LaravelAdministration\Classes\WRLAHelper;
use Throwable;

class HandleUpdateModal extends ModalComponent
{
    private const DONE_MARKER = '[[WRLA_UPDATE_COMPLETE]]';

    public string $consoleOutput = '';
    public string $mode = 'live';
    public bool $running = false;
    public bool $authorised = true;
    public bool $updateCompleted = false;
    public ?bool $composerUpdateAvailable = null;
    public ?string $latestVersion = null;

    public function mount()
    {
        $this->authorised = WRLAHelper::showVersionUpdateBar();

        $this->mode = config('wr-laravel-administration.developer.update.mode', 'live') === 'blocking'
            ? 'blocking'
            : 'live';

        if (!$this->authorised) {
            $this->consoleOutput = 'Update tools are not available for your account.' . PHP_EOL;
            $this->dispatch('dev-tools.handle-update-modal.opened');
            return;
        }

        $this->composerUpdateAvailable = VersionHandler::isComposerUpdateAvailable();

        $latest = VersionHandler::getLatestWrlaVersion();
        $this->latestVersion = $latest['version'] ?? null;

        $version = VersionHandler::$localPackageCurrentVersion ?? 'unknown';
        $this->consoleOutput = 'Installed package version: ' . $version . PHP_EOL;

        if ($this->composerUpdateAvailable === true) {
            $this->consoleOutput .= 'A composer update is available - press "Run composer update" to apply it.' . PHP_EOL;
        } elseif ($this->composerUpdateAvailable === false) {
            $this->consoleOutput .= 'You are on the latest version, no updates required.' . PHP_EOL;
        } else {
            $this->consoleOutput .= 'Could not determine update status.' . PHP_EOL;
        }

        $this->dispatch('dev-tools.handle-update-modal.opened');
    }

    public function render()
    {
        return view(WRLAHelper::getViewPath('livewire.dev-tools.handle-update-modal'));
    }

    public function runComposerOnly(): void
    {
        if (!WRLAHelper::showVersionUpdateBar()) {
            $this->authorised = false;
            $this->consoleOutput = 'You do not have permission to run updates.' . PHP_EOL;
            return;
        }

        $this->updateCompleted = false;

        $this->mode === 'blocking'
            ? $this->runBlockingComposerOnly()
            : $this->runLive('wrla:update --no-interaction');
    }

    protected function runLive(string $artisanArgs): void
    {
        try {
            (new BackgroundUpdateProcess())->start(
                $this->logPath(),
                self::DONE_MARKER,
                $artisanArgs
            );

            $this->running = true;
            $this->consoleOutput = 'Starting update...' . PHP_EOL;
        } catch (Throwable $e) {
            $this->consoleOutput = 'Could not start background update (' . $e->getMessage() . ')' . PHP_EOL
                . 'Falling back to blocking mode...' . PHP_EOL;

            $this->runBlockingComposerOnly();
        }
    }

    protected function runBlockingComposerOnly(): void
    {
        @set_time_limit(0);

        $this->running = true;

        $context = new WebVersionUpdateContext();

        try {
            $versionHandler = new VersionHandler($context);
            if ($versionHandler->runComposerUpdate()) {
                $versionHandler->runOptimizeClear();
                $context->info('Composer update completed successfully.');
            }
        } catch (Throwable $e) {
            $context->error($e->getMessage());
        }

        $this->consoleOutput .= $this->stripAnsi($context->getOutput());
        $this->running = false;
        $this->updateCompleted = true;

        $this->composerUpdateAvailable = VersionHandler::isComposerUpdateAvailable();
    }

    public function pollOutput(): void
    {
        if (!$this->running) {
            return;
        }

        $logPath = $this->logPath();
        if (!file_exists($logPath)) {
            return;
        }

        $output = file_get_contents($logPath);

        if (str_contains($output, self::DONE_MARKER)) {
            $output = trim(str_replace(self::DONE_MARKER, '', $output));
            $this->running = false;
            $this->updateCompleted = true;
            $this->composerUpdateAvailable = VersionHandler::isComposerUpdateAvailable();
        }

        $this->consoleOutput = $this->stripAnsi($output);
    }

    protected function stripAnsi(string $output): string
    {
        return (string) preg_replace('/\x1b\[[0-9;?]*[ -\/]*[@-~]|\x1b[@-_]/', '', $output);
    }

    protected function logPath(): string
    {
        return storage_path('app/wrla/update.log');
    }
}
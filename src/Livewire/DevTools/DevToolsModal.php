<?php

namespace WebRegulate\LaravelAdministration\Livewire\DevTools;

use LivewireUI\Modal\ModalComponent;
use Symfony\Component\Process\Process;
use WebRegulate\LaravelAdministration\Classes\VersionHandler\BackgroundUpdateProcess;
use WebRegulate\LaravelAdministration\Classes\VersionHandler\VersionHandler;
use WebRegulate\LaravelAdministration\Classes\VersionHandler\WebVersionUpdateContext;
use WebRegulate\LaravelAdministration\Classes\WRLAHelper;
use Throwable;

class DevToolsModal extends ModalComponent
{
    private const DONE_MARKER = '[[WRLA_UPDATE_COMPLETE]]';

    public string $consoleOutput = '';
    public string $mode = 'live';
    public bool $running = false;
    public bool $authorised = true;
    public bool $updateCompleted = false;
    public bool $commandCompleted = false;
    public ?string $runningLabel = null;
    public ?bool $composerUpdateAvailable = null;
    public ?string $latestVersion = null;
    public ?string $currentVersion = null;

    /** Whatever is currently running, so polling knows how to finish: 'update' | 'command' | null. */
    public ?string $runType = null;

    /** Config-defined commands the current user is allowed to run (keyed by config index). */
    public array $commands = [];

    public static function modalMaxWidth(): string
    {
        return '6xl';
    }

    public function mount()
    {
        $this->authorised = WRLAHelper::showVersionUpdateBar();

        if (!$this->authorised) {
            $this->consoleOutput = 'Developer tools are not available for your account.' . PHP_EOL;
            $this->dispatch('dev-tools.dev-tools-modal.opened');
            return;
        }

        $this->mode = config('wr-laravel-administration.developer.update.mode', 'live') === 'blocking'
            ? 'blocking'
            : 'live';

        $this->commands = $this->resolveCommands();

        $this->composerUpdateAvailable = VersionHandler::isComposerUpdateAvailable();

        $latest = VersionHandler::getLatestWrlaVersion();
        $this->latestVersion = $latest['version'] ?? null;
        $this->currentVersion = VersionHandler::$localPackageCurrentVersion;

        $version = VersionHandler::$localPackageCurrentVersion ?? 'unknown';
        $this->consoleOutput = 'Installed package version: ' . $version . PHP_EOL;

        if ($this->composerUpdateAvailable === true) {
            $this->consoleOutput .= 'A composer update is available - press "Click to update WRLA" to apply it.' . PHP_EOL;
        } elseif ($this->composerUpdateAvailable === false) {
            $this->consoleOutput .= 'You are on the latest version, no updates required.' . PHP_EOL;
        } else {
            $this->consoleOutput .= 'Could not determine update status.' . PHP_EOL;
        }

        $this->dispatch('dev-tools.dev-tools-modal.opened');
    }

    public function render()
    {
        return view(WRLAHelper::getViewPath('livewire.dev-tools.dev-tools-modal'));
    }

    /**
     * Resolve the configured developer commands the current user is allowed to run.
     * Each command's `condition` may be a boolean or a callback taking $wrlaUserData.
     * Never trust these values for execution - they are re-resolved server-side in runCommand().
     */
    protected function resolveCommands(): array
    {
        $configured = (array) config('wr-laravel-administration.developer.commands', []);
        $wrlaUserData = WRLAHelper::getCurrentUserData();

        $resolved = [];

        foreach ($configured as $index => $item) {
            $command = trim((string) ($item['command'] ?? ''));
            if ($command === '') {
                continue;
            }

            $condition = $item['condition'] ?? true;
            $allowed = is_callable($condition)
                ? (bool) call_user_func($condition, $wrlaUserData)
                : (bool) $condition;

            if (!$allowed) {
                continue;
            }

            $resolved[$index] = [
                'index' => $index,
                'command' => $command,
                'label' => (string) ($item['label'] ?? $command),
            ];
        }

        return $resolved;
    }

    public function runComposerOnly(): void
    {
        if (!WRLAHelper::showVersionUpdateBar()) {
            $this->authorised = false;
            $this->consoleOutput = 'You do not have permission to run updates.' . PHP_EOL;
            return;
        }

        if ($this->running) {
            return;
        }

        $this->updateCompleted = false;
        $this->commandCompleted = false;
        $this->runType = 'update';

        $this->mode === 'blocking'
            ? $this->runBlockingComposerOnly()
            : $this->runLive('wrla:update --no-interaction');
    }

    /**
     * Run a configured developer command by its config index. The command and its
     * condition are re-resolved server-side so a tampered client cannot run anything
     * that is not both configured and permitted for the current user.
     */
    public function runCommand(int $index): void
    {
        if (!WRLAHelper::showVersionUpdateBar()) {
            $this->authorised = false;
            $this->consoleOutput = 'You do not have permission to run commands.' . PHP_EOL;
            return;
        }

        if ($this->running) {
            return;
        }

        $resolved = $this->resolveCommands();

        if (!isset($resolved[$index])) {
            $this->consoleOutput = 'That command is not available.' . PHP_EOL;
            return;
        }

        $command = $resolved[$index]['command'];
        $label = $resolved[$index]['label'];

        $this->updateCompleted = false;
        $this->commandCompleted = false;
        $this->runType = 'command';
        $this->runningLabel = $label;

        $this->mode === 'blocking'
            ? $this->runBlockingCommand($command, $label)
            : $this->runLiveCommand($command, $label);
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

    protected function runLiveCommand(string $command, string $label): void
    {
        try {
            (new BackgroundUpdateProcess())->startRaw(
                $this->logPath(),
                self::DONE_MARKER,
                $command
            );

            $this->running = true;
            $this->consoleOutput = 'Running: ' . $label . PHP_EOL . '> ' . $command . PHP_EOL . PHP_EOL;
        } catch (Throwable $e) {
            $this->consoleOutput = 'Could not start background command (' . $e->getMessage() . ')' . PHP_EOL
                . 'Falling back to blocking mode...' . PHP_EOL;

            $this->runBlockingCommand($command, $label);
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
        $this->runType = null;

        $this->composerUpdateAvailable = VersionHandler::isComposerUpdateAvailable();
    }

    protected function runBlockingCommand(string $command, string $label): void
    {
        @set_time_limit(0);

        $this->running = true;
        $this->consoleOutput = 'Running: ' . $label . PHP_EOL . '> ' . $command . PHP_EOL . PHP_EOL;

        try {
            $process = Process::fromShellCommandline($command, base_path());
            $process->setTimeout(null);
            $process->run();

            $this->consoleOutput .= $this->stripAnsi($process->getOutput() . $process->getErrorOutput());
            $this->consoleOutput .= PHP_EOL . ($process->isSuccessful()
                ? 'Command completed successfully.'
                : 'Command exited with code ' . $process->getExitCode() . '.') . PHP_EOL;
        } catch (Throwable $e) {
            $this->consoleOutput .= $e->getMessage() . PHP_EOL;
        }

        $this->running = false;
        $this->commandCompleted = true;
        $this->runType = null;
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

            if ($this->runType === 'update') {
                $this->updateCompleted = true;
                $this->composerUpdateAvailable = VersionHandler::isComposerUpdateAvailable();
            } else {
                $this->commandCompleted = true;
            }

            $this->runType = null;
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
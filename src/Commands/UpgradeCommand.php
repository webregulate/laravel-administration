<?php

namespace WebRegulate\LaravelAdministration\Commands;

use Illuminate\Console\Command;
use WebRegulate\LaravelAdministration\Services\Upgrades\UpgradeTo0100;

class UpgradeCommand extends Command
{
    protected $signature = 'wrla:upgrade
        {--dry-run : Preview application file changes without writing anything}
        {--yes : Explicitly approve the previewed migration, including in non-interactive mode}';

    protected $description = 'Migrate application files for WRLA 0.10.1, with preview and confirmation.';

    public function handle(): int
    {
        try {
            $changes = UpgradeTo0100::plan(base_path());
        } catch (\RuntimeException|\InvalidArgumentException $exception) {
            $this->error('Upgrade could not be planned: ' . $exception->getMessage());

            return self::FAILURE;
        }

        // Already upgraded applications need no writes or cache clearing
        if ($changes === []) {
            $this->info('WRLA application files are already up to date for 0.10.1.');

            return self::SUCCESS;
        }

        $this->info('WRLA 0.10.1 application upgrade');
        $this->line('Rename config to wrla and configure every runtime-capable setting exclusively in WRLASettings::runtime().');
        $this->table(['Action', 'Application file'], array_map(
            fn (string $path, array $change) => [
                $change['after'] === null ? 'Remove legacy file' : ($change['before'] === null ? 'Create' : 'Update'),
                $path,
            ],
            array_keys($changes),
            array_values($changes),
        ));
        $this->warn('Start with a clean Git worktree: no staged, unstaged or untracked changes. No backup copies are created.');
        $this->warn('Review the automatically applied changes in Git and test locally before finalising the upgrade, committing or deploying.');

        // A preview never changes source files or caches
        if ($this->option('dry-run')) {
            $this->info('Dry run only. No files have been changed.');

            return self::SUCCESS;
        }

        // Never interpret --no-interaction as permission to edit application files
        if (!$this->option('yes') && (!$this->input->isInteractive() || !$this->confirm('Apply these application file changes automatically?', false))) {
            $this->warn('Upgrade not applied. Run php artisan wrla:upgrade locally to review and approve it, or use --yes for explicit non-interactive approval.');

            return self::FAILURE;
        }

        try {
            UpgradeTo0100::apply(base_path(), $changes);
        } catch (\RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Application files migrated.');

        foreach (['config:clear', 'route:clear', 'view:clear'] as $command) {
            // Report cache failures without claiming the file migration was undone
            if ($this->call($command) !== self::SUCCESS) {
                $this->error('Files were migrated, but ' . $command . ' failed. Clear application caches before testing.');

                return self::FAILURE;
            }
        }

        $this->info('WRLA 0.10.1 changes applied. Review the Git diff and test before finalising the upgrade.');

        return self::SUCCESS;
    }
}

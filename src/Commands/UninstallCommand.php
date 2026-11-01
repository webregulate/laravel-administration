<?php

namespace WebRegulate\LaravelAdministration\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Artisan;
use WebRegulate\LaravelAdministration\Classes\WRLAHelper;

class UninstallCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'wrla:uninstall';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Uninstall the WRLA package';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $this->line(PHP_EOL.'Uninstalling WRLA package...');

        // Remove WRLA related database tables
        $this->confirmAndRemoveTables('Remove WRLA related database tables?', [
            'wrla_user_data',
            'wrla_email_templates',
            'wrla_notifications',
        ]);

        // Remove app/WRLA directory
        $this->confirmAndRemove('Remove WRLA related directories and files?', ['app/WRLA', 'app/Mail/WRLA', 'resources/views/email/wrla', 'public/vendor/wr-laravel-administration']);

        $userDataModelPaths = ['app/Models/WRLAUserData.php'];
        if (ltrim(WRLAHelper::getUserDataModelClass(), '\\') === 'App\\Models\\UserData') {
            $userDataModelPaths[] = 'app/Models/UserData.php';
        }
        $this->confirmAndRemove('Remove the WRLAUserData model (including the configured legacy model, if applicable)?', $userDataModelPaths);

        // Remove app/config/wr-laravel-administration.php
        $this->confirmAndRemove('Remove the WRLA configuration file?', ['config/wr-laravel-administration.php'], function() {
            // Clear configuration cache
            Artisan::call('config:clear');
        });

        // Confirm remove composer package
        if($this->confirm('Remove webregulate/laravel-administration package from composer.json?', true)) {
            // Remove the package from composer.json
            $composerJsonPath = $this->applicationPath('composer.json');
            $composerJson = json_decode(file_get_contents($composerJsonPath), true);

            if (isset($composerJson['require']['webregulate/laravel-administration'])) {
                unset($composerJson['require']['webregulate/laravel-administration']);
                file_put_contents($composerJsonPath, json_encode($composerJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                $this->info('Removed webregulate/laravel-administration from composer.json');
            } else {
                $this->info('webregulate/laravel-administration is not found in composer.json');
            }
        }

        // Remind user that they must run composer update themselves
        $this->warn(PHP_EOL.'Please run <comment>composer update</comment> to finalize the removal of the package.'.PHP_EOL);

        return 0;
    }

    /**
     * Returns OS corrected path from the base directory.
     * 
     * @var string
     * @return string
     */
    protected function applicationPath(string $path): string
    {
        // If Windows
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            return str_replace('\\', '/', base_path($path));
        }
        // Other OS's
        else {
            return base_path($path);
        }
    }

    /**
     * Confirm and remove the provided pattern from the application.
     * 
     * @param string $question
     * @param array $patterns Array of strings using glob pattern syntax, allowing wildcard eg. `app/WRLA/*` or `app/Models/WRLAUserData.php`
     * @param ?callable $callback Only calls back if the user confirms the removal.
     * @return bool
     */
    protected function confirmAndRemove(string $question, array $patterns, ?callable $callback = null): bool
    {
        $this->info(PHP_EOL.$question);

        foreach ($patterns as $pattern) {
            $this->line("Files matching pattern <comment>$pattern</comment> will be removed.");
        }

        $confirm = $this->confirm('Do you want to proceed?', true);

        if ($confirm) {
            foreach ($patterns as $pattern) {
                $files = glob($this->applicationPath($pattern));

                // If no files found, we can skip the removal
                if (empty($files)) {
                    $this->info("No files found for pattern: $pattern");
                    continue;
                }
    
                foreach ($files as $file) {
                    if (is_file($file)) {
                        unlink($file);
                        $this->info("Removed: $file");
                    } elseif (is_dir($file)) {
                        // If it's a directory, we need to remove it recursively
                        $filesToDelete = new \RecursiveIteratorIterator(
                            new \RecursiveDirectoryIterator($file, \RecursiveDirectoryIterator::SKIP_DOTS),
                            \RecursiveIteratorIterator::CHILD_FIRST
                        );

                        foreach ($filesToDelete as $fileToDelete) {
                            if ($fileToDelete->isDir()) {
                                rmdir($fileToDelete->getPathname());
                                $this->info("Removed directory: " . $fileToDelete->getPathname());
                            } else {
                                unlink($fileToDelete->getPathname());
                                $this->info("Removed file: " . $fileToDelete->getPathname());
                            }
                        }

                        rmdir($file); // Finally remove the empty directory
                    }
                }
            }

            if (is_callable($callback)) {
                $callback();
            }

            return true;
        } else {
            $this->info("$pattern removal skipped.");

            return false;
        }
    }

    /**
     * Confirm and remove the following tables from the database
     * 
     * @param string $question
     * @param array $tables
     * @return void
     */
    protected function confirmAndRemoveTables(string $question, array $tables): void
    {
        $this->info(PHP_EOL.$question);

        foreach ($tables as $table) {
            $this->line("Table <comment>$table</comment> will be removed.");
        }

        $confirm = $this->confirm('Do you want to proceed?', true);

        if ($confirm) {
            foreach ($tables as $table) {
                // Check if the table exists before attempting to drop it
                if (!Schema::hasTable($table)) {
                    $this->info("Table $table does not exist, skipping removal.");
                    continue;
                }

                Schema::dropIfExists($table);

                $this->info("Removed table: $table");
            }
        } else {
            $this->info("WRLA table removals skipped.");
        }
    }

}
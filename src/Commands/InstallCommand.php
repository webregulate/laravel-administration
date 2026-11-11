<?php

namespace WebRegulate\LaravelAdministration\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use WebRegulate\LaravelAdministration\Classes\WRLAHelper;

class InstallCommand extends Command
{
    /**
     * Resolved User model class (with leading backslash).
     */
    private string $userModelClass = '';

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'wrla:install';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Install the WRLA package';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $this->userModelClass = $this->promptUserModelClass();
        $this->publishConfig();
        $this->updatePublishedConfig();
        $this->publishAssets();
        $this->publishLogViewerAssets();
        $this->generateWRLAUserDataModel();
        $this->generateUserManageableModel();
        $this->generateEmailTemplateManageableModel();
        $createdSettings = $this->generateWRLASettingsClass();
        $this->generateNotificationCustomClass();
        $this->generateEmailTemplateMailBlade();

        // Finish provider boot once the new application's runtime settings exist
        if ($createdSettings) {
            $this->laravel->getProvider(\WebRegulate\LaravelAdministration\WRLAServiceProvider::class)->bootAdministration();
        }

        $this->line('');
        $this->info('🥳 WRLA installed successfully.');

        [$databaseConnectionExists, $databaseName] = $this->checkDatabaseConnection();
        $migrationsRan = $this->promptRunMigrations($databaseConnectionExists, $databaseName);
        $this->promptCreateStorageSymlink();

        $createdUser = $migrationsRan || $databaseConnectionExists
            ? $this->promptCreateMasterUser($databaseConnectionExists)
            : null;

        $this->promptConfigureDeveloperTools($createdUser);
        $this->promptOpenDocumentation();

        $this->line('');

        return 1;
    }

    private function promptUserModelClass(): string
    {
        while (true) {
            $modelClass = $this->ask('Enter the fully qualified class name of your User model:', '\App\Models\User');
            $normalizedClass = ltrim($modelClass, '\\');
            if (class_exists($normalizedClass)) {
                return '\\' . $normalizedClass;
            }
            $this->error("Class '{$modelClass}' not found. Please check the namespace and try again.");
        }
    }

    private function publishConfig(): void
    {
        $this->call('vendor:publish', [
            '--provider' => \WebRegulate\LaravelAdministration\WRLAServiceProvider::class,
            '--tag' => 'wrla-config',
        ]);
    }

    private function updatePublishedConfig(): void
    {
        $configPath = config_path('wrla.php');
        if (! file_exists($configPath)) {
            return;
        }
        $contents = file_get_contents($configPath);
        $updated = str_replace(
            "'user' => \\App\\Models\\User::class",
            "'user' => {$this->userModelClass}::class",
            $contents
        );
        file_put_contents($configPath, $updated);
    }

    private function publishAssets(): void
    {
        $this->call('vendor:publish', [
            '--provider' => \WebRegulate\LaravelAdministration\WRLAServiceProvider::class,
            '--tag' => 'wrla-assets',
        ]);
    }

    private function publishLogViewerAssets(): void
    {
        $this->call('log-viewer:publish');
        $this->call('vendor:publish', [
            '--tag' => 'log-viewer-config',
        ]);
    }

    private function generateWRLAUserDataModel(): void
    {
        $legacyModelPath = app_path('Models/UserData.php');
        if (ltrim(WRLAHelper::getUserDataModelClass(), '\\') === 'App\\Models\\UserData' && file_exists($legacyModelPath)) {
            $this->warn(' - Legacy UserData model preserved at '.WRLAHelper::removeBasePath($legacyModelPath).'. Rename it to WRLAUserData and update models.wrla_user_data when following the v0.9.0 migration notes.');

            return;
        }

        $createdWRLAUserDataAt = WRLAHelper::generateFileFromStub(
            'WRLAUserData.stub',
            [
                '{{ NAMESPACE }}' => 'App\Models',
            ],
            app_path('Models/WRLAUserData.php')
        );

        if ($createdWRLAUserDataAt !== false) {
            $this->info(' - WRLAUserData model created successfully here: '.$createdWRLAUserDataAt);
        } else {
            $this->warn(' - WRLAUserData model already exists at '.WRLAHelper::removeBasePath(app_path('Models/WRLAUserData.php')).'. Existing customizations have been preserved; see the v0.9.0 migration notes to adopt WRLAUserDataBase.');
        }
    }

    private function generateUserManageableModel(): void
    {
        $createdUserAt = WRLAHelper::generateFileFromStub(
            'User.stub',
            [],
            app_path('WRLA/User.php')
        );

        if ($createdUserAt !== false) {
            $this->info(' - User model created successfully here: '.$createdUserAt);
        } else {
            $this->warn(' - User model already exists at '.WRLAHelper::removeBasePath(app_path('WRLA/User.php')).'. To replace it delete the file and run again.');
        }
    }

    private function generateEmailTemplateManageableModel(): void
    {
        $createdEmailTemplateAt = WRLAHelper::generateFileFromStub(
            'EmailTemplate.stub',
            [],
            app_path('WRLA/EmailTemplate.php')
        );

        if ($createdEmailTemplateAt !== false) {
            $this->info(' - EmailTemplate model created successfully here: '.$createdEmailTemplateAt);
        } else {
            $this->warn(' - EmailTemplate model already exists at '.WRLAHelper::removeBasePath(app_path('WRLA/EmailTemplate.php')).'. To replace it delete the file and run again.');
        }
    }

    private function generateWRLASettingsClass(): bool
    {
        $createdWRLASettingsAt = WRLAHelper::generateFileFromStub(
            'WRLASettings.stub',
            [],
            app_path('WRLA/WRLASettings.php')
        );

        if ($createdWRLASettingsAt !== false) {
            require_once app_path('WRLA/WRLASettings.php');
            $this->info(' - WRLASettings class created successfully here: '.$createdWRLASettingsAt);
        } else {
            $this->warn(' - WRLASettings class already exists at '.WRLAHelper::removeBasePath(app_path('WRLA/WRLASettings.php')).'. To replace it delete the file and run again.');
        }

        return $createdWRLASettingsAt !== false;
    }

    private function generateNotificationCustomClass(): void
    {
        $notificationBaseFile = WRLAHelper::generateFileFromStub(
            'NotificationCustom.stub',
            [],
            app_path('WRLA/NotificationDefinitions/NotificationCustom.php')
        );

        if ($notificationBaseFile !== false) {
            $this->info(' - NotificationCustom class created successfully here: '.$notificationBaseFile);
        } else {
            $this->warn(' - NotificationCustom class already exists at '.WRLAHelper::removeBasePath(app_path('WRLA/NotificationDefinitions/NotificationCustom.php')).'. To replace it delete the file and run again.');
        }
    }

    private function generateEmailTemplateMailBlade(): void
    {
        $emailTemplateMailBladeFile = WRLAHelper::generateFileFromStub(
            'email-template-mail.blade.stub',
            [],
            resource_path('views/email/wrla/email-template-mail.blade.php')
        );

        if ($emailTemplateMailBladeFile !== false) {
            $this->info(' - email-template-mail.blade.php created successfully here: '.$emailTemplateMailBladeFile);
        } else {
            $this->warn(' - email-template-mail.blade.php already exists at '.WRLAHelper::removeBasePath(resource_path('views/email/wrla/email-template-mail.blade.php')).'. To replace it delete the file and run again.');
        }
    }

    private function checkDatabaseConnection(): array
    {
        $databaseConnectionExists = false;
        $databaseName = 'null';
        try {
            $databaseName = DB::connection()->getPDO()
                ? DB::connection()->getDatabaseName()
                : 'null';
            $databaseConnectionExists = true;
        } catch (\Exception) {
            $databaseConnectionExists = false;
        }

        return [$databaseConnectionExists, $databaseName];
    }

    private function promptRunMigrations(bool $databaseConnectionExists, string $databaseName): bool
    {
        $runMigrations = $this->confirm('Would you like to run the migrations'.($databaseConnectionExists ? " (Connected to $databaseName)" : '').'?', true);
        if ($runMigrations) {
            $this->call('migrate');
        }

        return $runMigrations;
    }

    private function promptCreateStorageSymlink(): void
    {
        if (! file_exists(public_path('storage'))) {
            if ($this->confirm('Would you like to create a symlink for the storage folder?', true)) {
                $this->call('storage:link');
            }
        } else {
            $this->warn(' - storage symlink already exists');
        }
    }

    private function promptCreateMasterUser(bool $databaseConnectionExists): mixed
    {
        $userModelClass = ltrim($this->userModelClass, '\\');
        $anyUsersExist = $databaseConnectionExists ? $userModelClass::limit(1)->count() > 0 : false;

        if ($this->confirm('Would you like to create a master user?', ! $anyUsersExist)) {
            /** @var CreateUserCommand $command */
            $command = $this->getApplication()->find('wrla:create-user');
            $command->run(
                new \Symfony\Component\Console\Input\ArrayInput([
                    'master' => true,
                    '--user-model' => $this->userModelClass,
                ]),
                $this->getOutput()
            );

            return $command->createdUser;
        }

        return null;
    }

    private function promptConfigureDeveloperTools(mixed $createdUser): void
    {
        $this->line('');
        $this->info('🔧 Developer tools enables updating WRLA through the backend, shows debug info, and provides a documentation link in the backend.');

        $choices = [];
        $values  = [];

        if ($createdUser !== null) {
            $emailOrId = $createdUser->getEmailForPasswordReset() ?? "User ID #$createdUser->id";
            $choices[] = "For {$emailOrId} only";
            $values[]  = "fn(\$wrlaUserData) => \$wrlaUserData?->user_id === {$createdUser->id}";
        }

        $choices[] = 'For all Master users';
        $values[]  = 'fn($wrlaUserData) => $wrlaUserData?->isMaster()';

        $choices[] = 'For all admin users';
        $values[]  = 'fn($wrlaUserData) => $wrlaUserData?->isAdmin()';

        $choices[] = 'Disabled for all users (I will configure this myself if needed)';
        $values[]  = 'false';

        $selectedChoice = $this->choice(
            'Who should have developer tools enabled?',
            $choices,
            0
        );

        $selectedValue = $values[array_search($selectedChoice, $choices)];

        $settingsPath = app_path('WRLA/WRLASettings.php');

        // Leave missing or customized settings for explicit developer review
        if (!file_exists($settingsPath)) {
            $this->warn(' - WRLASettings.php not found. Configure developer.enable in WRLASettings::runtime() manually.');

            return;
        }

        $contents = file_get_contents($settingsPath);

        // Do not mistake an unreadable settings file for a customized one
        if ($contents === false) {
            throw new \RuntimeException('Unable to read WRLASettings.php developer access settings.');
        }

        $placeholder = "'enable' => fn (\$wrlaUserData) => \$wrlaUserData?->isMaster() ?? false,";

        // Only replace the generated access rule, never application-owned runtime logic
        if (substr_count($contents, $placeholder) !== 1) {
            $this->warn(' - Existing runtime settings preserved. Set developer.enable in WRLASettings::runtime() to: ' . $selectedValue);

            return;
        }

        $updated = str_replace($placeholder, "'enable' => {$selectedValue},", $contents);

        // Surface failed settings writes instead of claiming success
        if (file_put_contents($settingsPath, $updated) === false) {
            throw new \RuntimeException('Unable to write WRLASettings.php developer access settings.');
        }

        $this->info(' - Developer tools runtime settings updated successfully.');
    }

    private function promptOpenDocumentation(): void
    {
        $this->line('');
        $this->info('📚 You can open the documentation at any time by running: <comment>php artisan wrla:docs</comment>');
        $this->line('');
        if ($this->confirm('Would you like to open the WRLA Documentation in your browser now?', true)) {
            $this->call('wrla:docs');
        }
    }
}

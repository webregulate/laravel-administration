<?php

namespace WebRegulate\LaravelAdministration\Tests\Commands;

use Illuminate\Config\Repository;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Once;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application as ConsoleApplication;
use Symfony\Component\Console\Tester\CommandTester;
use WebRegulate\LaravelAdministration\Commands\UpgradeCommand;
use WebRegulate\LaravelAdministration\Commands\InstallCommand;
use WebRegulate\LaravelAdministration\Services\Upgrades\UpgradeTo0100;
use WebRegulate\LaravelAdministration\Services\WRLASettingsService;
use WebRegulate\LaravelAdministration\WRLAServiceProvider;

class UpgradeCommandTest extends TestCase
{
    private string $directory;
    private Application $app;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir() . '/wrla-command-' . bin2hex(random_bytes(6));
        mkdir($this->directory . '/config', 0755, true);
        mkdir($this->directory . '/bootstrap/cache', 0755, true);
        $this->app = new Application($this->directory);
        $this->app->instance('config', new Repository());
        Facade::setFacadeApplication($this->app);
        Once::instance()->flush();
        file_put_contents($this->directory . '/config/wr-laravel-administration.php', "<?php return ['base_url' => 'custom', 'developer' => ['enable' => fn (\$user) => \$user?->id === 7]];");
    }

    protected function tearDown(): void
    {
        Once::instance()->flush();
        Facade::clearResolvedInstances();
        (new Filesystem())->deleteDirectory($this->directory);
        parent::tearDown();
    }

    public function test_dry_run_warns_to_start_with_clean_git_and_does_not_change_files_or_caches(): void
    {
        [$command, $tester] = $this->command();
        $this->assertSame(0, $tester->execute(['--dry-run' => true], ['interactive' => false]));
        $this->assertStringContainsString('config/wrla.php', $tester->getDisplay());
        $this->assertStringContainsString('No files have been changed', $tester->getDisplay());
        $this->assertStringContainsString('Start with a clean Git worktree', $tester->getDisplay());
        $this->assertStringContainsString('No backup copies are created', $tester->getDisplay());
        $this->assertStringContainsString('test locally before finalising the upgrade', $tester->getDisplay());
        $this->assertSame([], $command->cleared);
        $this->assertFileExists($this->directory . '/config/wr-laravel-administration.php');
        $this->assertFileDoesNotExist($this->directory . '/config/wrla.php');
        $this->assertDirectoryDoesNotExist($this->directory . '/storage');
    }

    public function test_non_interactive_execution_does_not_imply_consent(): void
    {
        [$command, $tester] = $this->command();
        $this->assertSame(1, $tester->execute([], ['interactive' => false]));
        $this->assertStringContainsString('Upgrade not applied', $tester->getDisplay());
        $this->assertSame([], $command->cleared);
        $this->assertFileExists($this->directory . '/config/wr-laravel-administration.php');
        $this->assertDirectoryDoesNotExist($this->directory . '/storage');
    }

    public function test_declining_confirmation_does_not_change_the_application(): void
    {
        [$command, $tester] = $this->command();
        $tester->setInputs(['no']);
        $this->assertSame(1, $tester->execute([], ['interactive' => true]));
        $this->assertSame([], $command->cleared);
        $this->assertFileExists($this->directory . '/config/wr-laravel-administration.php');
        $this->assertDirectoryDoesNotExist($this->directory . '/storage');
    }

    public function test_explicit_approval_applies_the_upgrade_and_repeated_runs_do_nothing(): void
    {
        [$command, $tester] = $this->command();
        $this->assertSame(0, $tester->execute(['--yes' => true], ['interactive' => false]));
        $this->assertSame(['config:clear', 'route:clear', 'view:clear'], $command->cleared);
        $this->assertFileExists($this->directory . '/config/wrla.php');
        $this->assertFileDoesNotExist($this->directory . '/config/wr-laravel-administration.php');
        $this->assertDirectoryDoesNotExist($this->directory . '/storage/wrla');
        $this->assertStringContainsString('Review the Git diff and test before finalising the upgrade', $tester->getDisplay());
        $command->cleared = [];
        $this->assertSame(0, $tester->execute([], ['interactive' => false]));
        $this->assertStringContainsString('already up to date', $tester->getDisplay());
        $this->assertSame([], $command->cleared);
    }

    public function test_accepting_the_interactive_prompt_applies_the_upgrade(): void
    {
        [$command, $tester] = $this->command();
        $tester->setInputs(['yes']);
        $this->assertSame(0, $tester->execute([], ['interactive' => true]));
        $this->assertSame(['config:clear', 'route:clear', 'view:clear'], $command->cleared);
        $this->assertFileExists($this->directory . '/config/wrla.php');
        $this->assertFileDoesNotExist($this->directory . '/config/wr-laravel-administration.php');
    }

    public function test_legacy_config_remains_effective_before_migration_and_new_overrides_win(): void
    {
        config(['wr-laravel-administration' => ['base_url' => 'legacy-admin', 'database_browser' => ['enabled' => false]]]);
        $provider = new ConfigMergeProviderFixture($this->app);
        $provider->mergePublishedConfig();
        $this->assertSame('legacy-admin', config('wrla.base_url'));
        $this->assertFalse(config('wrla.database_browser.enabled'));
        config(['wrla.base_url' => 'new-admin']);
        $provider->mergePublishedConfig();
        $this->assertSame('new-admin', config('wrla.base_url'));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_fresh_install_generates_complete_runtime_settings_and_updates_the_actual_developer_rule(): void
    {
        $hostLoader = require getenv('WRLA_TEST_APP_PATH') . '/vendor/autoload.php';
        $loader = new \Composer\Autoload\ClassLoader();
        $loader->addClassMap(array_filter($hostLoader->getClassMap(), fn (string $class) => !str_starts_with($class, 'App\\'), ARRAY_FILTER_USE_KEY));
        foreach ($hostLoader->getPrefixesPsr4() as $namespace => $paths) {
            $loader->addPsr4($namespace, $paths);
        }
        $loader->setPsr4('App\\', [$this->directory . '/app']);
        $hostLoader->unregister();
        $loader->register();
        $this->assertFalse(class_exists(\App\WRLA\WRLASettings::class));
        $this->app->instance('files', new Filesystem());
        config(['wrla' => require dirname(__DIR__, 2) . '/src/config/wrla.php']);
        $_SERVER['argv'] = ['artisan', 'wrla:install'];
        (new WRLAServiceProvider($this->app))->boot();

        $command = new InstallRuntimeCommandFixture();
        $command->setLaravel($this->app);
        (new ConsoleApplication())->addCommand($command);
        $tester = new CommandTester($command);
        $tester->setInputs(['2']);
        $this->assertSame(0, $tester->execute([], ['interactive' => true]));
        $this->assertFalse(WRLASettingsService::needsSetup());
        WRLASettingsService::validate(\App\WRLA\WRLASettings::runtime());
        $source = file_get_contents($this->directory . '/app/WRLA/WRLASettings.php');
        $this->assertStringContainsString("'enable' => false,", $source);
        $this->assertStringContainsString('runtime settings updated successfully', $tester->getDisplay());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_migrated_application_can_cache_config_and_resolve_callbacks_after_a_fresh_boot(): void
    {
        UpgradeTo0100::apply($this->directory, UpgradeTo0100::plan($this->directory));
        mkdir($this->directory . '/storage/framework/views', 0755, true);
        file_put_contents($this->directory . '/bootstrap/app.php', <<<'PHP'
<?php
return \Illuminate\Foundation\Application::configure(basePath: dirname(__DIR__))
    ->withProviders([
        \Opcodes\LogViewer\LogViewerServiceProvider::class,
        \WebRegulate\LaravelAdministration\WRLAServiceProvider::class,
    ], withBootstrapProviders: false)
    ->create();
PHP);
        $loader = require getenv('WRLA_TEST_APP_PATH') . '/vendor/autoload.php';
        $loader->addClassMap(['App\\WRLA\\WRLASettings' => $this->directory . '/app/WRLA/WRLASettings.php']);
        $app = require $this->directory . '/bootstrap/app.php';
        $kernel = $app->make(Kernel::class);
        $kernel->bootstrap();
        $this->assertSame(0, $kernel->call('config:cache'), $kernel->output());
        $this->assertFileExists($this->directory . '/bootstrap/cache/config.php');
        $cached = require $this->directory . '/bootstrap/cache/config.php';
        $this->assertSame('custom', $cached['wrla']['base_url']);
        $this->assertArrayNotHasKey('enable', $cached['wrla']['developer']);
        $this->assertArrayNotHasKey('wr-laravel-administration', $cached);

        Once::instance()->flush();
        Facade::clearResolvedInstances();
        $app = require $this->directory . '/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();
        $this->assertTrue($app->configurationIsCached());
        $this->assertTrue(WRLASettingsService::get('developer.enable')((object) ['id' => 7]));
        $this->assertFalse(WRLASettingsService::get('developer.enable')((object) ['id' => 8]));
        $this->assertTrue(is_callable(WRLASettingsService::get('user_groups.admin')));
        $this->assertFalse(config('wrla.database_browser.read_only'));
    }

    private function command(): array
    {
        $command = new UpgradeCommandFixture();
        $command->setLaravel($this->app);
        (new ConsoleApplication())->addCommand($command);

        return [$command, new CommandTester($command)];
    }
}

class UpgradeCommandFixture extends UpgradeCommand
{
    public array $cleared = [];

    public function call($command, array $arguments = []): int
    {
        $this->cleared[] = $command;

        return 0;
    }
}

class InstallRuntimeCommandFixture extends InstallCommand
{
    public function handle(): int
    {
        (new \ReflectionMethod(InstallCommand::class, 'generateWRLASettingsClass'))->invoke($this);
        (new \ReflectionMethod(InstallCommand::class, 'promptConfigureDeveloperTools'))->invoke($this, null);

        return self::SUCCESS;
    }
}

class ConfigMergeProviderFixture extends WRLAServiceProvider
{
    public function mergePublishedConfig(): void
    {
        $this->deepMergeConfigFrom(dirname(__DIR__, 2) . '/src/config/wrla.php', 'wrla');
    }
}

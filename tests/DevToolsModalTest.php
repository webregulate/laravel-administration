<?php

namespace WebRegulate\LaravelAdministration\Tests;

use Illuminate\Config\Repository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Once;
use Illuminate\View\Compilers\BladeCompiler;
use Illuminate\View\Concerns\ManagesLoops;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use WebRegulate\LaravelAdministration\Classes\VersionHandler\VersionHandler;
use WebRegulate\LaravelAdministration\Commands\UpdateCommand;
use WebRegulate\LaravelAdministration\Livewire\DevTools\DevToolsModal;

class DevToolsModalTest extends TestCase
{
    private Application $app;

    protected function setUp(): void
    {
        parent::setUp();

        $path = getenv('WRLA_TEST_APP_PATH');
        if (!$path) {
            $this->markTestSkipped('Set WRLA_TEST_APP_PATH to a Laravel application with WRLA dependencies installed.');
        }

        $loader = require $path . '/vendor/autoload.php';
        $loader->addPsr4('WebRegulate\\LaravelAdministration\\', dirname(__DIR__) . '/src', true);
        $this->app = new Application($path);
        $this->app->instance('config', new Repository([
            'wr-laravel-administration' => require dirname(__DIR__) . '/src/config/wr-laravel-administration.php',
        ]));
        config([
            'wr-laravel-administration.developer.enable' => true,
            'wr-laravel-administration.models.wrla_user_data' => DevToolsUserDataFixture::class,
        ]);
        Facade::setFacadeApplication($this->app);
        $cache = Mockery::mock();
        $cache->shouldReceive('get')->with('wrla.version.composer_update_available')->andReturn(false);
        $cache->shouldReceive('forget')->andReturn(true);
        Cache::swap($cache);
        if (class_exists(VersionHandler::class, false)) {
            VersionHandler::clearComposerUpdateAvailableCache();
        }
        Once::instance()->flush();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        Once::instance()->flush();
        Facade::clearResolvedInstances();
        parent::tearDown();
    }

    public function test_default_commands_include_cache_clear_and_upgrade(): void
    {
        $commands = array_column((new DevToolsModalFixture())->availableCommands(), null, 'command');

        $this->assertArrayHasKey('php artisan optimize:clear', $commands);
        $this->assertFalse($commands['php artisan optimize:clear']['refresh']);
        $this->assertArrayHasKey('php artisan wrla:update --no-interaction', $commands);
        $this->assertTrue($commands['php artisan wrla:update --no-interaction']['refresh']);
    }

    public function test_upgrade_uses_configured_command_in_both_modes_and_retains_refresh_prompt(): void
    {
        $commands = array_column((new DevToolsModalFixture())->availableCommands(), null, 'command');
        $upgradeIndex = $commands['php artisan wrla:update --no-interaction']['index'];
        $cacheIndex = $commands['php artisan optimize:clear']['index'];

        foreach (['live', 'blocking'] as $mode) {
            $modal = new DevToolsModalFixture();
            $modal->mode = $mode;
            $modal->commands = [$upgradeIndex => ['command' => 'tampered client command', 'refresh' => false]];
            $modal->runCommand($upgradeIndex);

            $this->assertSame([$mode, 'php artisan wrla:update --no-interaction', 'Update WRLA'], $modal->executed);
            $this->assertSame('update', $modal->runType);
            $modal->finish();
            $this->assertTrue($modal->updateCompleted);
            $this->assertFalse($modal->commandCompleted);
            $this->assertFalse($modal->running);
            $this->assertNull($modal->runType);
            $this->assertFalse($modal->composerUpdateAvailable);

            $modal->runCommand($cacheIndex);
            $modal->finish();
            $this->assertTrue($modal->commandCompleted);
            $this->assertFalse($modal->updateCompleted);
        }
    }

    public function test_commands_are_checked_server_side_and_cannot_overlap(): void
    {
        config(['wr-laravel-administration.developer.commands' => [
            ['command' => 'allowed', 'condition' => fn ($userData) => $userData === null],
            ['command' => 'denied', 'condition' => false],
        ]]);

        $modal = new DevToolsModalFixture();
        $modal->runCommand(1);
        $this->assertSame([], $modal->executed);
        $this->assertStringContainsString('not available', $modal->consoleOutput);

        $modal->runCommand(0);
        $this->assertSame(['live', 'allowed', 'allowed'], $modal->executed);
        $modal->executed = [];
        $modal->runCommand(0);
        $this->assertSame([], $modal->executed);
    }

    public function test_unauthorised_users_cannot_execute_commands(): void
    {
        config(['wr-laravel-administration.developer.enable' => false]);
        $modal = new DevToolsModalFixture();
        $modal->runCommand(1);

        $this->assertFalse($modal->authorised);
        $this->assertSame([], $modal->executed);
    }

    public function test_live_output_is_streamed_and_update_completion_requests_refresh(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'wrla-modal-');
        try {
            $modal = new DevToolsModalFixture();
            $modal->outputLogPath = $path;
            $modal->running = true;
            $modal->runType = 'update';
            file_put_contents($path, "\x1b[32mUpdating...\x1b[0m\n");
            $modal->pollOutput();
            $this->assertTrue($modal->running);
            $this->assertSame("Updating...\n", $modal->consoleOutput);

            file_put_contents($path, "Finished\n[[WRLA_UPDATE_COMPLETE]]\n");
            $modal->pollOutput();
            $this->assertFalse($modal->running);
            $this->assertTrue($modal->updateCompleted);
            $this->assertFalse($modal->commandCompleted);
            $this->assertSame('Finished', $modal->consoleOutput);
        } finally {
            unlink($path);
        }
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    #[DataProvider('updateOutcomes')]
    public function test_upgrade_automatically_clears_cache_and_returns_correct_exit_code(bool $composer, bool $clear, int $exitCode): void
    {
        $handler = Mockery::mock('overload:' . VersionHandler::class);
        $handler->shouldReceive('runComposerUpdate')->once()->andReturn($composer);
        if ($composer) {
            $handler->shouldReceive('runOptimizeClear')->once()->andReturn($clear);
        } else {
            $handler->shouldNotReceive('runOptimizeClear');
        }

        $this->assertSame($exitCode, (new UpdateCommand())->handle());
    }

    public static function updateOutcomes(): array
    {
        return [[true, true, 0], [false, true, 1], [true, false, 1]];
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    #[DataProvider('updateStatusValues')]
    public function test_mount_loads_the_current_package_update_status(?bool $available): void
    {
        $handler = Mockery::mock('alias:' . VersionHandler::class);
        $handler->shouldReceive('getLocalVersion')->once()->andReturn('0.8.57');
        $handler->shouldReceive('isComposerUpdateAvailable')->once()->andReturn($available);

        $modal = new DevToolsModalFixture();
        $modal->mount();

        $this->assertSame('0.8.57', $modal->currentVersion);
        $this->assertSame($available, $modal->composerUpdateAvailable);
    }

    public static function updateStatusValues(): array
    {
        return [[true], [false], [null]];
    }

    public function test_modal_displays_installed_version_resources_and_command_states(): void
    {
        config([
            'wr-laravel-administration.database_browser.enabled' => true,
            'wr-laravel-administration.database_schema_viewer.enabled' => true,
            'wr-laravel-administration.scheduler.enabled' => true,
        ]);
        Route::swap(new class {
            public function has(string $name): bool
            {
                return false;
            }
        });
        $compiler = new BladeCompiler(new Filesystem(), sys_get_temp_dir());
        $compiler->withoutComponentTags();
        $compiled = $compiler->compileString(file_get_contents(dirname(__DIR__) . '/src/resources/views/themes/default/livewire/dev-tools/dev-tools-modal.blade.php'));
        $commands = (new DevToolsModalFixture())->availableCommands();
        $render = static function (array $state) use ($compiled, $commands): string {
            $__env = new class { use ManagesLoops; };
            extract(array_merge([
                'authorised' => true, 'running' => false, 'runningLabel' => null,
                'currentVersion' => 'dev-main', 'commands' => $commands,
                'composerUpdateAvailable' => false,
                'consoleOutput' => 'Installed package version: dev-main',
                'updateCompleted' => false, 'commandCompleted' => false,
            ], $state));
            ob_start();
            try {
                eval('?>' . $compiled);
                return ob_get_contents();
            } finally {
                ob_end_clean();
            }
        };

        $html = $render([]);
        $this->assertStringContainsString('dev-main', $html);
        $this->assertStringContainsString('Up to date', $html);
        $this->assertStringContainsString('Update available', $render(['composerUpdateAvailable' => true]));
        $this->assertStringContainsString('Unable to check updates', $render(['composerUpdateAvailable' => null]));
        $this->assertStringContainsString('Documentation', $html);
        $this->assertStringContainsString('#versions/versions.html', $html);
        $this->assertStringContainsString('sm:grid-cols-2', $html);
        $this->assertStringContainsString('runCommand(1)', $html);
        $this->assertStringNotContainsString('runComposerOnly', $html);
        $this->assertStringNotContainsString('#versions/vdev-main.html', $html);
        $this->assertStringNotContainsString('Inspect &amp; Monitor', $html);
        $this->assertStringContainsString('Update WRLA running...', $render(['running' => true, 'runningLabel' => 'Update WRLA']));
        $this->assertStringContainsString('Refresh page', $render(['updateCompleted' => true]));
        $this->assertStringContainsString('No developer commands configured.', $render(['commands' => []]));
        $this->assertStringNotContainsString('runCommand(', $render(['authorised' => false]));
    }
}

class DevToolsUserDataFixture
{
    public static function getCurrentUserData(): mixed
    {
        return null;
    }
}

class DevToolsModalFixture extends DevToolsModal
{
    public array $executed = [];
    public ?string $outputLogPath = null;

    public function availableCommands(): array
    {
        return $this->resolveCommands();
    }

    public function finish(): void
    {
        $this->completeRun();
    }

    protected function runLiveCommand(string $command, string $label): void
    {
        $this->executed = ['live', $command, $label];
        $this->running = true;
    }

    protected function runBlockingCommand(string $command, string $label): void
    {
        $this->executed = ['blocking', $command, $label];
        $this->running = true;
    }

    protected function logPath(): string
    {
        return $this->outputLogPath ?? parent::logPath();
    }
}
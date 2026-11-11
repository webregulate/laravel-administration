<?php

namespace WebRegulate\LaravelAdministration\Tests\Unit\Services;

use Illuminate\Config\Repository;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Once;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use WebRegulate\LaravelAdministration\Classes\ManageableModel;
use WebRegulate\LaravelAdministration\Classes\WRLAHelper;
use WebRegulate\LaravelAdministration\Enums\ManageableModelPermissions;
use WebRegulate\LaravelAdministration\Services\WRLASettingsService;
use WebRegulate\LaravelAdministration\Tests\Fixtures\RuntimeSettings as RuntimeSettingsFixture;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class WRLASettingsServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $app = new Application(getenv('WRLA_TEST_APP_PATH'));
        $app->instance('config', new Repository([
            'wrla' => require dirname(__DIR__, 3) . '/src/config/wrla.php',
        ]));
        Facade::setFacadeApplication($app);
        Once::instance()->flush();
        RuntimeSettingsFixture::useForApplication();
    }

    protected function tearDown(): void
    {
        Once::instance()->flush();
        Facade::clearResolvedInstances();
        parent::tearDown();
    }

    public function test_config_is_exportable_and_the_complete_application_template_provides_callbacks(): void
    {
        $config = config('wrla');
        $export = var_export($config, true);
        $restored = eval('return ' . $export . ';');
        $this->assertSame($config, $restored);
        foreach (WRLASettingsService::RUNTIME_KEYS as $key) {
            $this->assertFalse(\Illuminate\Support\Arr::has($config, $key), $key . ' must not be in config.');
        }
        $this->assertSame([], WRLASettingsService::missingKeys(RuntimeSettingsFixture::$values));
        $this->assertTrue(is_callable(WRLASettingsService::get('developer.enable')));
        $this->assertTrue(is_callable(WRLASettingsService::get('documentation.show_link')));
        $this->assertTrue(is_callable(WRLASettingsService::get('user_groups.admin')));
        $permission = ManageableModel::getDefaultPermission(ManageableModelPermissions::ENABLED);
        $admin = new class {
            public function isAdmin(): bool { return true; }
        };
        $this->assertTrue($permission($admin));
        $this->assertFalse($permission(null));
        $this->assertSame(42, WRLASettingsService::get('build_wrla_user_data_id')((object) ['id' => 42]));
    }

    public function test_runtime_values_override_config_without_inserting_objects_into_the_repository(): void
    {
        config(['wrla.developer.enable' => false]);
        $callback = fn ($user) => $user->allowed;
        RuntimeSettingsFixture::set([
            'developer.enable' => $callback,
            'user_avatar' => fn ($user) => $user->avatar,
            'database_browser.permissions.delete' => false,
            'build_wrla_user_data_id' => fn ($user) => 'tenant-' . $user->id,
        ]);
        $this->assertSame($callback, WRLASettingsService::get('developer.enable'));
        $this->assertTrue(WRLASettingsService::get('developer.enable')((object) ['allowed' => true]));
        $this->assertSame('/avatar.png', WRLASettingsService::get('user_avatar')((object) ['avatar' => '/avatar.png']));
        $this->assertFalse(WRLASettingsService::get('database_browser.permissions.delete'));
        $this->assertTrue(WRLASettingsService::get('database_browser.permissions.create'));
        $this->assertFalse(config('wrla.developer.enable'));
        $this->assertNull(config('wrla.user_avatar'));
        $this->assertSame('tenant-42', WRLAHelper::buildUserId((object) ['id' => 42]));
        $this->assertSame(1, RuntimeSettingsFixture::$calls);
    }

    public function test_false_null_empty_lists_and_scalar_overrides_are_not_lost(): void
    {
        config(['wrla.developer.enable' => true, 'wrla.documentation.show_link' => true]);
        RuntimeSettingsFixture::set([
            'developer.enable' => null,
            'developer.commands' => [],
            'documentation.show_link' => false,
            'user_groups' => [],
        ]);
        $this->assertNull(WRLASettingsService::get('developer.enable'));
        $this->assertFalse(WRLASettingsService::get('documentation.show_link'));
        $this->assertSame([], WRLASettingsService::get('developer.commands'));
        $this->assertSame([], WRLASettingsService::get('user_groups'));
    }

    public function test_callbacks_are_not_evaluated_or_their_user_results_memoized_by_the_resolver(): void
    {
        $user = (object) ['allowed' => false];
        RuntimeSettingsFixture::set(['scheduler.enabled' => fn ($user) => $user->allowed]);
        $callback = WRLASettingsService::get('scheduler.enabled');
        $this->assertFalse($callback($user));
        $user->allowed = true;
        $this->assertTrue(WRLASettingsService::get('scheduler.enabled')($user));
        $this->assertSame(1, RuntimeSettingsFixture::$calls);
    }

    public function test_unsupported_runtime_keys_fail_instead_of_being_silently_ignored(): void
    {
        RuntimeSettingsFixture::set(['developer.update.mode' => 'blocking']);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported runtime setting developer.update');
        WRLASettingsService::get('developer.enable');
    }

    public function test_missing_required_settings_do_not_fall_back_to_config_or_package_values(): void
    {
        unset(RuntimeSettingsFixture::$values['developer']['enable']);
        config(['wrla.developer.enable' => true]);
        $this->assertTrue(WRLASettingsService::needsSetup());
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('developer.enable. Run php artisan wrla:upgrade');
        WRLASettingsService::get('developer.enable');
    }

    public function test_missing_permission_members_are_reported(): void
    {
        unset(RuntimeSettingsFixture::$values['default_manageable_model_permissions']['SHOW_IN_NAV']);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('default_manageable_model_permissions.SHOW_IN_NAV');
        WRLASettingsService::get();
    }

    public function test_unknown_runtime_lookups_fail_clearly(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown WRLA runtime setting unknown');
        WRLASettingsService::get('unknown');
    }

    public function test_invalid_user_identifier_builders_do_not_fall_back_to_the_model_id(): void
    {
        RuntimeSettingsFixture::set(['build_wrla_user_data_id' => null]);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('callable build_wrla_user_data_id');
        WRLAHelper::buildUserId((object) ['id' => 42]);
    }
}

<?php

namespace WebRegulate\LaravelAdministration\Tests\Unit\Services\Upgrades;

use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\TestCase;
use WebRegulate\LaravelAdministration\Services\Upgrades\UpgradeTo0100;
use WebRegulate\LaravelAdministration\Services\WRLASettingsService;

class UpgradeTo0100Test extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir() . '/wrla-upgrade-' . bin2hex(random_bytes(6));
        mkdir($this->directory . '/config', 0755, true);
        mkdir($this->directory . '/app/WRLA', 0755, true);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->deleteDirectory($this->directory);
        parent::tearDown();
    }

    public function test_migration_preserves_imports_callbacks_and_customizations_without_creating_backups(): void
    {
        $config = <<<'PHP'
<?php
use WebRegulate\LaravelAdministration\Enums\ManageableModelPermissions as Permission;
use Illuminate\Support\Collection as Users;

return [
    'base_url' => 'my-admin',
    'default_manageable_model_permissions' => [
        Permission::ENABLED->value => fn ($user) => $user?->allowed ?? false,
        Permission::DELETE->value => false,
    ],
    'developer' => [
        'enable' => fn ($user) => $user?->id === 7,
        'commands' => [
            ['command' => 'example', 'condition' => fn ($user) => $user?->allowed],
        ],
        'update' => ['mode' => 'blocking'],
    ],
    'middleware' => ['commands' => [fn ($command) => $command->line('ready')]],
    'user_groups' => ['custom' => fn () => new Users([7])],
    'build_wrla_user_data_id' => function (mixed $user) {
        return 'tenant-' . $user->id;
    },
    'user_avatar' => fn ($user) => $user->avatar,
    'database_browser' => ['enabled' => false, 'permissions' => ['delete' => fn ($user) => $user?->allowed]],
    'scheduler' => ['enabled' => fn ($user) => is_file(__DIR__ . '/ready')],
];
PHP;
        $settings = <<<'PHP'
<?php
namespace App\WRLA;

class WRLASettings
{
    public const CUSTOM_VALUE = 'preserved';

    // Keep application navigation unchanged
    public static function buildNavigation(): array { return ['custom-navigation']; }
    public static function buildRoutes(): void {}
}
PHP;
        $this->put('config/wr-laravel-administration.php', $config);
        $this->put('app/WRLA/WRLASettings.php', $settings);
        $this->put('app/Example.php', "<?php\nreturn config('wr-laravel-administration.base_url');");
        $this->put('app/Callbacks.php', "<?php\nreturn config('wr-laravel-administration.user_avatar');");
        $this->put('resources/views/example.blade.php', "{{ config('wr-laravel-administration.scheduler.enabled') }}");
        $this->put('bootstrap/cache/config.php', "<?php\nreturn ['wr-laravel-administration.base_url'];");

        $changes = UpgradeTo0100::plan($this->directory);
        $this->assertSame($config, file_get_contents($this->directory . '/config/wr-laravel-administration.php'));
        $this->assertDirectoryDoesNotExist($this->directory . '/storage');
        $this->assertStringContainsString('custom-navigation', $changes['app/WRLA/WRLASettings.php']['after']);
        $this->assertStringContainsString('\\Illuminate\\Support\\Collection', $changes['app/WRLA/WRLASettings.php']['after']);
        $this->assertStringContainsString('\\config_path()', $changes['app/WRLA/WRLASettings.php']['after']);
        $generatedSettings = $changes['app/WRLA/WRLASettings.php']['after'];
        $this->assertMatchesRegularExpression('/public const CUSTOM_VALUE.*public static function runtime.*public static function buildNavigation/s', $generatedSettings);
        $this->assertMatchesRegularExpression('/    \}\r?\n\r?\n    \/\/ Keep application navigation unchanged/', $generatedSettings);
        $this->assertStringContainsString("public static function buildNavigation(): array { return ['custom-navigation']; }", $generatedSettings);
        $this->assertStringNotContainsString('fn ', $changes['config/wrla.php']['after']);
        $this->assertStringContainsString('WRLASettingsService::get', $changes['app/Callbacks.php']['after']);
        $this->assertStringContainsString('WRLASettingsService::get', $changes['resources/views/example.blade.php']['after']);
        $this->assertArrayNotHasKey('bootstrap/cache/config.php', $changes);
        UpgradeTo0100::apply($this->directory, $changes);
        $this->assertFileDoesNotExist($this->directory . '/config/wr-laravel-administration.php');
        $this->assertDirectoryDoesNotExist($this->directory . '/storage/wrla');
        $static = require $this->directory . '/config/wrla.php';
        $this->assertSame('my-admin', $static['base_url']);
        $this->assertSame('blocking', $static['developer']['update']['mode']);
        $this->assertArrayNotHasKey('database_browser', $static);
        $this->assertSame([], UpgradeTo0100::plan($this->directory));
    }

    public function test_generated_callbacks_remain_executable_with_their_original_aliases(): void
    {
        $this->put('config/wr-laravel-administration.php', <<<'PHP'
<?php
use Illuminate\Support\Collection as UserCollection;
return [
    'developer' => ['enable' => fn ($user) => $user?->id === 7],
    'user_groups' => ['custom' => fn () => new UserCollection([7])],
    'build_wrla_user_data_id' => fn ($user) => 'tenant-' . $user->id,
];
PHP);
        $changes = UpgradeTo0100::plan($this->directory);
        $source = str_replace(['namespace App\\WRLA;', 'class WRLASettings'], ['namespace WRLAUpgradeFixture;', 'class MigratedSettings'], $changes['app/WRLA/WRLASettings.php']['after']);
        eval(substr($source, 5));
        $runtime = \WRLAUpgradeFixture\MigratedSettings::runtime();
        $this->assertSame([], WRLASettingsService::missingKeys($runtime));
        $this->assertTrue($runtime['developer']['enable']((object) ['id' => 7]));
        $this->assertFalse($runtime['developer']['enable']((object) ['id' => 8]));
        $this->assertSame([7], $runtime['user_groups']['custom']()->all());
        $this->assertSame('tenant-7', $runtime['build_wrla_user_data_id']((object) ['id' => 7]));
    }

    public function test_generated_runtime_avoids_unnecessary_qualification_and_callback_formatting(): void
    {
        $this->put('config/wr-laravel-administration.php', <<<'PHP'
<?php return [
    'developer' => [
        'enable' => fn ($user) => $user?->allowed ?? false,
        'commands' => [['command' => 'echo \true', 'condition' => true, 'refresh' => false]],
    ],
    'user_avatar' => fn ($user) => '\null',
    'scheduler' => ['enabled' => fn () => PHP_VERSION_ID > 0, 'can_run_adhoc' => null],
];
PHP);
        $changes = UpgradeTo0100::plan($this->directory);
        $source = $changes['app/WRLA/WRLASettings.php']['after'];
        $nodes = (new \PhpParser\ParserFactory())->createForNewestSupportedVersion()->parse($source);
        $qualifiedLiterals = (new \PhpParser\NodeFinder())->find($nodes, fn ($node) =>
            $node instanceof \PhpParser\Node\Expr\ConstFetch && $node->name instanceof \PhpParser\Node\Name\FullyQualified
            && in_array(strtolower($node->name->toString()), ['true', 'false', 'null'], true));
        $this->assertSame([], $qualifiedLiterals);
        $this->assertStringContainsString('\\PHP_VERSION_ID', $source);
        $this->assertStringContainsString("whereJsonContains('permissions', ['admin' => true])", $source);
        $this->assertStringNotContainsString('\\WebRegulate\\LaravelAdministration\\Enums\\ManageableModelPermissions::', $source);
        $source = str_replace(['namespace App\\WRLA;', 'class WRLASettings'], ['namespace WRLAUpgradeFixture;', 'class ReadableSettings'], $source);
        eval(substr($source, 5));
        $runtime = \WRLAUpgradeFixture\ReadableSettings::runtime();
        $this->assertFalse($runtime['developer']['enable'](null));
        $this->assertTrue($runtime['developer']['commands'][0]['condition']);
        $this->assertFalse($runtime['developer']['commands'][0]['refresh']);
        $this->assertSame('echo \true', $runtime['developer']['commands'][0]['command']);
        $this->assertSame('\null', $runtime['user_avatar'](null));
        $this->assertTrue($runtime['scheduler']['enabled']());
        $this->assertNull($runtime['scheduler']['can_run_adhoc']);
        UpgradeTo0100::apply($this->directory, $changes);
        $this->assertSame([], UpgradeTo0100::plan($this->directory));
    }

    public function test_existing_runtime_builtin_values_are_cleaned_up_without_changing_their_meaning(): void
    {
        $this->put('app/WRLA/WRLASettings.php', <<<'PHP'
<?php
namespace App\WRLA;
class WRLASettings {
    public static function runtime(): array {
        return ['developer' => ['enable' => \false, 'commands' => []], 'user_avatar' => \null];
    }
}
PHP);
        $changes = UpgradeTo0100::plan($this->directory);
        $source = $changes['app/WRLA/WRLASettings.php']['after'];
        $this->assertStringContainsString("'enable' => false", $source);
        $this->assertStringContainsString("'user_avatar' => null", $source);
        $this->assertStringNotContainsString('\\false', $source);
        $this->assertStringNotContainsString('\\null', $source);
        UpgradeTo0100::apply($this->directory, $changes);
        $this->assertSame([], UpgradeTo0100::plan($this->directory));
    }

    public function test_existing_disjoint_runtime_overrides_are_preserved(): void
    {
        $this->put('config/wr-laravel-administration.php', "<?php return ['developer' => ['enable' => fn (\$user) => true]];");
        $this->put('app/WRLA/WRLASettings.php', <<<'PHP'
<?php
namespace App\WRLA;
class WRLASettings {
    public static function buildRoutes(): void {}

    public static function runtime(): array {
        return ['developer' => ['commands' => []], 'user_avatar' => null];
    }
}
PHP);
        $changes = UpgradeTo0100::plan($this->directory);
        $this->assertStringContainsString("'commands' => []", $changes['app/WRLA/WRLASettings.php']['after']);
        $this->assertStringContainsString("'user_avatar' => null", $changes['app/WRLA/WRLASettings.php']['after']);
        $this->assertStringContainsString("'enable'", $changes['app/WRLA/WRLASettings.php']['after']);
        $this->assertLessThan(
            strpos($changes['app/WRLA/WRLASettings.php']['after'], 'public static function buildRoutes'),
            strpos($changes['app/WRLA/WRLASettings.php']['after'], 'public static function runtime'),
        );
    }

    public function test_ambiguous_config_files_do_not_change_any_files_and_existing_runtime_values_win(): void
    {
        $source = "<?php return ['developer' => ['enable' => fn (\$user) => true]];";
        $this->put('config/wr-laravel-administration.php', $source);
        $this->put('config/wrla.php', '<?php return [];');
        try {
            UpgradeTo0100::plan($this->directory);
            $this->fail('Two config files must not be automatically merged.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Both config/', $exception->getMessage());
        }
        unlink($this->directory . '/config/wrla.php');
        $this->put('app/WRLA/WRLASettings.php', "<?php namespace App\\WRLA; class WRLASettings { public static function runtime(): array { return ['developer' => ['enable' => false]]; } }");
        $changes = UpgradeTo0100::plan($this->directory);
        $this->assertStringContainsString("'enable' => false", $changes['app/WRLA/WRLASettings.php']['after']);
        $this->assertSame($source, file_get_contents($this->directory . '/config/wr-laravel-administration.php'));
        $this->assertDirectoryDoesNotExist($this->directory . '/storage');
    }

    public function test_file_scope_logic_and_unsupported_callbacks_require_manual_review(): void
    {
        foreach ([
            '<?php $flag = true; return ["developer" => ["enable" => fn () => $flag]];',
            '<?php return ["unknown" => fn () => true];',
            '<?php return ["developer" => ["enable" => function () use ($flag) { return $flag; }]];',
            '<?php return ["developer" => ["enable" => fn () => $config->get("flag")]];',
            '<?php return ["developer" => ["enable" => fn () => __CLASS__]];',
            '<?php return [...$settings];',
        ] as $source) {
            $this->put('config/wr-laravel-administration.php', $source);
            try {
                UpgradeTo0100::plan($this->directory);
                $this->fail('Unsupported config must not be evaluated or migrated.');
            } catch (\RuntimeException $exception) {
                $this->assertNotSame('', $exception->getMessage());
            }
            $this->assertSame($source, file_get_contents($this->directory . '/config/wr-laravel-administration.php'));
            $this->assertFileDoesNotExist($this->directory . '/config/wrla.php');
        }
    }

    public function test_a_stale_preview_cannot_overwrite_a_developers_changes(): void
    {
        $this->put('config/wr-laravel-administration.php', '<?php return ["base_url" => "original"];');
        $changes = UpgradeTo0100::plan($this->directory);
        $edited = '<?php return ["base_url" => "edited"];';
        $this->put('config/wr-laravel-administration.php', $edited);
        try {
            UpgradeTo0100::apply($this->directory, $changes);
            $this->fail('Stale plan must be rejected.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('changed after', $exception->getMessage());
        }
        $this->assertSame($edited, file_get_contents($this->directory . '/config/wr-laravel-administration.php'));
        $this->assertFileDoesNotExist($this->directory . '/config/wrla.php');
    }

    public function test_only_config_names_change_not_view_namespaces_or_asset_paths(): void
    {
        $source = <<<'PHP'
<?php
config('wr-laravel-administration.base_url');
config('wr-laravel-administration.default_manageable_model_permissions.'.$permission);
Config::get("wr-laravel-administration.scheduler.enabled");
\Illuminate\Support\Facades\Config::get('wr-laravel-administration.user_avatar');
config()->get('wr-laravel-administration.developer.commands');
view('wr-laravel-administration::example');
$asset = 'vendor/wr-laravel-administration/images/logo.svg';
PHP;
        $after = UpgradeTo0100::rewriteReferences($source);
        $this->assertStringContainsString("config('wrla.base_url')", $after);
        $this->assertSame(4, substr_count($after, 'WRLASettingsService::get('));
        $this->assertStringContainsString("view('wr-laravel-administration::example')", $after);
        $this->assertStringContainsString('vendor/wr-laravel-administration/images/logo.svg', $after);
        $this->assertSame($after, UpgradeTo0100::rewriteReferences($after));
    }

    public function test_write_failure_rolls_back_originals_and_removes_created_files(): void
    {
        $this->put('app/a.php', '<?php return "original-a";');
        $this->put('app/b.php', '<?php return "original-b";');
        $changes = [
            'app/a.php' => ['before' => '<?php return "original-a";', 'after' => '<?php return "new-a";'],
            'app/created.php' => ['before' => null, 'after' => '<?php return "created";'],
            'app/b.php' => ['before' => '<?php return "original-b";', 'after' => 'simulate-write-failure'],
        ];
        try {
            FailingUpgradeFixture::apply($this->directory, $changes);
            $this->fail('Injected write failure must abort and roll back.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Source changes were rolled back', $exception->getMessage());
        }
        $this->assertSame('<?php return "original-a";', file_get_contents($this->directory . '/app/a.php'));
        $this->assertSame('<?php return "original-b";', file_get_contents($this->directory . '/app/b.php'));
        $this->assertFileDoesNotExist($this->directory . '/app/created.php');
    }

    public function test_another_upgrade_cannot_apply_while_the_application_is_locked(): void
    {
        $this->put('storage/framework/cache/wrla-upgrade.lock', '');
        $lock = fopen($this->directory . '/storage/framework/cache/wrla-upgrade.lock', 'c');
        flock($lock, LOCK_EX);
        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Another WRLA application upgrade is running');
            UpgradeTo0100::apply($this->directory, []);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function test_facade_aliases_scalar_setters_and_blade_php_blocks_are_migrated(): void
    {
        $source = <<<'PHP'
<?php
use Illuminate\Support\Facades\Config as Settings;
Settings::get('wr-laravel-administration.developer.enable', false);
Settings::set('wr-laravel-administration.base_url', 'admin');
config(['other' => 1, 'wr-laravel-administration.database_browser.read_only' => true]);
app('config')->get('wr-laravel-administration.scheduler.enabled');
$path = config_path('wr-laravel-administration.php');
$asset = 'vendor/wr-laravel-administration.js';
PHP;
        $after = UpgradeTo0100::rewriteReferences($source);
        $this->assertSame(2, substr_count($after, 'WRLASettingsService::get('));
        $this->assertStringContainsString("Settings::set('wrla.base_url', 'admin')", $after);
        $this->assertStringContainsString("'wrla.database_browser.read_only' => true", $after);
        $this->assertStringContainsString("config_path('wrla.php')", $after);
        $this->assertStringContainsString('vendor/wr-laravel-administration.js', $after);
        $blade = "@php\nconfig(['wr-laravel-administration.database_browser.read_only' => false]);\n@endphp\n{{ config('wr-laravel-administration.user_avatar') }}";
        $rewritten = UpgradeTo0100::rewriteReferences($blade);
        $this->assertStringContainsString("'wrla.database_browser.read_only' => false", $rewritten);
        $this->assertStringContainsString('WRLASettingsService::get', $rewritten);
        $this->assertSame($rewritten, UpgradeTo0100::rewriteReferences($rewritten));
    }

    public function test_mixed_owner_reads_and_runtime_config_writes_require_manual_migration(): void
    {
        foreach ([
            "<?php config('wr-laravel-administration');",
            "<?php config('wr-laravel-administration.developer');",
            "<?php config(['wr-laravel-administration.developer.enable' => false]);",
            "<?php Config::set('wr-laravel-administration.scheduler.enabled', true);",
            "<?php config(['wrla' => ['developer' => ['enable' => true]]]);",
            "<?php Config::set('wrla.database_browser', ['enabled' => false]);",
        ] as $source) {
            try {
                UpgradeTo0100::rewriteReferences($source);
                $this->fail('This reference must not be silently changed to read the wrong owner.');
            } catch (\RuntimeException $exception) {
                $this->assertNotSame('', $exception->getMessage());
            }
        }
    }

    public function test_explicit_new_namespace_scalar_sections_remain_scalar_reads_and_writes(): void
    {
        $source = "<?php config('wrla'); config('wrla.developer'); config(['wrla' => ['developer' => ['update' => ['mode' => 'live']]]]);";
        $this->assertSame($source, UpgradeTo0100::rewriteReferences($source));
    }

    public function test_scalar_only_migration_generates_all_options_and_preserves_explicit_empty_values(): void
    {
        $this->put('config/wr-laravel-administration.php', <<<'PHP'
<?php return [
    'developer' => ['enable' => false, 'commands' => []],
    'documentation' => ['show_link' => false],
    'user_groups' => [],
    'user_avatar' => null,
    'middleware' => ['commands' => []],
    'database_browser' => ['enabled' => false, 'permissions' => ['edit' => false]],
];
PHP);
        $changes = UpgradeTo0100::plan($this->directory);
        $source = str_replace(['namespace App\\WRLA;', 'class WRLASettings'], ['namespace WRLAUpgradeFixture;', 'class ScalarSettings'], $changes['app/WRLA/WRLASettings.php']['after']);
        $this->assertMatchesRegularExpression('/\n\s+ManageableModelPermissions::CREATE/', $source);
        eval(substr($source, 5));
        $runtime = \WRLAUpgradeFixture\ScalarSettings::runtime();
        $this->assertSame([], WRLASettingsService::missingKeys($runtime));
        $this->assertFalse($runtime['developer']['enable']);
        $this->assertSame([], $runtime['developer']['commands']);
        $this->assertSame([], $runtime['user_groups']);
        $this->assertNull($runtime['user_avatar']);
        $this->assertFalse($runtime['database_browser']['permissions']['edit']);
        $this->assertTrue($runtime['database_browser']['permissions']['create']);
        UpgradeTo0100::apply($this->directory, $changes);
        $this->assertSame([], UpgradeTo0100::plan($this->directory));
    }

    public function test_ambiguous_dynamic_config_reads_are_reported_for_manual_review(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('computed WRLA config key');
        UpgradeTo0100::rewriteReferences('<?php config("wr-laravel-administration.{$setting}");');
    }

    public function test_blade_rewrites_preserve_php_boundaries_and_all_other_bytes(): void
    {
        foreach ([
            "@php\n    use App\\Services\\NavigationService;\n@endphp\n<header>{{ config('wr-laravel-administration.base_url') }}</header>\n",
            "<div>{{ config('wr-laravel-administration.base_url') }}</div>",
            "\r\n<div>{{ config('wr-laravel-administration.base_url') }}</div>\r\n",
            "<?php \$url = config('wr-laravel-administration.base_url'); ?>\n<div>{{ config('wr-laravel-administration.base_url') }}</div>\n",
            "<header>\n<?php echo config('wr-laravel-administration.base_url'); ?>\n{{ config('wr-laravel-administration.base_url') }}\n</header>",
            "@php\n\$url = config('wr-laravel-administration.base_url');\n@endphp\n{{ \$url }}",
            "@include('wr-laravel-administration::example')\n",
        ] as $source) {
            $expected = str_replace('wr-laravel-administration.base_url', 'wrla.base_url', $source);
            $rewritten = UpgradeTo0100::rewriteReferences($source);
            $this->assertSame($expected, $rewritten);
            $this->assertSame($rewritten, UpgradeTo0100::rewriteReferences($rewritten));
        }
    }

    public function test_blade_runtime_reads_drop_obsolete_defaults_without_touching_surrounding_markup(): void
    {
        $source = <<<'BLADE'
<header>{{ config('wr-laravel-administration.base_url', 'admin') }}</header>
@if(config('wr-laravel-administration.developer.enable', fn () => [config('wr-laravel-administration.scheduler.enabled', false), ')']))
    {!! config()->get(
        'wr-laravel-administration.user_avatar',
        avatar_url(')', ['fallback' => true]),
    ) !!}
@endif
BLADE;
        $service = '\\' . WRLASettingsService::class;
        $expected = "<header>{{ config('wrla.base_url', 'admin') }}</header>\n"
            . "@if({$service}::get('developer.enable'))\n"
            . "    {!! {$service}::get('user_avatar') !!}\n"
            . '@endif';
        foreach (["\n", "\r\n"] as $newline) {
            $input = str_replace("\n", $newline, str_replace("\r\n", "\n", $source));
            $rewritten = UpgradeTo0100::rewriteReferences($input);
            $this->assertSame(str_replace("\n", $newline, $expected), $rewritten);
            $this->assertSame($rewritten, UpgradeTo0100::rewriteReferences($rewritten));
        }
    }

    private function put(string $relative, string $contents): void
    {
        $path = $this->directory . '/' . $relative;

        // Create only this fixture's required parent directory
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }

        file_put_contents($path, $contents);
    }
}

class FailingUpgradeFixture extends UpgradeTo0100
{
    protected static function write(string $path, string $contents): void
    {
        // Simulate a disk failure without relying on OS-specific filesystem permissions
        if ($contents === 'simulate-write-failure') {
            throw new \RuntimeException('Simulated disk write failure.');
        }

        parent::write($path, $contents);
    }
}

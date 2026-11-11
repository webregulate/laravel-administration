<?php

namespace WebRegulate\LaravelAdministration\Services;

use Illuminate\Support\Arr;
use WebRegulate\LaravelAdministration\Enums\ManageableModelPermissions;

class WRLASettingsService
{
    /**
     * Settings owned exclusively by App\WRLA\WRLASettings::runtime().
     */
    public const RUNTIME_KEYS = [
        'default_manageable_model_permissions',
        'developer.enable',
        'developer.commands',
        'documentation.show_link',
        'user_avatar',
        'user_groups',
        'build_wrla_user_data_id',
        'middleware.commands',
        'database_browser.enabled',
        'database_browser.permissions',
        'database_schema_viewer.enabled',
        'scheduler.enabled',
        'scheduler.can_run_adhoc',
    ];

    /**
     * Read application runtime settings without config or package-default fallbacks.
     */
    public static function get(?string $key = null): mixed
    {
        $runtime = once(function (): array {
            $values = static::values();
            static::validate($values);

            return $values;
        });

        // Unknown lookups must not receive silent replacement values
        if ($key !== null && !Arr::has($runtime, $key)) {
            throw new \InvalidArgumentException("Unknown WRLA runtime setting {$key}.");
        }

        return Arr::get($runtime, $key);
    }

    /**
     * Allow console installation and upgrade commands to boot before setup is complete.
     */
    public static function needsSetup(): bool
    {
        // No runtime values are supplied while an application awaits setup
        if (!class_exists(\App\WRLA\WRLASettings::class) || !method_exists(\App\WRLA\WRLASettings::class, 'runtime')) {
            return true;
        }

        return static::missingKeys(static::values()) !== [];
    }

    private static function values(): array
    {
        return once(function (): array {
            $settings = \App\WRLA\WRLASettings::class;

            // Direct reads always require the application's settings method
            if (!class_exists($settings) || !method_exists($settings, 'runtime')) {
                throw new \RuntimeException('Define App\\WRLA\\WRLASettings::runtime() by running php artisan wrla:upgrade (or wrla:install for a new application).');
            }

            $values = $settings::runtime();

            // Invalid methods must fail before any settings are used
            if (!is_array($values)) {
                throw new \UnexpectedValueException('WRLASettings::runtime() must return an array.');
            }

            return $values;
        });
    }

    public static function missingKeys(array $values): array
    {
        $required = static::RUNTIME_KEYS;
        foreach (ManageableModelPermissions::cases() as $permission) {
            $required[] = 'default_manageable_model_permissions.' . $permission->value;
        }
        foreach (['create', 'edit', 'delete'] as $permission) {
            $required[] = 'database_browser.permissions.' . $permission;
        }

        return array_values(array_filter($required, fn (string $key) => !Arr::has($values, $key)));
    }

    public static function validate(array $values): void
    {
        static::validateRuntime($values);
        $missing = static::missingKeys($values);

        // Require an explicit value for every supported runtime setting
        if ($missing !== []) {
            throw new \RuntimeException('WRLASettings::runtime() is missing: ' . implode(', ', $missing) . '. Run php artisan wrla:upgrade to complete the application settings.');
        }

        foreach (['default_manageable_model_permissions', 'developer.commands', 'user_groups', 'middleware.commands', 'database_browser.permissions'] as $key) {
            // Lists and maps must not become null or scalar replacement values
            if (!is_array(Arr::get($values, $key))) {
                throw new \UnexpectedValueException("WRLASettings::runtime() setting {$key} must be an array.");
            }
        }
    }

    private static function validateRuntime(array $values, string $prefix = ''): void
    {
        foreach ($values as $key => $value) {
            $path = $prefix . $key;

            // A supported runtime section owns its complete value, including lists
            if (in_array($path, static::RUNTIME_KEYS, true)) {
                continue;
            }

            $branch = array_filter(static::RUNTIME_KEYS, fn (string $runtimeKey) => str_starts_with($runtimeKey, $path . '.'));

            // Reject settings that package consumers read only from scalar config
            if ($branch === [] || !is_array($value) || $value === []) {
                throw new \InvalidArgumentException("Unsupported runtime setting {$path}. Keep scalar options in config/wrla.php.");
            }

            static::validateRuntime($value, $path . '.');
        }
    }
}

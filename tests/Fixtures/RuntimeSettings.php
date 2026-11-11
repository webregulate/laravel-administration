<?php

namespace WebRegulate\LaravelAdministration\Tests\Fixtures;

use Illuminate\Support\Arr;
use Illuminate\Support\Once;

$template = file_get_contents(dirname(__DIR__, 2) . '/src/stubs/WRLASettings.stub');
eval(str_replace(
    ['namespace App\\WRLA;', 'class WRLASettings'],
    ['namespace WebRegulate\\LaravelAdministration\\Tests\\Fixtures;', 'class RuntimeSettingsTemplate'],
    substr($template, 5),
));

class RuntimeSettings extends RuntimeSettingsTemplate
{
    public static array $values = [];
    public static int $calls = 0;

    public static function useForApplication(): void
    {
        // Reuse the same explicit application fixture across integration tests
        if (!class_exists(\App\WRLA\WRLASettings::class, false)) {
            class_alias(static::class, \App\WRLA\WRLASettings::class);
        }

        static::$values = parent::runtime();
        static::$calls = 0;
        Once::instance()->flush();
    }

    public static function set(array $values): void
    {
        foreach ($values as $key => $value) {
            Arr::set(static::$values, $key, $value);
        }
        Once::instance()->flush();
    }

    public static function runtime(): array
    {
        static::$calls++;

        return static::$values;
    }
}

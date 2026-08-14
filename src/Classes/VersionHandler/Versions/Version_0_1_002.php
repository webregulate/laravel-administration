<?php

namespace WebRegulate\LaravelAdministration\Classes\VersionHandler\Versions;

use WebRegulate\LaravelAdministration\Classes\VersionHandler\VersionUpdate;
use WebRegulate\LaravelAdministration\Classes\VersionHandler\VersionChangeStatus;
use WebRegulate\LaravelAdministration\Classes\VersionHandler\VersionUpdateContext;

/**
 * Version 0.1.002
 *
 * - Adds the new `favicon_url` key to the published config so existing apps
 *   pick up the favicon setting used by the admin layout:
 *
 *       // Favicon URL
 *       'favicon_url' => '/favicon.ico',
 *
 *   The key is inserted in the same location as the default config (in the
 *   GENERAL CONFIGURATION section, directly after `title_template`).
 */
class Version_0_1_002 extends VersionUpdate
{
    public function version(): string
    {
        return '0.1.002';
    }

    public function title(): string
    {
        return 'Add favicon_url config key';
    }

    public function run(VersionUpdateContext $context): void
    {
        $context->line('Adding favicon_url to configuration...');
        $this->migrateFaviconConfig($context);
    }

    /**
     * Ensure the user's published config file contains the new `favicon_url`
     * key. Handles three scenarios:
     *
     *  1. The `favicon_url` key already exists -> nothing to do.
     *  2. The `title_template` key exists -> insert directly after it (matching
     *     the default config location).
     *  3. Neither exists -> insert before the logging configuration as a
     *     sensible fallback.
     */
    protected function migrateFaviconConfig(VersionUpdateContext $context): void
    {
        $configPath = config_path('wr-laravel-administration.php');

        if (! file_exists($configPath)) {
            $context->change(VersionChangeStatus::Skipped, 'Published config not found, config migration skipped.');
            return;
        }

        $contents = file_get_contents($configPath);

        // 1. Idempotent: if the favicon_url key already exists, there is nothing to do
        if ($this->hasFaviconKey($contents)) {
            $context->change(VersionChangeStatus::Unchanged, 'favicon_url config already present, nothing to do.');
            return;
        }

        // 2. & 3. Attempt to insert the key at a sensible location
        $this->insertFaviconKey($context, $configPath, $contents);
    }

    /**
     * Determine whether the config already declares the `favicon_url` key.
     */
    protected function hasFaviconKey(string $contents): bool
    {
        return (bool) preg_match("/^[ \t]*'favicon_url'\s*=>/m", $contents);
    }

    /**
     * Insert the new `favicon_url` key. Prefers to place it directly after the
     * `title_template` key (matching the default config), otherwise falls back
     * to inserting before the logging configuration.
     */
    protected function insertFaviconKey(VersionUpdateContext $context, string $configPath, string $contents): void
    {
        // Preferred: append immediately after the title_template key so it lands
        // in the exact spot the default config keeps it.
        $afterTitleTemplate = "/^([ \t]*)'title_template'\s*=>[^\n]*\r?\n/m";

        $updated = preg_replace_callback($afterTitleTemplate, function ($matches) {
            $indent = $matches[1];
            $block = $this->buildFaviconConfigBlock($indent);

            // Re-emit the matched title_template line, then the new block below it.
            return $matches[0] . PHP_EOL . $block . PHP_EOL;
        }, $contents, 1, $count);

        if ($count && $updated !== null) {
            file_put_contents($configPath, $updated);
            $context->change(VersionChangeStatus::Changed, 'favicon_url config added after title_template.');
            return;
        }

        // Fallback: insert before the logging configuration.
        $beforeLogging = "/^([ \t]*)(\/\/ Logging\r?\n[ \t]*)?'logging'\s*=>/m";

        $updated = preg_replace_callback($beforeLogging, function ($matches) {
            $indent = $matches[1];
            $block = $this->buildFaviconConfigBlock($indent);

            return $block . PHP_EOL . PHP_EOL . $matches[0];
        }, $contents, 1, $count);

        if ($count && $updated !== null) {
            file_put_contents($configPath, $updated);
            $context->change(VersionChangeStatus::Changed, 'favicon_url config added before logging configuration.');
            return;
        }

        $context->change(VersionChangeStatus::Skipped, 'Could not find a location to insert favicon_url, config left unchanged.');
    }

    /**
     * Build the `favicon_url` config block with the given base indentation.
     */
    protected function buildFaviconConfigBlock(string $indent): string
    {
        $eol = PHP_EOL;

        return "{$indent}// Favicon URL{$eol}"
            . "{$indent}'favicon_url' => '/favicon.ico',";
    }
}

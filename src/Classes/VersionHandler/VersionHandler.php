<?php

namespace WebRegulate\LaravelAdministration\Classes\VersionHandler;

use Symfony\Component\Process\PhpExecutableFinder;

class VersionHandler
{
    /**
     * The composer package name of WRLA, used when reading composer.lock /
     * composer.json and when querying Packagist.
     */
    public const PACKAGE_NAME = 'webregulate/laravel-administration';

    public static $localPackageCurrentVersion = null;

    private VersionUpdateContext $context;

    public function __construct(VersionUpdateContext $context)
    {
        $this->context = $context;
    }

    /**
     * Clear Laravel's cached framework state (config, routes, views, events,
     * compiled services, etc.) after an update so the application picks up the
     * freshly installed code. Runs as a separate PHP CLI process so it executes
     * against the updated codebase rather than the already-booted request.
     */
    public function runOptimizeClear(): bool
    {
        $php = (new PhpExecutableFinder())->find(false) ?: PHP_BINARY;

        $command = [$php, base_path('artisan'), 'optimize:clear'];

        $this->context->line('');
        $this->context->line('Clearing cached application state...');
        $this->context->line('Running: php artisan optimize:clear');

        $success = $this->context->runProcess($command);

        if (!$success) {
            $this->context->error('optimize:clear failed.');
        }

        return $success;
    }

    /**
     * Run `composer update` honouring the developer.composer.no_dev config.
     */
    public function runComposerUpdate(): bool
    {
        $noDevEnvironments = config('wr-laravel-administration.developer.composer.no_dev', ['production']);
        $useNoDev = in_array(app()->environment(), (array) $noDevEnvironments, true);

        $command = ['composer', 'update', '--no-interaction'];
        if ($useNoDev) {
            $command[] = '--no-dev';
        }

        $this->context->line('Running: ' . implode(' ', $command));

        $success = $this->context->runProcess($command);

        if (!$success) {
            $this->context->error('composer update failed.');
        }

        return $success;
    }

    public static function buildLocalAndRemotePackageInformation(): void
    {
        $composerLockPath = base_path('composer.lock');

        if (file_exists($composerLockPath)) {
            $composerData = json_decode(file_get_contents($composerLockPath), true);
            if (isset($composerData['packages'])) {
                foreach ($composerData['packages'] as $package) {
                    if ($package['name'] === 'webregulate/laravel-administration') {
                        VersionHandler::$localPackageCurrentVersion = $package['version'] ?? null;
                    }
                }
            }
        }
    }

    /**
     * Resolve the version constraint the root project requires for WRLA (eg.
     * "dev-main"). Falls back to "dev-main" when it cannot be determined.
     */
    public static function getRequiredPackageConstraint(): string
    {
        $composerJsonPath = base_path('composer.json');

        if (file_exists($composerJsonPath)) {
            $data = json_decode(file_get_contents($composerJsonPath), true) ?: [];
            $constraint = $data['require'][self::PACKAGE_NAME]
                ?? $data['require-dev'][self::PACKAGE_NAME]
                ?? null;

            if (is_string($constraint) && trim($constraint) !== '') {
                return trim($constraint);
            }
        }

        return 'dev-main';
    }

    /**
     * Resolve the git commit reference (the "hash version") that the locally
     * installed WRLA package is locked to, as recorded in composer.lock.
     *
     * Returns null when composer.lock is missing or the package is not present.
     */
    public static function getLocalComposerReference(): ?string
    {
        $composerLockPath = base_path('composer.lock');

        if (!file_exists($composerLockPath)) {
            return null;
        }

        $composerData = json_decode(file_get_contents($composerLockPath), true) ?: [];

        foreach (array_merge($composerData['packages'] ?? [], $composerData['packages-dev'] ?? []) as $package) {
            if (($package['name'] ?? null) === self::PACKAGE_NAME) {
                return $package['dist']['reference']
                    ?? $package['source']['reference']
                    ?? null;
            }
        }

        return null;
    }

    /**
     * Fetch the latest git commit reference Packagist advertises for the package's
     * installed constraint (dev-main by default).
     *
     * Returns null on any failure so callers can degrade gracefully.
     */
    public static function getRemoteComposerReference(): ?string
    {
        $constraint = self::getRequiredPackageConstraint();

        try {
            $ctx = stream_context_create(['http' => [
                'timeout' => 5,
                'ignore_errors' => true,
                'header' => "User-Agent: wr-laravel-administration\r\n",
            ]]);

            // Dev versions (eg. dev-main) live in the ~dev metadata file.
            $json = @file_get_contents(
                'https://repo.packagist.org/p2/' . self::PACKAGE_NAME . '~dev.json',
                false,
                $ctx
            );

            if ($json === false) {
                return null;
            }

            $data = json_decode($json, true) ?: [];
            $versions = $data['packages'][self::PACKAGE_NAME] ?? [];

            if ($versions === []) {
                return null;
            }

            // Prefer the version matching the root constraint (eg. dev-main).
            foreach ($versions as $version) {
                if (($version['version'] ?? null) === $constraint) {
                    return $version['dist']['reference']
                        ?? $version['source']['reference']
                        ?? null;
                }
            }

            // Fall back to the most recent dev version Packagist returned.
            return $versions[0]['dist']['reference']
                ?? $versions[0]['source']['reference']
                ?? null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Whether Packagist advertises a different commit reference for the installed
     * package than the one currently locked in composer.lock (i.e. a `composer
     * update` would pull new code).
     *
     * Returns null when either reference could not be resolved, so the UI can
     * degrade gracefully rather than wrongly claim the package is up to date.
     */
    public static function isComposerUpdateAvailable(): ?bool
    {
        $local = self::getLocalComposerReference();
        $remote = self::getRemoteComposerReference();

        if ($local === null || $remote === null) {
            return null;
        }

        return !hash_equals($local, $remote);
    }
}
<?php

namespace WebRegulate\LaravelAdministration\Classes\VersionHandler;

use Illuminate\Support\Facades\Cache;
use Symfony\Component\Process\PhpExecutableFinder;

class VersionHandler
{
    /**
     * The composer package name of WRLA, used when reading composer.lock /
     * composer.json and when querying Packagist.
     */
    public const PACKAGE_NAME = 'webregulate/laravel-administration';

    /**
     * Cache key holding the shared "a composer update is available" flag so every
     * consumer (top-bar version indicator, update modal, CLI) reports the same state.
     */
    private const UPDATE_AVAILABLE_CACHE_KEY = 'wrla.version.composer_update_available';

    public static $localPackageCurrentVersion = null;

    /**
     * In-request memo of the update-available flag so the version bar and modal
     * cannot disagree within a single request, and Packagist is only queried once.
     */
    private static ?bool $composerUpdateAvailableMemo = null;
    private static bool $composerUpdateAvailableResolved = false;

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
        } else {
            // The lock file has (potentially) changed, so drop the cached flag to
            // force a fresh check and keep every consumer in sync.
            self::clearComposerUpdateAvailableCache();
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
     * Read all entries from the bundled docs/versions.json file.
     *
     * @return array<int, array{version: string, date: string}>
     */
    public static function getVersionsJsonData(): array
    {
        $path = __DIR__ . '/../../../docs/versions.json';

        if (!file_exists($path)) {
            return [];
        }

        return json_decode(file_get_contents($path), true) ?: [];
    }

    /**
     * Return the latest (highest version number) entry from docs/versions.json.
     *
     * @return array{version: string, date: string}|null
     */
    public static function getLatestWrlaVersion(): ?array
    {
        $versions = self::getVersionsJsonData();

        if (empty($versions)) {
            return null;
        }

        usort($versions, fn($a, $b) => version_compare($b['version'], $a['version']));

        return $versions[0];
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
        // Memoised within the request so repeated renders share one answer.
        if (self::$composerUpdateAvailableResolved) {
            return self::$composerUpdateAvailableMemo;
        }

        // Shared across requests so the server-rendered version bar and the
        // Livewire update modal always report the same status.
        $cached = Cache::get(self::UPDATE_AVAILABLE_CACHE_KEY);

        if (is_bool($cached)) {
            return self::memoiseComposerUpdateAvailable($cached);
        }

        $result = self::resolveComposerUpdateAvailable();

        // Only persist a definitive answer - a transient network failure (null)
        // should be retried next request rather than cached as "up to date".
        if ($result !== null) {
            Cache::put(self::UPDATE_AVAILABLE_CACHE_KEY, $result, self::updateCheckTtl());
        }

        return self::memoiseComposerUpdateAvailable($result);
    }

    /**
     * Compare the locally locked commit reference against the one Packagist
     * advertises for the installed constraint. Null when either is unresolved.
     */
    protected static function resolveComposerUpdateAvailable(): ?bool
    {
        $local = self::getLocalComposerReference();
        $remote = self::getRemoteComposerReference();

        if ($local === null || $remote === null) {
            return null;
        }

        return !hash_equals($local, $remote);
    }

    protected static function memoiseComposerUpdateAvailable(?bool $result): ?bool
    {
        self::$composerUpdateAvailableMemo = $result;
        self::$composerUpdateAvailableResolved = true;

        return $result;
    }

    /**
     * Forget the cached update-available flag (and in-request memo) so the next
     * check re-evaluates against the current composer.lock. Call after an update.
     */
    public static function clearComposerUpdateAvailableCache(): void
    {
        self::$composerUpdateAvailableMemo = null;
        self::$composerUpdateAvailableResolved = false;

        Cache::forget(self::UPDATE_AVAILABLE_CACHE_KEY);
    }

    protected static function updateCheckTtl(): int
    {
        return (int) config('wr-laravel-administration.developer.update.check_ttl', 600);
    }
}
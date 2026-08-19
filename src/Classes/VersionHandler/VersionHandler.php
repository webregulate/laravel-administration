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

    /**
     * Cache key holding the latest stable tagged version Packagist advertises, so the
     * version bar, update modal and CLI all report the same "latest version".
     */
    private const LATEST_VERSION_CACHE_KEY = 'wrla.version.latest_remote_version';

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
     * Resolve the tagged version of WRLA currently installed, as recorded in
     * composer.lock. Strips any leading "v" so it can be compared with
     * version_compare. Returns null when it cannot be determined.
     */
    public static function getLocalVersion(): ?string
    {
        if (self::$localPackageCurrentVersion === null) {
            self::buildLocalAndRemotePackageInformation();
        }

        $version = self::$localPackageCurrentVersion;

        if (!is_string($version) || trim($version) === '') {
            return null;
        }

        return ltrim(trim($version), 'vV');
    }

    /**
     * Fetch the latest stable tagged release Packagist advertises for the package.
     * Packagist tags mirror the repository's public git tags, so this is the single
     * source of truth for "what is the newest published version".
     *
     * Cached for the configured TTL so the version bar and modal stay in sync and we
     * avoid repeated network calls. Returns null on any failure so callers degrade
     * gracefully rather than wrongly claiming a version.
     *
     * @return array{version: string, date: ?string}|null
     */
    public static function getLatestWrlaVersion(): ?array
    {
        $cached = Cache::get(self::LATEST_VERSION_CACHE_KEY);

        if (is_array($cached)) {
            return $cached;
        }

        $latest = self::fetchLatestRemoteVersion();

        // Only cache a definitive answer - a transient network failure should be
        // retried next request rather than cached as "unknown".
        if ($latest !== null) {
            Cache::put(self::LATEST_VERSION_CACHE_KEY, $latest, self::updateCheckTtl());
        }

        return $latest;
    }

    /**
     * Query Packagist's metadata for the highest stable tagged version.
     *
     * @return array{version: string, date: ?string}|null
     */
    protected static function fetchLatestRemoteVersion(): ?array
    {
        try {
            $ctx = stream_context_create(['http' => [
                'timeout' => 5,
                'ignore_errors' => true,
                'header' => "User-Agent: wr-laravel-administration\r\n",
            ]]);

            // Stable tagged versions live in the main (non ~dev) metadata file.
            $json = @file_get_contents(
                'https://repo.packagist.org/p2/' . self::PACKAGE_NAME . '.json',
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

            $latestVersion = null;
            $latestDate = null;

            foreach ($versions as $entry) {
                $version = $entry['version'] ?? null;
                $normalized = $entry['version_normalized'] ?? $version;

                if (!is_string($version) || $version === '') {
                    continue;
                }

                // Ignore branch / dev releases (eg. dev-main) - we only track tags.
                if (is_string($normalized) && str_contains($normalized, 'dev')) {
                    continue;
                }

                $clean = ltrim($version, 'vV');

                if ($latestVersion === null || version_compare($clean, $latestVersion, '>')) {
                    $latestVersion = $clean;
                    $latestDate = isset($entry['time']) ? substr((string) $entry['time'], 0, 10) : null;
                }
            }

            if ($latestVersion === null) {
                return null;
            }

            return ['version' => $latestVersion, 'date' => $latestDate];
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
     * Whether Packagist advertises a newer stable tag than the version currently
     * installed (from composer.lock). Null when either side is unresolved so the UI
     * can degrade gracefully rather than wrongly claim the package is up to date.
     */
    protected static function resolveComposerUpdateAvailable(): ?bool
    {
        $local = self::getLocalVersion();
        $latest = self::getLatestWrlaVersion();

        if ($local === null || $latest === null || empty($latest['version'])) {
            return null;
        }

        // A non-numeric local version (eg. a legacy dev-main install) should be
        // nudged onto a tagged release.
        if (!preg_match('/^\d+(\.\d+)*/', $local)) {
            return true;
        }

        return version_compare($latest['version'], $local, '>');
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
        Cache::forget(self::LATEST_VERSION_CACHE_KEY);
    }

    protected static function updateCheckTtl(): int
    {
        return (int) config('wr-laravel-administration.developer.update.check_ttl', 600);
    }
}
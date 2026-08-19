<?php

namespace WebRegulate\LaravelAdministration\Classes\VersionHandler;

use RuntimeException;
use Symfony\Component\Process\PhpExecutableFinder;

/**
 * Launches an artisan command as a detached, fire-and-forget background process
 * whose output is appended to a log file, followed by a completion marker.
 *
 * Platform handling is deliberately reduced to a single, unavoidable distinction:
 * Windows vs everything-else (POSIX: Linux, macOS, BSD, ...). Nothing here is tied
 * to a specific distro, macOS, or a local stack such as Laragon. The PHP binary is
 * resolved with Symfony's PhpExecutableFinder so we never assume PHP_BINARY is the
 * CLI executable (under Apache/FPM it is the web server / fpm binary, not php).
 */
class BackgroundUpdateProcess
{
    /**
     * Launch the given artisan command detached from the current request.
     *
     * @param string $logPath     Absolute path the process should append its output to.
     * @param string $doneMarker  Marker line written to the log once the command finishes.
     * @param string $artisanArgs The artisan command (and args) to run, e.g. "wrla:update --no-interaction".
     *
     * @throws RuntimeException If the platform offers no usable way to spawn a detached process.
     */
    public function start(string $logPath, string $doneMarker, string $artisanArgs): void
    {
        $commandLine = '"' . $this->phpBinary() . '" "' . base_path('artisan') . '" ' . $artisanArgs;

        $this->startRaw($logPath, $doneMarker, $commandLine);
    }

    /**
     * Launch an arbitrary command line detached from the current request, streaming its
     * output to the log followed by the completion marker. Used by the dev-tools modal so
     * developers can run any configured command (not just artisan) and watch it live.
     *
     * @param string $logPath     Absolute path the process should append its output to.
     * @param string $doneMarker  Marker line written to the log once the command finishes.
     * @param string $commandLine The full command line to execute, e.g. "php artisan optimize:clear".
     *
     * @throws RuntimeException If the platform offers no usable way to spawn a detached process.
     */
    public function startRaw(string $logPath, string $doneMarker, string $commandLine): void
    {
        // Fresh log for this run
        @mkdir(dirname($logPath), 0775, true);
        file_put_contents($logPath, '');

        $script = $this->writeLauncherScript($logPath, $doneMarker, $commandLine);

        $this->spawnDetached($script);
    }

    /**
     * Resolve the PHP CLI binary in a way that works regardless of the current SAPI.
     */
    protected function phpBinary(): string
    {
        // find(false) => path only, without any appended arguments.
        $php = (new PhpExecutableFinder())->find(false);

        if (!$php) {
            throw new RuntimeException('Unable to locate the PHP CLI binary to run background updates.');
        }

        return $php;
    }

    /**
     * Whether the host is Windows. The only OS branch we need: Windows uses cmd/start,
     * every POSIX system (Linux, macOS, BSD, ...) shares the same nohup/& behaviour.
     */
    protected function isWindows(): bool
    {
        return PHP_OS_FAMILY === 'Windows';
    }

    /**
     * Write a small launcher script that runs the command, appending stdout+stderr to the
     * log, then writes the completion marker so the poller knows when it has finished.
     *
     * @return string Absolute path to the written script.
     */
    protected function writeLauncherScript(string $logPath, string $doneMarker, string $commandLine): string
    {
        $dir = dirname($logPath);
        $basePath = base_path();

        if ($this->isWindows()) {
            $scriptPath = $dir . DIRECTORY_SEPARATOR . 'run-update.bat';
            $script = "@echo off\r\n"
                . "cd /d \"{$basePath}\"\r\n"
                . "{$commandLine} >> \"{$logPath}\" 2>&1\r\n"
                . "echo {$doneMarker}>> \"{$logPath}\"\r\n";
        } else {
            $scriptPath = $dir . DIRECTORY_SEPARATOR . 'run-update.sh';
            $script = "#!/bin/sh\n"
                . "cd \"{$basePath}\"\n"
                . "{$commandLine} >> \"{$logPath}\" 2>&1\n"
                . "echo \"{$doneMarker}\" >> \"{$logPath}\"\n";
        }

        file_put_contents($scriptPath, $script);

        if (!$this->isWindows()) {
            @chmod($scriptPath, 0755);
        }

        return $scriptPath;
    }

    /**
     * Spawn the launcher script fully detached so it survives the end of this request.
     *
     * We avoid Symfony Process here on purpose: its object destructor terminates the
     * child when the request-scoped instance is garbage collected. popen/pclose (Windows)
     * and exec with a trailing & (POSIX) are the portable fire-and-forget idioms that
     * return immediately without holding a handle to the child.
     */
    protected function spawnDetached(string $script): void
    {
        if ($this->isWindows()) {
            if (!function_exists('popen')) {
                throw new RuntimeException('popen() is disabled; set developer.update.mode to "blocking".');
            }

            // "start /B" runs the script in the background with no new window.
            pclose(popen('start /B "" "' . $script . '"', 'r'));
            return;
        }

        if (!function_exists('exec')) {
            throw new RuntimeException('exec() is disabled; set developer.update.mode to "blocking".');
        }

        // nohup detaches from the controlling terminal; & backgrounds it; exec returns at once.
        exec('nohup sh "' . $script . '" > /dev/null 2>&1 &');
    }
}

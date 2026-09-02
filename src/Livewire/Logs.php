<?php

namespace WebRegulate\LaravelAdministration\Livewire;

use Illuminate\Support\Facades\File;
use Livewire\Attributes\Title;
use WebRegulate\LaravelAdministration\Classes\WRLAHelper;

#[Title('View Logs')]
class Logs extends WRLAPageComponent
{
    /* Properties
    --------------------------------------------------------------------------*/
    public string $viewingLogsDirectory = '';

    public array $logDirectoriesAndFiles = [];

    public ?string $viewingLogFile = null;

    public string $viewingLogContent = 'No content 1';

    public int $viewLogMaxCharacters = 0;

    /**
     * opcodesio/log-viewer iframe src. Null when using the built-in WRLA log viewer.
     */
    public ?string $logViewerIframeSrc = null;

    /* Livewire Methods / Hooks
    --------------------------------------------------------------------------*/
    public function mount()
    {
        // opcodesio/log-viewer mode: redirect out to the standalone viewer, or embed
        // it in an iframe when configured to display within WRLA.
        if (config('wr-laravel-administration.logs.current') === 'opcodesio/log-viewer') {
            $logViewerPath = '/'.config('log-viewer.route_path', 'log-viewer');

            if (config('wr-laravel-administration.logs.opcodesio/log-viewer.display_within_wrla', false) !== true) {
                return redirect()->to($logViewerPath);
            }

            $this->logViewerIframeSrc = $logViewerPath;
            return;
        }

        // Built-in WRLA log viewer
        $this->viewLogMaxCharacters = config('wr-laravel-administration.logs.wrla.max_characters', 200000);

        // Set all log files and directories
        $this->setDirectoriesAndFiles();

        // Find the first file within found logs and set it as the default viewing log
        $this->selectFirstLogFileInCurrentDirectory();
    }

    public function render()
    {
        // opcodesio/log-viewer embedded within WRLA
        if ($this->logViewerIframeSrc !== null) {
            return view(WRLAHelper::getViewPath('livewire.logs-log-viewer'), [
                'src' => $this->logViewerIframeSrc,
            ]);
        }

        // Get current directories and files
        $currentDirectoriesAndFiles = empty($this->viewingLogsDirectory)
            ? $this->logDirectoriesAndFiles
            : data_get($this->logDirectoriesAndFiles, $this->viewingLogsDirectory, []);

        // Prepend .. directory if viewing a subdirectory
        if (! empty($this->viewingLogsDirectory)) {
            $currentDirectoriesAndFiles = ['..' => '..'] + $currentDirectoriesAndFiles;
        }

        return view(WRLAHelper::getViewPath('livewire.logs'), [
            'currentDirectoriesAndFiles' => $currentDirectoriesAndFiles,
        ]);
    }

    /* Methods
    --------------------------------------------------------------------------*/
    private function setDirectoriesAndFiles()
    {
        // Get all log files and directories
        $this->logDirectoriesAndFiles = WRLAHelper::getDirectoriesAndFiles(storage_path('logs'));
    }

    private function getLogContent(string $logFile): string
    {
        $fullPath = storage_path('logs/'.str($logFile)->ltrim('/'));

        if (file_exists($fullPath)) {
            // If not file
            if (! is_file($fullPath)) {
                return "Error: {$fullPath} is not a file";
                // If is .gz file
            } elseif (str($fullPath)->endsWith('.gz')) {
                return 'Cannot view .gz files';
                // Valid
            } else {
                return str(file_get_contents($fullPath))->limit($this->viewLogMaxCharacters, '... (truncated)');
            }
        }

        $this->viewingLogFile = null;

        return '';
    }

    public function getFullPath(string $directory, string $file): string
    {
        // If directory is empty, just pass the file
        if (empty($directory)) {
            return str_replace('\\', '/', storage_path('logs/'.$file));
        }

        // Swap .'s for /'s
        $directory = str_replace('.', '/', $directory);

        return str_replace('\\', '/', storage_path('logs/'.$directory.'/'.$file));
    }

    public function switchDirectory(string $directory)
    {
        // If $directory is .., go up a directory
        if ($directory === '..' && ! empty($this->viewingLogsDirectory)) {
            $this->viewingLogsDirectory = ! str($this->viewingLogsDirectory)->contains('.')
                ? ''
                : str($this->viewingLogsDirectory)->beforeLast('.');

            $this->selectFirstLogFileInCurrentDirectory();

            return;
        }

        $this->viewingLogsDirectory = $directory;
        $this->selectFirstLogFileInCurrentDirectory();
        $this->viewLogFile($this->viewingLogsDirectory, $this->viewingLogFile);
    }

    public function viewLogFile(string $directoryPath, ?string $logFile)
    {
        if ($logFile === null) {
            return;
        }

        $this->viewingLogsDirectory = $directoryPath;
        $this->viewingLogFile = $logFile;
        $this->viewingLogContent = $this->getLogContent("{$this->viewingLogsDirectory}/{$this->viewingLogFile}");
    }

    public function deleteLogFile(string $directoryPath, string $logFile)
    {
        $fullPath = $this->getFullPath($directoryPath, $logFile);

        if (file_exists($fullPath)) {
            if (is_file($fullPath)) {
                // Use Laravel file facade to delete file
                File::delete($fullPath);

                WRLAHelper::unsetNestedArrayByKeyAndValue(
                    $this->logDirectoriesAndFiles,
                    $directoryPath,
                    $logFile
                );
            }

            if (is_dir($fullPath)) {
                // Use Laravel file facade to delete directory
                File::deleteDirectory($fullPath);

                WRLAHelper::unsetNestedArrayByKey(
                    $this->logDirectoriesAndFiles,
                    $logFile
                );
            }
        }

        $this->viewingLogsDirectory = $directoryPath;
        $this->selectFirstLogFileInCurrentDirectory();
        $this->render();
    }

    public function refresh()
    {
        // Set all log files and directories
        $this->setDirectoriesAndFiles();

        // Update current selected log content
        $this->viewLogFile($this->viewingLogsDirectory, $this->viewingLogFile);
    }

    public function selectFirstLogFileInCurrentDirectory()
    {
        $this->viewingLogFile = null;

        $currentDirectoryContents = empty($this->viewingLogsDirectory)
            ? $this->logDirectoriesAndFiles
            : data_get($this->logDirectoriesAndFiles, $this->viewingLogsDirectory, []);

        foreach ($currentDirectoryContents as $directoryOrFile) {
            // If full path is not a file, skip
            if (is_array($directoryOrFile)) {
                continue;
            }

            if (is_string($directoryOrFile)) {
                $this->viewingLogFile = $directoryOrFile;
                break;
            }
        }

        if ($this->viewingLogFile === null) {
            return;
        }

        $this->viewingLogContent = $this->getLogContent("{$this->viewingLogsDirectory}/{$this->viewingLogFile}");
    }
}

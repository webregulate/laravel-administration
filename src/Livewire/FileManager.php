<?php

namespace WebRegulate\LaravelAdministration\Livewire;

use Exception;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\WithFileUploads;
use Symfony\Component\Mime\MimeTypes;
use WebRegulate\LaravelAdministration\Classes\WRLAHelper;

#[Title('File Manager')]
class FileManager extends WRLAPageComponent
{
    use WithFileUploads;

    /* Properties
    --------------------------------------------------------------------------*/
    #[Locked]
    public ?string $currentFileSystemName = null; // Set on mount

    public string $viewingDirectory = '';

    public array $currentDirectories = [];

    public array $currentFiles = [];

    public ?string $highlightedItem = null;

    public string $viewingItemContent = 'No content 1';

    public ?string $viewingItemType = null; // null, text, image, video, file (link)

    public ?string $viewingItemData = null;

    public ?string $viewingItemPublicUrl = null;

    public int $viewFileMaxCharacters = 0;

    public $listeners = [
        'createDirectory' => 'createDirectory',
        'deleteFile' => 'deleteFile',
        'deleteDirectory' => 'deleteDirectory',
    ];

    public $uploadFile; // Modelled, used for file upload

    public $uploadFilePath; // Absolute path to directory for upload

    public $replaceFile; // Modelled, used for individual file replacement

    public $replaceFilePath; // Absolute path to file for replacement

    /* Update fields
    --------------------------------------------------------------------------*/
    public function updatedUploadFile($value)
    {
        if ($value && $this->uploadFilePath !== null) {
            $fileSystem = $this->getCurrentFileSystem();
            if (! $this->validateUpload($value, 'uploadFile', $value->getClientOriginalName())) {
                return;
            }

            // Get full file name
            $fileName = $value->getClientOriginalName();

            // Get filesystem path
            $fileSystemPath = str_replace('//', '/', str_replace('.', '/', $this->viewingDirectory).'/'.$fileName);

            // Store the new file
            $fileSystem->put($fileSystemPath, $value->get());

            // Reset uploadFilePath
            $this->uploadFilePath = null;

            // Refresh the directories and files list
            $this->refresh();

            // View the new file
            $this->viewFile($this->viewingDirectory, $fileName);
        }
    }

    public function updatedReplaceFile($value)
    {
        if ($value && $this->replaceFilePath) {
            $fileSystem = $this->getCurrentFileSystem();

            // Get last part of absolute file path
            $fileName = str($this->replaceFilePath)->afterLast('/')->toString();

            if (! $this->validateUpload($value, 'replaceFile', $fileName)) {
                return;
            }

            // Get filesystem path
            $fileSystemPath = str_replace('//', '/', str_replace('.', '/', $this->viewingDirectory).'/'.$fileName);

            if (! $fileSystem->fileExists($fileSystemPath)) {
                $this->addError('replaceFile', 'The file to replace does not exist.');

                return;
            }

            // Store the new file
            $fileSystem->put($fileSystemPath, $value->get());

            // Reset replaceFilePath
            $this->replaceFilePath = null;

            // Refresh the directories and files list
            $this->refresh();

            // View the new file
            $this->viewFile($this->viewingDirectory, $fileName);
        }
    }

    private function validateUpload(UploadedFile $file, string $errorKey, string $destinationName): bool
    {
        $allowedMimeTypes = config('wrla.file_manager.can_upload_mime_types', []);
        $mimeType = $file->getMimeType();

        if (! in_array($mimeType, $allowedMimeTypes, true)) {
            $this->addError($errorKey, 'Can only upload files of type: '.implode(', ', $allowedMimeTypes));

            return false;
        }

        $allowedExtensions = (new MimeTypes())->getExtensions($mimeType);
        foreach ([$file->getClientOriginalName(), $destinationName] as $name) {
            if (! in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), $allowedExtensions, true)) {
                $this->addError($errorKey, 'Can only upload files with extensions: '.implode(', ', $allowedExtensions));

                return false;
            }
        }

        return true;
    }

    /* Livewire Methods / Hooks
    --------------------------------------------------------------------------*/
    public function boot(): void
    {
        abort_unless(WRLAHelper::fileManagerEnabled(), 403, 'Access to file manager permission denied.');
    }

    public function mount()
    {
        // Config
        $this->viewFileMaxCharacters = config('wrla.file_manager.max_characters', 500000);
        $attemptFileSystemName = config('wrla.file_manager.default_filesystem', null);

        // Set file system
        $this->switchFileSystem($attemptFileSystemName);
    }

    public function render()
    {
        // Render the view
        return view(WRLAHelper::getViewPath('livewire.file-manager'), [
            'fileSystemNames' => $this->getAvailableFileSystemNames(),
            'fullDirectoryPath' => $this->getFullDirectoryPath(false),
            'fullFilePath' => $this->getFullFilePath(false),
            'fullAbsoluteFilePath' => $this->highlightedItem !== null ? $this->getFullFilePath(true) : null,
        ]);
    }

    /* Methods
    --------------------------------------------------------------------------*/
    public function getFullDirectoryPath($absolute = false)
    {
        // If viewing directory is empty, return root path
        if (empty($this->viewingDirectory)) {
            return $absolute ? $this->getCurrentFileSystem()->path('') : '';
        }

        // Get the full path to the current viewing directory
        $directoryPath = str_replace('.', '/', $this->viewingDirectory);

        return $absolute
            ? str_replace('\\', '/', $this->getCurrentFileSystem()->path($directoryPath))
            : $directoryPath;
    }

    public function getFullFilePath($absolute = false)
    {
        // Get the full path to the current viewing directory
        $directoryPath = $this->getFullDirectoryPath($absolute);

        // If no file is highlighted, return the directory path
        if ($this->highlightedItem === null) {
            return $directoryPath;
        }

        // Append the highlighted item to the directory path
        $filePath = $directoryPath.'/'.$this->highlightedItem;

        return $absolute
            ? str_replace('\\', '/', $this->getCurrentFileSystem()->path($filePath))
            : $filePath;
    }

    private function setCurrentDirectoriesAndFiles()
    {
        // If no file system is set, return
        if ($this->currentFileSystemName === null || $this->currentFileSystemName === '') {
            $this->currentDirectories = [];
            $this->currentFiles = [];

            return;
        }

        // Get all directories within given path and get last part of path
        $this->currentDirectories = $this->getCurrentFileSystem()->directories(str_replace('.', '/', $this->viewingDirectory));
        $this->currentDirectories = array_map(fn ($directory) => str($directory)->afterLast('/')->toString(), $this->currentDirectories);

        // Get all files but ignore hidden files and get last part of path
        $this->currentFiles = $this->getCurrentFileSystem()->files(str_replace('.', '/', $this->viewingDirectory));
        $this->currentFiles = array_filter($this->currentFiles, fn ($file) => ! str($file)->afterLast('/')->startsWith('.'));
        $this->currentFiles = array_map(fn ($file) => str($file)->afterLast('/')->toString(), $this->currentFiles);
    }

    private function getFileContent(string $filePath): string
    {
        // File path
        $filePath = str($filePath)->ltrim('/')->toString();

        // Reset viewing item data to null
        $this->viewingItemData = null;

        // Set public URL
        $this->viewingItemPublicUrl = $this->getCurrentFileSystem()->url($filePath);

        // Get file mime type
        try {
            $mimeType = $this->getCurrentFileSystem()->mimeType($filePath);
        } catch (Exception) {
            $mimeType = 'error';
        }

        // Match type of file
        $this->viewingItemType = match ($mimeType) {
            'image/jpeg', 'image/png', 'image/gif', 'image/svg+xml', 'image/webp' => 'image',
            'video/mp4' => 'video',
            'text/plain', 'text/html', 'application/json', 'application/javascript' => 'text',
            'application/pdf' => 'pdf',
            'error' => 'error',
            default => 'unhandled',
        };

        // If file is text, return content
        if ($this->viewingItemType === 'text') {
            // Use storage to get file contents
            return $this->getCurrentFileSystem()->get($filePath);
        } elseif ($this->viewingItemType === 'image') {
            // Get raw image data and convert to base64
            try {
                $rawImageData = $this->getCurrentFileSystem()->get($filePath);

                // Get image size (not filesize)
                $imageMeta = getimagesizefromstring($rawImageData);
                $fileSize = $this->getCurrentFileSystem()->fileSize($filePath);
                $humanReadableFileSize = $this->humanReadableSize($fileSize ?? 0);
                $this->viewingItemData = "Width: <b>{$imageMeta[0]}</b>px, Height: <b>{$imageMeta[1]}</b>px, Size: <b>".$humanReadableFileSize.'</b>';

                return 'data:image/'.str($mimeType)->afterLast('/').';base64,'.base64_encode((string) $rawImageData);
                // If fails, get public path to image file
            } catch (Exception) {
                return $this->getCurrentFileSystem()->url($filePath);
            }
        } elseif ($this->viewingItemType === 'video') {
            // Get public path to video file
            return $this->getCurrentFileSystem()->url($filePath);
        } elseif ($this->viewingItemType === 'pdf') {
            return '❌ Cannot display PDF contents...';
        } elseif ($this->viewingItemType === 'error') {
            return '❌ Cannot display file contents...';
        }

        $this->highlightedItem = null;

        return '❌ Mime type not handled...';
    }

    public function humanReadableSize($bytes, $decimals = 2)
    {
        $sizeUnits = ['B', 'KB', 'MB', 'GB', 'TB'];
        $factor = floor((strlen((string) $bytes) - 1) / 3);

        return sprintf("%.{$decimals}f", $bytes / 1024 ** $factor).$sizeUnits[$factor];
    }

    public function getFullPath(string $directory, string $file): string
    {
        // If directory is empty, just pass the file
        if (empty($directory)) {
            return $this->getCurrentFileSystem()->path(str($file)->rtrim('/'));
        }

        // Swap .'s for /'s
        $directory = str_replace('.', '/', $directory);

        return str_replace('\\', '/', $this->getCurrentFileSystem()->path(str($directory.'/'.$file)->rtrim('/')));
    }

    public function switchDirectory(string $directory)
    {
        // Reset viewing item type
        $this->viewingItemType = null;

        // If $directory is .., go up a directory
        if (! empty($this->viewingDirectory) && $directory === '..') {
            $this->viewingDirectory = ! str($this->viewingDirectory)->contains('.')
                ? ''
                : str($this->viewingDirectory)->beforeLast('.');
        }
        // Otherwise set viewing directory
        else {
            $this->viewingDirectory = trim($directory, '.');
        }

        $this->refresh();
        $this->selectFirstFileInCurrentDirectory();
        $this->viewFile($this->viewingDirectory, $this->highlightedItem);
    }

    public function viewFile(string $directoryPath, ?string $filePath)
    {
        if ($filePath === null) {
            return;
        }

        $this->viewingDirectory = $directoryPath;
        $this->highlightedItem = $filePath;

        $this->viewingItemContent = $this->getFileContent(str($this->viewingDirectory)->replace('.', '/')->toString()."/{$this->highlightedItem}");
    }

    public function deleteFile(string $directoryPath, string $name)
    {
        $diskPath = rtrim(str_replace('.', '/', $directoryPath).'/'.$name, '/');
        $fileSystem = $this->getCurrentFileSystem();

        try {
            $deleted = $fileSystem->delete($diskPath);
        } catch (Exception) {
            $deleted = false;
        }

        WRLAHelper::pushAlert(
            $deleted ? 'success' : 'danger',
            $deleted ? 'File deleted successfully.' : 'File could not be deleted.',
        );

        // Clean up, refresh and re-render
        $this->viewingDirectory = $directoryPath;
        $this->refresh();
        $this->selectFirstFileInCurrentDirectory();
        $this->render();
    }

    public function deleteDirectory(string $directoryPath, string $name)
    {
        $diskPath = rtrim(str_replace('.', '/', $directoryPath).'/'.$name, '/');
        $fileSystem = $this->getCurrentFileSystem();

        try {
            $deleted = $fileSystem->deleteDirectory($diskPath);
        } catch (Exception) {
            $deleted = false;
        }

        WRLAHelper::pushAlert(
            $deleted ? 'success' : 'danger',
            $deleted ? 'Directory deleted successfully.' : 'Directory could not be deleted.',
        );

        // Clean up, refresh and re-render
        $this->viewingDirectory = $directoryPath;
        $this->refresh();
        $this->selectFirstFileInCurrentDirectory();
        $this->render();
    }

    public function createDirectory(string $newDirectoryName)
    {
        // Get the full path to the new directory
        $fullPath = $this->getFullPath($this->viewingDirectory, $newDirectoryName);

        // Check if directory already exists
        if ($this->getCurrentFileSystem()->exists($fullPath)) {
            $this->addError('directory', 'Directory already exists.');

            return;
        }

        // Create the new directory
        $this->getCurrentFileSystem()->makeDirectory($this->viewingDirectory.'/'.$newDirectoryName);

        // Refresh the directories and files list
        $this->refresh();
    }

    public function switchFileSystem(string $fileSystemName)
    {
        // Get available file system names
        $availableFileSystemNames = $this->getAvailableFileSystemNames();

        // If the file system name is not in the available file systems, return
        if ($fileSystemName !== '' && ! in_array($fileSystemName, $availableFileSystemNames)) {
            WRLAHelper::pushAlert('danger', "File system '$fileSystemName' not available or enabled in wrla.file_manager config.");

            return;
        }

        // Set current file system name
        $this->currentFileSystemName = $fileSystemName;

        // If empty
        if (empty($this->currentFileSystemName)) {
            $this->viewingDirectory = '';
            $this->highlightedItem = null;
            $this->currentDirectories = [];
            $this->currentFiles = [];
            $this->viewingItemContent = '';
            $this->viewingItemType = null;
        } else {
            // Set all log files and directories
            $this->setCurrentDirectoriesAndFiles();

            // Select the first file in the current directory
            $this->selectFirstFileInCurrentDirectory();
        }
    }

    public function refresh()
    {
        // Set all log files and directories
        $this->setCurrentDirectoriesAndFiles();

        // Update current selected log content
        $this->viewFile($this->viewingDirectory, $this->highlightedItem);
    }

    public function selectFirstFileInCurrentDirectory()
    {
        $this->highlightedItem = null;

        if (count($this->currentFiles) === 0) {
            return;
        }

        $this->highlightedItem = $this->currentFiles[array_key_first($this->currentFiles)];

        $this->viewingItemContent = $this->getFileContent("{$this->viewingDirectory}/{$this->highlightedItem}");
    }

    public function getFileSystemAbsolutePath(): string
    {
        // Get the absolute path to the current file system
        return str_replace('\\', '/', $this->getCurrentFileSystem()->path(''));
    }

    private function getCurrentFileSystem()
    {
        abort_unless(
            WRLAHelper::fileManagerEnabled(),
            403,
            'Access to file manager permission denied.',
        );
        abort_unless(
            in_array($this->currentFileSystemName, $this->getAvailableFileSystemNames(), true),
            403,
            'File system not available or enabled in file manager config.',
        );

        return Storage::disk($this->currentFileSystemName);
    }

    private function getAvailableFileSystemNames(): array
    {
        return array_keys($this->getAvailableFileSystemConfigs());
    }

    private function getAvailableFileSystemConfigs(): array
    {
        // Only where where enabled
        return collect(config('wrla.file_manager.file_systems', []))->filter(fn ($fileSystem) => $fileSystem['enabled'] ?? false)->toArray();
    }
}

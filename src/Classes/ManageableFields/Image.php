<?php

namespace WebRegulate\LaravelAdministration\Classes\ManageableFields;

use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;
use WebRegulate\LaravelAdministration\Classes\WRLAHelper;

/**
 * Image manageable field.
 *
 * Specialises {@see File} to:
 *   - Run uploaded files through Intervention Image (with an optional
 *     user-supplied manipulation callback) before writing them to disk.
 *   - Add image-specific options (default image, aspect ratio, object-fit,
 *     preview controls, rounded class helpers).
 *   - Render via the image-specific blade view.
 */
class Image extends File
{
    /**
     * Manipulate image function, set with {@see manipulateImage()} on creation.
     *
     * @var callable|null
     */
    public $manipulateImageFunction = null;

    /**
     * Clockwise rotation (in degrees) requested by the user for the current
     * submission. Populated by {@see applySubmittedValue()} and applied when
     * (re)encoding the image.
     */
    public int $rotationDegrees = 0;

    /**
     * Default options, augmenting {@see File::defaultOptions()} with the
     * image-only options consumed by the input-image blade view.
     */
    protected static function defaultOptions(): array
    {
        return array_merge(parent::defaultOptions(), [
            'defaultImage' => WRLAHelper::getCurrentThemeData('no_image_src'),
            'aspect' => null,
            'objectFit' => 'fit',
            'showPreview' => true,
            'previewContainerClass' => '',
            'allowRotation' => true,
        ]);
    }

    /**
     * When the model column is empty, fall back to the configured "no image"
     * asset so the UI always renders a sensible placeholder.
     */
    protected static function emptyModelUrl(): string
    {
        return WRLAHelper::getCurrentThemeData('no_image_src') ?? '';
    }

    /**
     * Apply the submitted value.
     *
     * Captures the requested rotation for this field, then defers upload / remove
     * handling to {@see File::applySubmittedValue()}. When a new file is uploaded
     * the rotation is baked in during {@see processUploadedFile()}. When the user
     * only rotated an already-stored image (no new upload) the base class reports
     * "no change" (null) and we rotate the stored file into a new cache-busting
     * filename via {@see rotateExistingFile()}, returning that new value to store.
     */
    public function applySubmittedValue(Request $request, mixed $value): mixed
    {
        $this->rotationDegrees = $this->getOption('allowRotation') == true
            ? ((int) $request->input('wrla_rotation_'.$this->getName(), 0)) % 360
            : 0;

        // Rotation-only submission: the image was rotated without uploading a
        // replacement. The value is a sentinel injected by the update loop (see
        // ManageableModel::updateModelInstanceProperties) so the field is still
        // processed. Rotate the stored file in place and persist the new value.
        if ($value === WRLAHelper::WRLA_KEY_ROTATE) {
            if ($this->rotationDegrees !== 0 && !empty($this->getAttribute('value'))) {
                return $this->rotateExistingFile();
            }

            // Nothing to rotate — keep the existing stored value unchanged.
            return $this->getAttribute('value');
        }

        return parent::applySubmittedValue($request, $value);
    }

    /**
     * Run the upload through Intervention Image, applying rotation and any user
     * supplied manipulation callback, then write the encoded result to disk.
     */
    protected function processUploadedFile(UploadedFile $file, string $path, string $filename): void
    {
        $imageManager = new ImageManager(new Driver);
        $image = $imageManager->read($file);

        $image = $this->applyRotation($image);

        if ($this->manipulateImageFunction !== null) {
            $manipulateImageFunction = $this->manipulateImageFunction;
            $image = $manipulateImageFunction($image);
        }

        Storage::disk($this->getOption('fileSystem'))->put("$path/$filename", $image->encode());
    }

    /**
     * Rotate the already-stored image (when no replacement file was uploaded).
     *
     * The rotated result is written under a *new*, cache-busting filename and
     * the original file is removed (respecting the `unlinkOld` option). Writing
     * to the same path would leave the browser showing the previously cached
     * image, so a fresh filename guarantees the change is visible and lets the
     * new value propagate to the database.
     *
     * @return string The new value to store, or the current stored value
     *                unchanged if the original file could no longer be found.
     */
    protected function rotateExistingFile(): string
    {
        $disk = $this->getFileSystem();
        $oldDiskPath = ltrim($this->getDiskStoragePath(), '/');

        // If the original file has vanished, leave the stored value untouched.
        if (!$disk->exists($oldDiskPath)) {
            return (string) $this->getAttribute('value');
        }

        $imageManager = new ImageManager(new Driver);
        $image = $imageManager->read($disk->get($oldDiskPath));
        $image = $this->applyRotation($image);

        // Build a new, cache-busting filename in the same directory. Any prior
        // rotation suffix is stripped first so repeated rotations don't stack.
        $directory = ltrim(WRLAHelper::forwardSlashPath($this->getPathOnly()), '/');
        $oldFilename = basename($oldDiskPath);
        $extension = pathinfo($oldFilename, PATHINFO_EXTENSION);
        $baseName = preg_replace('/-r\d+$/', '', pathinfo($oldFilename, PATHINFO_FILENAME));

        $newFilename = $baseName.'-r'.time().($extension !== '' ? '.'.$extension : '');
        $newDiskPath = ltrim(($directory !== '' ? $directory.'/' : '').$newFilename, '/');

        $disk->put($newDiskPath, $image->encode());

        // Remove the now-orphaned original file.
        if ($this->getOption('unlinkOld') == true && $newDiskPath !== $oldDiskPath) {
            $disk->delete($oldDiskPath);
        }

        // Return the value to store (filename only, or full path).
        return $this->getOption('storeFilenameOnly') == true
            ? $newFilename
            : $newDiskPath;
    }

    /**
     * Apply the requested clockwise rotation to the given Intervention image.
     * The UI reports clockwise degrees whereas Intervention rotates
     * counter-clockwise, so the angle is negated.
     *
     * @param  \Intervention\Image\Interfaces\ImageInterface  $image
     * @return \Intervention\Image\Interfaces\ImageInterface
     */
    protected function applyRotation($image)
    {
        $degrees = $this->rotationDegrees % 360;

        if ($degrees === 0) {
            return $image;
        }

        $image->rotate(-$degrees);

        return $image;
    }

    /**
     * Get value. For synthetic placeholder fields (those prefixed with
     * `wrla_field_`) the stored value isn't a real path; instead we resolve it
     * by running the configured filename template through
     * {@see formatFileName()}.
     */
    public function getValue(): string
    {
        if (!str_starts_with($this->getName(), 'wrla_field_')) {
            return $this->getAttribute('value');
        }

        return $this->formatFileName($this->getOption('filename'), $this->getAttribute('value'));
    }

    /**
     * Show preview option
     */
    public function showPreview(bool $show = true): static
    {
        $this->setOption('showPreview', $show);

        return $this;
    }

    /**
     * Allow the user to rotate the image (defaults to true).
     *
     * @return $this
     */
    public function allowRotation(bool $allow = true): static
    {
        $this->setOption('allowRotation', $allow);

        return $this;
    }

    /**
     * Preview class
     */
    public function previewContainerClass(string $class): static
    {
        $this->setOption('previewContainerClass', $class);

        return $this;
    }

    /**
     * Manipulate image
     *
     * @return $this
     */
    public function manipulateImage(callable $callback): static
    {
        $this->manipulateImageFunction = $callback;

        return $this;
    }

    /**
     * Cover fit aspect ratio
     *
     * @param  string  $aspect  (4/3, 16/9, etc)
     * @param  string  $position  (center, top, bottom, left, right, top-left, top-right, bottom-left, bottom-right)
     * @return $this
     */
    public function coverFitAspect(int $width, string $aspect = '4/3', string $position = 'center', int $quality = 100): static
    {
        // Set aspect display for image
        $this->aspect($aspect);

        // Get aspect parts
        $aspectParts = explode('/', $aspect);

        return $this->manipulateImage(function ($image) use ($width, $aspectParts, $position, $quality) {
            $height = $width / $aspectParts[0] * $aspectParts[1];
            $image->cover($width, $height, $position);
            $image->toJpeg($quality);

            return $image;
        });
    }

    /**
     * Default if no image is set
     *
     * @return $this
     */
    public function defaultImage(?string $path): static
    {
        $this->setOption('defaultImage', $path);

        return $this;
    }

    /**
     * Set aspect ratio using 1/1 format
     *
     * @param  ?string  $aspect  Aspect ratio string (eg. '1/1', '16/9'), or null for auto.
     * @param  string  $position  How the image fits within the aspect-ratio box. Accepts 'fit'
     *                            (alias of CSS object-contain, the default — preserves the image's
     *                            original aspect ratio inside the box), or any CSS object-fit value
     *                            ('cover', 'contain', 'fill', 'none', 'scale-down').
     */
    public function aspect(?string $aspect = null, string $position = 'fit'): static
    {
        $this->setOption('aspect', $aspect);
        $this->setOption('objectFit', $position);

        return $this;
    }

    /**
     * Set rounded image, with false, null, or 'none' for none, true for 'full', or any tailwind string rounded available value
     */
    public function rounded(null|bool|string $rounded = true): static
    {
        if ($rounded == false) {
            $this->options['class'] = str_replace('rounded-full', '', $this->options['class']);

            return $this;
        }

        if ($rounded === true || $rounded === null) {
            $rounded = 'full';
        }

        $this->options['class'] .= " rounded-$rounded";

        return $this;
    }

    /**
     * Render hook: use the image input blade view.
     */
    protected function viewPath(): string
    {
        return WRLAHelper::getViewPath('components.forms.input-image');
    }

    /**
     * Render hook: image inputs use the disk storage path (with collapsed
     * double slashes) rather than the file-style prefixed value.
     */
    protected function renderInputValue(): string
    {
        return $this->getDiskStoragePath();
    }

    /**
     * Render hook: the image blade view consumes `fileSystemImageExists`
     * (named for clarity / parity with the existing template).
     */
    protected function additionalViewData(bool $fileExists): array
    {
        return ['fileSystemImageExists' => $fileExists];
    }

    /**
     * @deprecated Use {@see uploadFile()} instead. Retained for backwards compatibility.
     */
    public function uploadImage(UploadedFile $file): string
    {
        return $this->uploadFile($file);
    }

    /**
     * @deprecated Use {@see deleteFile()} instead. Retained for backwards compatibility.
     */
    public function deleteImage(string $filePathRelativeToFileSystem): void
    {
        $this->deleteFile($filePathRelativeToFileSystem);
    }

    /**
     * @deprecated Use {@see formatFileName()} instead. Retained for backwards compatibility.
     */
    public function formatImageName(null|string|callable $name, string $originalFileName): string
    {
        return $this->formatFileName($name, $originalFileName);
    }
}

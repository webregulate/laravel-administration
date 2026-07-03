<?php

namespace WebRegulate\LaravelAdministration\Classes\ManageableFields;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use WebRegulate\LaravelAdministration\Classes\ManageableModel;
use WebRegulate\LaravelAdministration\Classes\WRLAHelper;
use WebRegulate\LaravelAdministration\Traits\ManageableField;

/**
 * MultiField — a fully flexible multi-form-group (repeater) manageable field.
 *
 * Each form group is composed of arbitrary inner fields (image, date, text, select, ...) defined
 * via {@see MultiFieldItem}. Inner fields render as items exactly like the MultiImage
 * grid, form groups can be freely added and deleted, and image items support the same upload /
 * replace / delete lifecycle as MultiImage.
 *
 * Storage: by default the field stores a JSON array of form groups, each form group being an
 * associative array keyed by the inner item keys (eg. [{"image": "...", "date": "...", "url": "..."}]).
 * Use {@see overrideFinalValue()} to transform the assembled form groups before they are stored,
 * and {@see skipItem()} to drop form groups that should not be persisted.
 */
class MultiField
{
    use ManageableField;

    /**
     * Row layout — each entry renders its inner fields side by side (horizontally),
     * vertically centered, with entries stacked on top of each other.
     */
    public const LAYOUT_ROW = 'row';

    /**
     * Column layout — each entry stacks its inner fields vertically (one below the
     * other), with entries flowing in a wrapping grid like the MultiImage field.
     */
    public const LAYOUT_COLUMN = 'column';

    /**
     * Make method.
     */
    public static function make(?ManageableModel $manageableModel = null, ?string $column = null): static
    {
        $value = $manageableModel?->model()->{$column};
        if (is_array($value)) {
            $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        $instance = new static($column, $value, $manageableModel);

        $instance->setOptions([
            'items' => [],
            'maxFormGroups' => 0,
            'addItemLabel' => 'Add new',
            'emptyText' => 'No items yet added, click the button below to add one.',
            'skipItem' => null,
            'finalValueOverride' => null,
            'layout' => self::LAYOUT_ROW,
            'columns' => 4,
        ]);

        return $instance;
    }

    /**
     * This field handles file uploads (image items), so its raw value cannot be
     * transferred to a new record (eg. when duplicating).
     */
    public function isFileUploadField(): bool
    {
        return true;
    }

    /**
     * Pre-validation hook. The Livewire component writes its state into suffixed inputs
     * ({fieldName}_groups, {fieldName}_existing_images, ...) rather than {fieldName} itself,
     * so returning true forces the current column value to be merged into the request under
     * the field's own key. This prevents updateModelInstanceProperties from skipping this
     * field due to its key-existence guard.
     */
    public function preValidation(?string $value): bool
    {
        return true;
    }

    /**
     * Define the inner item fields for each form group.
     *
     * @param MultiFieldItem[] $items
     */
    public function setItems(array $items): static
    {
        $this->setOption('items', array_values($items));
        return $this;
    }

    /**
     * Get the configured inner items.
     *
     * @return MultiFieldItem[]
     */
    public function getItems(): array
    {
        return $this->getOption('items') ?? [];
    }

    /**
     * Set the maximum number of form groups (0 = unlimited).
     */
    public function maxFormGroups(int $maxFormGroups): static
    {
        $this->setOption('maxFormGroups', $maxFormGroups);
        return $this;
    }

    /**
     * Set the label of the "add form group" button.
     */
    public function addItemLabel(string $label): static
    {
        $this->setOption('addItemLabel', $label);
        return $this;
    }

    /**
     * Set the layout mode for each entry's inner fields.
     *
     * Accepts {@see self::LAYOUT_ROW} (inner fields horizontal, vertically centered) or
     * {@see self::LAYOUT_COLUMN} (inner fields stacked vertically, entries flowing in a
     * wrapping grid like MultiImage). Any unknown value falls back to row layout.
     */
    public function setLayout(string $layout): static
    {
        $layout = in_array($layout, [self::LAYOUT_ROW, self::LAYOUT_COLUMN], true)
            ? $layout
            : self::LAYOUT_ROW;

        $this->setOption('layout', $layout);
        return $this;
    }

    /**
     * Use row layout — inner fields render horizontally, vertically centered.
     */
    public function useRowLayout(): static
    {
        return $this->setLayout(self::LAYOUT_ROW);
    }

    /**
     * Use column layout — inner fields stack vertically, entries flow in a wrapping grid like the
     * MultiImage field. Accepts the number of columns the grid should use (defaults to 4).
     */
    public function useColumnLayout(int $columns = 4): static
    {
        $this->setColumns($columns);
        return $this->setLayout(self::LAYOUT_COLUMN);
    }

    /**
     * Set the number of columns used by the column layout grid (minimum 1).
     */
    public function setColumns(int $columns): static
    {
        $this->setOption('columns', max(1, $columns));
        return $this;
    }

    /**
     * Provide a callback that determines whether an assembled form group should be skipped
     * (excluded) when building the final stored value. Receives the form group associative array.
     */
    public function skipItem(callable $callback): static
    {
        $this->setOption('skipItem', $callback);
        return $this;
    }

    /**
     * Override the final stored value. The callback receives the assembled array of form groups
     * (after image resolution and the skipItem filter) and must return the value to store.
     * Use this when the storage shape must differ from the default key => value mapping.
     */
    public function overrideFinalValue(callable $callback): static
    {
        $this->setOption('finalValueOverride', $callback);
        return $this;
    }

    /**
     * Resolve a public URL for a stored image filename within an item.
     */
    protected function resolveImageUrl(MultiFieldItem $item, string $filename): string
    {
        $fileSystemName = $item->fileSystem;
        $disk = Storage::disk($fileSystemName);
        $path = rtrim($item->path, '/');
        $filePath = ltrim("{$path}/{$filename}", '/');

        if ($fileSystemName === 'public') {
            return $disk->url($filePath);
        }

        return route('wrla.serve-file', [
            'disk' => $fileSystemName,
            'encodedPath' => base64_encode($filePath),
        ]);
    }

    /**
     * Format the stored filename for a newly uploaded image.
     */
    protected function formatImageName(null|string|callable $name, string $originalFileName, int $groupIndex): string
    {
        if ($name === null) {
            return $originalFileName;
        }

        if (is_callable($name)) {
            return $name($this->manageableModel?->model(), $originalFileName, $groupIndex);
        }

        $name = str_replace(['{index}', '{rowIndex}', '{groupIndex}'], (string) $groupIndex, $name);

        if (str_contains($name, '{id}')) {
            $id = $this->manageableModel?->model()->id;
            if (empty($id)) {
                $id = ($this->manageableModel?->model()->max('id') ?? 0) + 1;
            }
            $name = str_replace('{id}', (string) $id, $name);
        }

        if (str_contains($name, '{time}')) {
            $name = str_replace('{time}', (string) time(), $name);
        }

        return $name;
    }

    /**
     * Store an uploaded temp file to permanent storage for a given image item and return its filename.
     */
    protected function storeImage(MultiFieldItem $item, TemporaryUploadedFile $file, int $groupIndex): string
    {
        $disk = Storage::disk($item->fileSystem);
        $path = WRLAHelper::forwardSlashPath($item->path);

        if (!$disk->exists($path)) {
            $disk->makeDirectory($path);
        }

        $filename = $this->formatImageName($item->filename, $file->getClientOriginalName(), $groupIndex);

        if (!str_contains($filename, '.')) {
            $extension = $file->getClientOriginalExtension() ?: 'jpg';
            $filename .= '.' . $extension;
        }

        $disk->put("{$path}/{$filename}", $file->get());

        return $filename;
    }

    /**
     * Render the field — builds existing form group data then embeds the MultiFormGroups Livewire component.
     */
    public function render(): mixed
    {
        $items = $this->getItems();

        $stored = $this->getValue();
        $data = is_array($stored) ? $stored : (json_decode($stored ?: '[]', true) ?: []);
        if (!is_array($data)) {
            $data = [];
        }

        $formGroups = [];
        $existingImages = [];

        foreach (array_values($data) as $groupIndex => $group) {
            $group = is_array($group) ? $group : [];
            $scalarGroup = [];

            foreach ($items as $item) {
                if ($item->isImage()) {
                    $filename = $group[$item->key] ?? null;
                    if (!empty($filename) && is_string($filename)) {
                        $existingImages[$groupIndex . '__' . $item->key] = [
                            'url' => $this->resolveImageUrl($item, $filename),
                            'name' => $filename,
                        ];
                    }
                } else {
                    $scalarGroup[$item->key] = $group[$item->key] ?? '';
                }
            }

            $formGroups[] = $scalarGroup;
        }

        return view(WRLAHelper::getViewPath('components.forms.multi-field'), [
            'label' => $this->getLabel(),
            'options' => $this->options,
            'fieldName' => $this->getName(),
            'items' => array_map(fn (MultiFieldItem $c) => $c->toViewArray(), $items),
            'maxFormGroups' => $this->getOption('maxFormGroups'),
            'addItemLabel' => $this->getOption('addItemLabel'),
            'emptyText' => $this->getOption('emptyText'),
            'layout' => $this->getOption('layout'),
            'columns' => $this->getOption('columns'),
            'formGroups' => $formGroups,
            'existingImages' => $existingImages,
        ])->render();
    }

    /**
     * Apply submitted value.
     *
     * Reads the inputs written by the MultiFormGroups Livewire component:
     *   {fieldName}_groups                          — native array of scalar form group values
     *   {fieldName}_existing_images                 — JSON map of "{groupIndex}__{itemKey}" => kept filename
     *   {fieldName}_newimg_{groupIndex}__{itemKey}  — serialized TemporaryUploadedFile for new uploads
     *
     * Returns the current value untouched when the component was not rendered.
     */
    public function applySubmittedValue(Request $request, mixed $value): mixed
    {
        $fieldName = $this->getName();

        if (!$request->has($fieldName . '_groups') && !$request->has($fieldName . '_existing_images')) {
            // Component was not rendered on this page (eg. hidden via showOnlyOn()). Never persist
            // an empty string into a JSON column — normalise blank values to null.
            return ($value === '' || $value === null) ? null : $value;
        }

        $items = $this->getItems();

        // Native scalar form group data, keyed by group index.
        $scalarGroups = $request->input($fieldName . '_groups', []);
        if (!is_array($scalarGroups)) {
            $scalarGroups = [];
        }
        ksort($scalarGroups, SORT_NUMERIC);

        // Surviving existing image filenames, keyed by "{groupIndex}__{itemKey}".
        $existingImages = json_decode($request->input($fieldName . '_existing_images', '{}'), true);
        if (!is_array($existingImages)) {
            $existingImages = [];
        }

        // Determine the full set of group indices present (scalar data and/or existing images).
        $groupIndexes = array_keys($scalarGroups);
        foreach (array_keys($existingImages) as $compositeKey) {
            $groupIndexes[] = (int) explode('__', $compositeKey, 2)[0];
        }
        $groupIndexes = array_values(array_unique(array_map('intval', $groupIndexes)));
        sort($groupIndexes, SORT_NUMERIC);

        $resultGroups = [];

        foreach ($groupIndexes as $groupIndex) {
            $assembled = [];

            // Assemble in the item definition order so the stored shape is stable.
            foreach ($items as $item) {
                if ($item->isImage()) {
                    // Image items — resolve new uploads or fall back to the kept existing filename.
                    $compositeKey = $groupIndex . '__' . $item->key;
                    $serialized = $request->input($fieldName . '_newimg_' . $compositeKey, '');
                    $resolvedFilename = $existingImages[$compositeKey] ?? null;

                    if (!empty($serialized)) {
                        $files = TemporaryUploadedFile::unserializeFromLivewireRequest($serialized);
                        $files = array_values(array_filter($files, fn ($f) => $f instanceof TemporaryUploadedFile && $f->exists()));

                        if (!empty($files)) {
                            $resolvedFilename = $this->storeImage($item, $files[0], $groupIndex);
                        }
                    }

                    $assembled[$item->key] = $resolvedFilename;
                } else {
                    // Scalar items from the native form post.
                    $assembled[$item->key] = $scalarGroups[$groupIndex][$item->key] ?? null;
                }
            }

            $resultGroups[] = $assembled;
        }

        // Apply skipItem filter.
        $skipItem = $this->getOption('skipItem');
        if (is_callable($skipItem)) {
            $resultGroups = array_values(array_filter($resultGroups, fn (array $group) => !$skipItem($group)));
        }

        // Apply final value override (allows mapping the stored shape differently).
        $finalValueOverride = $this->getOption('finalValueOverride');
        if (is_callable($finalValueOverride)) {
            return $finalValueOverride(array_values($resultGroups));
        }

        return json_encode(array_values($resultGroups), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}

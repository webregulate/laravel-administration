<?php

namespace WebRegulate\LaravelAdministration\Classes\ManageableFields;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Laravel\SerializableClosure\SerializableClosure;
use WebRegulate\LaravelAdministration\Classes\ManageableModel;
use WebRegulate\LaravelAdministration\Classes\WRLAHelper;
use WebRegulate\LaravelAdministration\Traits\ManageableField;

/**
 * Searchable single-select field
 */
class SearchSelect
{
    use ManageableField {
        make as protected makeBase;
    }

    /** Callback: fn(string $term): Builder */
    protected mixed $searchQueryCallable = null;

    /** String column name, or callback: fn(Model $model): string */
    protected mixed $itemLabelResolver = null;

    /** Value used when nothing is selected. Eg. use 0 or 'none' instead of null (default). */
    public mixed $emptyValue = null;

    /**
     * Make the field, optionally setting the search query, item label and
     * prepended option inline.
     *
     * @param  class-string<ManageableModel>|class-string<Model>|(callable(string): Builder)|null  $searchQuery
     * @param  string|(callable(Model): string)|null  $itemLabel
     * @param  array{0: int|string, 1: string}|null  $prependItem  [value, label] (e.g. ['', 'None'])
     */
    public static function make(
        ?ManageableModel $manageableModel = null,
        ?string $column = null,
        string|callable|null $searchQuery = null,
        string|callable|null $itemLabel = null,
        ?array $prependItem = null,
        ?int $searchLimit = null,
        ?array $options = null,
    ): static {
        $manageableField = static::makeBase($manageableModel, $column, array_merge([
            'placeholder' => 'Select...',
            'searchPlaceholder' => 'Search...',
            'searchLimit' => $searchLimit ?? 50,
            'prependOption' => [],
            'canCancel' => true,
        ], $options ?? []));

        if ($searchQuery !== null) {
            $manageableField->searchQuery($searchQuery);
        }

        if ($itemLabel !== null) {
            $manageableField->itemLabel($itemLabel);
        }

        if ($prependItem !== null) {
            $manageableField->prependItem($prependItem[0], $prependItem[1]);
        }

        return $manageableField;
    }

    /**
     * Define the search query. You may pass any of the following:
     *  - A ManageableModel class (if passed, must also pass the column name to search on)
     *  - A Model class (if passed, must also pass the column name to search on)
     *  - A callable that returns a custom Eloquent builder
     *
     * When a ManageableModel or Model class is passed together with a column, the
     * search filters that column by the term (case-insensitive LIKE) and the item
     * label is automatically set to the same column.
     *
     * @param class-string<ManageableModel>|class-string<Model>|callable(string): Builder  $callbackOrModel
     * @param ?string $column The column name to search on (only used/required when passing a ManageableModel or Model class)
     */
    public function searchQuery(string|callable $callbackOrModel, ?string $column = null): static
    {
        // Resolve a model class from either a ManageableModel class-string or a Model class-string.
        $modelClass = null;

        if (is_string($callbackOrModel) && is_subclass_of($callbackOrModel, ManageableModel::class)) {
            $modelClass = $callbackOrModel::getBaseModelClass();
        } elseif (is_string($callbackOrModel) && is_subclass_of($callbackOrModel, Model::class)) {
            $modelClass = $callbackOrModel;
        }

        // Class-string path: build a query that (optionally) filters on the given column,
        // and auto-set the item label to that column when provided.
        if ($modelClass !== null) {
            $this->searchQueryCallable = function (string $term) use ($modelClass, $column): Builder {
                $query = $modelClass::query();

                if ($column !== null && $term !== '') {
                    $query->where($column, 'like', "%{$term}%");
                }

                return $query;
            };

            if ($column !== null) {
                $this->itemLabel($column);
            }

            return $this;
        }

        $this->searchQueryCallable = $callbackOrModel;

        return $this;
    }

    /**
     * Define how each result is labelled. Either a column name on the model, or
     * a callback that receives the model and returns the label string.
     *
     * @param  string|callable(Model): string  $columnOrCallback
     */
    public function itemLabel(string|callable $columnOrCallback): static
    {
        $this->itemLabelResolver = $columnOrCallback;

        return $this;
    }

    /**
     * Prepend an option to the top of the list (e.g. an "All" / "None" entry).
     * Maps to the Livewire component's prependOption.
     */
    public function prependItem(int|string $value, string $label): static
    {
        $this->options['prependOption'] = [(string) $value => $label];

        return $this;
    }

    /**
     * Set the maximum number of results returned per search.
     */
    public function searchLimit(int $searchLimit): static
    {
        $this->options['searchLimit'] = $searchLimit;

        return $this;
    }

    /**
     * Set the placeholder shown on the closed field.
     */
    public function placeholder(string $placeholder): static
    {
        $this->options['placeholder'] = $placeholder;

        return $this;
    }

    /**
     * Set the placeholder shown inside the search input.
     */
    public function searchPlaceholder(string $searchPlaceholder): static
    {
        $this->options['searchPlaceholder'] = $searchPlaceholder;

        return $this;
    }

    /**
     * Set whether a "Cancel" button is shown that clears the selection back to
     * null when pressed. Defaults to true.
     */
    public function canCancel(bool $canCancel = true): static
    {
        $this->options['canCancel'] = $canCancel;

        return $this;
    }

    /**
     * Define the empty value. Eg. use 0 or 'none' instead of null (default). Used
     * as the field value when nothing is selected.
     */
    public function setEmptyValue(mixed $emptyValue): static
    {
        $this->emptyValue = $emptyValue;

        return $this;
    }

    /**
     * Run the configured search query for the given term. Called by the Livewire
     * component after it re-derives this field.
     */
    public function runSearchQuery(string $term): Builder
    {
        if ($this->searchQueryCallable === null) {
            throw new \Exception('SearchSelect field "' . $this->getName() . '" is missing a searchQuery() callback.');
        }

        return ($this->searchQueryCallable)($term);
    }

    /**
     * Resolve the display label for a single model. Called by the Livewire
     * component after it re-derives this field.
     */
    public function resolveItemLabel(Model $model): string
    {
        $resolver = $this->itemLabelResolver;

        if ($resolver === null) {
            return (string) $model->getKey();
        }

        if (is_callable($resolver)) {
            return (string) $resolver($model);
        }

        return (string) data_get($model, $resolver);
    }

    /**
     * Expose the prepend option to the Livewire component.
     */
    public function getPrependOption(): array
    {
        return $this->options['prependOption'] ?? [];
    }

    /**
     * Serialize a callback so the Livewire component can rebuild it on its own
     * requests when there is no ManageableModel to act as a factory. The payload
     * is signed with the app key (via Laravel's SerializableClosure), so it
     * cannot be tampered with in transit.
     *
     * @throws \Exception when the callback captures non-serializable state.
     */
    protected function serializeCallable(callable $callable, string $context): string
    {
        if (! $callable instanceof Closure) {
            $callable = Closure::fromCallable($callable);
        }

        try {
            return base64_encode(serialize(new SerializableClosure($callable)));
        } catch (\Throwable $e) {
            throw new \Exception(
                "SearchSelect field \"{$this->getName()}\" could not serialize its {$context} callback. "
                .'When used without a ManageableModel the callback must be serializable — avoid capturing $this '
                ."or other non-serializable objects via 'use'. Alternatively, pass a ManageableModel to "
                ."SearchSelect::make(). Original error: {$e->getMessage()}",
                0,
                $e
            );
        }
    }

    /**
     * Render the field — embeds the search-select Livewire component within a
     * labelled wrapper.
     *
     * When a ManageableModel is present the component receives only serializable
     * identifiers and re-derives this field (and its closures) on each request.
     * When no ManageableModel is present (standalone usage), the search/label
     * closures are signed and serialized so the component can rebuild them on
     * its own requests without a factory.
     */
    public function render(): mixed
    {
        $usesManageableModel = $this->manageableModel !== null;

        // If an empty value is defined and the current value is empty, fall back to it.
        if ($this->emptyValue !== null && empty($this->getValue())) {
            $this->setAttribute('value', $this->emptyValue);
        }

        $serializedSearchQuery = null;
        $serializedItemLabel = null;
        $itemLabelColumn = null;

        // Detect a parent wire:model binding (set via setLivewireModel), so the embedded
        // Livewire component can be bound two-way to the parent's property. Without this the
        // isolated child component's value never reaches the parent component.
        $wireModelTarget = null;
        foreach ($this->htmlAttributes as $key => $attributeValue) {
            if (str_starts_with((string) $key, 'wire:model')) {
                $wireModelTarget = $attributeValue;
                break;
            }
        }

        if (! $usesManageableModel) {
            if ($this->searchQueryCallable !== null) {
                $serializedSearchQuery = $this->serializeCallable($this->searchQueryCallable, 'searchQuery');
            }

            if ($this->itemLabelResolver instanceof Closure) {
                $serializedItemLabel = $this->serializeCallable($this->itemLabelResolver, 'itemLabel');
            } elseif (is_string($this->itemLabelResolver)) {
                $itemLabelColumn = $this->itemLabelResolver;
            }
        }

        return view(WRLAHelper::getViewPath('components.forms.search-select'), [
            'label' => $this->getLabel(),
            'options' => $this->options,
            'fieldName' => $this->getName(),
            'manageableModelClass' => $usesManageableModel ? get_class($this->manageableModel) : null,
            'modelId' => $this->manageableModel?->model()?->getKey(),
            'serializedSearchQuery' => $serializedSearchQuery,
            'serializedItemLabel' => $serializedItemLabel,
            'itemLabelColumn' => $itemLabelColumn,
            'value' => (string) $this->getValue(),
            'wireModelTarget' => $wireModelTarget,
            'placeholder' => $this->getOption('placeholder'),
            'searchPlaceholder' => $this->getOption('searchPlaceholder'),
            'searchLimit' => $this->getOption('searchLimit'),
            'prependOption' => $this->getOption('prependOption') ?? [],
            'canCancel' => (bool) $this->getOption('canCancel'),
            'disabled' => (bool) $this->getAttribute('disabled'),
            'required' => (bool) $this->getAttribute('required'),
        ])->render();
    }
}

<?php

namespace WebRegulate\LaravelAdministration\Classes\ManageableFields;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
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

    /**
     * Make the field, optionally setting the search query, item label and
     * prepended option inline.
     *
     * @param  (callable(string): Builder)|null  $searchQuery
     * @param  string|(callable(Model): string)|null  $itemLabel
     * @param  array{0: int|string, 1: string}|null  $prependItem  [value, label] (e.g. ['', 'None'])
     */
    public static function make(
        ?ManageableModel $manageableModel = null,
        ?string $column = null,
        ?callable $searchQuery = null,
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
     * Define the search query. The callback receives the trimmed search term
     * (may be empty when the popout first opens) and must return an Eloquent
     * query builder.
     *
     * @param  callable(string): Builder  $callback
     */
    public function searchQuery(callable $callback): static
    {
        $this->searchQueryCallable = $callback;

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
     * Render the field — embeds the search-select Livewire component within a
     * labelled wrapper. The component receives only serializable identifiers so
     * it can re-derive this field (and its closures) on each request.
     */
    public function render(): mixed
    {
        return view(WRLAHelper::getViewPath('components.forms.search-select'), [
            'label' => $this->getLabel(),
            'options' => $this->options,
            'fieldName' => $this->getName(),
            'manageableModelClass' => $this->manageableModel !== null ? get_class($this->manageableModel) : null,
            'modelId' => $this->manageableModel?->model()?->getKey(),
            'value' => (string) $this->getValue(),
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

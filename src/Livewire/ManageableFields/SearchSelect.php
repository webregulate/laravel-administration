<?php

namespace WebRegulate\LaravelAdministration\Livewire\ManageableFields;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Reactive;
use Livewire\Component;
use WebRegulate\LaravelAdministration\Classes\ManageableFields\SearchSelect as SearchSelectField;
use WebRegulate\LaravelAdministration\Classes\WRLAHelper;

/**
 * Self-contained searchable single-select Livewire component, embedded by the
 * SearchSelect ManageableField.
 *
 * It runs its own AJAX searches. Because closures cannot survive Livewire
 * hydration, the search query and item label are not passed in directly.
 * Instead this component re-derives the owning ManageableField each request
 * (via ManageableModel::getManageableFieldByName) and delegates to its
 * runSearchQuery() / resolveItemLabel() methods, which invoke the inline
 * callbacks defined on the field.
 *
 * The selected value is submitted with the surrounding form through a hidden
 * input named after the field, mirroring the MultiUploadFields pattern.
 */
class SearchSelect extends Component
{
    /** Field / hidden-input name used for form submission and re-derivation. */
    public string $name = '';

    /** Manageable model class used to rebuild the field and its callbacks. */
    public ?string $manageableModelClass = null;

    /** Model id (null when creating) used when rebuilding the manageable model. */
    public ?int $modelId = null;

    public string $placeholder = 'Select...';

    public string $searchPlaceholder = 'Search...';

    public string $search = '';

    public int $searchLimit = 50;

    public ?string $selectedId = null;

    public string $selectedLabel = '';

    /** When true, the field is disabled and cannot be opened or changed. */
    #[Reactive]
    public bool $disabled = false;

    /** When true, the selection cannot be cleared back to empty. */
    public bool $required = false;

    /** Optional prepended option as [value => label] (e.g. ['' => 'None']). */
    #[Reactive]
    public array $prependOption = [];

    /** Search results: list of ['id' => mixed, 'label' => string, 'selected' => bool]. */
    public array $results = [];

    public function mount(
        string $name,
        ?string $manageableModelClass = null,
        ?int $modelId = null,
        string $value = '',
        ?string $placeholder = null,
        ?string $searchPlaceholder = null,
        ?int $searchLimit = null,
        array $prependOption = [],
        bool $disabled = false,
        bool $required = false,
    ): void {
        $this->name = $name;
        $this->manageableModelClass = $manageableModelClass;
        $this->modelId = $modelId;
        $this->prependOption = $prependOption;
        $this->disabled = $disabled;
        $this->required = $required;

        if ($placeholder !== null) {
            $this->placeholder = $placeholder;
        }

        if ($searchPlaceholder !== null) {
            $this->searchPlaceholder = $searchPlaceholder;
        }

        if ($searchLimit !== null) {
            $this->searchLimit = $searchLimit;
        }

        // Resolve the initial selection so its label is populated on load.
        if ($this->isPrependKey($value)) {
            $this->applyPrependSelection();
        } elseif ($value !== '') {
            $this->applyInitialSelection($value);
        }

        // Fall back to the prepended option (e.g. "None") when nothing else is
        // selected, so it becomes the default selection.
        if ($this->selectedId === null && !empty($this->prependOption)) {
            $this->applyPrependSelection();
        }
    }

    public function updatedSearch(): void
    {
        $this->runSearch();
    }

    /**
     * Re-derive the owning ManageableField so its inline callbacks can be used.
     */
    protected function field(): SearchSelectField
    {
        if ($this->manageableModelClass === null) {
            throw new \Exception('SearchSelect Livewire component is missing a manageableModelClass.');
        }

        $manageableModel = $this->manageableModelClass::make($this->modelId, true);
        $field = $manageableModel->getManageableFieldByName($this->name);

        if (!$field instanceof SearchSelectField) {
            throw new \Exception("SearchSelect could not re-derive field '{$this->name}' on {$this->manageableModelClass}.");
        }

        return $field;
    }

    /**
     * Search query for the given term, delegated to the field's callback.
     */
    protected function searchQuery(string $term): Builder
    {
        return $this->field()->runSearchQuery($term);
    }

    /**
     * Display label for a single model, delegated to the field's resolver.
     */
    protected function itemLabel(Model $model): string
    {
        return $this->field()->resolveItemLabel($model);
    }

    /**
     * Execute the search and populate $results, flagging the selected item.
     */
    public function runSearch(): void
    {
        $term = trim($this->search);

        $field = $this->field();

        $items = $field->runSearchQuery($term)
            ->limit($this->searchLimit)
            ->get();

        $results = $items
            ->map(fn (Model $model): array => [
                'id' => $model->getKey(),
                'label' => $field->resolveItemLabel($model),
                'selected' => (string) $model->getKey() === (string) $this->selectedId,
            ])
            ->values()
            ->all();

        // Prepend the optional option (e.g. "None") to the top of the list.
        if (!empty($this->prependOption)) {
            $key = (string) array_key_first($this->prependOption);

            array_unshift($results, [
                'id' => $key,
                'label' => (string) $this->prependOption[array_key_first($this->prependOption)],
                'selected' => $key === (string) $this->selectedId,
            ]);
        }

        $this->results = $results;
    }

    /**
     * Select an item, or clear the selection if it's already selected (toggle).
     */
    public function select(string $id): void
    {
        if ((string) $this->selectedId === $id) {
            $this->clear();

            return;
        }

        $results = collect($this->results)->keyBy(fn (array $result): string => (string) $result['id']);

        if (!$results->has($id)) {
            return;
        }

        $this->selectedId = $id;
        $this->selectedLabel = $results->get($id)['label'];

        $this->flagSelectedResults();
    }

    /**
     * Clear the current selection (falls back to the prepend option if present,
     * otherwise empty when not required).
     */
    public function clear(): void
    {
        if (!empty($this->prependOption)) {
            $this->applyPrependSelection();
            $this->flagSelectedResults();

            return;
        }

        if ($this->required) {
            return;
        }

        $this->selectedId = null;
        $this->selectedLabel = '';

        $this->flagSelectedResults();
    }

    /**
     * Re-flag the visible results so the selected item stays highlighted without
     * re-running the search.
     */
    protected function flagSelectedResults(): void
    {
        $this->results = collect($this->results)
            ->map(function (array $result): array {
                $result['selected'] = (string) $result['id'] === (string) $this->selectedId;

                return $result;
            })
            ->values()
            ->all();
    }

    /**
     * Apply the initial selection from a scalar id, resolving it to a label.
     */
    protected function applyInitialSelection(string $value): void
    {
        if ($value === '') {
            return;
        }

        $model = $this->resolveItem($value);

        if ($model !== null) {
            $this->selectedId = (string) $model->getKey();
            $this->selectedLabel = $this->itemLabel($model);
        }
    }

    protected function isPrependKey(string $id): bool
    {
        return !empty($this->prependOption)
            && (string) array_key_first($this->prependOption) === $id;
    }

    /**
     * Apply the prepended option (e.g. "None") as the current selection.
     */
    protected function applyPrependSelection(): void
    {
        $key = array_key_first($this->prependOption);

        $this->selectedId = (string) $key;
        $this->selectedLabel = (string) $this->prependOption[$key];
    }

    /**
     * Resolve an id back into its model so its label can be rendered.
     */
    protected function resolveItem(string $id): ?Model
    {
        $model = $this->field()->runSearchQuery('')->getModel();

        return $model->newQuery()
            ->whereKey($id)
            ->first();
    }

    public function render()
    {
        return view(WRLAHelper::getViewPath('livewire.manageable-fields.search-select'));
    }
}

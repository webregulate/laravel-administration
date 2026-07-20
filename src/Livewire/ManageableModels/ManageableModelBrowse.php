<?php

namespace WebRegulate\LaravelAdministration\Livewire\ManageableModels;

use Livewire\Component;
use Livewire\Features\SupportRedirects\HandlesRedirects;
use Symfony\Component\HttpFoundation\StreamedResponse;
use WebRegulate\LaravelAdministration\Classes\BrowseColumns\BrowseColumnBase;
use WebRegulate\LaravelAdministration\Classes\CSVHelper;
use WebRegulate\LaravelAdministration\Classes\ManageableModel;
use WebRegulate\LaravelAdministration\Classes\WRLAHelper;
use WebRegulate\LaravelAdministration\Enums\ManageableModelPermissions;
use WebRegulate\LaravelAdministration\Enums\PageType;
use WebRegulate\LaravelAdministration\Traits\ManageableField;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Livewire\WithPagination;
use Throwable;

/**
 * Class ManageableModelBrowse
 *
 * This class represents a Livewire component for browsing manageable models.
 */
class ManageableModelBrowse extends Component
{
    use HandlesRedirects, WithPagination;

    /* Properties
    --------------------------------------------------------------------------*/

    /**
     * The class name of the manageable model.
     *
     * @var string
     */
    public $manageableModelClass;

    /**
     * Columns to display (Collection of column names)
     */
    public $columns = null;

    /**
     * Filters (array of BrowseFilter's). The manageable model's filters key => value pairs. key being the
     * filter key and value being the current value of the filter.
     */
    public array $filters = [];

    /**
     * Dynamic filter inputs
     */
    public array $dynamicFilterInputs = [];

    /**
     * Pre filters
     */
    public ?array $preFilters = null;

    /**
     * Order by
     */
    public $orderBy = 'id';

    /**
     * Order direction.
     *
     * @var string
     */
    public $orderDirection = 'desc';

    /**
     * Table columns.
     *
     * @var array
     */
    public $tableColumns = [];

    /**
     * Livewire fields, attach with manageable model ->setAttribute('wire:model.live', 'livewireData.key')
     */
    public array $livewireData = [];

    /**
     * Success message.
     *
     * @var ?string
     */
    public $successMessage = null;

    /**
     * Error message.
     *
     * @var ?string
     */
    public $errorMessage = null;

    /**
     * Debug message
     */
    public ?string $debugMessage = null;

    /**
     * Renders
     */
    public int $renders = 0;

    /**
     * Selected row ids (primary keys) when multi selection is enabled. Stored as strings as they
     * originate from checkbox values.
     *
     * @var array
     */
    public array $wrlaSelectedIds = [];

    /**
     * Listeners
     *
     * @var array
     */
    protected $listeners = [
        'filtersUpdatedOutside' => 'filtersUpdatedOutside',
        'deleteModel' => 'deleteModel',
    ];

    /* Livewire Methods / Hooks
    --------------------------------------------------------------------------*/

    /**
     * Filters updated outside
     *
     * @param  array  $filters
     */
    public function filtersUpdatedOutside(array $dynamicFilterInputs): void
    {
        $this->dynamicFilterInputs = $dynamicFilterInputs;

        // foreach($dynamicFilterInputs as $item) {
        //     $this->filters[$item['field']] = $item['value'];
        // }
    }

    /**
     * Updates fields
     */
    public function updatedFilters(string $field): void
    {
        $this->resetPage();
    }

    /**
     * Mount the component.
     *
     * @param  string  $manageableModelClass  The class name of the manageable model.
     * @param  ?array  $preFilters  Pre filters passed from the AdminController::browse -> livewire-content view
     * @return \Illuminate\Http\RedirectResponse|null
     */
    public function mount(string $manageableModelClass, ?array $preFilters = null)
    {
        // Set the page type so page-type aware logic resolves correctly for the browse view.
        WRLAHelper::setCurrentPageType(PageType::BROWSE);

        // If the manageable model reference is null, redirect to the dashboard
        if (is_null($manageableModelClass)) {
            return redirect()->route('wrla.dashboard')->with('error', "Manageable model `$manageableModelClass` not found.");
        }

        // Get the manageable model and base model class
        $this->manageableModelClass = $manageableModelClass;
        $manageableModelInstance = $this->model();
        $modelClass = $manageableModelInstance::getBaseModelClass();

        // If the model class does not exist, redirect to the dashboard
        if (! class_exists($modelClass)) {
            return redirect()->route('wrla.dashboard')->with('error', "Model `$modelClass` not found while loading manageable model `$manageableModelClass`.");
        }

        // Run browse setup method
        $this->manageableModelClass::browseSetupFinal($this->filters);
        $this->preFilters = $preFilters;

        // Build parent columns from manageable model
        $columns = $manageableModelInstance->getBrowseColumns();
        $this->columns = collect($columns)->map(fn ($column) => $column instanceof BrowseColumnBase ? $column->label : $column);

        // Apply default order by and order direction
        $orderByData = $manageableModelClass::getDefaultOrderBy();
        $this->orderBy = $orderByData->get('column');
        $this->orderDirection = $orderByData->get('direction');

        // Get manageable model filter keys from collection
        $manageableModelFilters = $manageableModelClass::getBrowseFilters();

        foreach ($manageableModelFilters as $browseFilter) {
            $this->filters[$browseFilter->getKey()] = $browseFilter->getField()->getValue();
        }

        // 1. Check the pre filters and override default browse filters if so
        if (! empty($preFilters)) {
            foreach ($preFilters as $key => $value) {
                if (array_key_exists($key, $this->filters)) {
                    $this->filters[$key] = $value;
                }
            }
        }

        // 2. Override with URL query parameters if present
        foreach($manageableModelFilters as $browseFilter) {
            $key = $browseFilter->getKey();
            if (request()->has($key)) {
                $this->filters[$key] = request()->input($key);
                ManageableField::setStaticBrowseFilterValue($browseFilter, $this->filters[$key]);
            }
        }

        // I know we said final, but at the moment we need to run browse setup method one final final time (Now that the filters are pre-set)
        $this->manageableModelClass::browseSetupFinal($this->filters);

        // Get table columns
        $this->tableColumns = $this->manageableModelClass::getTableColumns();
    }

    /**
     * Get manageable model instance
     */
    public function model(): ManageableModel
    {
        return new $this->manageableModelClass;
    }

    /**
     * Order by
     *
     * @return void
     */
    public function reOrderAction(string $column, string $direction)
    {
        $this->orderBy = $column;
        $this->orderDirection = $direction;
        $this->debugMessage = "Order by $column $direction";
    }

    /**
     * Export as CSV action
     *
     * @param  ?string  $manageableModelStaticExportMethod  The static export method to use in place of the standard export, method name must begin with 'export', takes collection of models and optional &$fileName, returns [string filename, array rowData (with keys as headings)]
     * @param  ?int  $limit  Optional limit on the number of rows to export.
     */
    public function exportAsCSVAction(?string $manageableModelStaticExportMethod = null, ?int $limit = null): ?StreamedResponse
    {
        $query = $this->browseModels();

        if (is_int($limit) && $limit > 0) {
            $query->limit($limit);
        }

        try {
            return CSVHelper::exportManageableModels(
                $this->manageableModelClass,
                $query->get(),
                $manageableModelStaticExportMethod
            );
        } catch (Throwable $e) {
            $this->addError('error', 'CSV export failed: '.$e->getMessage());

            return null;
        }
    }

    /**
     * Render the component.
     *
     * @return \Illuminate\Contracts\View\View
     */
    public function render()
    {
        // Re-assert the page type on every render. Livewire update requests bypass the
        // HTTP controller (which is the only other place the page type is set), so without
        // this the global WRLAHelper::$currentPageType would hold a stale/default value
        // during browse renders, causing page-type aware conditions (e.g. instance action
        // requireCondition checks) to evaluate incorrectly on the browse listing.
        WRLAHelper::setCurrentPageType(PageType::BROWSE);

        $this->renders++;
        $models = $this->browseModels()->paginate(18);

        return view(WRLAHelper::getViewPath('livewire.manageable-models.browse'), [
            'models' => $models,
            'hasFilters' => $this->hasFilters(),
        ]);
    }

    /* Methods
    --------------------------------------------------------------------------*/

    /**
     * Get standard columns (non json reference or relationship columns)
     *
     * @return Collection
     */
    public function getStandardColumns()
    {
        $columns = func_num_args() > 0 ? func_get_arg(0) : $this->columns;
        return collect($columns)->filter(fn ($label, $column) => ! WRLAHelper::isBrowseColumnRelationship($column) && ! str_contains((string) $column, '->'));
    }

    /**
     * Get JSON reference columns
     *
     * @return Collection
     */
    public function getJsonReferenceColumns()
    {
        $columns = func_num_args() > 0 ? func_get_arg(0) : $this->columns;
        return collect($columns)->filter(fn ($label, $column) => str_contains((string) $column, '->'));
    }

    /**
     * Get relationship columns
     *
     * @return Collection
     */
    public function getRelationshipColumns()
    {
        $columns = func_num_args() > 0 ? func_get_arg(0) : $this->columns;
        // Get any keys from columns that have a relationship
        return collect($columns)->filter(fn ($label, $column) => WRLAHelper::isBrowseColumnRelationship($column));
    }

    /**
     * Browse the models.
     */
    protected function browseModels(): Builder
    {
        // get base model class and instance
        $baseModelClass = $this->manageableModelClass::getBaseModelClass();
        $baseModelInstance = new $baseModelClass;

        // Run browse setup method
        if ($this->renders > 1) {
            $this->manageableModelClass::browseSetupFinal($this->filters);

            // We need to run the below simply incase the browse columns appendPreQuery in any way
            $manageableModelInstance = $this->model();
            $manageableModelInstance->getBrowseColumns();
        }

        // Get connection and table name
        $tableName = $baseModelInstance->getTable();

        // If table does not exist in database, redirect to dashboard with error
        if (! WRLAHelper::tableExists($baseModelInstance, $tableName)) {
            session()->flash('error', 'Table `'.$tableName.'` does not exist in the database.');
            $this->redirectRoute('wrla.dashboard');

            // Now we just return builder
            return $baseModelClass::query();
        }

        // Get all types of columns from a single collection
        $allColumns = $this->columns;
        $standardColumns = $this->getStandardColumns($allColumns);
        $relationshipColumns = $this->getRelationshipColumns($allColumns);
        $jsonReferenceColumns = $this->getJsonReferenceColumns($allColumns);
        $orderByIsRelationship = WRLAHelper::isBrowseColumnRelationship($this->orderBy);

        // Start eloquent query
        $eloquent = $baseModelClass::query();

        // Select any fields that aren't relationships or json references
        $eloquent = $eloquent->addSelect("$tableName.*");
        
        // Pre query
        $preQueryResult = $this->manageableModelClass::processPreQuery($eloquent, $this->filters);
        if($preQueryResult instanceof Builder) {
            $eloquent = $preQueryResult;
        }

        // Relationship named columns look like this relationship->remote_column, so we need to split them
        // and add left joins and selects to the query
        $joinsMade = [];
        if ($relationshipColumns->count() > 0) {
            // We used to use localcolumn::relationship_table.remote_column, but now we use relationship_method->remote_column
            // So we can just use the relationship method to get the relationship
            foreach ($relationshipColumns as $relationshipKey => $label) {
                // Get the relationship method and remote column
                [$relationshipMethod, $remoteColumn] = WRLAHelper::parseBrowseColumnRelationship($relationshipKey);

                // Get relation information
                $relation = $eloquent->getRelation($relationshipMethod);
                if ($relation === null) {
                    continue;
                }

                // With relationship
                $eloquent->with($relationshipMethod);

                // Get related data
                $related = $relation->getRelated();
                $relationTable = $related->getTable();

                // If join already made, skip
                if (in_array($relationTable, $joinsMade)) {
                    continue;
                }

                $eloquent = $eloquent->leftJoinRelation($relationshipMethod);

                // Add to joins made (And check for any joins added by the relationship's joins)
                $joinsMade[] = $relationTable;
                foreach ($eloquent->getQuery()->joins as $join) {
                    if (in_array($join->table, $joinsMade)) {
                        continue;
                    }
                    $joinsMade[] = $join->table;
                }
            }
        }

        // If Json reference columns exist, add them to the query
        if ($jsonReferenceColumns->count() > 0) {
            foreach ($jsonReferenceColumns as $column => $label) {
                [$relationshipMethod, $remoteColumn] = WRLAHelper::parseBrowseColumnRelationship($column);
                $relation = $eloquent->getRelation($relationshipMethod);
                if ($relation === null) {
                    continue;
                }
                $related = $relation->getRelated();

                // If in relationship columns
                if ($relationshipColumns->has($column)) {
                    $relationTable = $related->getTable();
                    $eloquent->addSelect("{$relationTable}.$remoteColumn as $column");

                    continue;
                }

                // Note that the column can be nested any number of levels deep, for example: data->profile->avatar
                // With query builder, json_extract is already automatically added, so we just make a nice alias for the value
                $eloquent = $eloquent->addSelect("{$column} as $column");
            }
        }

        // If first render, apply session filters (if rememberFilters is enabled)
        if($this->renders == 1 && empty($this->preFilters)) {
            $rememberFilters = $this->manageableModelClass::getStaticOption($this->manageableModelClass, 'rememberFilters');
            if($rememberFilters['enabled']) {
                // Standard filters
                foreach($this->filters as $key => $value) {
                    $sessionKey = "wrla_filter_{$this->manageableModelClass}_{$key}";
                    $sessionValue = session($sessionKey);
                    if(!empty($sessionValue)) {
                        $this->filters[$key] = is_string($sessionValue) ? (json_decode($sessionValue, true) ?? $sessionValue) : $sessionValue;
                        ManageableField::setStaticBrowseFilterValue($key, $this->filters[$key]);
                    }
                }

                // NOTE: Dynamic filters are handled withiin ManageableModelDynamicBrowseFilters
            }
        }

        // Now we loop through the filterable fields and apply them to the query
        $manageableModelFilters = [];

        // If dynamic filter inputs are empty, use standard manageable model filters
        if (empty($this->dynamicFilterInputs)) {
            // Get manageable model filters from collection
            $manageableModelFilters = $this->manageableModelClass::getBrowseFilters();

            // Loop through each filter and apply query
            foreach ($manageableModelFilters as $browseFilter) {
                // Get filter key
                $key = $browseFilter->getKey();

                // If filter value is empty, skip
                if (empty($this->filters[$key])) continue;

                // Merge additional columns if set in browse filter options
                $columns = $allColumns;
                foreach($browseFilter->getField()->getOption('mergeColumns') ?? [] as $col) {
                    $columns->put($col, $col);
                }

                // Apply the filter to the query
                $eloquent = $browseFilter->apply($eloquent, $tableName, $columns, $this->filters[$key]);
            }
        }
        // Otherwise use dynamic filter inputs
        else {
            // Loop through each dynamic filter input and apply query
            foreach ($this->dynamicFilterInputs as $item) {
                // Build browse filter from input
                $browseFilter = ManageableModelDynamicBrowseFilters::buildBrowseFilter($item);
                $browseFilter->field->setAttribute('value', $item['value'] ?? '');
                $manageableModelFilters[] = $browseFilter;

                // Apply dynamic filter to query
                $eloquent = $browseFilter->apply($eloquent, $tableName, $allColumns, $browseFilter->field->getValue());
            }
        }

        // If manageable model uses rememberFilters, store filters in session
        $rememberFilters = $this->manageableModelClass::getStaticOption($this->manageableModelClass, 'rememberFilters');
        if($rememberFilters['enabled']) {
            // Standard filters
            foreach($this->filters as $key => $value) {
                $sessionKey = "wrla_filter_{$this->manageableModelClass}_{$key}";
                $sessionValue = is_array($value) ? json_encode($value) : $value;
                session([$sessionKey => $sessionValue]);
            }

            // NOTE: Dynamic filters are handled withiin ManageableModelDynamicBrowseFilters
        }

        // Order by
        // If orderBy is standard column, order by that column
        if (! $orderByIsRelationship) {
            // If orderBy column exists in standard columns, then prefix with table name
            if(in_array($this->orderBy, $this->tableColumns)) {
                $eloquent = $eloquent->orderBy("$tableName.$this->orderBy", $this->orderDirection);
            }
            // If orderBy column does not exist in standard columns, then just order by the column name
            else {
                $eloquent = $eloquent->orderBy($this->orderBy, $this->orderDirection);
            }
        }
        // If orderBy is a relationship column, order by the relationship column
        else {
            // Get relationship method and remote column
            [$relationshipMethod, $remoteColumn] = WRLAHelper::parseBrowseColumnRelationship($this->orderBy);

            // Get relation information
            $relation = $eloquent->getRelation($relationshipMethod);

            // TODO: This needs fixing as we cannot currently order by through style relationships eg. $this->relationship->belongsTo()
            if ($relation !== null) {
                $related = $relation->getRelated();
                $relationTable = $related->getTable();

                // Apply join for relationship and order by relationship column (if not already joined)
                if (! in_array($relationTable, $joinsMade)) {
                    $eloquent = $eloquent->leftJoinRelation($relationshipMethod);
                    $joinsMade[] = $relationTable;
                }

                // Order by relationship column
                $eloquent = $eloquent->orderBy("$relationTable.$remoteColumn", $this->orderDirection);
            }
        }

        $this->debugMessage = $eloquent->toRawSql();

        return $eloquent;
    }

    /**
     * Delete a model.
     *
     * @param  string  $modelUrlAlias  The URL alias of the model to delete.
     * @param  int  $id  The ID of the model to delete.
     */
    public function deleteModel(string $modelUrlAlias, int $id)
    {
        // Get manageable model instance
        $manageableModel = new $this->{'manageableModelClass'}($id, true);

        // Set current manageable model class and instance
        WRLAHelper::setCurrentActiveManageableModelClass($this->manageableModelClass);
        WRLAHelper::setCurrentActiveManageableModelInstance($manageableModel);

        // Check has permission to delete
        if(!$this->manageableModelClass::getPermission(ManageableModelPermissions::DELETE)) {
            $this->errorMessage = 'You do not have permission to delete this model.';
            return;
        }

        // Check that model URL alias matches the manageable model class URL alias
        if ($modelUrlAlias != $this->manageableModelClass::getUrlAlias()) {
            $this->errorMessage = 'Model URL alias does not match the manageable model class URL alias.';
            return;
        }

        // Delete the model and deconstruct the response
        [$success, $message] = WRLAHelper::deleteModel($manageableModel, $id);

        if ($success) {
            $this->successMessage = $message;
            $this->errorMessage = null;
        } else {
            $this->errorMessage = $message;
            $this->successMessage = null;
        }
    }

    /**
     * Restore a model.
     *
     * @param  string  $modelUrlAlias  The URL alias of the model to restore.
     * @param  int  $id  The ID of the model to restore.
     */
    public function restoreModel(int $id)
    {
        // Check that model URL alias matches the manageable model class URL alias
        // if($modelUrlAlias != $this->manageableModelClass::getUrlAlias()) {
        //     return;
        // }

        $baseModelClass = $this->manageableModelClass::getStaticOption($this->manageableModelClass, 'baseModelClass');
        $model = $baseModelClass::withTrashed()->find($id);
        $model->restore();

        // Forget any cached manageable model instance for this id so the re-render reflects
        // the restored (non-trashed) state instead of the stale cached instance.
        $this->manageableModelClass::forgetModelInstanceCache($id);
    }

    /**
     * Check if any filters are set.
     *
     * @return bool
     */
    public function hasFilters()
    {
        // If manageable model uses dynamic browse filters, return true
        if ($this->manageableModelClass::getStaticOption($this->manageableModelClass, 'browse.useDynamicFilters')) {
            return true;
        }

        // Loop through the filters and compare their values with the default values
        foreach ($this->filters as $value) {
            // Return true If any filter value is not null
            if ($value != null) {
                return true;
            }
        }

        // Return false if no filters are set
        return false;
    }

    /**
     * Reset filters action.
     */
    public function resetFiltersAction()
    {
        $rememberFilters = $this->manageableModelClass::getStaticOption($this->manageableModelClass, 'rememberFilters');

        // Build a map of the original default filter values as defined in the manageable model.
        // This mirrors how mount() initialises $this->filters, so resetting restores those defaults
        // (or empty/null where a filter has no default value set).
        $defaultFilterValues = [];
        foreach ($this->manageableModelClass::getBrowseFilters() as $browseFilter) {
            $defaultFilterValues[$browseFilter->getKey()] = $browseFilter->getField()->getValue();
        }

        // Standard filters
        foreach ($this->filters as $key => $value) {
            $defaultValue = $defaultFilterValues[$key] ?? null;
            $this->filters[$key] = $defaultValue;
            ManageableField::setStaticBrowseFilterValue($key, $defaultValue);

            // If rememberFilters is enabled, also remove from session
            if ($rememberFilters['enabled']) {
                $sessionKey = "wrla_filter_{$this->manageableModelClass}_{$key}";
                session()->forget($sessionKey);
            }
        }

        // Dynamic filters - This doesn't seem to work. The fields attached to this component
        // are not the same as those in the ManageableModelDynamicBrowseFilters component
        // if ($rememberFilters['enabled']) {
        //     $sessionKey = "wrla_dynamicFilters_{$this->manageableModelClass}}";
        //     session()->forget($sessionKey);
        // }
        // $this->dynamicFilterInputs = [];
        // $this->filtersUpdatedOutside($this->dynamicFilterInputs);

        // Reset to first page
        $this->resetPage();
    }

    /**
     * Call manageable model action.
     */
    public function callManageableModelAction(int $instanceId, string $actionKey, array $parameters = []) {
        $result = WRLAHelper::callManageableModelAction($this, $this->manageableModelClass, $instanceId, $actionKey, $parameters);
        if (!($result instanceof \Symfony\Component\HttpFoundation\BinaryFileResponse) && !($result instanceof \Symfony\Component\HttpFoundation\StreamedResponse)) {
            $this->dispatch('instanceActionCompleted');
        }
        return $result;
    }

    /**
     * Set selection state for all rows on the current page.
     *
     * @param array $pageIds The primary keys of the rows currently displayed on the page.
     * @param bool $isChecked Whether the page-level checkbox is currently checked.
     */
    public function setSelectAllOnPage(array $pageIds, bool $isChecked): void
    {
        $pageIds = array_map('strval', $pageIds);
        $selected = array_map('strval', $this->wrlaSelectedIds);

        if ($isChecked) {
            $this->wrlaSelectedIds = array_values(array_unique(array_merge($selected, $pageIds)));
            return;
        }

        $this->wrlaSelectedIds = array_values(array_diff($selected, $pageIds));
    }

    /**
     * Backwards-compatible toggle helper for older callers.
     *
     * @param array $pageIds The primary keys of the rows currently displayed on the page.
     */
    public function toggleSelectAllOnPage(array $pageIds): void
    {
        $pageIds = array_map('strval', $pageIds);
        $selected = array_map('strval', $this->wrlaSelectedIds);
        $allSelected = ! empty($pageIds) && empty(array_diff($pageIds, $selected));

        $this->setSelectAllOnPage($pageIds, ! $allSelected);
    }

    /**
     * Call a multi instance action against the currently selected rows.
     *
     * @param string $actionKey The registered multi action key.
     * @param array $parameters Optional parameters passed to the action handler.
     */
    public function callMultiInstanceAction(string $actionKey, array $parameters = [])
    {
        $ids = array_values($this->wrlaSelectedIds);

        if (empty($ids)) {
            $this->errorMessage = 'No rows selected.';
            $this->successMessage = null;
            return;
        }

        // Set current active manageable model class and build a fresh instance so the multi actions
        // (and therefore their registered keys) are registered ready to be called by key.
        WRLAHelper::setCurrentActiveManageableModelClass($this->manageableModelClass);
        $seedId = (int) ($ids[0] ?? 0);
        $manageableModel = $seedId > 0
            ? $this->manageableModelClass::make($seedId, true)
            : $this->manageableModelClass::make();
        WRLAHelper::setCurrentActiveManageableModelInstance($manageableModel);
        $manageableModel->getInstanceActions();

        $result = $manageableModel->callMultiInstanceAction($actionKey, $ids, $parameters);

        // Clear the selection now the action has run.
        $this->wrlaSelectedIds = [];

        // If a file download response is returned, hand it straight back to Livewire.
        if ($result instanceof \Symfony\Component\HttpFoundation\BinaryFileResponse || $result instanceof \Symfony\Component\HttpFoundation\StreamedResponse) {
            return $result;
        }

        // If a redirect is returned, redirect to the given route.
        if ($result instanceof \Illuminate\Http\RedirectResponse) {
            return redirect($result->getTargetUrl());
        }

        // If a string message is returned, show it as a success message.
        if (is_string($result)) {
            $this->successMessage = $result;
            $this->errorMessage = null;
        }

        $this->resetPage();
        $this->dispatch('instanceActionCompleted');
    }
}

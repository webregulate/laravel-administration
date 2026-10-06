<?php

namespace WebRegulate\LaravelAdministration\Livewire\DevTools;

use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\On;
use Livewire\WithPagination;
use Throwable;
use WebRegulate\LaravelAdministration\Classes\WRLAHelper;
use WebRegulate\LaravelAdministration\Livewire\ManageableModels\ManageableModelDynamicBrowseFilters;

class DatabaseTableBrowse extends DatabasePage
{
    use WithPagination;

    public array $dynamicFilterInputs = [
        ['field' => '*', 'type' => 'Text', 'operator' => 'contains', 'value' => ''],
    ];

    public string $orderBy = '';

    public string $orderDirection = 'asc';

    public int $perPage = 20;

    public ?string $pendingDelete = null;

    public function mount(string $connection, string $table): void
    {
        $this->connection = $connection;
        $this->table = $table;
        $model = $this->model();
        $this->orderBy = $model->primaryKey ?? $model->columnNames()[0];
        $this->perPage = $this->pageSize((int) (config('wr-laravel-administration.database_browser.pagination.default')
            ?? config('wr-laravel-administration.browse.pagination.default', 20)));
        if (!config('wr-laravel-administration.database_browser.filters.enable_all_fields', true)) {
            $this->dynamicFilterInputs = [];
        }
    }

    protected function getPageTitle(): ?string
    {
        return $this->table.' - Database Browser';
    }

    public function openRecord(string $action, ?string $record = null): void
    {
        abort_unless(WRLAHelper::databaseBrowserCan($action, $this->connection, $this->table), 403);
        abort_unless(in_array($action, ['create', 'view', 'edit'], true) && $this->model()->primaryKey !== null, 422);
        abort_unless($action === 'create' ? $record === null : $record !== null, 422);
        $options = WRLAHelper::databaseBrowserUpsertOptions();
        $this->dispatch('openModal', 'wrla.dev-tools.database-record-modal', [
            'connection' => $this->connection,
            'table' => $this->table,
            'record' => $record,
            'readOnly' => $action === 'view',
        ], [
            'maxWidth' => $options->getModalSize(),
            'maxWidthClass' => $options->getModalSizeClass(),
        ]);
        $this->skipRender();
    }

    #[On('database-record-saved')]
    public function recordSaved(string $connection, string $table): void
    {
        if ($connection === $this->connection && $table === $this->table) {
            $this->resetPage();
        }
    }

    #[On('filtersUpdatedOutside')]
    public function filtersUpdatedOutside(array $dynamicFilterInputs): void
    {
        validator(['dynamicFilterInputs' => $dynamicFilterInputs], $this->filterRules())->validate();
        $this->dynamicFilterInputs = $dynamicFilterInputs;
        $this->resetPage();
    }

    public function updatedPerPage(): void
    {
        $this->perPage = $this->pageSize($this->perPage);
        $this->resetPage();
    }

    protected function pageSize(int $size): int
    {
        $options = WRLAHelper::databaseBrowserPageSizes();
        return in_array($size, $options, true) ? $size : $options[0];
    }

    public function reOrderAction(string $column): void
    {
        abort_unless(in_array($column, $this->model()->columnNames(), true), 422);
        $this->orderDirection = $this->orderBy === $column && $this->orderDirection === 'asc' ? 'desc' : 'asc';
        $this->orderBy = $column;
        $this->resetPage();
    }

    protected function browseQuery(): Builder
    {
        $model = $this->model();
        $columns = $model->columnNames();
        abort_unless(in_array($this->orderBy, $columns, true) && in_array($this->orderDirection, ['asc', 'desc'], true), 422);
        abort_if(validator(['dynamicFilterInputs' => $this->dynamicFilterInputs], $this->filterRules())->fails(), 422);
        $query = $model->query();
        foreach ($this->dynamicFilterInputs as $filter) {
            $browseFilter = ManageableModelDynamicBrowseFilters::buildBrowseFilter($filter);
            $browseFilter->apply($query, $this->table, collect($columns), (string) ($filter['value'] ?? ''));
        }

        return $query->orderBy($this->table.'.'.$this->orderBy, $this->orderDirection);
    }

    protected function filterRules(): array
    {
        $columns = $this->model()->columnNames();
        if (config('wr-laravel-administration.database_browser.filters.enable_all_fields', true)) {
            $columns[] = '*';
        }

        return [
            'dynamicFilterInputs' => ['array', 'max:'.max(1, (int) config('wr-laravel-administration.database_browser.filters.max_filters', 50))],
            'dynamicFilterInputs.*.field' => ['required', \Illuminate\Validation\Rule::in($columns)],
            'dynamicFilterInputs.*.operator' => ['required', \Illuminate\Validation\Rule::in(['contains', 'not contains', 'like', 'not like', '=', '!=', '>', '<', '>=', '<=', 'empty', 'not empty'])],
            'dynamicFilterInputs.*.value' => ['nullable', 'string', 'max:'.max(1, (int) config('wr-laravel-administration.database_browser.filters.max_value_length', 10000))],
        ];
    }

    public function deleteRecord(): void
    {
        abort_unless(WRLAHelper::databaseBrowserCan('delete', $this->connection, $this->table), 403);
        abort_unless($this->pendingDelete !== null && $this->model()->primaryKey !== null, 422);
        try {
            $record = $this->model()->query()->findOrFail($this->pendingDelete);
            $record->delete();
            $this->pendingDelete = null;
            WRLAHelper::pushAlert('success', 'Record deleted.');
        } catch (Throwable $exception) {
            report($exception);
            WRLAHelper::pushAlert('danger', 'Record could not be deleted. Check database constraints and permissions.');
        }
    }

    public function render()
    {
        $model = $this->model();
        $models = $this->browseQuery()->paginate($this->pageSize($this->perPage));
        return view(WRLAHelper::getViewPath('livewire.dev-tools.database-table-browse'), [
            'model' => $model,
            'models' => $models,
            'useModals' => WRLAHelper::databaseBrowserUpsertOptions()->isModal(),
            'pageSizes' => WRLAHelper::databaseBrowserPageSizes(),
            'enableAllFields' => (bool) config('wr-laravel-administration.database_browser.filters.enable_all_fields', true),
            'valueMaxLength' => max(1, (int) config('wr-laravel-administration.database_browser.display.value_max_length', 300)),
            'tooltipMaxLength' => max(1, (int) config('wr-laravel-administration.database_browser.display.tooltip_max_length', 1000)),
            'canCreate' => WRLAHelper::databaseBrowserCan('create', $this->connection, $this->table),
            'canEdit' => WRLAHelper::databaseBrowserCan('edit', $this->connection, $this->table),
            'canDelete' => WRLAHelper::databaseBrowserCan('delete', $this->connection, $this->table),
        ]);
    }
}
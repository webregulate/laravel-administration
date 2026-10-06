<?php

namespace WebRegulate\LaravelAdministration\Livewire\DevTools;

use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\On;
use Livewire\WithPagination;
use Throwable;
use WebRegulate\LaravelAdministration\Classes\UpsertOptions;
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
        $this->perPage = $this->pageSize((int) config('wr-laravel-administration.browse.pagination.default', 20));
    }

    protected function getPageTitle(): ?string
    {
        return $this->table.' - Database Browser';
    }

    public function openRecord(string $action, ?string $record = null): void
    {
        abort_unless(in_array($action, ['create', 'view', 'edit'], true) && $this->model()->primaryKey !== null, 422);
        abort_unless($action === 'create' ? $record === null : $record !== null, 422);
        $options = UpsertOptions::fromConfig();
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
        $options = array_map('intval', config('wr-laravel-administration.browse.pagination.perPage', [20, 30, 50, 75, 100]));
        return in_array($size, $options, true) ? $size : ($options[0] ?? 20);
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
        return [
            'dynamicFilterInputs' => ['array', 'max:50'],
            'dynamicFilterInputs.*.field' => ['required', \Illuminate\Validation\Rule::in(['*', ...$this->model()->columnNames()])],
            'dynamicFilterInputs.*.operator' => ['required', \Illuminate\Validation\Rule::in(['contains', 'not contains', 'like', 'not like', '=', '!=', '>', '<', '>=', '<=', 'empty', 'not empty'])],
            'dynamicFilterInputs.*.value' => ['nullable', 'string', 'max:10000'],
        ];
    }

    public function deleteRecord(): void
    {
        abort_unless(WRLAHelper::userIsDev(), 403);
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
            'useModals' => UpsertOptions::fromConfig()->isModal(),
        ]);
    }
}
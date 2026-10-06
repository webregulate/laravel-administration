<?php

namespace WebRegulate\LaravelAdministration\Livewire\DevTools;

use Livewire\Attributes\Locked;
use Throwable;
use WebRegulate\LaravelAdministration\Classes\ManageableModelDynamic;
use WebRegulate\LaravelAdministration\Classes\WRLAHelper;

class DatabaseRecordUpsert extends DatabasePage
{
    #[Locked]
    public ?string $recordId = null;

    #[Locked]
    public bool $readOnly = false;

    #[Locked]
    public bool $inModal = false;

    public array $values = [];

    public array $nullValues = [];

    public array $useDefaults = [];

    public function mount(string $connection, string $table, ?string $record = null, bool $inModal = false, bool $readOnly = false): void
    {
        $this->connection = $connection;
        $this->table = $table;
        $this->recordId = $record === null ? null : ManageableModelDynamic::decodeRecordKey($record);
        $this->inModal = $inModal;
        $this->readOnly = $readOnly || request()->routeIs('wrla.database.record.view');
        $model = $this->model();
        abort_unless($model->primaryKey !== null, 422);

        foreach ($model->schemaColumns as $index => $column) {
            $value = $model->model()->getRawOriginal($column['name']);
            $binary = preg_match('/blob|binary|bytea|geometry|geography/i', $column['type_name']);
            $this->values[$index] = $binary && $value !== null ? '[binary]' : $value;
            $this->nullValues[$index] = $value === null && $column['nullable'];
            $this->useDefaults[$index] = $record === null && $column['default'] !== null;
        }
    }

    protected function model(): ManageableModelDynamic
    {
        return $this->dynamicModel ??= new ManageableModelDynamic($this->connection, $this->table, $this->recordId);
    }

    protected function getPageTitle(): ?string
    {
        return ($this->readOnly ? 'View' : ($this->recordId === null ? 'Create' : 'Edit')).' '.$this->table;
    }

    protected function rendersWithinAdminLayout(): bool
    {
        return !$this->inModal;
    }

    public function editRecord(): void
    {
        abort_unless(WRLAHelper::userIsDev() && $this->inModal && $this->recordId !== null, 403);
        $this->readOnly = false;
    }

    public function save(): void
    {
        abort_unless(WRLAHelper::userIsDev() && !$this->readOnly, 403);
        $model = $this->model();
        foreach ($model->schemaColumns as $index => $column) {
            if ($column['nullable'] && ($this->nullValues[$index] ?? false)) {
                $this->values[$index] = null;
            }
        }
        $attributes = [];
        foreach ($model->schemaColumns as $index => $column) {
            $attributes["values.$index"] = $column['name'];
        }
        $this->validate($model->validationRules($this->useDefaults, $this->recordId === null), [], $attributes);
        try {
            $model->applyValues($this->values, $this->useDefaults);
            $model->model()->save();
            WRLAHelper::pushAlert('success', 'Record saved.');
            if ($this->inModal) {
                $this->recordId = (string) $model->model()->getKey();
                $this->dynamicModel = null;
                $this->useDefaults = array_fill(0, count($model->schemaColumns), false);
                foreach ($this->model()->schemaColumns as $index => $column) {
                    $value = $this->model()->model()->getRawOriginal($column['name']);
                    $binary = preg_match('/blob|binary|bytea|geometry|geography/i', $column['type_name']);
                    $this->values[$index] = $binary && $value !== null ? '[binary]' : $value;
                    $this->nullValues[$index] = $value === null && $column['nullable'];
                }
                $this->dispatch('database-record-saved', connection: $this->connection, table: $this->table);
                if (config('wr-laravel-administration.upsert.modal.return_to_browse_after_save', true)) {
                    $this->dispatch('closeModal');
                }
            } else {
                $this->redirectRoute('wrla.database.table', ['connection' => $this->connection, 'table' => $this->table]);
            }
        } catch (Throwable $exception) {
            report($exception);
            $this->addError('database', 'Record could not be saved. Check database constraints and permissions.');
        }
    }

    public function render()
    {
        $model = $this->model();
        return view(WRLAHelper::getViewPath('livewire.dev-tools.database-record-upsert'), [
            'model' => $model,
            'manageableFields' => $model->getManageableFields(),
        ]);
    }
}
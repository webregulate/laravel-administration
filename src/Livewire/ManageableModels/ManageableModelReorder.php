<?php

namespace WebRegulate\LaravelAdministration\Livewire\ManageableModels;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use WebRegulate\LaravelAdministration\Classes\ManageableModel;
use WebRegulate\LaravelAdministration\Classes\OrderColumnUpdater;
use WebRegulate\LaravelAdministration\Classes\OrderableOptions;
use WebRegulate\LaravelAdministration\Classes\WRLAHelper;
use WebRegulate\LaravelAdministration\Enums\ManageableModelPermissions;
use WebRegulate\LaravelAdministration\Enums\PageType;
use WebRegulate\LaravelAdministration\Livewire\WRLAPageComponent;

class ManageableModelReorder extends WRLAPageComponent
{
    public string $manageableModelClass;
    public bool $inModal = false;

    /**
     * Resolve and authorize the reorderable manageable model.
     */
    public function mount(string $modelUrlAlias, bool $inModal = false)
    {
        $this->inModal = $inModal;
        WRLAHelper::setCurrentPageType(PageType::BROWSE);

        $manageableModelClass = ManageableModel::getByUrlAlias($modelUrlAlias);

        if ($manageableModelClass === null) {
            WRLAHelper::pushAlert('danger', "Manageable model with url alias `$modelUrlAlias` not found.");

            return redirect()->route('wrla.dashboard');
        }

        WRLAHelper::setCurrentActiveManageableModelClass($manageableModelClass);

        if (! $manageableModelClass::getPermission(ManageableModelPermissions::BROWSE)
            || ! $manageableModelClass::getPermission(ManageableModelPermissions::EDIT)) {
            WRLAHelper::pushAlert('danger', 'You do not have permission to reorder '.$manageableModelClass::getDisplayName(true).'.');

            return redirect()->route('wrla.dashboard');
        }

        $options = $manageableModelClass::getOrderableOptions();

        if (! $options->enabled) {
            WRLAHelper::pushAlert('warning', 'Reordering is not enabled for '.$manageableModelClass::getDisplayName(true).'.');

            return redirect()->to($manageableModelClass::urlBrowse());
        }

        $this->manageableModelClass = $manageableModelClass;

        if (! Schema::connection($this->baseModel()->getConnectionName())->hasColumn($this->baseModel()->getTable(), $options->orderColumn)) {
            WRLAHelper::pushAlert('danger', "Order column `{$options->orderColumn}` does not exist.");

            return redirect()->to($manageableModelClass::urlBrowse());
        }
    }

    /**
     * Persist a complete, validated ordering of the model's records.
     */
    public function saveOrder(array $orderedIds): void
    {
        $saved = (new OrderColumnUpdater)->persist($this->baseModel(), $this->options(), $orderedIds);

        if (! $saved) {
            WRLAHelper::pushAlert('danger', 'The records changed while you were reordering. Refresh the page and try again.');

            return;
        }

        WRLAHelper::pushAlert('success', $this->manageableModelClass::getDisplayName(true).' reordered successfully.');

        if ($this->inModal) {
            $this->dispatch('wrla-browse-refresh')->to(ManageableModelBrowse::class);
            $this->dispatch('closeModal');
        }
    }

    public function render()
    {
        WRLAHelper::setCurrentPageType(PageType::BROWSE);
        WRLAHelper::setCurrentActiveManageableModelClass($this->manageableModelClass);

        $options = $this->options();
        $model = $this->baseModel();
        $keyName = $model->getKeyName();
        $columns = array_values(array_unique(array_filter([
            $keyName,
            $options->orderColumn,
            $options->labelColumn,
            ...$options->searchColumns,
        ])));

        $items = $this->manageableModelClass::getBaseModelClass()::query()
            ->select($columns)
            ->orderBy($options->orderColumn)
            ->orderBy($keyName)
            ->get()
            ->map(function (Model $record) use ($keyName, $options): array {
                $label = $options->labelColumn === null
                    ? '#'.$record->getAttribute($keyName)
                    : $record->getAttribute($options->labelColumn);
                $search = collect($options->searchColumns)
                    ->map(fn (string $column) => $record->getAttribute($column))
                    ->prepend($label)
                    ->filter(fn ($value) => is_scalar($value))
                    ->implode(' ');

                return [
                    'id' => (string) $record->getAttribute($keyName),
                    'label' => (string) $label,
                    'search' => mb_strtolower($search),
                ];
            })
            ->values()
            ->all();

        return view(WRLAHelper::getViewPath('livewire.manageable-models.reorder'), [
            'items' => $items,
            'startAt' => $options->startAt,
        ]);
    }

    protected function getPageTitle(): ?string
    {
        return 'Reorder '.$this->manageableModelClass::getDisplayName(true);
    }

    protected function options(): OrderableOptions
    {
        return $this->manageableModelClass::getOrderableOptions();
    }

    protected function baseModel(): Model
    {
        $modelClass = $this->manageableModelClass::getBaseModelClass();

        return new $modelClass;
    }
}

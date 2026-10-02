<?php

namespace WebRegulate\LaravelAdministration\Livewire\ManageableModels;

use LivewireUI\Modal\ModalComponent;
use WebRegulate\LaravelAdministration\Classes\ManageableModel;
use WebRegulate\LaravelAdministration\Classes\WRLAHelper;
use WebRegulate\LaravelAdministration\Enums\PageType;

class ManageableModelReorderModal extends ModalComponent
{
    public string $modelUrlAlias;

    public function mount(string $modelUrlAlias): void
    {
        $this->modelUrlAlias = $modelUrlAlias;
        $this->dispatch('manageable-models.reorder-modal.opened');
    }

    public function render()
    {
        $manageableModelClass = ManageableModel::getByUrlAlias($this->modelUrlAlias);

        WRLAHelper::setCurrentPageType(PageType::BROWSE);
        WRLAHelper::setCurrentActiveManageableModelClass($manageableModelClass);

        return view(WRLAHelper::getViewPath('livewire.manageable-models.reorder-modal'), [
            'title' => 'Reorder '.$manageableModelClass::getDisplayName(true),
            'icon' => $manageableModelClass::getIcon(),
        ]);
    }
}
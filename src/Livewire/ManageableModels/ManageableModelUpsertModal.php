<?php

namespace WebRegulate\LaravelAdministration\Livewire\ManageableModels;

use LivewireUI\Modal\ModalComponent;
use WebRegulate\LaravelAdministration\Classes\ManageableModel;
use WebRegulate\LaravelAdministration\Classes\WRLAHelper;
use WebRegulate\LaravelAdministration\Enums\PageType;

/**
 * Thin wire-elements modal wrapper that hosts the existing ManageableModelUpsert
 * component. The upsert component itself is unchanged aside from rendering
 * standalone (no admin layout) when embedded here.
 *
 * The modal size is supplied per-open via the openModal modalAttributes (see the
 * wrlaOpenUpsertModal JS helper), so no static modalMaxWidth() override is needed.
 */
class ManageableModelUpsertModal extends ModalComponent
{
    public string $modelUrlAlias;
    public ?int $modelId = null;

    public ?int $duplicateFrom = null;

    public function mount(string $modelUrlAlias, ?int $id = null, ?int $duplicateFrom = null): void
    {
        $this->modelUrlAlias = $modelUrlAlias;
        $this->modelId = $id;
        $this->duplicateFrom = $duplicateFrom;

        // Resolves the button-loading promise in the JS opener.
        $this->dispatch('manageable-models.upsert-modal.opened');
    }

    /**
     * Call an instance action rendered in the modal header.
     */
    public function callManageableModelAction(int $instanceId, string $actionKey, array $parameters = [])
    {
        $manageableModelClass = ManageableModel::getByUrlAlias($this->modelUrlAlias);

        WRLAHelper::setCurrentPageType(PageType::EDIT);
        WRLAHelper::setCurrentActiveManageableModelClass($manageableModelClass);

        $result = WRLAHelper::callManageableModelAction(
            $this,
            $manageableModelClass,
            $instanceId,
            $actionKey,
            $parameters
        );

        if (! ($result instanceof \Symfony\Component\HttpFoundation\BinaryFileResponse)
            && ! ($result instanceof \Symfony\Component\HttpFoundation\StreamedResponse)) {
            $this->dispatch('instanceActionCompleted');
        }

        return $result;
    }

    public function render()
    {
        $manageableModelClass = ManageableModel::getByUrlAlias($this->modelUrlAlias);
        $pageType = $this->modelId === null ? PageType::CREATE : PageType::EDIT;

        WRLAHelper::setCurrentPageType($pageType);
        WRLAHelper::setCurrentActiveManageableModelClass($manageableModelClass);

        $manageableModel = $manageableModelClass::make($this->modelId, true);
        WRLAHelper::setCurrentActiveManageableModelInstance($manageableModel);

        $title = $manageableModel->getUpsertTitle($this->modelId === null);
        $icon = $manageableModelClass::getIcon();

        return view(WRLAHelper::getViewPath('livewire.manageable-models.upsert-modal'), [
            'manageableModel' => $manageableModel,
            'title' => $title,
            'icon' => $icon,
        ]);
    }
}

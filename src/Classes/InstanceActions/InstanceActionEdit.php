<?php

namespace WebRegulate\LaravelAdministration\Classes\InstanceActions;

use WebRegulate\LaravelAdministration\Classes\InstanceAction;
use WebRegulate\LaravelAdministration\Classes\ManageableModel;
use WebRegulate\LaravelAdministration\Classes\WRLAHelper;
use WebRegulate\LaravelAdministration\Enums\ManageableModelPermissions;

class InstanceActionEdit
{
    public static function make(ManageableModel $manageableModel, ?string $modelUrlAlias = null, int|null $modelId = null): InstanceAction
    {
        $modelUrlAlias ??= $manageableModel::getUrlAlias();
        $modelId ??= $manageableModel->model()->id ?? null;

        $action = InstanceAction::make($manageableModel, 'Edit', 'fa fa-edit', 'primary')
            ->requireCondition(
                $manageableModel::getPermission(ManageableModelPermissions::EDIT)
                && WRLAHelper::isBrowsePage()
            );

        $upsertOptions = $manageableModel::getUpsertOptions();

        if ($upsertOptions->isModal()) {
            return $action->setAdditionalAttributes([
                'onclick' => "window.wrlaOpenUpsertModal(this, {
                    modelUrlAlias: '".$modelUrlAlias."',
                    id: ".($modelId ?? 'null').",
                    maxWidth: '".$upsertOptions->getModalSize()."',
                    maxWidthClass: '".$upsertOptions->getModalSizeClass()."'
                });",
            ]);
        }

        return $action->setAction(route('wrla.manageable-models.edit', [
            'modelUrlAlias' => $modelUrlAlias,
            'id' => $modelId,
        ]));
    }
}

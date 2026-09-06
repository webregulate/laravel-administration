<?php

namespace WebRegulate\LaravelAdministration\Classes\InstanceActions;

use WebRegulate\LaravelAdministration\Classes\InstanceAction;
use WebRegulate\LaravelAdministration\Classes\ManageableModel;
use WebRegulate\LaravelAdministration\Enums\ManageableModelPermissions;

class InstanceActionDuplicate
{
    public static function make(ManageableModel $manageableModel, ?string $modelUrlAlias = null, int|null $modelId = null): InstanceAction
    {
        $modelUrlAlias ??= $manageableModel::getUrlAlias();
        $modelId ??= $manageableModel->model()->id ?? null;

        $action = InstanceAction::make($manageableModel, 'Duplicate', 'fa fa-copy', 'secondary')
            ->requireCondition($manageableModel::getPermission(ManageableModelPermissions::CREATE));

        $upsertOptions = $manageableModel::getUpsertOptions();

        if ($upsertOptions->isModal()) {
            return $action->setAdditionalAttributes([
                'onclick' => "window.wrlaOpenUpsertModal(this, {
                    modelUrlAlias: '".$modelUrlAlias."',
                    duplicateFrom: ".($modelId ?? 'null').",
                    maxWidth: '".$upsertOptions->getModalSize()."',
                    maxWidthClass: '".$upsertOptions->getModalSizeClass()."'
                });",
            ]);
        }

        return $action->setAction(route('wrla.manageable-models.create', [
            'modelUrlAlias' => $modelUrlAlias,
            'wrlaDuplicateFrom' => $modelId,
        ]));
    }
}

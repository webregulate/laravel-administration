<?php

namespace WebRegulate\LaravelAdministration\Classes\InstanceActions;

use WebRegulate\LaravelAdministration\Classes\InstanceAction;
use WebRegulate\LaravelAdministration\Classes\ManageableModel;
use WebRegulate\LaravelAdministration\Classes\WRLAHelper;
use WebRegulate\LaravelAdministration\Enums\ManageableModelPermissions;

class InstanceActionRestore
{
    public static function make(ManageableModel $manageableModel): InstanceAction
    {
        $multiConfirmMessage = 'Are you sure you want to restore the selected items?';

        return InstanceAction::make($manageableModel, 'Restore', 'fa fa-undo', 'primary')
            ->requireCondition(
                $manageableModel::getPermission(ManageableModelPermissions::RESTORE)
                && $manageableModel->isModelSoftDeleted()
            )
            ->requireMultiActionCondition(function (array $ids) use ($manageableModel) {
                if (empty($ids) || ! $manageableModel::getPermission(ManageableModelPermissions::RESTORE)) {
                    return false;
                }

                $manageableModelClass = $manageableModel::class;
                $baseModelClass = $manageableModelClass::getStaticOption($manageableModelClass, 'baseModelClass');

                // Restore only applies to soft deletable models
                if (! WRLAHelper::isSoftDeletable($baseModelClass)) {
                    return false;
                }

                return $baseModelClass::withTrashed()
                    ->whereIn($baseModelClass::make()->getKeyName(), array_map('intval', $ids))
                    ->get()
                    ->contains(fn ($model) => method_exists($model, 'trashed') && $model->trashed());
            })
            ->setAdditionalAttributes([
                'wire:click' => 'restoreModel(' . $manageableModel->model()->id . ')',
            ])
            ->multiAction(function (array $ids) use ($manageableModel) {
                // Check has restore permission
                if (! $manageableModel::getPermission(ManageableModelPermissions::RESTORE)) {
                    return 'You do not have permission to restore these items.';
                }

                $restored = 0;
                $failed = 0;

                $manageableModelClass = $manageableModel::class;
                $baseModelClass = $manageableModelClass::getStaticOption($manageableModelClass, 'baseModelClass');

                // Restore only applies to soft deletable models
                if (! WRLAHelper::isSoftDeletable($baseModelClass)) {
                    return 'These items cannot be restored.';
                }

                foreach ($ids as $id) {
                    $model = $baseModelClass::withTrashed()->find((int) $id);

                    if (! $model || ! method_exists($model, 'trashed') || ! $model->trashed()) {
                        $failed++;
                        continue;
                    }

                    try {
                        $model->restore();
                        $manageableModelClass::forgetModelInstanceCache((int) $id);
                        $restored++;
                    } catch (\Throwable $e) {
                        $failed++;
                    }
                }

                $message = $restored.' '.str($manageableModel::getDisplayName())->plural($restored)->toString().' restored.';

                if ($failed > 0) {
                    $message .= ' '.$failed.' could not be restored.';
                }

                return $message;
            }, $multiConfirmMessage);
    }
}

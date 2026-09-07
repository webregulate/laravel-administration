<?php

namespace WebRegulate\LaravelAdministration\Classes\BrowseActions;

use WebRegulate\LaravelAdministration\Classes\BrowseAction;
use WebRegulate\LaravelAdministration\Classes\UpsertModal;
use WebRegulate\LaravelAdministration\Enums\ManageableModelPermissions;

class BrowseActionCreate
{
    public static function make(string $manageableModelClass): BrowseAction
    {
        $action = BrowseAction::make('Create '.$manageableModelClass::getDisplayName(), 'fa fa-plus', 'primary', 'left')
            ->requireCondition($manageableModelClass::getPermission(ManageableModelPermissions::CREATE));

        $upsertOptions = $manageableModelClass::getUpsertOptions();

        if ($upsertOptions->isModal()) {
            return $action->setAttributes(UpsertModal::make($manageableModelClass)->attributes());
        }

        return $action->setHref(route('wrla.manageable-models.create', [
            'modelUrlAlias' => $manageableModelClass::getUrlAlias(),
        ]));
    }
}

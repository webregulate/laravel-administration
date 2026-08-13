<?php

namespace WebRegulate\LaravelAdministration\Classes\BrowseActions;

use WebRegulate\LaravelAdministration\Classes\BrowseAction;
use WebRegulate\LaravelAdministration\Enums\ManageableModelPermissions;

class BrowseActionCreate
{
    public static function make(string $manageableModelClass): BrowseAction
    {
        return BrowseAction::make('Create '.$manageableModelClass::getDisplayName(), 'fa fa-plus', 'primary', 'left')
            ->requireCondition($manageableModelClass::getPermission(ManageableModelPermissions::CREATE))
            ->setHref(route('wrla.manageable-models.create', [
                'modelUrlAlias' => $manageableModelClass::getUrlAlias(),
            ]));
    }
}

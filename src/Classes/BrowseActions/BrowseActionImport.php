<?php

namespace WebRegulate\LaravelAdministration\Classes\BrowseActions;

use WebRegulate\LaravelAdministration\Classes\BrowseAction;
use WebRegulate\LaravelAdministration\Enums\ManageableModelPermissions;

class BrowseActionImport
{
    public static function make(string $manageableModelClass): BrowseAction
    {
        return BrowseAction::make('Import Data', 'fa fa-file-import', 'primary')
            ->requireCondition($manageableModelClass::getPermission(ManageableModelPermissions::CREATE))
            ->setAttributes([
                'onclick' => "window.loadLivewireModal(this, 'import-data-modal', {
                    manageableModelClass: '".str($manageableModelClass)->replace('\\', '\\\\')."'
                });",
            ]);
    }
}

<?php

namespace WebRegulate\LaravelAdministration\Classes\NavigationItems;

class NavigationItemCreate
{
    /**
     * Make a "Create" child navigation item for the given manageable model class.
     */
    public static function make(string $manageableModelClass, string $name = 'Create', string $icon = 'fa fa-plus'): NavigationItem
    {
        $navigationItem = NavigationItem::make(
            url: route('wrla.manageable-models.create', ['modelUrlAlias' => $manageableModelClass::getUrlAlias()]),
            name: $name,
            icon: $icon,
        );

        $upsertOptions = $manageableModelClass::getUpsertOptions();

        if ($upsertOptions->isModal()) {
            $navigationItem->openUpsertModal($manageableModelClass);
        }

        return $navigationItem;
    }
}

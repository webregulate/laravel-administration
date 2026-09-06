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
            $navigationItem->setAttributes([
                'onclick' => "if (!this.hasAttribute('href')) return; event.preventDefault(); window.wrlaOpenUpsertModal(this, {
                    modelUrlAlias: '".$manageableModelClass::getUrlAlias()."',
                    maxWidth: '".$upsertOptions->getModalSize()."',
                    maxWidthClass: '".$upsertOptions->getModalSizeClass()."'
                });",
            ]);
        }

        return $navigationItem;
    }
}

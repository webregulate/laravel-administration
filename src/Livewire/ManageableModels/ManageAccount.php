<?php

namespace WebRegulate\LaravelAdministration\Livewire\ManageableModels;

use WebRegulate\LaravelAdministration\Classes\WRLAHelper;
use WebRegulate\LaravelAdministration\Enums\PageType;

/**
 * Class ManageAccount
 *
 * Full-page component for the current user's "Manage Account" page. Reuses the
 * manageable model upsert component, targeting the logged-in user's record.
 */
class ManageAccount extends ManageableModelUpsert
{
    /**
     * Mount the manage account page for the current user.
     *
     * @return \Illuminate\Http\RedirectResponse|null
     */
    public function mount(string $modelUrlAlias = '', ?int $id = null)
    {
        WRLAHelper::setCurrentPageType(PageType::EDIT);

        $userManageableModelClass = WRLAHelper::getUserManageableModelClass();
        $manageableModel = WRLAHelper::getCurrentUserManageableModel();

        return $this->initialise($userManageableModelClass, PageType::EDIT, $manageableModel->model()->id, 'Manage Account');
    }
}

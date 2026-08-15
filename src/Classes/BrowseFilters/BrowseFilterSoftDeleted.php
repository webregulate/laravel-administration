<?php

namespace WebRegulate\LaravelAdministration\Classes\BrowseFilters;

use Illuminate\Database\Eloquent\Builder;
use WebRegulate\LaravelAdministration\Classes\BrowseFilter;
use WebRegulate\LaravelAdministration\Classes\WRLAHelper;
use WebRegulate\LaravelAdministration\Classes\ManageableFields\Select;

class BrowseFilterSoftDeleted extends BrowseFilterBase
{
    /**
     * Build the default soft-deleted status filter (Active only / Soft deleted only / All).
     * Returns null when the underlying model does not use soft deletes, so it is always safe to include.
     *
     * @param  string  $manageableModelClass  The manageable model class this filter belongs to.
     * @param  string  $alias  The filter key/alias.
     * @param  ?string  $label  The filter label.
     * @param  ?string  $icon  The filter icon.
     */
    public static function make(
        string $manageableModelClass,
        string $alias = 'softDeletedFilter',
        ?string $label = 'Status',
        ?string $icon = 'fas fa-heartbeat text-slate-400 !mr-1'
    ): ?BrowseFilter {
        if (! WRLAHelper::isSoftDeletable($manageableModelClass::getBaseModelClass())) {
            return null;
        }

        return Select::makeBrowseFilter($alias)
            ->setLabel($label, $icon)
            ->setItems([
                'not_trashed' => 'Active only',
                'trashed' => 'Soft deleted only',
                'all' => 'All',
            ])
            ->setOption('containerClass', 'w-1/6')
            ->validation('required|in:all,trashed,not_trashed')
            ->browseFilterApply(function (Builder $query, $table, $columns, $value) {
                if ($value === 'not_trashed') {
                    return $query;
                } elseif ($value === 'trashed') {
                    return $query->onlyTrashed();
                } elseif ($value == 'all') {
                    return $query->withTrashed();
                }

                return $query;
            });
    }
}

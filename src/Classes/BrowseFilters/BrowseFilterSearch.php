<?php

namespace WebRegulate\LaravelAdministration\Classes\BrowseFilters;

use Illuminate\Database\Eloquent\Builder;
use WebRegulate\LaravelAdministration\Classes\BrowseFilter;
use WebRegulate\LaravelAdministration\Classes\WRLAHelper;
use WebRegulate\LaravelAdministration\Classes\ManageableFields\Text;

class BrowseFilterSearch extends BrowseFilterBase
{
    /**
     * Build the default search filter. Searches (case-insensitive LIKE) across every column
     * returned by getBrowseColumns(), handling relationship (dot-notation) columns automatically.
     *
     * @param  string  $manageableModelClass  The manageable model class this filter belongs to.
     * @param  ?array  $columns  Columns to search over (supports relationships via dot-notation).
     *                           Accepts a list (['name', 'user.email']) or a column => label map.
     *                           When null, defaults to the model's getBrowseColumns().
     * @param  string  $alias  The filter key/alias.
     * @param  ?string  $label  The filter label.
     * @param  ?string  $icon  The filter icon.
     */
    public static function make(
        string $manageableModelClass,
        ?array $columns = null,
        string $alias = 'searchFilter',
        ?string $label = 'Search',
        ?string $icon = 'fas fa-search text-slate-400'
    ): BrowseFilter {
        // Normalise provided columns into a column => label map (list values become their own key)
        $searchColumns = $columns === null
            ? null
            : collect($columns)->mapWithKeys(fn ($value, $key) => is_int($key) ? [$value => $value] : [$key => $value]);

        return Text::makeBrowseFilter($alias, $label, $icon)
            ->setAttributes([
                'autofocus' => true,
                'placeholder' => 'Search filter...',
                'autocomplete' => 'off',
            ])
            ->setOptions([
                'mergeColumns' => [],
            ])
            ->browseFilterApply(function (Builder $outerQuery, $table, $columns, $value) use ($manageableModelClass, $searchColumns) {
                // Use the explicitly provided columns if set, otherwise fall back to the browse columns
                $columns = $searchColumns ?? $columns;

                return $outerQuery->where(function ($query) use ($table, $columns, $value, $manageableModelClass) {
                    $whereIndex = 0;

                    // Get all actual table columns (this is because we may have custom added columns from a preQuery)
                    $actualTableColumns = WRLAHelper::getTableColumns($table, (new ($manageableModelClass::getBaseModelClass()))->getConnectionName());

                    foreach ($columns as $column => $label) {
                        // If column is int or begins with !, skip
                        if (is_int($column) || str_starts_with($column, '!')) {
                            continue;
                        }

                        // If column is relationship, then modify the column to be the related column
                        if ((WRLAHelper::isBrowseColumnRelationship($column))) {
                            $relationshipParts = WRLAHelper::parseBrowseColumnRelationship($column);

                            $baseModelClass = $manageableModelClass::getBaseModelClass();
                            $relationship = (new $baseModelClass)->{$relationshipParts[0]}();
                            if ($relationship?->getRelated() == null) {
                                continue;
                            }
                            $relationshipTableName = $relationship->getRelated()->getTable();
                            $foreignColumn = $relationship->getForeignKeyName();

                            // If relationship connection is not empty, generate the SQL to inject it
                            if (! empty($relationshipConnection)) {
                                $relationshipConnection = "`$relationshipConnection`.";
                            }

                            $whereIndex++;

                            // Safely escape value
                            $query->orWhereRelation($relationshipParts[0], "{$relationshipTableName}.{$relationshipParts[1]}", 'like', "%{$value}%");
                        }
                        // If table has this column, prepend table name
                        elseif (in_array($column, $actualTableColumns)) {
                            // Force case-insensitive search using LOWER()
                            $column = "$table.$column";
                            $query->orWhereRaw("LOWER($column) LIKE ?", ['%' . strtolower($value) . '%']);
                        }
                        // Otherwise just use column name directly
                        else {
                            $query->orHaving($column, 'like', "%{$value}%");
                        }
                    }
                });
            });
    }
}

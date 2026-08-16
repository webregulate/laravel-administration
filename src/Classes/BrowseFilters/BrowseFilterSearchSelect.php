<?php

namespace WebRegulate\LaravelAdministration\Classes\BrowseFilters;

use Illuminate\Database\Eloquent\Builder;
use WebRegulate\LaravelAdministration\Classes\BrowseFilter;
use WebRegulate\LaravelAdministration\Classes\ManageableFields\SearchSelect;

class BrowseFilterSearchSelect extends BrowseFilterBase
{
    /**
     * Build a searchable single-select browse filter (live server-side search).
     *
     * The searchable source may be any of:
     *  - A Model class-string (searched on $searchColumn)
     *  - A ManageableModel class-string (its base model searched on $searchColumn)
     *  - A callback fn(string $term): Builder returning a custom query
     *
     * The selected value (the model's key) is matched against $filterColumn on the
     * browsed table by default. Pass $apply to override the query behaviour entirely.
     *
     * @param  string  $alias  Filter key/alias.
     * @param  string|callable  $source  Model::class, ManageableModel::class, or fn(string $term): Builder.
     * @param  string|callable|null  $itemLabel  Display column, or fn(Model $model): string. Defaults to $searchColumn.
     * @param  ?string  $searchColumn  Column searched when $source is a model class.
     * @param  ?string  $filterColumn  Browsed-table column matched against the selected value. Defaults to $alias.
     * @param  ?string  $label  Filter label.
     * @param  ?string  $icon  Filter icon.
     * @param  array|bool  $prependAll  Prepend an "All" (clear) option. true => ['all' => 'All'], array => custom [value => label], false => none.
     * @param  ?int  $searchLimit  Maximum results returned per search.
     * @param  ?callable  $apply  fn(Builder $query, string $table, Collection $columns, mixed $value): Builder override.
     * @param  string  $containerClass  Wrapper container class.
     */
    public static function make(
        string $alias,
        string|callable $source,
        string|callable|null $itemLabel = null,
        ?string $searchColumn = null,
        ?string $filterColumn = null,
        ?string $label = null,
        ?string $icon = null,
        array|bool $prependAll = true,
        ?int $searchLimit = null,
        ?callable $apply = null,
        string $containerClass = 'flex-1',
    ): BrowseFilter {
        $filterColumn ??= $alias;
        [$allValue, $allLabel] = static::resolvePrependAll($prependAll);

        $field = SearchSelect::makeBrowseFilter($alias, $label, $icon, $containerClass);

        // Configure the search source (auto-sets item label to $searchColumn for model sources)
        if (is_string($source)) {
            $field->searchQuery($source, $searchColumn);
        } else {
            $field->searchQuery($source);
        }

        // Explicit item label overrides the column auto-derived above
        if ($itemLabel !== null) {
            $field->itemLabel($itemLabel);
        }

        if ($searchLimit !== null) {
            $field->searchLimit($searchLimit);
        }

        // Prepend an "All"/clear option and treat it as the empty value
        if ($allValue !== null) {
            $field->prependItem($allValue, $allLabel);
            $field->setEmptyValue($allValue);
        }

        return $field->browseFilterApply(
            $apply ?? function (Builder $query, $table, $columns, $value) use ($filterColumn, $allValue) {
                if ($value === null || $value === '' || ($allValue !== null && (string) $value === (string) $allValue)) {
                    return $query;
                }

                return $query->where($filterColumn, $value);
            }
        );
    }

    /**
     * Normalise the $prependAll argument into [allValue, allLabel|null].
     *
     * @return array{0: mixed, 1: ?string}
     */
    protected static function resolvePrependAll(array|bool $prependAll): array
    {
        if ($prependAll === false) {
            return [null, null];
        }

        if ($prependAll === true) {
            return ['all', 'All'];
        }

        // Custom [value => label] map — the first entry acts as the "clear" option.
        return [array_key_first($prependAll), reset($prependAll)];
    }
}

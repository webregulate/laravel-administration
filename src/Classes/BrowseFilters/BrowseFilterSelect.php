<?php

namespace WebRegulate\LaravelAdministration\Classes\BrowseFilters;

use Illuminate\Support\Collection;
use Illuminate\Database\Eloquent\Builder;
use WebRegulate\LaravelAdministration\Classes\BrowseFilter;
use WebRegulate\LaravelAdministration\Classes\ManageableFields\Select;

class BrowseFilterSelect extends BrowseFilterBase
{
    /**
     * Build a standard <select> dropdown browse filter.
     *
     * The option list may come from any of:
     *  - A plain array / Collection in [value => label] format
     *  - A Model class-string (options built from its table)
     *  - A ManageableModel class-string (options built from its base model)
     *
     * By default the selected value is matched (equals) against $filterColumn on the
     * browsed table, and the special "all" option clears the filter. Pass $apply to
     * override the query behaviour entirely.
     *
     * @param  string  $alias  Filter key/alias.
     * @param  array|Collection|string  $source  Items ([value => label]), Model::class, or ManageableModel::class.
     * @param  ?string  $displayColumn  Display column when $source is a model class (required for model sources).
     * @param  ?string  $filterColumn  Browsed-table column matched against the value. Defaults to $alias.
     * @param  ?string  $label  Filter label.
     * @param  ?string  $icon  Filter icon.
     * @param  array|bool  $prependAll  Prepend an "All" (clear) option. true => ['all' => 'All'], array => custom [value => label], false => none.
     * @param  string  $operator  Comparison operator used by the default filter query.
     * @param  ?callable  $sourceQuery  fn(Builder $query): Builder to shape the dropdown's option-source query (model sources only).
     * @param  ?callable  $filterQuery  fn(Builder $query, string $table, Collection $columns, mixed $value): Builder that applies the selected value to the browsed query (overrides the default).
     * @param  string  $containerClass  Wrapper container class.
     */
    public static function make(
        string $alias,
        array|Collection|string $source,
        ?string $displayColumn = null,
        ?string $filterColumn = null,
        ?string $label = null,
        ?string $icon = null,
        array|bool $prependAll = true,
        string $operator = '=',
        ?callable $sourceQuery = null,
        ?callable $filterQuery = null,
        string $containerClass = 'flex-1',
        mixed $default = null,
    ): BrowseFilter {
        $filterColumn ??= $alias;
        [$allValue, $prependItems] = static::resolvePrependAll($prependAll);

        $field = Select::makeBrowseFilter($alias, $label, $icon, $containerClass);

        // Resolve the option list from a model class or an inline array/collection
        if (is_string($source)) {
            if ($displayColumn === null) {
                throw new \InvalidArgumentException(
                    "BrowseFilterSelect [$alias]: a \$displayColumn is required when \$source is a model class."
                );
            }

            $field->setItemsFromModel(
                $source,
                $displayColumn,
                $sourceQuery,
                $prependItems === null ? null : fn ($items) => $prependItems + $items,
            );
        } else {
            $items = $source instanceof Collection ? $source->toArray() : $source;

            if ($prependItems !== null) {
                $items = $prependItems + $items;
            }

            $field->setItems($items);
        }

        if ($default !== null) {
            $field->default($default);
        }

        return $field->browseFilterApply(
            $filterQuery ?? function (Builder $query, $table, $columns, $value) use ($filterColumn, $allValue, $operator) {
                if ($value === null || $value === '' || ($allValue !== null && (string) $value === (string) $allValue)) {
                    return $query;
                }

                return $query->where($filterColumn, $operator, $value);
            }
        );
    }

    /**
     * Normalise the $prependAll argument into [allValue, prependItemsMap|null].
     *
     * @return array{0: mixed, 1: ?array}
     */
    protected static function resolvePrependAll(array|bool $prependAll): array
    {
        if ($prependAll === false) {
            return [null, null];
        }

        if ($prependAll === true) {
            return ['all', ['all' => 'All']];
        }

        // Custom [value => label] map — the first entry acts as the "clear" option.
        return [array_key_first($prependAll), $prependAll];
    }
}

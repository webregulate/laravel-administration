<?php

namespace WebRegulate\LaravelAdministration\Classes\BrowseFilters;

use Illuminate\Database\Eloquent\Builder;
use WebRegulate\LaravelAdministration\Classes\BrowseFilter;
use WebRegulate\LaravelAdministration\Classes\ManageableFields\Text;

class BrowseFilterText extends BrowseFilterBase
{
    /**
     * Build a simple free-text browse filter.
     *
     * The filter can be applied against:
     *  - A single string column ('name')
     *  - An array of columns, OR'd together, which may include relationships via
     *    dot-notation ('name', 'user.email', 'user.company.name')
     *  - A callable which overrides the query behaviour entirely
     *
     * @param  string  $alias  Filter key/alias.
     * @param  string|array|callable|null  $columns  Column, list of columns (dot-notation for relationships), or an apply override of the form fn(Builder $query, string $table, Collection $columns, mixed $value): Builder. Optional when $filterQuery is provided.
     * @param  ?string  $label  Filter label.
     * @param  ?string  $icon  Filter icon.
     * @param  string  $operator  Comparison operator used to match each column. 'like' wraps the value in wildcards.
     * @param  string  $containerClass  Wrapper container class.
     * @param  mixed  $default  Default filter value.
     * @param  ?callable  $filterQuery  fn(Builder $query, string $table, Collection $columns, mixed $value): Builder that applies the value to the browsed query (overrides the default column matching).
     */
    public static function make(
        string $alias,
        string|array|callable|null $columns = null,
        ?string $label = null,
        ?string $icon = null,
        string $operator = 'like',
        string $containerClass = 'flex-1',
        mixed $default = null,
        ?string $placeholder = null,
        ?callable $filterQuery = null,
    ): BrowseFilter {
        // A callable passed as $columns is treated as the apply override.
        if (is_callable($columns)) {
            $filterQuery = $columns;
            $columns = null;
        }

        if ($columns === null && $filterQuery === null) {
            throw new \InvalidArgumentException(
                "BrowseFilterText [$alias]: \$columns is required when no \$filterQuery is provided."
            );
        }

        $field = Text::makeBrowseFilter($alias, $label, $icon, $containerClass)
            ->setAttributes([
                'placeholder' => $placeholder ?? 'Filter...',
                'autocomplete' => 'off',
            ]);

        if ($default !== null) {
            $field->default($default);
        }

        // A custom filter query overrides the default column matching entirely.
        if ($filterQuery !== null) {
            return $field->browseFilterApply($filterQuery);
        }

        $filterColumns = (array) $columns;

        return $field->browseFilterApply(function (Builder $query, $table, $ignoredColumns, $value) use ($filterColumns, $operator) {
            if ($value === null || $value === '') {
                return $query;
            }

            $matchValue = strtolower($operator) === 'like' ? "%{$value}%" : $value;

            return $query->where(function ($query) use ($filterColumns, $table, $operator, $matchValue) {
                foreach ($filterColumns as $column) {
                    // Relationship column (dot-notation) — e.g. 'user.email' or 'user.company.name'.
                    if (str_contains($column, '.')) {
                        $parts = explode('.', $column);
                        $relatedColumn = array_pop($parts);
                        $relation = implode('.', $parts);

                        $query->orWhereRelation($relation, $relatedColumn, $operator, $matchValue);

                        continue;
                    }

                    $query->orWhere("$table.$column", $operator, $matchValue);
                }
            });
        });
    }
}

<?php

namespace WebRegulate\LaravelAdministration\Classes\BrowseFilters;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use WebRegulate\LaravelAdministration\Classes\ManageableModel;

/**
 * Base class for the built-in browse filter factories (Search, SoftDeleted, ...).
 *
 * Intentionally minimal — each concrete filter exposes a static make() that returns
 * a fully configured BrowseFilter instance, mirroring the BrowseAction pattern.
 */
abstract class BrowseFilterBase
{
    /**
     * Derive a foreign-key column name (e.g. "producer_id") from a Model or
     * ManageableModel class-string. Returns null when $source is not a resolvable class.
     */
    protected static function deriveForeignKeyColumn(mixed $source): ?string
    {
        if (!is_string($source)) {
            return null;
        }

        if (is_subclass_of($source, ManageableModel::class)) {
            $source = $source::getBaseModelClass();
        }

        if (!is_string($source) || !is_subclass_of($source, Model::class)) {
            return null;
        }

        return Str::snake(class_basename($source)) . '_id';
    }
}

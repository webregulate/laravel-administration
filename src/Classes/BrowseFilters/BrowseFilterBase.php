<?php

namespace WebRegulate\LaravelAdministration\Classes\BrowseFilters;

/**
 * Base class for the built-in browse filter factories (Search, SoftDeleted, ...).
 *
 * Intentionally minimal — each concrete filter exposes a static make() that returns
 * a fully configured BrowseFilter instance, mirroring the BrowseAction pattern.
 */
abstract class BrowseFilterBase
{
    //
}

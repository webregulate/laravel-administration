# WebRegulate Laravel Administration
#### See documentation here:
[https://webregulate.github.io/laravel-administration](https://webregulate.github.io/laravel-administration)

## Developer Database Browser

The Developer Tools modal includes a Database Browser link only when
`WRLAHelper::userIsDev()` is true. The pages also enforce this check on every
Livewire request. The browser lists reachable configured connections, searches
tables, and reuses WRLA's dynamic browse filters.

The database table browser starts with one blank **All fields / Contains** filter.
The shared dynamic filter component keeps this choice disabled by default; pass
`enableAllFields => true` to expose it and `defaultDynamicFilters` to supply initial
filters (use `field => '*'` for all fields). Positive searches match any column;
negative searches require every column to pass. Commas combine terms with AND
and pipes combine alternatives with OR, including terms in different columns.

`ManageableModelDynamic` infers browse columns, fields, and validation from the
selected connection and table. Single-column primary keys support record view,
create, edit, and confirmed permanent deletion, including string keys. Keyless
and composite-primary-key tables are browse-only. Generated and binary columns
are read-only; nullable values and database defaults have separate controls.

Record create, view, and edit actions follow `wr-laravel-administration.upsert.mode`.
Modal mode uses the configured upsert modal size and close-after-save setting,
and refreshes the table after saving. Full-page routes remain available in page
mode and for opening record links in a new tab.

Operations are raw database operations: they do not run application model hooks,
soft deletes, or business validation. Database constraints still apply.

The focused regression suite is `tests/DatabaseBrowserTest.php`. Set
`WRLA_TEST_APP_PATH` to a Laravel application with WRLA dependencies installed and
run its PHPUnit executable against this file using the application's Composer
autoload as the bootstrap. The suite loads this package's source and replaces
database, cache, and session configuration before providers boot; all record
writes use disposable in-memory SQLite databases.


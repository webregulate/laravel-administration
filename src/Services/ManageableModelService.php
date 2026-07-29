<?php

namespace WebRegulate\LaravelAdministration\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use WebRegulate\LaravelAdministration\Classes\ManageableFields\Text;

/**
 * Service that powers the wrla:manageable-model command. Handles stub variable
 * generation, migration parsing, database schema introspection and the automatic
 * generation of manageable fields and browse columns from column metadata.
 */
class ManageableModelService
{
    /** Columns that should never be turned into manageable fields or browse columns. */
    public const IGNORED_COLUMNS = ['id', 'created_at', 'updated_at', 'deleted_at'];

    /** Fully qualified class names of the field / browse column classes, keyed by short name. */
    protected const CLASS_MAP = [
        'Text' => 'WebRegulate\\LaravelAdministration\\Classes\\ManageableFields\\Text',
        'TextArea' => 'WebRegulate\\LaravelAdministration\\Classes\\ManageableFields\\TextArea',
        'Select' => 'WebRegulate\\LaravelAdministration\\Classes\\ManageableFields\\Select',
        'SearchSelect' => 'WebRegulate\\LaravelAdministration\\Classes\\ManageableFields\\SearchSelect',
        'Date' => 'WebRegulate\\LaravelAdministration\\Classes\\ManageableFields\\Date',
        'Json' => 'WebRegulate\\LaravelAdministration\\Classes\\ManageableFields\\Json',
        'BrowseColumn' => 'WebRegulate\\LaravelAdministration\\Classes\\BrowseColumns\\BrowseColumn',
        'BrowseColumnCheck' => 'WebRegulate\\LaravelAdministration\\Classes\\BrowseColumns\\BrowseColumnCheck',
        'BrowseColumnDate' => 'WebRegulate\\LaravelAdministration\\Classes\\BrowseColumns\\BrowseColumnDate',
    ];

    /** Indentation used for a top level field / column entry (3 levels). */
    protected const INDENT = '            ';

    /** Indentation used for a chained method call (4 levels). */
    protected const INDENT_CHAIN = '                ';

    /**
     * Get the stub variables used to generate a manageable model file.
     *
     * @param  array  $overrides  Additional / overriding stub variables (eg. generated fields).
     * @return array Map of stub placeholder => replacement value.
     */
    public static function getStubVariables(string $model, string $icon, array $overrides = []): array
    {
        $modelWithPath = $model;

        // If the model contains a backslash, it means it's namespaced
        if (str($model)->contains('\\')) {
            $namespace = 'App\\WRLA\\'.str($model)->beforeLast('\\')->__toString();
            $model = str($modelWithPath)->afterLast('\\')->__toString();
        } else {
            $namespace = 'App\\WRLA';
        }

        return array_merge([
            '{{ $NAMESPACE }}' => $namespace,
            '{{ $MODEL }}' => $model,
            '{{ $MODEL_WITH_PATH }}' => $modelWithPath,
            '{{ $URL_ALIAS }}' => str($model)->kebab()->lower()->__toString(),
            '{{ $DISPLAY_NAME }}' => str($model)->headline()->__toString(),
            '{{ $ICON }}' => $icon,
            '{{ $USE_STATEMENTS }}' => static::buildUseStatements(['BrowseColumn', 'Text']),
            '{{ $BROWSE_COLUMNS }}' => static::defaultBrowseColumnsCode(),
            '{{ $MANAGEABLE_FIELDS }}' => static::defaultManageableFieldsCode(),
        ], $overrides);
    }

    /**
     * Get the base table name (plural, snake case) for a given studly model name.
     */
    public static function getTableName(string $model): string
    {
        $modelBaseName = str($model)->afterLast('\\')->__toString();

        return str($modelBaseName)->plural()->snake()->lower()->__toString();
    }

    /**
     * Find all migration file paths that create or modify the given table, ordered chronologically.
     *
     * @return array<int, string> Absolute migration file paths.
     */
    public static function findMigrationPathsForTable(string $table): array
    {
        $migrationsPath = database_path('migrations');

        if (! File::isDirectory($migrationsPath)) {
            return [];
        }

        $matches = [];

        foreach (File::files($migrationsPath) as $file) {
            $contents = File::get($file->getPathname());

            if (
                str_contains($contents, "Schema::create('$table'")
                || str_contains($contents, "Schema::table('$table'")
                || str_contains($contents, "Schema::create(\"$table\"")
                || str_contains($contents, "Schema::table(\"$table\"")
            ) {
                $matches[] = $file->getPathname();
            }
        }

        // File::files already returns them sorted by filename (chronological by timestamp prefix)
        sort($matches);

        return $matches;
    }

    /**
     * Parse and merge column metadata from all migrations affecting the given table.
     *
     * @param  array<int, string>  $migrationPaths  Absolute migration file paths, chronological order.
     * @return array<string, array> Column name => normalized column metadata.
     */
    public static function mergeMigrationColumns(array $migrationPaths, string $table): array
    {
        $columns = [];

        foreach ($migrationPaths as $path) {
            $statements = static::extractTableStatements(File::get($path), $table);

            foreach ($statements as $statement) {
                static::applyStatement($statement, $columns);
            }
        }

        return $columns;
    }

    /**
     * Build normalized column metadata for an existing database table via the schema.
     *
     * @return array<string, array> Column name => normalized column metadata.
     */
    public static function getTableColumnMeta(string $table, ?string $connection = null): array
    {
        $schema = $connection ? Schema::connection($connection) : Schema::getFacadeRoot();

        $foreignKeys = [];
        try {
            foreach ($schema->getForeignKeys($table) as $foreignKey) {
                $localColumn = $foreignKey['columns'][0] ?? null;
                if ($localColumn !== null) {
                    $foreignKeys[$localColumn] = $foreignKey['foreign_table'] ?? null;
                }
            }
        } catch (\Throwable) {
            // Foreign key introspection unsupported on this driver, fall back to naming conventions.
        }

        $columns = [];

        foreach ($schema->getColumns($table) as $column) {
            $name = $column['name'] ?? null;
            if ($name === null) {
                continue;
            }

            $rawType = strtolower((string) ($column['type_name'] ?? $column['type'] ?? ''));
            $fullType = strtolower((string) ($column['type'] ?? ''));
            $normalizedType = static::normalizeColumnType($fullType !== '' ? $fullType : $rawType);
            $isForeignKey = array_key_exists($name, $foreignKeys) || str_ends_with($name, '_id');

            // Parse enum options from the full type definition (eg. enum('draft','published')).
            $enumValues = null;
            if ($normalizedType === 'enum' && preg_match_all('/\'([^\']*)\'/', (string) ($column['type'] ?? ''), $enumMatches)) {
                $enumValues = $enumMatches[1];
            }

            $columns[$name] = [
                'name' => $name,
                'type' => $normalizedType,
                'length' => static::extractLengthFromType($fullType),
                'nullable' => (bool) ($column['nullable'] ?? false),
                'unsigned' => str_contains($rawType, 'unsigned'),
                'enumValues' => $enumValues,
                'isForeignKey' => $isForeignKey,
                'relatedTable' => $foreignKeys[$name] ?? null,
                'raw' => $rawType,
            ];
        }

        return $columns;
    }

    /**
     * Generate manageable fields, browse columns, required imports and any warnings from column metadata.
     *
     * @param  array<string, array>  $columns  Normalized column metadata.
     * @return array{fields: string, browseColumns: string, useStatements: string, warnings: array<int, string>}
     */
    public static function generateFromColumns(array $columns): array
    {
        $fieldEntries = [];
        $browseLines = [];
        $usedClasses = [];
        $warnings = [];

        foreach ($columns as $column) {
            if (in_array($column['name'], static::IGNORED_COLUMNS, true)) {
                continue;
            }

            $field = static::mapColumnToManageableField($column);
            $browse = static::mapColumnToBrowseColumn($column);

            // Prefix each field with a labelled comment derived from the field name.
            $label = static::fieldLabel($column['name']);
            $fieldEntries[] = self::INDENT.'// '.$label."\n".self::INDENT.$field['code'];
            $browseLines[] = $browse['code'];

            $usedClasses = array_merge($usedClasses, $field['classes'], $browse['classes']);
            $warnings = array_merge($warnings, $field['warnings'], $browse['warnings']);
        }

        $usedClasses = array_values(array_unique($usedClasses));

        return [
            'fields' => implode("\n\n", $fieldEntries),
            'browseColumns' => static::indentBlock($browseLines),
            'useStatements' => static::buildUseStatements($usedClasses),
            'warnings' => $warnings,
        ];
    }

    /**
     * Build the labelled comment text for a field using the ManageableField helper.
     */
    protected static function fieldLabel(string $fieldName): string
    {
        return Text::getLabelFromFieldName($fieldName);
    }

    /**
     * Map a single column's metadata to a manageable field definition.
     *
     * @return array{code: string, classes: array<int, string>, warnings: array<int, string>}
     */
    protected static function mapColumnToManageableField(array $column): array
    {
        $name = $column['name'];

        // Relationship columns become a SearchSelect that queries the related model.
        if ($column['isForeignKey'] || str_ends_with($name, '_id')) {
            return static::buildRelationshipField($column);
        }

        $required = $column['nullable'] ? '' : "\n".self::INDENT_CHAIN.'->required()';

        // String columns that allow more than 255 characters are better suited to a TextArea.
        if ($column['type'] === 'string' && ($column['length'] ?? 0) > 255) {
            return [
                'code' => "TextArea::make(\$this, '$name')$required\n"
                    .self::INDENT_CHAIN."->validation('".static::rules($column, 'string', $column['length'])."'),",
                'classes' => ['TextArea'],
                'warnings' => [],
            ];
        }

        return match ($column['type']) {
            'text' => [
                'code' => "TextArea::make(\$this, '$name')$required\n"
                    .self::INDENT_CHAIN."->validation('".static::rules($column, 'string')."'),",
                'classes' => ['TextArea'],
                'warnings' => [],
            ],
            'integer' => [
                'code' => "Text::make(\$this, '$name')$required\n"
                    .self::INDENT_CHAIN."->validation('".static::rules($column, 'integer')."'),",
                'classes' => ['Text'],
                'warnings' => [],
            ],
            'decimal' => [
                'code' => "Text::make(\$this, '$name')$required\n"
                    .self::INDENT_CHAIN."->validation('".static::rules($column, 'numeric')."'),",
                'classes' => ['Text'],
                'warnings' => [],
            ],
            'boolean' => [
                'code' => "Select::make(\$this, '$name', ['0' => 'No', '1' => 'Yes'])$required,",
                'classes' => ['Select'],
                'warnings' => [],
            ],
            'date' => [
                'code' => "Date::make(\$this, '$name', Date::TYPE_DATE)$required,",
                'classes' => ['Date'],
                'warnings' => [],
            ],
            'datetime' => [
                'code' => "Date::make(\$this, '$name', Date::TYPE_DATETIME)$required,",
                'classes' => ['Date'],
                'warnings' => [],
            ],
            'time' => [
                'code' => "Date::make(\$this, '$name', Date::TYPE_TIME)$required,",
                'classes' => ['Date'],
                'warnings' => [],
            ],
            'json' => [
                'code' => "Json::make(\$this, '$name'),",
                'classes' => ['Json'],
                'warnings' => [],
            ],
            'enum' => static::buildEnumField($column, $required),
            'string' => [
                'code' => "Text::make(\$this, '$name')$required\n"
                    .self::INDENT_CHAIN."->validation('".static::rules($column, 'string', $column['length'] ?? 255)."'),",
                'classes' => ['Text'],
                'warnings' => [],
            ],
            default => [
                'code' => "Text::make(\$this, '$name')$required\n"
                    .self::INDENT_CHAIN."->validation('".static::rules($column, 'string', $column['length'] ?? 255)."'),",
                'classes' => ['Text'],
                'warnings' => ["Column '$name' has an unrecognized type ('{$column['raw']}') - defaulted to a Text field. Please review."],
            ],
        };
    }

    /**
     * Map a single column's metadata to a browse column definition.
     *
     * @return array{code: string, classes: array<int, string>, warnings: array<int, string>}
     */
    protected static function mapColumnToBrowseColumn(array $column): array
    {
        $name = $column['name'];
        $label = str($name)->headline()->__toString();

        // Relationship columns display via dot notation against the related model.
        if ($column['isForeignKey'] || str_ends_with($name, '_id')) {
            $relationship = static::resolveRelationship($column);

            if ($relationship !== false) {
                $relationLabel = str($relationship['relation'])->headline()->__toString();

                return [
                    'code' => "'{$relationship['relation']}.{$relationship['label']}' => BrowseColumn::make('$relationLabel'),",
                    'classes' => ['BrowseColumn'],
                    'warnings' => [],
                ];
            }

            return [
                'code' => "'$name' => BrowseColumn::make('$label'),",
                'classes' => ['BrowseColumn'],
                'warnings' => [],
            ];
        }

        return match ($column['type']) {
            'boolean' => [
                'code' => "'$name' => BrowseColumnCheck::make('$label'),",
                'classes' => ['BrowseColumnCheck'],
                'warnings' => [],
            ],
            'date', 'datetime' => [
                'code' => "'$name' => BrowseColumnDate::make('$label'),",
                'classes' => ['BrowseColumnDate'],
                'warnings' => [],
            ],
            default => [
                'code' => "'$name' => BrowseColumn::make('$label'),",
                'classes' => ['BrowseColumn'],
                'warnings' => [],
            ],
        };
    }

    /**
     * Build a SearchSelect relationship field, warning if the related model cannot be resolved.
     *
     * @return array{code: string, classes: array<int, string>, warnings: array<int, string>}
     */
    protected static function buildRelationshipField(array $column): array
    {
        $name = $column['name'];
        $relationship = static::resolveRelationship($column);

        // Could not resolve the related model - leave a manual TODO and a loud warning.
        if ($relationship === false) {
            $required = $column['nullable'] ? '' : "\n".self::INDENT_CHAIN.'->required()';

            return [
                'code' => "// TODO: Could not resolve the related model for '$name'. Please add the relationship field manually.\n"
                    .self::INDENT."Text::make(\$this, '$name')$required\n"
                    .self::INDENT_CHAIN."->validation('".static::rules($column, 'integer')."'),",
                'classes' => ['Text'],
                'warnings' => ["Could not resolve the related model for foreign key '$name'. A placeholder Text field and TODO were added - please add the relationship manually."],
            ];
        }

        $model = '\\'.$relationship['model'];
        $label = $relationship['label'];
        $table = $relationship['table'];

        $code = "SearchSelect::make(\$this, '$name',\n";
        $code .= self::INDENT_CHAIN."searchQuery: fn(string \$term) => {$model}::query()\n";
        $code .= self::INDENT_CHAIN."    ->when(!empty(\$term), fn(\$q) => \$q->where('$label', 'like', \"%\$term%\")),\n";
        $code .= self::INDENT_CHAIN."itemLabel: '$label',\n";
        $code .= self::INDENT.')';

        if ($column['nullable']) {
            $code .= "->validation('nullable|exists:$table,id'),";
        } else {
            $code .= "->required()\n".self::INDENT_CHAIN."->validation('exists:$table,id'),";
        }

        $warnings = [];
        if ($relationship['labelGuessed']) {
            $warnings[] = "Guessed the display column '$label' for relationship '$name' (table '$table'). Please verify it exists.";
        }

        return [
            'code' => $code,
            'classes' => ['SearchSelect'],
            'warnings' => $warnings,
        ];
    }

    /**
     * Build a Select field for an enum column.
     *
     * @return array{code: string, classes: array<int, string>, warnings: array<int, string>}
     */
    protected static function buildEnumField(array $column, string $required): array
    {
        $name = $column['name'];
        $values = $column['enumValues'] ?? [];

        if (empty($values)) {
            return [
                'code' => "Select::make(\$this, '$name', [])$required,",
                'classes' => ['Select'],
                'warnings' => ["Enum column '$name' had no parsable options. Please populate the Select options manually."],
            ];
        }

        $items = [];
        foreach ($values as $value) {
            $display = str($value)->headline()->__toString();
            $items[] = "'$value' => '$display'";
        }

        $itemsString = implode(', ', $items);

        return [
            'code' => "Select::make(\$this, '$name', [$itemsString])$required,",
            'classes' => ['Select'],
            'warnings' => [],
        ];
    }

    /**
     * Resolve a foreign key column into its related model, relation name and display column.
     *
     * @return array{relation: string, model: string, label: string, table: string, labelGuessed: bool}|false
     */
    protected static function resolveRelationship(array $column): array|false
    {
        $name = $column['name'];
        $base = str($name)->endsWith('_id') ? str($name)->beforeLast('_id')->__toString() : $name;

        $table = $column['relatedTable'] ?? str($base)->plural()->snake()->lower()->__toString();
        $model = static::guessModelFromTable($table);

        if ($model === null) {
            return false;
        }

        [$label, $labelGuessed] = static::guessLabelColumn($table);

        return [
            'relation' => str($base)->camel()->__toString(),
            'model' => $model,
            'label' => $label,
            'table' => $table,
            'labelGuessed' => $labelGuessed,
        ];
    }

    /**
     * Guess the fully qualified model class for a table, or null if it does not exist.
     */
    protected static function guessModelFromTable(string $table): ?string
    {
        $class = 'App\\Models\\'.str($table)->singular()->studly()->__toString();

        return class_exists($class) ? $class : null;
    }

    /**
     * Guess the best display column for a related table.
     *
     * @return array{0: string, 1: bool} The column name and whether it was a fallback guess.
     */
    protected static function guessLabelColumn(string $table): array
    {
        $preferred = ['name', 'title', 'label', 'email', 'first_name'];

        try {
            if (Schema::hasTable($table)) {
                $columns = Schema::getColumnListing($table);

                foreach ($preferred as $candidate) {
                    if (in_array($candidate, $columns, true)) {
                        return [$candidate, false];
                    }
                }

                return ['id', true];
            }
        } catch (\Throwable) {
            // Fall through to the naming convention default below.
        }

        return ['name', true];
    }

    /**
     * Build a validation rule string, prefixing nullable and appending a max length where relevant.
     */
    protected static function rules(array $column, string $baseRule, ?int $max = null): string
    {
        $rules = [];

        if ($column['nullable']) {
            $rules[] = 'nullable';
        }

        $rules[] = $baseRule;

        if ($max !== null) {
            $rules[] = "max:$max";
        }

        return implode('|', $rules);
    }

    /**
     * Normalize a raw migration / schema column type to a canonical internal type.
     */
    public static function normalizeColumnType(string $raw): string
    {
        $raw = strtolower($raw);

        return match (true) {
            str_contains($raw, 'enum'), str_contains($raw, 'set') => 'enum',
            str_contains($raw, 'json') => 'json',
            str_contains($raw, 'bool'), str_contains($raw, 'tinyint(1)') => 'boolean',
            str_contains($raw, 'datetime'), str_contains($raw, 'timestamp') => 'datetime',
            $raw === 'date' => 'date',
            str_contains($raw, 'time') => 'time',
            str_contains($raw, 'text'), str_contains($raw, 'blob') => 'text',
            str_contains($raw, 'int') => 'integer',
            str_contains($raw, 'decimal'), str_contains($raw, 'float'), str_contains($raw, 'double'), str_contains($raw, 'numeric') => 'decimal',
            str_contains($raw, 'char'), str_contains($raw, 'string'), str_contains($raw, 'uuid'), str_contains($raw, 'ulid') => 'string',
            default => 'unknown',
        };
    }

    /**
     * Extract the individual Blueprint statements that target the given table from migration file contents.
     *
     * @return array<int, string>
     */
    protected static function extractTableStatements(string $contents, string $table): array
    {
        // Strip PHP comments first so apostrophes inside comments cannot corrupt literal parsing.
        $contents = static::stripPhpComments($contents);

        $statements = [];

        foreach (['create', 'table'] as $method) {
            foreach (["'$table'", "\"$table\""] as $quotedTable) {
                $needle = "Schema::$method($quotedTable";
                $offset = 0;

                while (($position = strpos($contents, $needle, $offset)) !== false) {
                    $body = static::extractClosureBody($contents, $position);
                    $offset = $position + strlen($needle);

                    if ($body === null) {
                        continue;
                    }

                    foreach (explode(';', $body) as $statement) {
                        $statement = trim($statement);
                        if (str_contains($statement, '$table->')) {
                            $statements[] = $statement;
                        }
                    }
                }
            }
        }

        return $statements;
    }

    /**
     * Strip PHP comments (// # and block comments) from source while preserving line structure.
     */
    protected static function stripPhpComments(string $code): string
    {
        $output = '';

        foreach (token_get_all($code) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    // Preserve any newlines the comment spanned to keep statement boundaries intact.
                    $output .= str_repeat("\n", substr_count($token[1], "\n"));
                    continue;
                }

                $output .= $token[1];
            } else {
                $output .= $token;
            }
        }

        return $output;
    }

    /**
     * Extract the body of the first closure that follows the given position, using brace matching.
     */
    protected static function extractClosureBody(string $contents, int $fromIndex): ?string
    {
        $start = strpos($contents, '{', $fromIndex);
        if ($start === false) {
            return null;
        }

        $depth = 0;
        $length = strlen($contents);

        for ($i = $start; $i < $length; $i++) {
            $character = $contents[$i];

            if ($character === '{') {
                $depth++;
            } elseif ($character === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($contents, $start + 1, $i - $start - 1);
                }
            }
        }

        return null;
    }

    /**
     * Apply a single parsed Blueprint statement to the accumulating column metadata array.
     */
    protected static function applyStatement(string $statement, array &$columns): void
    {
        if (! preg_match('/\$table->(\w+)\(/', $statement, $methodMatch)) {
            return;
        }

        $method = $methodMatch[1];

        // Handle column removals and renames from later migrations.
        if ($method === 'dropColumn') {
            foreach (static::extractStringLiterals($statement) as $dropped) {
                unset($columns[$dropped]);
            }
            return;
        }

        if ($method === 'renameColumn') {
            $literals = static::extractStringLiterals($statement);
            if (count($literals) >= 2 && isset($columns[$literals[0]])) {
                $columns[$literals[1]] = $columns[$literals[0]];
                $columns[$literals[1]]['name'] = $literals[1];
                unset($columns[$literals[0]]);
            }
            return;
        }

        // A standalone foreign key constraint updates an already defined column.
        if ($method === 'foreign') {
            $literals = static::extractStringLiterals($statement);
            $localColumn = $literals[0] ?? null;
            if ($localColumn !== null && isset($columns[$localColumn])) {
                $columns[$localColumn]['isForeignKey'] = true;
                $columns[$localColumn]['relatedTable'] = static::extractOnTable($statement) ?? $columns[$localColumn]['relatedTable'];
            }
            return;
        }

        // Ignore structural helpers that do not define a usable column.
        $structuralMethods = [
            'id', 'timestamps', 'timestampsTz', 'softDeletes', 'softDeletesTz', 'rememberToken',
            'primary', 'index', 'unique', 'fullText', 'spatialIndex',
            'dropForeign', 'dropIndex', 'dropUnique', 'dropPrimary', 'dropSoftDeletes', 'dropTimestamps',
        ];

        if (in_array($method, $structuralMethods, true)) {
            return;
        }

        $meta = static::parseColumnStatement($method, $statement);

        if ($meta !== null) {
            $columns[$meta['name']] = $meta;
        }
    }

    /**
     * Parse a column-defining Blueprint statement into normalized metadata.
     *
     * @return array|null Normalized column metadata, or null if not a column definition.
     */
    protected static function parseColumnStatement(string $method, string $statement): ?array
    {
        $nullable = str_contains($statement, '->nullable(') && ! str_contains($statement, '->nullable(false)');
        $unsigned = str_contains($statement, '->unsigned(') || str_starts_with($method, 'unsigned') || in_array($method, ['foreignId', 'foreignIdFor'], true);

        // foreignIdFor derives its column from the referenced model class.
        if ($method === 'foreignIdFor') {
            preg_match('/foreignIdFor\(\s*([\\\\\w]+)::class(?:\s*,\s*[\'"]([^\'"]+)[\'"])?/', $statement, $match);
            $modelClass = $match[1] ?? null;
            $column = $match[2] ?? ($modelClass ? str(class_basename($modelClass))->snake()->__toString().'_id' : null);

            if ($column === null) {
                return null;
            }

            return static::foreignKeyMeta($column, $nullable, static::modelClassToTable($modelClass));
        }

        $literals = static::extractStringLiterals($statement);
        $column = $literals[0] ?? null;

        if ($column === null) {
            return null;
        }

        if ($method === 'foreignId') {
            return static::foreignKeyMeta($column, $nullable, static::extractConstrainedTable($statement, $column));
        }

        $type = static::normalizeColumnType($method);
        $isForeignKey = str_ends_with($column, '_id') || str_contains($statement, '->constrained(');
        $relatedTable = $isForeignKey
            ? (static::extractConstrainedTable($statement, $column) ?? static::extractOnTable($statement))
            : null;

        return [
            'name' => $column,
            'type' => $type,
            'length' => static::extractStringLength($method, $statement),
            'nullable' => $nullable,
            'unsigned' => $unsigned,
            'enumValues' => $type === 'enum' ? static::extractEnumValues($statement) : null,
            'isForeignKey' => $isForeignKey,
            'relatedTable' => $relatedTable,
            'raw' => $method,
        ];
    }

    /**
     * Build normalized metadata for a foreign key column.
     */
    protected static function foreignKeyMeta(string $column, bool $nullable, ?string $relatedTable): array
    {
        return [
            'name' => $column,
            'type' => 'integer',
            'length' => null,
            'nullable' => $nullable,
            'unsigned' => true,
            'enumValues' => null,
            'isForeignKey' => true,
            'relatedTable' => $relatedTable,
            'raw' => 'foreignId',
        ];
    }

    /**
     * Convert a model class reference (with or without namespace) to its conventional table name.
     */
    protected static function modelClassToTable(?string $modelClass): ?string
    {
        if ($modelClass === null) {
            return null;
        }

        $baseName = str($modelClass)->afterLast('\\')->__toString();

        return str($baseName)->plural()->snake()->lower()->__toString();
    }

    /**
     * Extract the table referenced by a ->constrained() call, inferring from the column when omitted.
     */
    protected static function extractConstrainedTable(string $statement, string $column): ?string
    {
        if (! str_contains($statement, '->constrained(')) {
            return str_ends_with($column, '_id')
                ? str(str($column)->beforeLast('_id')->__toString())->plural()->snake()->lower()->__toString()
                : null;
        }

        if (preg_match('/->constrained\(\s*[\'"]([^\'"]+)[\'"]/', $statement, $match)) {
            return $match[1];
        }

        return str_ends_with($column, '_id')
            ? str(str($column)->beforeLast('_id')->__toString())->plural()->snake()->lower()->__toString()
            : null;
    }

    /**
     * Extract the table referenced by a ->on('table') call.
     */
    protected static function extractOnTable(string $statement): ?string
    {
        if (preg_match('/->on\(\s*[\'"]([^\'"]+)[\'"]/', $statement, $match)) {
            return $match[1];
        }

        return null;
    }

    /**
     * Extract the length argument of a string/char column, if present.
     */
    protected static function extractStringLength(string $method, string $statement): ?int
    {
        if (! in_array($method, ['string', 'char'], true)) {
            return null;
        }

        if (preg_match('/\$table->(?:string|char)\(\s*[\'"][^\'"]+[\'"]\s*,\s*(\d+)/', $statement, $match)) {
            return (int) $match[1];
        }

        return null;
    }

    /**
     * Extract the length from a schema type definition (eg. varchar(1000) or char(50)).
     */
    protected static function extractLengthFromType(string $type): ?int
    {
        if (preg_match('/(?:var)?char\((\d+)\)/i', $type, $match)) {
            return (int) $match[1];
        }

        return null;
    }

    /**
     * Extract the option values from an enum/set column definition.
     *
     * @return array<int, string>
     */
    protected static function extractEnumValues(string $statement): array
    {
        if (! preg_match('/\$table->(?:enum|set)\(\s*[\'"][^\'"]+[\'"]\s*,\s*\[(.*?)\]/s', $statement, $match)) {
            return [];
        }

        preg_match_all('/[\'"]([^\'"]+)[\'"]/', $match[1], $valueMatches);

        return $valueMatches[1] ?? [];
    }

    /**
     * Extract all single or double quoted string literals from a statement, in order.
     *
     * @return array<int, string>
     */
    protected static function extractStringLiterals(string $statement): array
    {
        preg_match_all('/[\'"]([^\'"]+)[\'"]/', $statement, $matches);

        return $matches[1] ?? [];
    }

    /**
     * Build the "use" import statements block for the given set of short class names.
     */
    protected static function buildUseStatements(array $shortClassNames): string
    {
        $shortClassNames = array_values(array_unique($shortClassNames));

        $fqcns = [];
        foreach ($shortClassNames as $shortName) {
            if (isset(self::CLASS_MAP[$shortName])) {
                $fqcns[] = self::CLASS_MAP[$shortName];
            }
        }

        sort($fqcns);

        return implode("\n", array_map(fn ($fqcn) => "use $fqcn;", $fqcns));
    }

    /**
     * Indent a list of code lines to the standard field / column entry indentation.
     *
     * @param  array<int, string>  $lines
     */
    protected static function indentBlock(array $lines): string
    {
        return implode("\n", array_map(fn ($line) => self::INDENT.$line, $lines));
    }

    /**
     * The default (commented) manageable fields code used when auto-generation is skipped.
     */
    protected static function defaultManageableFieldsCode(): string
    {
        return self::INDENT."// Text::make(\$this, 'name')\n"
            .self::INDENT."//     ->required()\n"
            .self::INDENT."//     ->validation('string|max:255')\n"
            .self::INDENT."//     ->setAttribute('placeholder', 'John Doe'),";
    }

    /**
     * The default browse columns code used when auto-generation is skipped.
     */
    protected static function defaultBrowseColumnsCode(): string
    {
        return self::INDENT."'id' => BrowseColumn::make('ID'),\n"
            .self::INDENT."// 'name' => BrowseColumn::make('Name'),\n"
            .self::INDENT.'// ...';
    }

    /**
     * Resolve the absolute file path of a manageable model class (supports deeply nested namespaces).
     */
    public static function getManageableModelFilePath(string $manageableModelClass): string
    {
        $relative = str($manageableModelClass)->after('App\\WRLA\\')->replace('\\', '/')->__toString();

        return app_path('WRLA/'.$relative.'.php');
    }

    /**
     * Resolve the database table for a manageable model, preferring the registered base model class.
     */
    public static function resolveTableForManageableModel(string $manageableModelClass): string
    {
        try {
            $baseModelClass = $manageableModelClass::getBaseModelClass();

            if (! empty($baseModelClass) && class_exists($baseModelClass)) {
                return (new $baseModelClass)->getTable();
            }
        } catch (\Throwable) {
            // Fall back to the naming convention below.
        }

        return static::getTableName($manageableModelClass);
    }

    /**
     * Collate the up to date column metadata for a table, preferring migrations and falling back to the live table.
     *
     * @return array{columns: array<string, array>, source: string, migrationPaths: array<int, string>}
     */
    public static function collateColumns(string $table): array
    {
        $migrationPaths = static::findMigrationPathsForTable($table);

        if (! empty($migrationPaths)) {
            return [
                'columns' => static::mergeMigrationColumns($migrationPaths, $table),
                'source' => 'migrations',
                'migrationPaths' => $migrationPaths,
            ];
        }

        if (Schema::hasTable($table)) {
            return [
                'columns' => static::getTableColumnMeta($table),
                'source' => 'table',
                'migrationPaths' => [],
            ];
        }

        return [
            'columns' => [],
            'source' => 'none',
            'migrationPaths' => [],
        ];
    }

    /**
     * Extract the column names already referenced by manageable fields (::make($this, 'column')).
     *
     * @return array<int, string>
     */
    public static function extractExistingFieldNames(string $contents): array
    {
        $clean = static::stripPhpComments($contents);
        $location = static::locateMethodArray($clean, 'getManageableFields');

        if ($location === null) {
            return [];
        }

        $body = substr($clean, $location['open'], $location['close'] - $location['open'] + 1);
        preg_match_all('/::make\s*\(\s*\$this\s*,\s*[\'"]([^\'"]+)[\'"]/', $body, $matches);

        return array_values(array_unique($matches[1] ?? []));
    }

    /**
     * Extract the array keys already used by the browse columns (eg. 'name' or 'user.email').
     *
     * @return array<int, string>
     */
    public static function extractExistingBrowseKeys(string $contents): array
    {
        $clean = static::stripPhpComments($contents);
        $location = static::locateMethodArray($clean, 'getBrowseColumns');

        if ($location === null) {
            return [];
        }

        $body = substr($clean, $location['open'], $location['close'] - $location['open'] + 1);
        preg_match_all('/[\'"]([^\'"]+)[\'"]\s*=>/', $body, $matches);

        return array_values(array_unique($matches[1] ?? []));
    }

    /**
     * Append a block of generated entries to the end of a method's returned array.
     *
     * @return string|null Updated contents, or null when the method / array could not be located.
     */
    public static function appendToMethodArray(string $contents, string $methodName, string $entriesBlock): ?string
    {
        $entriesBlock = rtrim($entriesBlock);

        if ($entriesBlock === '') {
            return $contents;
        }

        $location = static::locateMethodArray($contents, $methodName);

        if ($location === null) {
            return null;
        }

        $closingIndent = static::closingIndentFor($contents, $location['close']);
        $head = rtrim(substr($contents, 0, $location['close']));
        $tail = substr($contents, $location['close']);

        if (str_ends_with($head, '[')) {
            return $head."\n".$entriesBlock."\n".$closingIndent.$tail;
        }

        if (! str_ends_with($head, ',')) {
            $head .= ',';
        }

        return $head."\n\n".$entriesBlock."\n".$closingIndent.$tail;
    }

    /**
     * Remove any generated browse lines whose key already exists in the model, avoiding duplicates.
     */
    public static function filterExistingBrowseLines(string $browseBlock, array $existingKeys): string
    {
        if (empty($existingKeys) || trim($browseBlock) === '') {
            return $browseBlock;
        }

        $kept = [];
        foreach (explode("\n", $browseBlock) as $line) {
            if (preg_match('/[\'"]([^\'"]+)[\'"]\s*=>/', $line, $match) && in_array($match[1], $existingKeys, true)) {
                continue;
            }

            $kept[] = $line;
        }

        return implode("\n", $kept);
    }

    /**
     * Ensure the given "use ...;" statements exist, inserting any missing ones after the last import.
     */
    public static function ensureUseStatements(string $contents, string $useStatementsBlock): string
    {
        $lines = array_filter(array_map('trim', explode("\n", $useStatementsBlock)));

        if (empty($lines)) {
            return $contents;
        }

        if (! preg_match_all('/^use\s+[^;]+;/m', $contents, $matches, PREG_OFFSET_CAPTURE)) {
            return $contents;
        }

        $toAdd = [];
        foreach ($lines as $line) {
            if (! str_contains($contents, $line)) {
                $toAdd[] = $line;
            }
        }

        if (empty($toAdd)) {
            return $contents;
        }

        $lastUse = end($matches[0]);
        $insertPos = $lastUse[1] + strlen($lastUse[0]);

        return substr($contents, 0, $insertPos)."\n".implode("\n", $toAdd).substr($contents, $insertPos);
    }

    /**
     * Locate the returned array of a named method, returning the opening and closing bracket offsets.
     *
     * @return array{open: int, close: int}|null
     */
    protected static function locateMethodArray(string $contents, string $methodName): ?array
    {
        if (! preg_match('/function\s+'.preg_quote($methodName, '/').'\s*\(/', $contents, $match, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        $returnPos = strpos($contents, 'return', $match[0][1]);
        if ($returnPos === false) {
            return null;
        }

        $openPos = strpos($contents, '[', $returnPos);
        if ($openPos === false) {
            return null;
        }

        $closePos = static::findMatchingArrayEnd($contents, $openPos);
        if ($closePos === null) {
            return null;
        }

        return ['open' => $openPos, 'close' => $closePos];
    }

    /**
     * Determine the whitespace indentation on the line containing the closing bracket.
     */
    protected static function closingIndentFor(string $contents, int $closePos): string
    {
        $lineStart = strrpos(substr($contents, 0, $closePos), "\n");
        $lineStart = $lineStart === false ? 0 : $lineStart + 1;

        return substr($contents, $lineStart, $closePos - $lineStart);
    }

    /**
     * Find the offset of the "]" that matches the "[" at the given position, skipping strings and comments.
     */
    protected static function findMatchingArrayEnd(string $contents, int $openPos): ?int
    {
        $length = strlen($contents);
        $depth = 0;

        for ($i = $openPos; $i < $length; $i++) {
            $character = $contents[$i];
            $next = $i + 1 < $length ? $contents[$i + 1] : '';

            if ($character === "'" || $character === '"') {
                $i = static::skipString($contents, $i, $character);
                continue;
            }

            if (($character === '/' && $next === '/') || $character === '#') {
                $i = static::skipToLineEnd($contents, $i);
                continue;
            }

            if ($character === '/' && $next === '*') {
                $i = static::skipBlockComment($contents, $i);
                continue;
            }

            if ($character === '[') {
                $depth++;
            } elseif ($character === ']') {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return null;
    }

    /**
     * Return the offset of the closing quote for a string starting at the given quote character.
     */
    protected static function skipString(string $contents, int $start, string $quote): int
    {
        $length = strlen($contents);

        for ($i = $start + 1; $i < $length; $i++) {
            if ($contents[$i] === '\\') {
                $i++;
                continue;
            }

            if ($contents[$i] === $quote) {
                return $i;
            }
        }

        return $length - 1;
    }

    /**
     * Return the offset of the last character before the next newline (for line comments).
     */
    protected static function skipToLineEnd(string $contents, int $start): int
    {
        $position = strpos($contents, "\n", $start);

        return $position === false ? strlen($contents) - 1 : $position - 1;
    }

    /**
     * Return the offset of the closing "/" of a block comment starting at the given position.
     */
    protected static function skipBlockComment(string $contents, int $start): int
    {
        $position = strpos($contents, '*/', $start + 2);

        return $position === false ? strlen($contents) - 1 : $position + 1;
    }
}

<?php

namespace WebRegulate\LaravelAdministration\Classes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use WebRegulate\LaravelAdministration\Classes\ManageableFields\Select;
use WebRegulate\LaravelAdministration\Classes\ManageableFields\Text;
use WebRegulate\LaravelAdministration\Classes\ManageableFields\TextArea;
use WebRegulate\LaravelAdministration\Models\DatabaseRecord;

class ManageableModelDynamic extends ManageableModel
{
    public array $schemaColumns;

    public ?string $primaryKey = null;

    public function __construct(public string $connection, public string $table, mixed $recordId = null)
    {
        abort_unless(array_key_exists($connection, config('database.connections', [])), 404);
        $schema = DB::connection($connection)->getSchemaBuilder();
        $tables = array_column($schema->getTables(), 'schema_qualified_name');
        abort_unless(in_array($table, $tables, true), 404);

        $this->schemaColumns = $schema->getColumns($table);
        foreach ($schema->getIndexes($table) as $index) {
            if ($index['primary'] && count($index['columns']) === 1) {
                $this->primaryKey = $index['columns'][0];
            }
        }

        $model = new DatabaseRecord;
        $model->setConnection($connection)->setTable($table);
        if ($this->primaryKey !== null) {
            $model->setKeyName($this->primaryKey);
            $keyColumn = $this->column($this->primaryKey);
            $model->setKeyType('string');
            $model->setIncrementing((bool) ($keyColumn['auto_increment'] ?? false));
        }

        if ($recordId !== null) {
            abort_unless($this->primaryKey !== null, 422);
            $model = $model->newQuery()->findOrFail($recordId);
        }
        $this->setModelInstance($model);
    }

    public static function mainSetup(): void {}

    public static function browseSetup(): void {}

    public function query(): Builder
    {
        return $this->model()->newQuery();
    }

    public function columnNames(): array
    {
        return array_column($this->schemaColumns, 'name');
    }

    public function column(string $name): array
    {
        return collect($this->schemaColumns)->firstWhere('name', $name) ?? [];
    }

    public function isInteger(array $column): bool
    {
        return preg_match('/^(tinyint|smallint|mediumint|int|integer|bigint|int[248])$/i', $column['type_name']) === 1;
    }

    public function isReadOnly(array $column): bool
    {
        return !empty($column['generation'])
            || !empty($column['auto_increment'])
            || ($this->model()->exists && $column['name'] === $this->primaryKey)
            || preg_match('/blob|binary|bytea|geometry|geography/i', $column['type_name']) === 1;
    }

    public function enumValues(array $column): array
    {
        return $column['type_name'] === 'enum' ? str_getcsv(substr($column['type'], 5, -1), ',', "'", '\\') : [];
    }

    public function isBoolean(array $column): bool
    {
        return in_array($column['type_name'], ['bool', 'boolean'], true) || preg_match('/^tinyint\(1\)/i', $column['type']) === 1;
    }

    public function recordUrl(string $action, string|int $key): string
    {
        return route('wrla.database.record.'.$action, [
            'connection' => $this->connection,
            'table' => $this->table,
            'record' => rtrim(strtr(base64_encode((string) $key), '+/', '-_'), '='),
        ]);
    }

    public static function decodeRecordKey(string $key): string
    {
        $decoded = base64_decode(strtr($key, '-_', '+/'), true);
        abort_if($decoded === false, 404);

        return $decoded;
    }

    public function getManageableFields(): array
    {
        $fields = [];
        foreach ($this->schemaColumns as $index => $column) {
            $value = $this->model()->getRawOriginal($column['name']);
            $fieldClass = preg_match('/text|json/i', $column['type_name']) ? TextArea::class : Text::class;
            $field = new $fieldClass($column['name'], $value === null ? null : (string) $value);
            $enumValues = $this->enumValues($column);
            if ($this->isBoolean($column) || $enumValues !== []) {
                $items = $this->isBoolean($column) ? [0 => 'False', 1 => 'True'] : array_combine($enumValues, $enumValues);
                $field = (new Select($column['name'], $value === null ? null : (string) $value))->setItems(['' => ''] + $items);
            } elseif ($this->isInteger($column) || preg_match('/decimal|numeric|float|double|real/i', $column['type_name'])) {
                $field->setAttributes(['type' => 'number', 'step' => $this->isInteger($column) ? '1' : 'any']);
            } elseif ($column['type_name'] === 'date') {
                $field->setAttribute('type', 'date');
            }
            $fields[$column['name']] = $field->setLabel($column['name'])
                ->setAttributes([
                    'wire:model' => "values.$index",
                    'id' => 'wrla-database-field-'.$index,
                    'aria-label' => $column['name'],
                    'disabled' => $this->isReadOnly($column),
                    'autocomplete' => 'off',
                ]);
        }
        return $fields;
    }

    public function getBrowseColumns(): array
    {
        return $this->columnNames();
    }

    public function getInstanceActions(): array
    {
        return [];
    }

    public function validationRules(array $useDefaults = [], bool $creating = false): array
    {
        $rules = [];
        foreach ($this->schemaColumns as $index => $column) {
            if ($this->isReadOnly($column) || ($creating && ($useDefaults[$index] ?? false) && $column['default'] !== null)) {
                continue;
            }
            $type = $column['type_name'];
            $rule = $this->isInteger($column) ? 'integer' : (preg_match('/decimal|numeric|float|double|real/i', $type) ? 'numeric' : 'string');
            if (preg_match('/json/i', $type)) {
                $rule = 'json';
            } elseif ($this->isBoolean($column)) {
                $rule = 'boolean';
            } elseif (preg_match('/^(date|datetime|timestamp)/i', $type)) {
                $rule = 'date';
            }
            $rules["values.$index"] = [$column['nullable'] ? 'nullable' : 'required', $rule];
            if ($this->enumValues($column) !== []) {
                $rules["values.$index"][] = Rule::in($this->enumValues($column));
            }
            if (preg_match('/^(?:var)?char\((\d+)\)/i', $column['type'], $matches)) {
                $rules["values.$index"][] = 'max:'.$matches[1];
            }
        }
        return $rules;
    }

    public function applyValues(array $values, array $useDefaults = []): void
    {
        abort_unless($this->primaryKey !== null, 422);
        foreach ($this->schemaColumns as $index => $column) {
            if ($this->isReadOnly($column) || (!$this->model()->exists && ($useDefaults[$index] ?? false) && $column['default'] !== null)) {
                continue;
            }
            $this->model()->setAttribute($column['name'], $values[$index] ?? null);
        }
    }
}
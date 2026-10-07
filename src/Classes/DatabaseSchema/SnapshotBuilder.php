<?php

namespace WebRegulate\LaravelAdministration\Classes\DatabaseSchema;

use AlbertoArena\Truss\Introspection\Data\Column;
use AlbertoArena\Truss\Introspection\Data\ForeignKey;
use AlbertoArena\Truss\Introspection\Data\Index;
use AlbertoArena\Truss\Introspection\Data\Table;
use AlbertoArena\Truss\Introspection\SnapshotBuilder as TrussSnapshotBuilder;
use Illuminate\Database\MySqlConnection;
use Illuminate\Support\Facades\Schema;

class SnapshotBuilder extends TrussSnapshotBuilder
{
    public function introspect(string $connection): array
    {
        $builder = Schema::connection($connection);
        $databaseConnection = $builder->getConnection();

        if (!$databaseConnection instanceof MySqlConnection) {
            return parent::introspect($connection);
        }

        $database = $databaseConnection->getDatabaseName();
        $tables = $builder->getTables($database);

        if ($tables === []) {
            return [];
        }

        $columns = $databaseConnection->selectFromWriteConnection(
            'SELECT TABLE_NAME AS table_name, COLUMN_NAME AS name, COLUMN_TYPE AS type,
                IS_NULLABLE AS is_nullable, COLUMN_DEFAULT AS column_default
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME, ORDINAL_POSITION',
            [$database],
        );
        $indexes = $databaseConnection->selectFromWriteConnection(
            'SELECT TABLE_NAME AS table_name, INDEX_NAME AS name, NON_UNIQUE AS non_unique,
                COLUMN_NAME AS column_name
             FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX',
            [$database],
        );
        $foreignKeys = $databaseConnection->selectFromWriteConnection(
            'SELECT k.TABLE_NAME AS table_name, k.CONSTRAINT_NAME AS name,
                k.COLUMN_NAME AS column_name, k.REFERENCED_TABLE_NAME AS foreign_table,
                k.REFERENCED_COLUMN_NAME AS foreign_column,
                r.UPDATE_RULE AS on_update, r.DELETE_RULE AS on_delete
             FROM information_schema.KEY_COLUMN_USAGE k
             INNER JOIN information_schema.REFERENTIAL_CONSTRAINTS r
                ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA
                AND r.TABLE_NAME = k.TABLE_NAME AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME
             WHERE k.TABLE_SCHEMA = ? AND k.REFERENCED_TABLE_NAME IS NOT NULL
             ORDER BY k.TABLE_NAME, k.CONSTRAINT_NAME, k.ORDINAL_POSITION',
            [$database],
        );

        $tableColumns = [];
        foreach ($columns as $column) {
            $tableColumns[$column->table_name][] = new Column(
                name: $column->name,
                type: $column->type,
                nullable: $column->is_nullable === 'YES',
                default: $column->column_default !== null ? (string) $column->column_default : null,
            );
        }

        $tableIndexes = [];
        foreach ($indexes as $index) {
            $indexName = strtolower($index->name);
            $tableIndexes[$index->table_name][$indexName]['columns'] ??= [];
            if ($index->column_name !== null) {
                $tableIndexes[$index->table_name][$indexName]['columns'][] = $index->column_name;
            }
            $tableIndexes[$index->table_name][$indexName]['unique'] = !(bool) $index->non_unique;
        }

        $tableForeignKeys = [];
        foreach ($foreignKeys as $foreignKey) {
            $key = &$tableForeignKeys[$foreignKey->table_name][$foreignKey->name];
            $key['columns'][] = $foreignKey->column_name;
            $key['foreign_columns'][] = $foreignKey->foreign_column;
            $key['foreign_table'] = $foreignKey->foreign_table;
            $key['on_update'] = $foreignKey->on_update;
            $key['on_delete'] = $foreignKey->on_delete;
            unset($key);
        }

        return array_map(function (array $table) use ($tableColumns, $tableIndexes, $tableForeignKeys): Table {
            $name = $table['name'];
            $indexes = [];
            $primaryKey = [];
            foreach ($tableIndexes[$name] ?? [] as $indexName => $index) {
                if ($indexName === 'primary') {
                    $primaryKey = $index['columns'];
                } else {
                    $indexes[] = new Index(
                        name: $indexName,
                        columns: $index['columns'],
                        unique: $index['unique'],
                    );
                }
            }

            $foreignKeys = [];
            foreach ($tableForeignKeys[$name] ?? [] as $keyName => $foreignKey) {
                $foreignKeys[] = new ForeignKey(
                    name: $keyName,
                    columns: $foreignKey['columns'],
                    referencesTable: $foreignKey['foreign_table'],
                    referencesColumns: $foreignKey['foreign_columns'],
                    onUpdate: $foreignKey['on_update'] ? strtolower($foreignKey['on_update']) : null,
                    onDelete: $foreignKey['on_delete'] ? strtolower($foreignKey['on_delete']) : null,
                );
            }

            return new Table(
                name: $name,
                columns: $tableColumns[$name] ?? [],
                primaryKey: $primaryKey,
                indexes: $indexes,
                foreignKeys: $foreignKeys,
            );
        }, $tables);
    }
}
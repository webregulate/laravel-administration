<?php

namespace WebRegulate\LaravelAdministration\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Eloquent\Model;
use WebRegulate\LaravelAdministration\Classes\WRLAHelper;
use function Laravel\Prompts\info;
use function Laravel\Prompts\note;
use function Laravel\Prompts\text;
use function Laravel\Prompts\table;
use function Laravel\Prompts\confirm;
use function Laravel\Prompts\warning;

class ModelDocblockCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'wrla:model-docblock {model?} {--force : Apply the docblock without asking for confirmation}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate an @property docblock from a model\'s database columns and optionally write it above the class.';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        // Run commands middleware from config middleware.commands
        WRLAHelper::runCommandMiddleware($this);

        // Discover all Eloquent models under App\Models (including nested namespaces)
        $models = $this->discoverModels();

        if (empty($models)) {
            warning('No Eloquent models found under '.WRLAHelper::removeBasePath(app_path('Models')).'.');

            return 1;
        }

        // Resolve the model either from the argument or an interactive select
        $modelClass = $this->resolveModelClass($models);

        if ($modelClass === null) {
            warning('Could not resolve the requested model.');

            return 1;
        }

        // Instantiate to read the table + connection the model actually uses
        /** @var Model $instance */
        $instance = new $modelClass;
        $table = str($instance->getTable())->afterLast('.')->toString();
        $connection = $instance->getConnectionName();

        $columns = $this->readColumns($table, $connection);

        if (empty($columns)) {
            warning("No columns found for table '{$table}'".($connection ? " on connection '{$connection}'" : '').'.');

            return 1;
        }

        // Preview the columns and their resolved PHP types before generating anything
        table(
            ['Column', 'DB Type', 'PHP Type', 'Nullable'],
            array_map(fn (array $c) => [
                $c['name'],
                $c['dbType'],
                $c['phpType'],
                $c['nullable'] ? 'yes' : 'no',
            ], $columns)
        );

        // Build the docblock string
        $docblock = $this->buildDocblock($table, $columns);

        // Present the docblock so it can be copied straight from the console
        note('Generated docblock:');
        $this->newLine();
        $this->line($docblock);
        $this->newLine();

        // Offer to write it directly above the class definition, unless --force skips the prompt
        if (!$this->option('force') && !confirm('Automatically apply this docblock above the '.class_basename($modelClass).' class?', true)) {
            info('Docblock not applied. You can copy it from above.');
            return 0;
        }

        return $this->applyDocblock($modelClass, $docblock, $columns);
    }

    /**
     * Discover all Eloquent model classes under app/Models, keyed by FQCN => display name.
     *
     * @return array<string, string>
     */
    protected function discoverModels(): array
    {
        $baseDir = app_path('Models');

        if (! File::isDirectory($baseDir)) {
            return [];
        }

        $models = [];

        foreach (File::allFiles($baseDir) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            // Build the relative namespace path (eg. Buyable/SomeModel -> Buyable\SomeModel)
            $relative = str($file->getRealPath())
                ->after($baseDir.DIRECTORY_SEPARATOR)
                ->replace(['/', '\\'], '\\')
                ->beforeLast('.php')
                ->toString();

            $fqcn = 'App\\Models\\'.$relative;

            if (! class_exists($fqcn) || ! is_subclass_of($fqcn, Model::class)) {
                continue;
            }

            $models[$fqcn] = $relative;
        }

        asort($models);

        return $models;
    }

    /**
     * Resolve the target model class from the argument or an interactive select.
     *
     * @param  array<string, string>  $models
     */
    protected function resolveModelClass(array $models): ?string
    {
        $argument = $this->argument('model');

        if (! empty($argument)) {
            // Normalise slashes and allow either the short/nested name or the full FQCN
            $normalised = str($argument)->replace('/', '\\')->trim('\\')->toString();
            $fqcn = str_starts_with($normalised, 'App\\Models\\')
                ? $normalised
                : 'App\\Models\\'.$normalised;

            return array_key_exists($fqcn, $models) ? $fqcn : null;
        }

        // options: FQCN => display name
        $indexed = array_values(array_keys($models));

        // Present a numbered list so a plain index can be typed (nicer on Windows/PowerShell)
        table(
            ['#', 'Model', 'FQCN'],
            array_map(
                fn (int $i, string $fqcn) => [(string) $i, $models[$fqcn], $fqcn],
                array_keys($indexed),
                $indexed
            )
        );

        $answer = text(
            label: 'Which model would you like to build a docblock for?',
            placeholder: '0',
            default: '0',
            validate: fn (string $value) => ctype_digit(trim($value)) && array_key_exists((int) trim($value), $indexed)
                ? null
                : 'Enter a number between 0 and '.(count($indexed) - 1).'.',
        );

        return $indexed[(int) trim($answer)];
    }

    /**
     * Read column metadata for the given table/connection.
     *
     * @return array<int, array{name: string, dbType: string, phpType: string, nullable: bool, note: ?string}>
     */
    protected function readColumns(string $table, ?string $connection): array
    {
        $schema = $connection ? Schema::connection($connection) : Schema::getFacadeRoot();

        if (! $schema->hasTable($table)) {
            return [];
        }

        $columns = [];

        foreach ($schema->getColumns($table) as $column) {
            $name = $column['name'] ?? null;

            if ($name === null) {
                continue;
            }

            $fullType = strtolower((string) ($column['type'] ?? ''));
            $typeName = strtolower((string) ($column['type_name'] ?? $fullType));
            $nullable = (bool) ($column['nullable'] ?? false);

            $columns[] = [
                'name' => $name,
                'dbType' => $fullType !== '' ? $fullType : $typeName,
                'phpType' => ($nullable ? '?' : '').$this->mapPhpType($typeName, $fullType),
                'nullable' => $nullable,
                'note' => $this->columnNote($name),
            ];
        }

        return $columns;
    }

    /**
     * Map a database type to the closest PHP type. Casts on the model are intentionally ignored.
     */
    protected function mapPhpType(string $typeName, string $fullType): string
    {
        return match (true) {
            $fullType === 'tinyint(1)', str_contains($typeName, 'bool') => 'bool',
            str_contains($typeName, 'int') => 'int',
            str_contains($typeName, 'decimal'), str_contains($typeName, 'float'),
                str_contains($typeName, 'double'), str_contains($typeName, 'numeric'),
                str_contains($typeName, 'real') => 'float',
            str_contains($typeName, 'datetime'), str_contains($typeName, 'timestamp'),
                $typeName === 'date' => 'Carbon',
            str_contains($typeName, 'json') => 'array',
            str_contains($typeName, 'char'), str_contains($typeName, 'text'),
                str_contains($typeName, 'string'), str_contains($typeName, 'blob'),
                str_contains($typeName, 'enum'), str_contains($typeName, 'set'),
                str_contains($typeName, 'uuid'), str_contains($typeName, 'ulid'),
                str_contains($typeName, 'time') => 'string',
            default => 'mixed',
        };
    }

    /**
     * Optional trailing note for well-known columns.
     */
    protected function columnNote(string $name): ?string
    {
        return match ($name) {
            'deleted_at' => 'soft delete timestamp',
            'created_at', 'updated_at' => 'auto-managed timestamp',
            default => null,
        };
    }

    /**
     * Build the PHPDoc docblock string from the column metadata.
     *
     * @param  array<int, array{name: string, dbType: string, phpType: string, nullable: bool, note: ?string}>  $columns
     */
    protected function buildDocblock(string $table, array $columns): string
    {
        $lines = [];
        $lines[] = '/**';
        $lines[] = ' * Model: '.Str::studly(Str::singular($table));
        $lines[] = ' *';

        foreach ($columns as $column) {
            $trailing = $column['dbType'];

            if ($column['note'] !== null) {
                $trailing .= ' — '.$column['note'];
            }

            $lines[] = ' * @property '.$column['phpType'].' $'.$column['name'].' '.$trailing;
        }

        $lines[] = ' */';

        return implode("\n", $lines);
    }

    /**
     * Write the docblock above the class definition, replacing an existing one if present.
     *
     * @param  array<int, array{name: string, dbType: string, phpType: string, nullable: bool, note: ?string}>  $columns
     */
    protected function applyDocblock(string $modelClass, string $docblock, array $columns): int
    {
        $filePath = (new \ReflectionClass($modelClass))->getFileName();

        if ($filePath === false || ! File::exists($filePath)) {
            warning('Could not locate the source file for '.$modelClass.'.');

            return 1;
        }

        $contents = File::get($filePath);
        $shortName = class_basename($modelClass);

        // Match the class declaration, optionally preceded by an existing docblock (whitespace only between)
        $pattern = '/(?:\/\*\*(?:[^*]|\*(?!\/))*\*\/\s*)?((?:abstract\s+|final\s+|readonly\s+)*class\s+'
            .preg_quote($shortName, '/').'\b)/';

        if (! preg_match($pattern, $contents)) {
            warning('Could not locate the class declaration for '.$shortName.' - docblock not applied.');

            return 1;
        }

        $contents = preg_replace_callback(
            $pattern,
            fn (array $m) => $docblock."\n".$m[1],
            $contents,
            1
        );

        // Ensure a Carbon import exists when any property is typed as Carbon
        if ($this->usesCarbon($columns)) {
            $contents = $this->ensureCarbonImport($contents);
        }

        File::put($filePath, $contents);

        info('Docblock applied to '.WRLAHelper::removeBasePath($filePath).'.');

        return 0;
    }

    /**
     * Whether any column resolved to a Carbon PHP type.
     *
     * @param  array<int, array{phpType: string}>  $columns
     */
    protected function usesCarbon(array $columns): bool
    {
        foreach ($columns as $column) {
            if (str_contains($column['phpType'], 'Carbon')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Add `use Carbon\Carbon;` after the last use statement if no Carbon import is present.
     */
    protected function ensureCarbonImport(string $contents): string
    {
        // Already imports a Carbon class (Carbon\Carbon or Illuminate\Support\Carbon)
        if (preg_match('/^use\s+[^;]*\bCarbon;/m', $contents)) {
            return $contents;
        }

        // Insert after the final top-level use statement
        if (preg_match_all('/^use\s+[^;]+;/m', $contents, $matches, PREG_OFFSET_CAPTURE)) {
            $last = end($matches[0]);
            $insertPos = $last[1] + strlen($last[0]);

            return substr($contents, 0, $insertPos)
                ."\nuse Carbon\\Carbon;"
                .substr($contents, $insertPos);
        }

        // No use statements - place it after the namespace declaration
        return preg_replace(
            '/^(namespace\s+[^;]+;)/m',
            "$1\n\nuse Carbon\\Carbon;",
            $contents,
            1
        );
    }
}

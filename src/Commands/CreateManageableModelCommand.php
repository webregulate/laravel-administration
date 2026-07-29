<?php

namespace WebRegulate\LaravelAdministration\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use WebRegulate\LaravelAdministration\Classes\WRLAHelper;
use WebRegulate\LaravelAdministration\Services\ManageableModelService;
use function Laravel\Prompts\info;
use function Laravel\Prompts\note;
use function Laravel\Prompts\text;
use function Laravel\Prompts\pause;
use function Laravel\Prompts\select;
use function Laravel\Prompts\confirm;
use function Laravel\Prompts\warning;

class CreateManageableModelCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'wrla:manageable-model {model?}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create a manageable model';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        // Question 1: Create or modify a manageable model
        $action = select('What would you like to do?', [
            1 => 'Create a new Manageable Model',
            2 => 'Modify an existing Manageable Model',
        ], 1);

        // Modify is not yet supported
        if ($action === 2) {
            info('Modifying an existing Manageable Model is coming soon. Please try again with the create option.');

            return 0;
        }

        return $this->handleCreate();
    }

    /**
     * Handle the creation of a new manageable model.
     */
    protected function handleCreate(): int
    {
        // Question 2: What kind of manageable model are we creating?
        $creationType = (int) select('Create a new manageable model that', [
            1 => '... does not have an already existing migration or table',
            2 => '... has an existing migration (we can build the fields from this/these)',
            3 => '... has an existing table (we can build the fields from this)',
        ], 1);

        // Ask for the model name / namespace
        $model = $this->argument('model')
            ?: text('Model class in studly case, nest with slashes (eg. ModelName or Folder/ModelName)', required: true);

        // Normalise forward slashes to backslashes so both "Folder/Model" and "Folder\Model" work
        $model = str($model)->replace('/', '\\')->trim('\\')->__toString();

        // Determine the destination file path and check for overwrite
        $filePath = str($model)->replace('\\', '/')->__toString();
        $forceOverwrite = false;
        if (File::exists(app_path('WRLA/'.$filePath.'.php'))) {
            // Ask the user if they want to overwrite the existing manageable model
            if (!confirm('The manageable model already exists. Do you want to overwrite it?', false)) {
                warning('Manageable model creation cancelled.');

                return 0;
            }

            $forceOverwrite = true;
        }

        // Icon for the model
        $icon = text('Icon for the model (https://fontawesome.com/v6/search)', default: 'fa fa-question-circle');

        // Determine the base table name for this model
        $table = ManageableModelService::getTableName($model);

        // Gather column metadata based on the chosen creation type
        $columns = match ($creationType) {
            1 => $this->handleNewMigration($model, $table),
            2 => $this->handleExistingMigration($model, $table),
            3 => $this->handleExistingTable($model),
            default => [],
        };

        // Build the stub overrides (generated fields / browse columns / imports) and collect warnings
        [$overrides, $warnings] = $this->buildGeneratedOverrides($columns);

        // Generate the manageable model file from the stub (generated last so it can embed fields)
        WRLAHelper::generateFileFromStub(
            'ManageableModel.stub',
            ManageableModelService::getStubVariables($model, $icon, $overrides),
            app_path('WRLA/'.$filePath.'.php'),
            $forceOverwrite
        );

        info('Manageable model '.$model.' created successfully here: '.WRLAHelper::removeBasePath(app_path('WRLA/'.$filePath.'.php')));

        // Render any warnings in a loud, non-missable way
        $this->renderWarnings($warnings);

        return 1;
    }

    /**
     * Option 1: create the migration and model interactively, then parse columns from the new migration.
     *
     * @return array<string, array> Normalized column metadata (empty when the user opts out).
     */
    protected function handleNewMigration(string $model, string $table): array
    {
        $baseModelName = str($model)->afterLast('\\')->__toString();

        // Ask whether to create the migration
        if (! confirm("Create the create_{$table}_table migration?", true)) {
            note('Skipping migration - no fields or browse columns will be auto-generated.');

            return [];
        }

        // Create the migration
        $this->call('make:migration', ['name' => 'Create'.str($baseModelName)->plural()->studly()->__toString().'Table']);

        // Find the newly created migration file and give the user a clickable path to fill it in
        $migrationPaths = ManageableModelService::findMigrationPathsForTable($table);
        $latestMigration = end($migrationPaths) ?: null;

        if (! empty($latestMigration)) {
            note("Fill out the migration columns now:\n".$latestMigration);
        }

        info('Once auto-generation runs, a manageable field and browse column will be created for every column (except id and timestamps).');
        pause('When you have finished editing the migration, press ENTER to continue...');

        // Ask whether to create the base model, suggesting relationships afterwards
        $this->createModelIfWanted($model, true);

        // Re-scan in case the migration path changed and merge column metadata
        $migrationPaths = ManageableModelService::findMigrationPathsForTable($table);

        if (empty($migrationPaths)) {
            warning("Could not locate a migration for table '$table'. No fields will be auto-generated.");

            return [];
        }

        return ManageableModelService::mergeMigrationColumns($migrationPaths, $table);
    }

    /**
     * Option 2: locate all existing migrations for the table and merge their columns.
     *
     * @return array<string, array> Normalized column metadata.
     */
    protected function handleExistingMigration(string $model, string $table): array
    {
        // Allow the user to confirm / correct the table name
        $table = text('Which table do the migrations target?', default: $table, required: true);

        $migrationPaths = ManageableModelService::findMigrationPathsForTable($table);

        if (empty($migrationPaths)) {
            warning("No migrations were found for table '$table'. No fields will be auto-generated.");
            $this->createModelIfWanted($model, false);

            return [];
        }

        note('Found '.count($migrationPaths)." migration(s) for table '$table':\n".implode("\n", $migrationPaths));

        // Offer to create the base model if it is missing
        $this->createModelIfWanted($model, false);

        return ManageableModelService::mergeMigrationColumns($migrationPaths, $table);
    }

    /**
     * Option 3: introspect an existing database table's columns.
     *
     * @return array<string, array> Normalized column metadata.
     */
    protected function handleExistingTable(string $model): array
    {
        $table = text('Which existing table should we build from?', default: ManageableModelService::getTableName($model), required: true);

        if (! Schema::hasTable($table)) {
            warning("Table '$table' does not exist. No fields will be auto-generated.");
            $this->createModelIfWanted($model, false);

            return [];
        }

        // Offer to create the base model if it is missing
        $this->createModelIfWanted($model, false);

        return ManageableModelService::getTableColumnMeta($table);
    }

    /**
     * Offer to create the base Eloquent model, optionally pausing for the user to add relationships.
     *
     * @param  bool  $suggestRelationships  Whether to pause for the user to add relationships.
     */
    protected function createModelIfWanted(string $model, bool $suggestRelationships): void
    {
        $filePath = str($model)->replace('\\', '/')->__toString();
        $baseModelExists = File::exists(app_path('Models/'.$filePath.'.php'));

        $createModel = confirm(
            ! $baseModelExists
                ? "Create the {$model} model?"
                : "The base model already exists. Overwrite the {$model} model?",
            ! $baseModelExists
        );

        if (! $createModel) {
            return;
        }

        $this->call('make:model', ['name' => $model]);

        if ($suggestRelationships) {
            note('Add any relationships (belongsTo, hasMany, etc.) your model needs now - foreign key fields will be generated as relationship selects.');
            pause('When you have finished editing the model, press ENTER to continue...');
        }
    }

    /**
     * Build the generated stub overrides and warnings, confirming with the user first.
     *
     * @param  array<string, array>  $columns
     * @return array{0: array<string, string>, 1: array<int, string>}
     */
    protected function buildGeneratedOverrides(array $columns): array
    {
        // Nothing to generate - fall back to the stub defaults
        if (empty($columns)) {
            return [[], []];
        }

        if (! confirm('Auto-generate manageable fields and browse columns for all columns?', true)) {
            return [[], []];
        }

        $generated = ManageableModelService::generateFromColumns($columns);

        $overrides = [
            '{{ $USE_STATEMENTS }}' => $generated['useStatements'],
            '{{ $BROWSE_COLUMNS }}' => $generated['browseColumns'],
            '{{ $MANAGEABLE_FIELDS }}' => $generated['fields'],
        ];

        return [$overrides, $generated['warnings']];
    }

    /**
     * Render any generation warnings in a loud, non-missable highlighted block.
     *
     * @param  array<int, string>  $warnings
     */
    protected function renderWarnings(array $warnings): void
    {
        if (empty($warnings)) {
            return;
        }

        warning(count($warnings)." item(s) need manual attention:\n\n• ".implode("\n• ", $warnings));
    }
}


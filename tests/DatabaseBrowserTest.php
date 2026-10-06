<?php

namespace WebRegulate\LaravelAdministration\Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Livewire\Livewire;
use WebRegulate\LaravelAdministration\Classes\ManageableModelDynamic;
use WebRegulate\LaravelAdministration\Livewire\DevTools\DatabaseRecordUpsert;
use WebRegulate\LaravelAdministration\Livewire\DevTools\DatabaseRecordModal;
use WebRegulate\LaravelAdministration\Livewire\DevTools\DatabaseTableBrowse;
use WebRegulate\LaravelAdministration\Livewire\DevTools\DatabaseTables;
use WebRegulate\LaravelAdministration\Livewire\ManageableModels\ManageableModelDynamicBrowseFilters;

class DatabaseBrowserTest extends TestCase
{
    public function createApplication()
    {
        $path = getenv('WRLA_TEST_APP_PATH');
        if (!$path) {
            $this->markTestSkipped('Set WRLA_TEST_APP_PATH to a Laravel application with WRLA dependencies installed.');
        }
        $loader = require $path.'/vendor/autoload.php';
        $loader->addPsr4('WebRegulate\\LaravelAdministration\\', dirname(__DIR__).'/src', true);
        $source = dirname(__DIR__).'/src/';
        $classes = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($source)) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $relative = substr(str_replace('\\', '/', $file->getPathname()), strlen($source));
                $classes['WebRegulate\\LaravelAdministration\\'.str_replace('/', '\\', substr($relative, 0, -4))] = $file->getPathname();
            }
        }
        $loader->addClassMap($classes);
        $app = require $path.'/bootstrap/app.php';
        $app->beforeBootstrapping(\Illuminate\Foundation\Bootstrap\RegisterProviders::class, function ($app) {
            $app['config']->set([
                'database.connections' => [
                    'browser_a' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
                    'browser_b' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
                ],
                'database.default' => 'browser_a',
                'session.driver' => 'array',
                'cache.default' => 'array',
            ]);
        });
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'database.connections' => [
                'browser_a' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
                'browser_b' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
            ],
            'database.default' => 'browser_a',
            'wr-laravel-administration.developer.enable' => true,
            'session.driver' => 'array',
            'cache.default' => 'array',
        ]);
        foreach (['browser_a', 'browser_b'] as $connection) {
            DB::connection($connection)->getSchemaBuilder()->create('records', function ($table) {
                $table->string('uuid')->primary();
                $table->string('name');
                $table->integer('quantity')->default(7);
                $table->text('notes')->nullable();
            });
        }
    }

    public function test_fields_accept_backed_enum_casts_and_tags_preserve_plain_values(): void
    {
        ManageableModelDynamic::register();
        ManageableModelDynamic::setStaticOption('tableData', [
            (object) ['Field' => 'notes', 'Default' => null],
        ]);

        foreach (\WebRegulate\LaravelAdministration\Enums\PageType::cases() as $status) {
            foreach ([
                \WebRegulate\LaravelAdministration\Classes\ManageableFields\Tags::class,
                \WebRegulate\LaravelAdministration\Classes\ManageableFields\Text::class,
            ] as $fieldClass) {
                $manageableModel = new ManageableModelDynamic('browser_a', 'main.records');
                $manageableModel->model()->mergeCasts(['notes' => \WebRegulate\LaravelAdministration\Enums\PageType::class]);
                $manageableModel->model()->notes = $status;

                $field = $fieldClass::make($manageableModel, 'notes');

                $this->assertSame($status->value, $field->getValue());
                $this->assertSame($status, $manageableModel->model()->notes);
            }
        }

        foreach (['gocardless', null] as $value) {
            $manageableModel = new ManageableModelDynamic('browser_a', 'main.records');
            $manageableModel->model()->notes = $value;
            $field = \WebRegulate\LaravelAdministration\Classes\ManageableFields\Tags::make(
                $manageableModel,
                'notes',
                ['maxTags' => 1, 'commonTags' => ['gocardless']],
            );

            $this->assertSame($value ?? '', $field->getValue());
            $this->assertSame(1, $field->options['maxTags']);
            $this->assertSame(['gocardless'], $field->options['commonTags']);
        }
    }

    public function test_dynamic_model_preserves_keys_and_connection_when_hydrated(): void
    {
        $model = new ManageableModelDynamic('browser_a', 'main.records');
        $model->applyValues(['key/with spaces', '<script>alert(1)</script>', 0, null]);
        $model->model()->save();
        $record = new ManageableModelDynamic('browser_a', 'main.records', 'key/with spaces');
        $this->assertSame('uuid', $record->model()->getKeyName());
        $this->assertSame('key/with spaces', $record->model()->getKey());
        $this->assertSame('browser_a', $record->model()->getConnectionName());
        $record->applyValues(['tampered-key', 'Updated', 1, '']);
        $record->model()->save();
        $this->assertSame('Updated', $record->query()->first()->name);
        $this->assertSame('', $record->query()->first()->notes);
        $this->assertSame(0, DB::connection('browser_b')->table('records')->count());
        $record->model()->delete();
        $this->assertSame(0, $record->query()->count());
    }

    public function test_table_picker_filters_and_lists_connected_connections(): void
    {
        DB::connection('browser_b')->getSchemaBuilder()->create('second_connection_only', fn ($table) => $table->id());
        Livewire::test(DatabaseTables::class)
            ->assertSet('connections', ['browser_a', 'browser_b'])
            ->assertSee('main.records')->assertDontSee('main.second_connection_only')
            ->assertSee('wire:model.live.change="connection"', false)->assertSee('Please wait...')
            ->set('connection', 'browser_b')->assertSee('main.second_connection_only')
            ->assertSee(route('wrla.database.table', ['connection' => 'browser_b', 'table' => 'main.second_connection_only']))
            ->set('connection', 'browser_a')->assertDontSee('main.second_connection_only')
            ->set('search', 'missing')->assertDontSee('main.records')
            ->set('connection', 'browser_b')->set('search', 'records')->assertSee('main.records');
    }

    public function test_mysql_connections_list_only_their_configured_database(): void
    {
        foreach (['browser_a' => 'mysql', 'browser_b' => 'mariadb'] as $name => $driver) {
            $schema = \Mockery::mock(\Illuminate\Database\Schema\Builder::class);
            $schema->shouldReceive('getTables')->with($name.'_database')->andReturn([
                ['schema_qualified_name' => $name.'_database.records', 'size' => null],
            ]);
            $connection = \Mockery::mock(\Illuminate\Database\Connection::class);
            $connection->shouldReceive('getDriverName')->andReturn($driver);
            $connection->shouldReceive('getDatabaseName')->andReturn($name.'_database');
            $connection->shouldReceive('getSchemaBuilder')->andReturn($schema);
            DB::shouldReceive('connection')->with($name)->andReturn($connection);
        }

        Livewire::test(DatabaseTables::class)
            ->assertSee('browser_a_database.records')->assertDontSee('browser_b_database.records')
            ->set('connection', 'browser_b')
            ->assertSee('browser_b_database.records')->assertDontSee('browser_a_database.records');
    }

    public function test_browse_reuses_filters_and_escapes_database_values(): void
    {
        DB::connection('browser_a')->table('records')->insert([
            ['uuid' => 'one', 'name' => '<script>alert(1)</script>', 'quantity' => 0],
            ['uuid' => 'two', 'name' => 'Beta', 'quantity' => 2],
        ]);
        Livewire::test(DatabaseTableBrowse::class, ['connection' => 'browser_a', 'table' => 'main.records'])
            ->assertSee('&lt;script&gt;', false)
            ->call('filtersUpdatedOutside', [['field' => 'quantity', 'operator' => '=', 'value' => '0']])
            ->assertSee('&lt;script&gt;', false)->assertDontSee('Beta');
        Livewire::test(ManageableModelDynamicBrowseFilters::class, ['schemaColumns' => ['uuid', 'name']])
            ->call('addFilterAction')->assertDispatched('filtersUpdatedOutside')
            ->call('removeFilterAction', 0)->assertSet('browseFilterInputs', []);
    }

    public function test_all_fields_option_is_opt_in(): void
    {
        Livewire::test(ManageableModelDynamicBrowseFilters::class, ['schemaColumns' => ['uuid', 'name']])
            ->assertSet('enableAllFields', false)
            ->call('addFilterAction')->assertSet('browseFilterInputs.0.field', 'uuid')->assertDontSee('All columns');
        Livewire::test(ManageableModelDynamicBrowseFilters::class, [
            'schemaColumns' => ['uuid', 'name'],
            'enableAllFields' => true,
            'defaultDynamicFilters' => [['field' => '*', 'value' => '']],
        ])->assertSet('browseFilterInputs', [['field' => '*', 'type' => 'Text', 'operator' => 'contains', 'value' => '']])
            ->assertSee('All columns')->call('addFilterAction')->assertSet('browseFilterInputs.1.field', 'uuid')
            ->set('browseFilterInputs.0.value', 'Alpha')->assertDispatched('filtersUpdatedOutside');
    }

    public function test_all_fields_searches_across_columns_and_preserves_grouping(): void
    {
        DB::connection('browser_a')->table('records')->insert([
            ['uuid' => 'one', 'name' => 'Alpha', 'quantity' => 0, 'notes' => 'Red'],
            ['uuid' => 'two', 'name' => 'Beta', 'quantity' => 2, 'notes' => 'Alpha'],
            ['uuid' => 'three', 'name' => 'Gamma', 'quantity' => 3, 'notes' => null],
        ]);
        $browse = Livewire::test(DatabaseTableBrowse::class, ['connection' => 'browser_a', 'table' => 'main.records'])
            ->assertSet('dynamicFilterInputs', [['field' => '*', 'type' => 'Text', 'operator' => 'contains', 'value' => '']])
            ->assertSee('Alpha')->assertSee('Beta')->assertSee('Gamma')->assertSee('All columns');
        $browse->call('filtersUpdatedOutside', [['field' => '*', 'operator' => 'contains', 'value' => 'Alpha']])
            ->assertHasNoErrors()->assertSee('Alpha')->assertSee('Beta')->assertDontSee('Gamma');
        $browse->call('filtersUpdatedOutside', [['field' => '*', 'operator' => 'contains', 'value' => 'Alpha,Red|Gamma']])
            ->assertSee('Alpha')->assertSee('Gamma')->assertDontSee('Beta');
        $browse->call('filtersUpdatedOutside', [['field' => '*', 'operator' => 'not contains', 'value' => 'Alpha']])
            ->assertSee('Gamma')->assertDontSee('Alpha')->assertDontSee('Beta');
        $browse->call('filtersUpdatedOutside', [['field' => '*', 'operator' => '=', 'value' => '0']])
            ->assertSee('Alpha')->assertDontSee('Beta')->assertDontSee('Gamma');
        $browse->call('filtersUpdatedOutside', [
            ['field' => '*', 'operator' => 'contains', 'value' => 'Alpha'],
            ['field' => 'name', 'operator' => '=', 'value' => 'Beta'],
        ])->assertSee('Beta')->assertDontSee('Red')->assertDontSee('Gamma');
        $browse->call('filtersUpdatedOutside', [['field' => '*', 'operator' => 'contains', 'value' => '']])
            ->assertSee('Alpha')->assertSee('Beta')->assertSee('Gamma');
        $browse->call('filtersUpdatedOutside', [])->assertSee('Alpha')->assertSee('Beta')->assertSee('Gamma');
    }

    public function test_create_defaults_edit_null_and_delete(): void
    {
        Livewire::test(DatabaseRecordUpsert::class, ['connection' => 'browser_a', 'table' => 'main.records'])
            ->set('values.0', 'key/with spaces')->set('values.1', 'Created')
            ->call('save')->assertHasNoErrors()->assertRedirect(route('wrla.database.table', ['connection' => 'browser_a', 'table' => 'main.records']));
        $this->assertSame(7, DB::connection('browser_a')->table('records')->first()->quantity);
        $key = rtrim(strtr(base64_encode('key/with spaces'), '+/', '-_'), '=');
        Livewire::test(DatabaseRecordUpsert::class, ['connection' => 'browser_a', 'table' => 'main.records', 'record' => $key])
            ->set('values.1', 'Edited')->set('nullValues.3', false)->set('values.3', '')
            ->call('save')->assertHasNoErrors();
        $this->assertSame('', DB::connection('browser_a')->table('records')->first()->notes);
        Livewire::test(DatabaseTableBrowse::class, ['connection' => 'browser_a', 'table' => 'main.records'])
            ->set('pendingDelete', 'key/with spaces')->call('deleteRecord')->assertSet('pendingDelete', null);
        $this->assertSame(0, DB::connection('browser_a')->table('records')->count());
    }

    public function test_read_only_view_rejects_save(): void
    {
        DB::connection('browser_a')->table('records')->insert(['uuid' => 'one', 'name' => 'Original']);
        $component = new DatabaseRecordUpsert;
        $component->readOnly = true;
        try {
            $component->save();
            $this->fail('Read-only saves must be forbidden.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertSame('Original', DB::connection('browser_a')->table('records')->first()->name);
    }

    public function test_non_developers_cannot_open_pages(): void
    {
        config(['wr-laravel-administration.developer.enable' => false]);
        foreach ([DatabaseTables::class, DatabaseTableBrowse::class, DatabaseRecordUpsert::class, DatabaseRecordModal::class] as $component) {
            Livewire::test($component, ['connection' => 'browser_a', 'table' => 'main.records'])->assertForbidden();
        }
    }

    public function test_browser_access_can_be_configured_independently(): void
    {
        foreach ([false, fn ($userData) => false] as $enabled) {
            config(['wr-laravel-administration.database_browser.enabled' => $enabled]);
            foreach ([DatabaseTables::class, DatabaseTableBrowse::class, DatabaseRecordUpsert::class] as $component) {
                Livewire::test($component, ['connection' => 'browser_a', 'table' => 'main.records'])->assertForbidden();
            }
        }

        config([
            'wr-laravel-administration.developer.enable' => false,
            'wr-laravel-administration.database_browser.enabled' => true,
        ]);
        Livewire::test(DatabaseTables::class)->assertOk();
    }

    public function test_browser_connections_and_tables_are_restricted_on_direct_access(): void
    {
        config([
            'wr-laravel-administration.database_browser.connections' => ['browser_b'],
            'wr-laravel-administration.database_browser.default_connection' => 'browser_b',
        ]);
        Livewire::test(DatabaseTables::class)->assertSet('connections', ['browser_b'])->assertSet('connection', 'browser_b');
        foreach ([DatabaseTableBrowse::class, DatabaseRecordUpsert::class, DatabaseRecordModal::class] as $component) {
            Livewire::test($component, ['connection' => 'browser_a', 'table' => 'main.records'])->assertNotFound();
        }

        config(['wr-laravel-administration.database_browser.excluded_tables' => ['browser_b' => ['rec*']]]);
        Livewire::test(DatabaseTables::class)->assertDontSee('main.records');
        foreach ([DatabaseTableBrowse::class, DatabaseRecordUpsert::class, DatabaseRecordModal::class] as $component) {
            Livewire::test($component, ['connection' => 'browser_b', 'table' => 'main.records'])->assertNotFound();
        }

        config([
            'wr-laravel-administration.database_browser.connections' => null,
            'wr-laravel-administration.database_browser.excluded_tables' => ['*' => ['main.*']],
        ]);
        Livewire::test(DatabaseTables::class)->assertDontSee('main.records');
        Livewire::test(DatabaseTableBrowse::class, ['connection' => 'browser_a', 'table' => 'main.records'])->assertNotFound();
        config(['wr-laravel-administration.database_browser.connections' => []]);
        Livewire::test(DatabaseTables::class)->assertSet('connections', [])->assertSet('connection', '');
    }

    public function test_browser_read_only_mode_blocks_writes_but_allows_viewing(): void
    {
        DB::connection('browser_a')->table('records')->insert(['uuid' => 'one', 'name' => 'Original']);
        foreach ([['read_only' => true], ['read_only_connections' => ['browser_a']]] as $settings) {
            config(['wr-laravel-administration.database_browser' => $settings]);
            $browse = Livewire::test(DatabaseTableBrowse::class, ['connection' => 'browser_a', 'table' => 'main.records'])
                ->assertSee('Original')->assertDontSee('Create record')->assertDontSee('Edit record')->assertDontSee('Delete record');
            $browse->call('openRecord', 'view', base64_encode('one'))->assertDispatched('openModal');
            Livewire::test(DatabaseTableBrowse::class, ['connection' => 'browser_a', 'table' => 'main.records'])
                ->set('pendingDelete', 'one')->call('deleteRecord')->assertForbidden();
            Livewire::test(DatabaseTableBrowse::class, ['connection' => 'browser_a', 'table' => 'main.records'])
                ->call('openRecord', 'edit', base64_encode('one'))->assertForbidden();
            Livewire::test(DatabaseRecordUpsert::class, ['connection' => 'browser_a', 'table' => 'main.records'])->assertForbidden();
            Livewire::test(DatabaseRecordModal::class, ['connection' => 'browser_a', 'table' => 'main.records'])->assertForbidden();
            Livewire::test(DatabaseRecordUpsert::class, ['connection' => 'browser_a', 'table' => 'main.records', 'record' => base64_encode('one'), 'readOnly' => true, 'inModal' => true])
                ->assertSee('Original')->assertDontSee('Edit record')->call('editRecord')->assertForbidden();
        }
        $this->assertSame('Original', DB::connection('browser_a')->table('records')->value('name'));
    }

    public function test_browser_permissions_are_independent_and_rechecked_before_saving(): void
    {
        config(['wr-laravel-administration.database_browser.permissions' => ['create' => true, 'edit' => false, 'delete' => false]]);
        $create = Livewire::test(DatabaseRecordUpsert::class, ['connection' => 'browser_a', 'table' => 'main.records', 'inModal' => true])
            ->set('values.0', 'one')->set('values.1', 'Created')->call('save')->assertHasNoErrors();
        $create->set('values.1', 'Blocked')->call('save')->assertForbidden();
        config(['wr-laravel-administration.database_browser.permissions.create' => false]);
        Livewire::test(DatabaseRecordUpsert::class, ['connection' => 'browser_a', 'table' => 'main.records'])->assertForbidden();
        config(['wr-laravel-administration.database_browser.permissions.edit' => true]);
        $edit = Livewire::test(DatabaseRecordUpsert::class, ['connection' => 'browser_a', 'table' => 'main.records', 'record' => base64_encode('one')]);
        config(['wr-laravel-administration.database_browser.read_only' => true]);
        $edit->set('values.1', 'Blocked')->call('save')->assertForbidden();
        $this->assertSame('Created', DB::connection('browser_a')->table('records')->value('name'));
    }

    public function test_browser_pagination_and_filter_limits_are_independent(): void
    {
        config([
            'wr-laravel-administration.browse.pagination' => ['default' => 75, 'perPage' => [75, 100]],
            'wr-laravel-administration.database_browser.pagination' => ['default' => 2, 'perPage' => [2, 3, 100, 0, -1], 'max_per_page' => 3],
            'wr-laravel-administration.database_browser.filters' => ['enable_all_fields' => false, 'max_filters' => 1, 'max_value_length' => 3],
        ]);
        Livewire::test(DatabaseTableBrowse::class, ['connection' => 'browser_a', 'table' => 'main.records'])
            ->assertSet('perPage', 2)->assertSet('dynamicFilterInputs', [])->assertDontSee('All columns')
            ->assertSee('value="3"', false)->assertDontSee('value="100"', false)
            ->set('perPage', 100000)->assertSet('perPage', 2)
            ->call('filtersUpdatedOutside', [['field' => '*', 'operator' => 'contains', 'value' => 'one']])
            ->assertHasErrors('dynamicFilterInputs.0.field');
        Livewire::test(DatabaseTableBrowse::class, ['connection' => 'browser_a', 'table' => 'main.records'])
            ->call('filtersUpdatedOutside', [['field' => 'name', 'operator' => '=', 'value' => 'long']])
            ->assertHasErrors('dynamicFilterInputs.0.value');
        Livewire::test(DatabaseTableBrowse::class, ['connection' => 'browser_a', 'table' => 'main.records'])
            ->call('filtersUpdatedOutside', array_fill(0, 2, ['field' => 'name', 'operator' => '=', 'value' => 'one']))
            ->assertHasErrors('dynamicFilterInputs');
    }

    public function test_browser_upsert_options_override_general_options(): void
    {
        config([
            'wr-laravel-administration.upsert.mode' => 'page',
            'wr-laravel-administration.database_browser.upsert' => ['mode' => 'modal', 'modal' => ['size' => '4xl', 'return_to_browse_after_save' => true]],
        ]);
        Livewire::test(DatabaseTableBrowse::class, ['connection' => 'browser_a', 'table' => 'main.records'])
            ->assertSee('openRecord', false)->call('openRecord', 'create')
            ->assertDispatched('openModal', fn ($event, $parameters) => $parameters[2]['maxWidth'] === '4xl');
        Livewire::test(DatabaseRecordUpsert::class, ['connection' => 'browser_a', 'table' => 'main.records', 'inModal' => true])
            ->set('values.0', 'one')->set('values.1', 'Created')->call('save')->assertHasNoErrors()->assertDispatched('closeModal');
        config([
            'wr-laravel-administration.database_browser.upsert.mode' => 'page',
            'wr-laravel-administration.database_browser.upsert.page.return_to_browse_after_save' => false,
        ]);
        Livewire::test(DatabaseTableBrowse::class, ['connection' => 'browser_a', 'table' => 'main.records'])->assertDontSee('openRecord', false);
        Livewire::test(DatabaseRecordUpsert::class, ['connection' => 'browser_a', 'table' => 'main.records'])
            ->set('values.0', 'two')->set('values.1', 'Stays open')->call('save')->assertHasNoErrors()->assertNoRedirect()->assertSet('recordId', 'two')
            ->set('values.1', 'Edited')->call('save')->assertHasNoErrors();
        $this->assertSame(2, DB::connection('browser_a')->table('records')->count());
        $this->assertSame('Edited', DB::connection('browser_a')->table('records')->where('uuid', 'two')->value('name'));
    }

    public function test_browser_save_uses_the_rendered_page_or_modal_settings(): void
    {
        config([
            'wr-laravel-administration.database_browser.upsert.mode' => 'modal',
            'wr-laravel-administration.database_browser.upsert.page.return_to_browse_after_save' => true,
            'wr-laravel-administration.database_browser.upsert.modal.return_to_browse_after_save' => false,
        ]);
        Livewire::test(DatabaseRecordUpsert::class, ['connection' => 'browser_a', 'table' => 'main.records'])
            ->set('values.0', 'page')->set('values.1', 'Page')->call('save')->assertHasNoErrors()
            ->assertRedirect(route('wrla.database.table', ['connection' => 'browser_a', 'table' => 'main.records']));
        config(['wr-laravel-administration.database_browser.upsert.mode' => 'page']);
        Livewire::test(DatabaseRecordUpsert::class, ['connection' => 'browser_a', 'table' => 'main.records', 'inModal' => true])
            ->set('values.0', 'modal')->set('values.1', 'Modal')->call('save')->assertHasNoErrors()->assertNoRedirect()->assertNotDispatched('closeModal');
    }

    public function test_mysql_record_urls_cannot_target_another_database(): void
    {
        foreach (['browser_a' => 'mysql', 'browser_b' => 'mariadb'] as $name => $driver) {
            $schema = \Mockery::mock(\Illuminate\Database\Schema\Builder::class);
            $schema->shouldReceive('getTables')->once()->with($name.'_database')->andReturn([
                ['schema_qualified_name' => $name.'_database.records'],
            ]);
            $connection = \Mockery::mock(\Illuminate\Database\Connection::class);
            $connection->shouldReceive('getDriverName')->andReturn($driver);
            $connection->shouldReceive('getDatabaseName')->andReturn($name.'_database');
            $connection->shouldReceive('getSchemaBuilder')->andReturn($schema);
            DB::shouldReceive('connection')->with($name)->andReturn($connection);
            Livewire::test(DatabaseTableBrowse::class, ['connection' => $name, 'table' => 'other_database.records'])->assertNotFound();
        }
    }

    public function test_record_actions_follow_configured_mode_and_modal_size(): void
    {
        DB::connection('browser_a')->table('records')->insert(['uuid' => 'key/with spaces', 'name' => 'Original']);
        $key = rtrim(strtr(base64_encode('key/with spaces'), '+/', '-_'), '=');
        config(['wr-laravel-administration.upsert.mode' => 'modal', 'wr-laravel-administration.upsert.modal.size' => '5xl']);
        $browse = Livewire::test(DatabaseTableBrowse::class, ['connection' => 'browser_a', 'table' => 'main.records'])
            ->assertSee('openRecord', false)->assertSee('window.wrlaOpenDatabaseRecordModal', false);
        foreach (['create', 'view', 'edit'] as $action) {
            $record = $action === 'create' ? null : $key;
            $browse->call('openRecord', $action, $record)->assertDispatched('openModal', function ($event, $parameters) use ($action, $record) {
                return $parameters[0] === 'wrla.dev-tools.database-record-modal'
                    && $parameters[1] === ['connection' => 'browser_a', 'table' => 'main.records', 'record' => $record, 'readOnly' => $action === 'view']
                    && $parameters[2]['maxWidth'] === '5xl';
            });
        }
        Livewire::test(\WebRegulate\LaravelAdministration\Livewire\WireElementsModal::class)
            ->dispatch('openModal', 'wrla.dev-tools.database-record-modal', ['connection' => 'browser_a', 'table' => 'main.records', 'record' => $key, 'readOnly' => true], ['maxWidth' => '5xl'])
            ->assertDispatched('activeModalComponentChanged')->assertSee('View main.records')->assertSee('Original');
        config(['wr-laravel-administration.upsert.mode' => 'page']);
        Livewire::test(DatabaseTableBrowse::class, ['connection' => 'browser_a', 'table' => 'main.records'])
            ->assertDontSee('openRecord', false);
    }

    public function test_modal_view_can_switch_to_edit_without_navigation(): void
    {
        DB::connection('browser_a')->table('records')->insert(['uuid' => 'one', 'name' => 'Original']);
        $parameters = ['connection' => 'browser_a', 'table' => 'main.records', 'record' => base64_encode('one'), 'readOnly' => true];
        Livewire::test(DatabaseRecordModal::class, $parameters)->assertSee('View main.records')->assertSee('Original')
            ->assertDispatched('dev-tools.database-record-modal.opened');
        Livewire::test(DatabaseRecordUpsert::class, $parameters + ['inModal' => true])
            ->assertSet('readOnly', true)->assertDontSee('Browse')
            ->call('editRecord')->assertSet('readOnly', false)->assertSee('Save')->assertDontSee('Save record')
            ->assertSee('flex flex-wrap justify-center gap-4 mt-10', false)
            ->assertSee('fa fa-edit', false)->assertSee('fa fa-xmark', false);
        Livewire::test(DatabaseRecordUpsert::class, $parameters + ['inModal' => true])->call('save')->assertForbidden();
    }

    public function test_modal_save_refreshes_listing_and_respects_close_setting(): void
    {
        config(['wr-laravel-administration.upsert.modal.return_to_browse_after_save' => true]);
        Livewire::test(DatabaseRecordUpsert::class, ['connection' => 'browser_a', 'table' => 'main.records', 'inModal' => true])
            ->set('values.0', 'one')->set('values.1', 'Created')->call('save')
            ->assertHasNoErrors()->assertNoRedirect()->assertDispatched('closeModal')
            ->assertDispatched('database-record-saved', connection: 'browser_a', table: 'main.records');
        config(['wr-laravel-administration.upsert.modal.return_to_browse_after_save' => false]);
        Livewire::test(DatabaseRecordUpsert::class, ['connection' => 'browser_a', 'table' => 'main.records', 'inModal' => true])
            ->set('values.0', 'two')->set('values.1', 'Stays open')->call('save')
            ->assertHasNoErrors()->assertSet('recordId', 'two')->assertSet('values.2', 7)
            ->assertSet('useDefaults.2', false)->assertNotDispatched('closeModal')
            ->set('values.1', 'Edited')->call('save')->assertHasNoErrors();
        $this->assertSame(2, DB::connection('browser_a')->table('records')->count());
        $this->assertSame('Edited', DB::connection('browser_a')->table('records')->where('uuid', 'two')->value('name'));
    }

    public function test_keyless_and_composite_tables_are_browse_only(): void
    {
        $schema = DB::connection('browser_a')->getSchemaBuilder();
        $schema->create('keyless', fn ($table) => $table->string('name'));
        $schema->create('composite', function ($table) {
            $table->integer('left');
            $table->integer('right');
            $table->primary(['left', 'right']);
        });
        foreach (['main.keyless', 'main.composite'] as $table) {
            $this->assertNull((new ManageableModelDynamic('browser_a', $table))->primaryKey);
            Livewire::test(DatabaseTableBrowse::class, ['connection' => 'browser_a', 'table' => $table])
                ->assertSee('Read-only table')->assertDontSee('Create record');
            Livewire::test(DatabaseRecordUpsert::class, ['connection' => 'browser_a', 'table' => $table])->assertStatus(422);
        }
    }

    public function test_invalid_table_and_filter_are_rejected(): void
    {
        Livewire::test(DatabaseTableBrowse::class, ['connection' => 'browser_a', 'table' => 'records; DROP TABLE records'])->assertNotFound();
        Livewire::test(DatabaseTableBrowse::class, ['connection' => 'browser_a', 'table' => 'main.records'])
            ->call('filtersUpdatedOutside', [['field' => 'name` OR 1=1', 'operator' => '=', 'value' => 'test']])
            ->assertHasErrors('dynamicFilterInputs.0.field');
    }

    public function test_generated_columns_and_schema_validation(): void
    {
        DB::connection('browser_a')->getSchemaBuilder()->create('generated', function ($table) {
            $table->id();
            $table->integer('amount');
            $table->integer('doubled')->virtualAs('amount * 2');
            $table->json('data')->nullable();
            $table->binary('payload')->nullable();
        });
        $model = new ManageableModelDynamic('browser_a', 'main.generated');
        $this->assertTrue($model->isReadOnly($model->column('id')));
        $this->assertTrue($model->isReadOnly($model->column('doubled')));
        $this->assertTrue(Validator::make(['values' => [null, 'invalid', null, '{broken']], $model->validationRules([], true))->fails());
        $model->applyValues([999, 3, 999, '{}']);
        $model->model()->save();
        $this->assertSame(6, $model->query()->first()->doubled);
        DB::connection('browser_a')->table('generated')->where('id', $model->model()->getKey())->update(['payload' => "\xFF\x00\xFE"]);
        Livewire::test(DatabaseRecordUpsert::class, ['connection' => 'browser_a', 'table' => 'main.generated', 'record' => base64_encode((string) $model->model()->getKey())])
            ->assertSet('values.4', '[binary]')->assertSee('[binary]');
    }
}


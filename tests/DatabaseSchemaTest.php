<?php

namespace WebRegulate\LaravelAdministration\Tests;

use AlbertoArena\Truss\Http\Controllers\AssetController as TrussAssetController;
use AlbertoArena\Truss\Introspection\SchemaSerializer;
use AlbertoArena\Truss\Introspection\SnapshotBuilder as TrussSnapshotBuilder;
use Illuminate\Config\Repository;
use Illuminate\Contracts\Auth\Access\Gate as GateContract;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\Schema\MySqlBuilder;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Gate;
use Mockery;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use WebRegulate\LaravelAdministration\Classes\DatabaseSchema\AssetController;
use WebRegulate\LaravelAdministration\Classes\DatabaseSchema\SnapshotBuilder;
use WebRegulate\LaravelAdministration\WRLAServiceProvider;

class DatabaseSchemaTest extends TestCase
{
    private Application $app;

    protected function setUp(): void
    {
        parent::setUp();

        $path = getenv('WRLA_TEST_APP_PATH');
        if (!$path) {
            $this->markTestSkipped('Set WRLA_TEST_APP_PATH to a Laravel application with WRLA dependencies installed.');
        }

        $loader = require $path . '/vendor/autoload.php';
        $loader->addPsr4('WebRegulate\\LaravelAdministration\\', dirname(__DIR__) . '/src', true);
        $this->app = new Application($path);
        $this->app->instance('config', new Repository([
            'wr-laravel-administration' => ['database_schema_viewer' => ['enabled' => true]],
        ]));
        Facade::setFacadeApplication($this->app);
        DB::swap(Mockery::mock(DatabaseManager::class));
    }

    protected function tearDown(): void
    {
        Mockery::close();
        Facade::clearResolvedInstances();
        parent::tearDown();
    }

    public function test_mysql_metadata_is_batched_and_preserves_composite_keys(): void
    {
        $connection = Mockery::mock(MySqlConnection::class);
        $connection->shouldReceive('getDatabaseName')->once()->andReturn('tenant_database');
        $builder = Mockery::mock(MySqlBuilder::class);
        $builder->shouldReceive('getConnection')->once()->andReturn($connection);
        $tableNames = array_merge(
            ['portal_orders', 'portal_customers'],
            array_map(fn (int $number) => 'table_' . $number, range(1, 182)),
        );
        $builder->shouldReceive('getTables')->once()->with('tenant_database')->andReturn(
            array_map(fn (string $name) => ['name' => $name], $tableNames),
        );
        $connection->shouldReceive('getSchemaBuilder')->once()->andReturn($builder);
        DB::shouldReceive('connection')->once()->with('PDMS')->andReturn($connection);

        $metadata = [
            'COLUMNS' => [
                (object) ['table_name' => 'portal_orders', 'name' => 'customer_id', 'type' => 'bigint unsigned', 'is_nullable' => 'NO', 'column_default' => null],
                (object) ['table_name' => 'portal_orders', 'name' => 'site_id', 'type' => 'int', 'is_nullable' => 'NO', 'column_default' => 0],
                (object) ['table_name' => 'portal_customers', 'name' => 'name', 'type' => 'varchar(255)', 'is_nullable' => 'YES', 'column_default' => null],
                (object) ['table_name' => 'unlisted_view', 'name' => 'ignored', 'type' => 'int', 'is_nullable' => 'NO', 'column_default' => null],
            ],
            'STATISTICS' => [
                (object) ['table_name' => 'portal_orders', 'name' => 'PRIMARY', 'non_unique' => 0, 'column_name' => 'customer_id'],
                (object) ['table_name' => 'portal_orders', 'name' => 'PRIMARY', 'non_unique' => 0, 'column_name' => 'site_id'],
                (object) ['table_name' => 'portal_orders', 'name' => 'orders_customer', 'non_unique' => 1, 'column_name' => 'customer_id'],
                (object) ['table_name' => 'portal_orders', 'name' => 'orders_customer', 'non_unique' => 1, 'column_name' => 'site_id'],
                (object) ['table_name' => 'portal_customers', 'name' => 'Customers_Name', 'non_unique' => 0, 'column_name' => 'name'],
                (object) ['table_name' => 'portal_customers', 'name' => 'customers_expression', 'non_unique' => 0, 'column_name' => null],
            ],
            'KEY_COLUMN_USAGE' => [
                (object) ['table_name' => 'portal_orders', 'name' => 'orders_customer_fk', 'column_name' => 'customer_id', 'foreign_table' => 'portal_customers', 'foreign_column' => 'id', 'on_update' => 'CASCADE', 'on_delete' => 'RESTRICT'],
                (object) ['table_name' => 'portal_orders', 'name' => 'orders_customer_fk', 'column_name' => 'site_id', 'foreign_table' => 'portal_customers', 'foreign_column' => 'site_id', 'on_update' => 'CASCADE', 'on_delete' => 'RESTRICT'],
            ],
        ];

        foreach ($metadata as $source => $rows) {
            $connection->shouldReceive('selectFromWriteConnection')->once()->with(
                Mockery::on(fn (string $sql) => str_contains($sql, 'information_schema.' . $source)
                    && str_contains($sql, 'TABLE_SCHEMA = ?')),
                ['tenant_database'],
            )->andReturn($rows);
        }

        $tables = (new SchemaSerializer())->tables((new SnapshotBuilder())->introspect('PDMS'));

        $this->assertCount(184, $tables);
        $this->assertSame('portal_orders', $tables[0]['name']);
        $this->assertSame(['customer_id', 'site_id'], $tables[0]['primary_key']);
        $this->assertSame('bigint unsigned', $tables[0]['columns'][0]['type']);
        $this->assertFalse($tables[0]['columns'][0]['nullable']);
        $this->assertNull($tables[0]['columns'][0]['default']);
        $this->assertSame('0', $tables[0]['columns'][1]['default']);
        $this->assertSame(['customer_id', 'site_id'], $tables[0]['indexes'][0]['columns']);
        $this->assertFalse($tables[0]['indexes'][0]['unique']);
        $this->assertSame(['id', 'site_id'], $tables[0]['foreign_keys'][0]['references_columns']);
        $this->assertSame('portal_customers', $tables[0]['foreign_keys'][0]['references_table']);
        $this->assertSame('cascade', $tables[0]['foreign_keys'][0]['on_update']);
        $this->assertSame('restrict', $tables[0]['foreign_keys'][0]['on_delete']);
        $this->assertTrue($tables[1]['columns'][0]['nullable']);
        $this->assertTrue($tables[1]['indexes'][0]['unique']);
        $this->assertSame('customers_name', $tables[1]['indexes'][0]['name']);
        $this->assertSame([], $tables[1]['indexes'][1]['columns']);
        $this->assertSame([], $tables[1]['primary_key']);
        $this->assertSame([], $tables[1]['foreign_keys']);
    }

    public function test_empty_mysql_database_does_not_query_metadata(): void
    {
        $connection = Mockery::mock(MySqlConnection::class);
        $connection->shouldReceive('getDatabaseName')->once()->andReturn('empty_database');
        $connection->shouldNotReceive('selectFromWriteConnection');
        $builder = Mockery::mock(MySqlBuilder::class);
        $builder->shouldReceive('getConnection')->once()->andReturn($connection);
        $builder->shouldReceive('getTables')->once()->with('empty_database')->andReturn([]);
        $connection->shouldReceive('getSchemaBuilder')->once()->andReturn($builder);
        DB::shouldReceive('connection')->once()->with('mysql')->andReturn($connection);

        $this->assertSame([], (new SnapshotBuilder())->introspect('mysql'));
    }

    public function test_sqlite_uses_truss_introspection_and_restores_table_prefix(): void
    {
        $connection = new SQLiteConnection(new \PDO('sqlite::memory:'), ':memory:', 'portal_');
        $connection->statement('CREATE TABLE portal_customers (id INTEGER PRIMARY KEY, name TEXT NULL)');
        DB::shouldReceive('connection')->twice()->with('sqlite')->andReturn($connection);

        $tables = (new SchemaSerializer())->tables((new SnapshotBuilder())->introspect('sqlite'));

        $this->assertCount(1, $tables);
        $this->assertSame('portal_customers', $tables[0]['name']);
        $this->assertSame(['id'], $tables[0]['primary_key']);
        $this->assertSame(['id', 'name'], array_column($tables[0]['columns'], 'name'));
        $this->assertSame('portal_', $connection->getTablePrefix());
    }

    public function test_provider_binds_the_optimized_builder_without_changing_authorization(): void
    {
        $gate = Mockery::mock(GateContract::class);
        $gate->shouldReceive('define')->once()->with('viewTruss', Mockery::type('Closure'));
        Gate::swap($gate);
        $provider = new class($this->app) extends WRLAServiceProvider {
            public function configureTruss(): void
            {
                parent::configureTruss();
            }

            protected function applyWrlaThemeToTruss(): void {}
        };

        $provider->configureTruss();

        $this->assertInstanceOf(SnapshotBuilder::class, $this->app->make(TrussSnapshotBuilder::class));
        $this->assertInstanceOf(AssetController::class, $this->app->make(TrussAssetController::class));
        $this->assertTrue(config('truss.enabled'));
    }

    public function test_mermaid_asset_escapes_numeric_column_names_and_delegates_other_assets(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'wrla-schema-');
        try {
            file_put_contents($path, 'return `${type} ${column.name}${badge}`;');
            $original = new BinaryFileResponse($path, 200, [
                'Content-Type' => 'text/javascript',
                'Cache-Control' => 'private, max-age=86400',
            ]);
            $upstream = Mockery::mock(TrussAssetController::class);
            $upstream->shouldReceive('__invoke')->once()->with('mermaid-definition.js')->andReturn($original);
            $upstream->shouldReceive('__invoke')->once()->with('truss.js')->andReturn($original);
            $controller = new AssetController($upstream);

            $response = $controller('mermaid-definition.js');

            $this->assertSame(200, $response->getStatusCode());
            $this->assertSame('text/javascript', $response->headers->get('Content-Type'));
            $this->assertSame($original->headers->get('Cache-Control'), $response->headers->get('Cache-Control'));
            $this->assertSame(
                'return `${type} ${String(column.name).replace(/^(?=[0-9])/, "_")}${badge}`;',
                $response->getContent(),
            );
            $this->assertSame($original, $controller('truss.js'));
        } finally {
            unlink($path);
        }
    }
}
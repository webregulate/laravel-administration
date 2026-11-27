<?php

namespace WebRegulate\LaravelAdministration\Tests\Unit\Classes;

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WebRegulate\LaravelAdministration\Classes\OrderColumnUpdater;
use WebRegulate\LaravelAdministration\Classes\OrderableOptions;

class OrderColumnUpdaterTest extends TestCase
{
    private ?Connection $connection = null;

    public static function databases(): array
    {
        return [
            'sqlite' => ['sqlite'],
            'mysql' => ['mysql'],
        ];
    }

    protected function tearDown(): void
    {
        if ($this->connection !== null) {
            $this->connection->disconnect();
            $this->connection = null;
        }

        Model::unsetConnectionResolver();
        parent::tearDown();
    }

    #[DataProvider('databases')]
    public function test_reorder_updates_existing_rows_when_not_null_columns_have_no_default(string $driver): void
    {
        $connection = $this->connect($driver);
        $this->assertStrictModeWhenMysql($driver, $connection);
        $this->createPortfolios($connection);
        $connection->table('portfolios')->insert([
            ['id' => 2, 'url' => 'https://a.test/2', 'order' => 5],
            ['id' => 12, 'url' => 'https://a.test/12', 'order' => 9],
            ['id' => 7, 'url' => 'https://a.test/7', 'order' => 4],
        ]);

        try {
            $connection->table('portfolios')->upsert([
                ['id' => 2, 'order' => 1],
                ['id' => 12, 'order' => 2],
                ['id' => 7, 'order' => 3],
            ], ['id'], ['order']);
            $this->fail('Expected upsert to reject a NOT NULL column without a default.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('url', $exception->getMessage());
        }

        $this->assertSame([
            2 => ['url' => 'https://a.test/2', 'order' => 5],
            7 => ['url' => 'https://a.test/7', 'order' => 4],
            12 => ['url' => 'https://a.test/12', 'order' => 9],
        ], $this->portfolioRows($connection));

        $connection->flushQueryLog();
        $connection->enableQueryLog();

        $saved = (new OrderColumnUpdater)->persist(
            new ReorderPortfolio,
            OrderableOptions::modal('order')->startAt(1)->updateChunkSize(2),
            [12, 2, 7],
        );

        $this->assertTrue($saved);
        $this->assertSame([
            2 => ['url' => 'https://a.test/2', 'order' => 2],
            7 => ['url' => 'https://a.test/7', 'order' => 3],
            12 => ['url' => 'https://a.test/12', 'order' => 1],
        ], $this->portfolioRows($connection));
        $this->assertSame(3, $connection->table('portfolios')->count());
        $this->assertOrderWasUpdatedWithoutInsert($connection);
    }

    #[DataProvider('databases')]
    public function test_reorder_rejects_a_stale_id_list_without_writing(string $driver): void
    {
        $connection = $this->connect($driver);
        $this->createPortfolios($connection);
        $connection->table('portfolios')->insert([
            ['id' => 2, 'url' => 'https://a.test/2', 'order' => 5],
            ['id' => 12, 'url' => 'https://a.test/12', 'order' => 9],
            ['id' => 7, 'url' => 'https://a.test/7', 'order' => 4],
        ]);
        $options = OrderableOptions::modal('order')->startAt(1)->updateChunkSize(2);
        $updater = new OrderColumnUpdater;
        $before = $this->portfolioRows($connection);

        $connection->flushQueryLog();
        $connection->enableQueryLog();

        $this->assertFalse($updater->persist(new ReorderPortfolio, $options, [12, 2]));
        $this->assertFalse($updater->persist(new ReorderPortfolio, $options, [12, 2, 99]));
        $this->assertFalse($updater->persist(new ReorderPortfolio, $options, [12, 12, 2]));
        $this->assertFalse($updater->persist(new ReorderPortfolio, $options, [7, 12, 2, 7]));

        $this->assertSame($before, $this->portfolioRows($connection));
        foreach ($connection->getQueryLog() as $query) {
            $sql = strtolower(ltrim($query['query']));
            $this->assertFalse(str_starts_with($sql, 'update'), $query['query']);
            $this->assertFalse(str_starts_with($sql, 'insert'), $query['query']);
        }
    }

    #[DataProvider('databases')]
    public function test_reorder_updates_string_keys_on_a_prefixed_table(string $driver): void
    {
        $connection = $this->connect($driver, 'wrla_');
        $schema = $connection->getSchemaBuilder();
        $schema->dropIfExists('blocks');
        $schema->create('blocks', function (Blueprint $table): void {
            $table->string('uuid')->primary();
            $table->string('title');
            $table->unsignedInteger('sort_order');
        });
        $connection->table('blocks')->insert([
            ['uuid' => "o'reilly", 'title' => 'First', 'sort_order' => 4],
            ['uuid' => 'home', 'title' => 'Home', 'sort_order' => 8],
        ]);

        $saved = (new OrderColumnUpdater)->persist(
            new ReorderBlock,
            OrderableOptions::page('sort_order')->startAt(0)->updateChunkSize(1),
            ['home', "o'reilly"],
        );

        $this->assertTrue($saved);
        $this->assertSame([
            'home' => ['title' => 'Home', 'sort_order' => 0],
            "o'reilly" => ['title' => 'First', 'sort_order' => 1],
        ], $this->rowsBy($connection, 'blocks', 'uuid', ['title', 'sort_order']));
        $this->assertSame(2, $connection->table('blocks')->count());
        $this->assertTrue($schema->hasTable('blocks'));
    }

    private function connect(string $driver, string $prefix = ''): Connection
    {
        if ($driver === 'mysql' && ! $this->mysqlAvailable()) {
            $this->markTestSkipped('MySQL is not available at 127.0.0.1.');
        }

        $config = $driver === 'mysql'
            ? [
                'driver' => 'mysql',
                'host' => '127.0.0.1',
                'port' => 3306,
                'database' => 'wrla_reorder_test',
                'username' => 'wrla',
                'password' => 'wrla',
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                'prefix' => $prefix,
                'strict' => true,
            ]
            : [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => $prefix,
            ];

        $capsule = new Capsule;
        $capsule->addConnection($config);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        return $this->connection = $capsule->getConnection();
    }

    private function mysqlAvailable(): bool
    {
        if (! extension_loaded('pdo_mysql')) {
            return false;
        }

        try {
            new \PDO('mysql:host=127.0.0.1;port=3306', 'wrla', 'wrla', [\PDO::ATTR_TIMEOUT => 2]);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function assertStrictModeWhenMysql(string $driver, Connection $connection): void
    {
        if ($driver !== 'mysql') {
            return;
        }

        $mode = $connection->scalar('select @@session.sql_mode');
        $this->assertIsString($mode);
        $this->assertStringContainsString('STRICT_TRANS_TABLES', $mode);
    }

    private function createPortfolios(Connection $connection): void
    {
        $schema = $connection->getSchemaBuilder();
        $schema->dropIfExists('portfolios');
        $schema->create('portfolios', function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
            $table->string('url');
            $table->unsignedInteger('order');
        });
    }

    private function portfolioRows(Connection $connection): array
    {
        return $this->rowsBy($connection, 'portfolios', 'id', ['url', 'order']);
    }

    /**
     * @param  list<string>  $columns
     * @return array<int|string, array<string, mixed>>
     */
    private function rowsBy(Connection $connection, string $table, string $key, array $columns): array
    {
        $rows = [];

        foreach ($connection->table($table)->orderBy($key)->get() as $row) {
            $record = [];
            foreach ($columns as $column) {
                $record[$column] = $row->{$column};
            }
            $rows[$row->{$key}] = $record;
        }

        return $rows;
    }

    private function assertOrderWasUpdatedWithoutInsert(Connection $connection): void
    {
        $sql = array_map(
            fn (array $query): string => strtolower($query['query']),
            $connection->getQueryLog(),
        );

        $this->assertNotEmpty($sql);
        $this->assertTrue(
            array_reduce(
                $sql,
                fn (bool $found, string $query): bool => $found || str_starts_with(ltrim($query), 'update'),
                false,
            ),
            'Expected an UPDATE statement. Queries: '.implode(' | ', $sql),
        );
        foreach ($sql as $query) {
            $this->assertFalse(str_starts_with(ltrim($query), 'insert'), $query);
        }
    }
}

class ReorderPortfolio extends Model
{
    protected $table = 'portfolios';

    public $timestamps = false;

    protected $guarded = [];
}

class ReorderBlock extends Model
{
    protected $table = 'blocks';

    protected $primaryKey = 'uuid';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];
}

<?php

namespace WebRegulate\LaravelAdministration\Livewire\DevTools;

use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Throwable;
use WebRegulate\LaravelAdministration\Classes\WRLAHelper;
use WebRegulate\LaravelAdministration\Livewire\WRLAPageComponent;

#[Title('Database Browser')]
class DatabaseTables extends WRLAPageComponent
{
    #[Locked]
    public array $connections = [];

    #[Locked]
    public array $unavailableConnections = [];

    public string $connection = '';

    public string $search = '';

    public function boot(): void
    {
        abort_unless(WRLAHelper::userIsDev(), 403);
    }

    public function mount(): void
    {
        foreach (array_keys(config('database.connections', [])) as $name) {
            try {
                $this->connectionTables($name);
                $this->connections[] = $name;
            } catch (Throwable $exception) {
                $this->unavailableConnections[] = $name;
                report($exception);
            }
        }
        $default = config('database.default');
        $this->connection = in_array($default, $this->connections, true) ? $default : ($this->connections[0] ?? '');
    }

    public function render()
    {
        $tables = [];
        $connectionUnavailable = false;
        if ($this->connection !== '') {
            abort_unless(in_array($this->connection, $this->connections, true), 404);
            try {
                $tables = collect($this->connectionTables($this->connection))
                    ->filter(fn (array $table) => mb_stripos($table['schema_qualified_name'], $this->search) !== false)
                    ->sortBy('schema_qualified_name')->values()->all();
            } catch (Throwable $exception) {
                report($exception);
                $connectionUnavailable = true;
            }
        }

        return view(WRLAHelper::getViewPath('livewire.dev-tools.database-tables'), compact('tables', 'connectionUnavailable'));
    }

    protected function connectionTables(string $name): array
    {
        $connection = DB::connection($name);
        $schema = in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)
            ? $connection->getDatabaseName()
            : null;

        return $connection->getSchemaBuilder()->getTables($schema);
    }
}
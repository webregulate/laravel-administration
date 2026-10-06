<?php

namespace WebRegulate\LaravelAdministration\Livewire\DevTools;

use Livewire\Attributes\Locked;
use WebRegulate\LaravelAdministration\Classes\ManageableModelDynamic;
use WebRegulate\LaravelAdministration\Classes\WRLAHelper;
use WebRegulate\LaravelAdministration\Livewire\WRLAPageComponent;

abstract class DatabasePage extends WRLAPageComponent
{
    #[Locked]
    public string $connection = '';

    #[Locked]
    public string $table = '';

    protected ?ManageableModelDynamic $dynamicModel = null;

    public function boot(): void
    {
        abort_unless(WRLAHelper::databaseBrowserEnabled(), 403);
        if ($this->connection !== '') {
            abort_unless(WRLAHelper::databaseBrowserTableAllowed($this->connection, $this->table), 404);
        }
    }

    protected function model(): ManageableModelDynamic
    {
        abort_unless(WRLAHelper::databaseBrowserTableAllowed($this->connection, $this->table), 404);
        return $this->dynamicModel ??= new ManageableModelDynamic($this->connection, $this->table);
    }
}
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
        abort_unless(WRLAHelper::userIsDev(), 403);
    }

    protected function model(): ManageableModelDynamic
    {
        return $this->dynamicModel ??= new ManageableModelDynamic($this->connection, $this->table);
    }
}
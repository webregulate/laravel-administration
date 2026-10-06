<?php

namespace WebRegulate\LaravelAdministration\Livewire\DevTools;

use Livewire\Attributes\Locked;
use LivewireUI\Modal\ModalComponent;
use WebRegulate\LaravelAdministration\Classes\WRLAHelper;

class DatabaseRecordModal extends ModalComponent
{
    #[Locked]
    public string $connection;

    #[Locked]
    public string $table;

    #[Locked]
    public ?string $record = null;

    #[Locked]
    public bool $readOnly = false;

    public function boot(): void
    {
        abort_unless(WRLAHelper::databaseBrowserEnabled(), 403);
        if (isset($this->connection, $this->table)) {
            abort_unless(WRLAHelper::databaseBrowserTableAllowed($this->connection, $this->table), 404);
        }
    }

    public function mount(string $connection, string $table, ?string $record = null, bool $readOnly = false): void
    {
        abort_unless(WRLAHelper::databaseBrowserTableAllowed($connection, $table), 404);
        abort_unless(WRLAHelper::databaseBrowserCan($readOnly ? 'view' : ($record === null ? 'create' : 'edit'), $connection, $table), 403);
        $this->connection = $connection;
        $this->table = $table;
        $this->record = $record;
        $this->readOnly = $readOnly;
        $this->dispatch('dev-tools.database-record-modal.opened');
    }

    public function render()
    {
        abort_unless(WRLAHelper::databaseBrowserCan($this->readOnly ? 'view' : ($this->record === null ? 'create' : 'edit'), $this->connection, $this->table), 403);
        return view(WRLAHelper::getViewPath('livewire.dev-tools.database-record-modal'));
    }
}
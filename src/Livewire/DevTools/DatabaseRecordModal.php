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
        abort_unless(WRLAHelper::userIsDev(), 403);
    }

    public function mount(string $connection, string $table, ?string $record = null, bool $readOnly = false): void
    {
        $this->connection = $connection;
        $this->table = $table;
        $this->record = $record;
        $this->readOnly = $readOnly;
        $this->dispatch('dev-tools.database-record-modal.opened');
    }

    public function render()
    {
        return view(WRLAHelper::getViewPath('livewire.dev-tools.database-record-modal'));
    }
}
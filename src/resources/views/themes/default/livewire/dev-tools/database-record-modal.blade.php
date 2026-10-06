<x-wrla-modal-layout>
    @livewire('wrla.dev-tools.database-record-upsert', [
        'connection' => $connection,
        'table' => $table,
        'record' => $record,
        'readOnly' => $readOnly,
        'inModal' => true,
    ], key('database-record-'.$connection.'-'.$table.'-'.($record ?? 'new')))
</x-wrla-modal-layout>
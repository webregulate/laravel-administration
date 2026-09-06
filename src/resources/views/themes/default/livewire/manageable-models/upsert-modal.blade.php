<x-wrla-modal-layout>
    @livewire('wrla.manageable-models.upsert', [
        'modelUrlAlias' => $modelUrlAlias,
        'id' => $modelId,
        'duplicateFrom' => $duplicateFrom,
        'inModal' => true,
    ], key('wrla-upsert-modal-'.$modelUrlAlias.'-'.($modelId ?? 'new')))
</x-wrla-modal-layout>

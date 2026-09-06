<x-wrla-modal-layout :title="$title" :icon="$icon . ' mr-2'">
    {{-- Instance actions --}}
    <div class="flex justify-end gap-2 !text-sm">
        @foreach($manageableModel->getInstanceActionsFinal() as $key => $instanceAction)
            @continue($key == 'edit')
            {!! $instanceAction?->render() ?? '' !!}
        @endforeach
    </div>

    {{-- Upsert form / handler --}}
    @livewire('wrla.manageable-models.upsert', [
        'modelUrlAlias' => $modelUrlAlias,
        'id' => $modelId,
        'duplicateFrom' => $duplicateFrom,
        'inModal' => true,
    ], key('wrla-upsert-modal-'.$modelUrlAlias.'-'.($modelId ?? 'new')))
</x-wrla-modal-layout>

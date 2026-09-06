<x-wrla-modal-layout :title="$title" :icon="$icon">
    <x-slot:actions>
        <div class="relative top-[-2px] flex justify-end gap-2 !text-sm">
            @foreach($manageableModel->getInstanceActionsFinal() as $key => $instanceAction)
                @continue($key == 'edit')
                {!! $instanceAction?->render() ?? '' !!}
            @endforeach
        </div>
    </x-slot:actions>

    {{-- Upsert form / handler --}}
    @livewire('wrla.manageable-models.upsert', [
        'modelUrlAlias' => $modelUrlAlias,
        'id' => $modelId,
        'duplicateFrom' => $duplicateFrom,
        'inModal' => true,
    ], key('wrla-upsert-modal-'.$modelUrlAlias.'-'.($modelId ?? 'new')))
</x-wrla-modal-layout>

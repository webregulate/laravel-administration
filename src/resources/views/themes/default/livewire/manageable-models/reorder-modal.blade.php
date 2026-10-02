<x-wrla-modal-layout :title="$title" :icon="$icon">
    @livewire('wrla.manageable-models.reorder', [
        'modelUrlAlias' => $modelUrlAlias,
        'inModal' => true,
    ], key('wrla-reorder-modal-'.$modelUrlAlias))
</x-wrla-modal-layout>
@props([
    'title' => null,
    'icon' => null,
])

<div class="relative w-full h-full px-3 py-3 bg-white dark:bg-slate-900 dark:text-slate-200 rounded-lg overflow-hidden">
    <div class="absolute top-[6px] right-3">
        <button wire:click="$dispatch('closeModal')" class="text-gray-400 hover:text-gray-500 focus:outline-none focus:text-gray-500">
            <span class="sr-only">Close</span>
            <i class="fas fa-times"></i>
        </button>
    </div>

    {{-- Header --}}
    @if($title)
        <div class="flex items-center gap-3 pr-8 text-xl">
            <div class="flex items-center gap-3 min-w-0">
                <i class="{{ $icon }} text-gray-500"></i>
                <span class="relative">{{ $title }}</span>
            </div>
            @isset($actions)
                <div class="flex items-center gap-2 ml-auto !text-sm">
                    {{ $actions }}
                </div>
            @endisset
        </div>
        <hr class="border border-gray-300 mt-[6px] mb-3">
    @endif
    
    {{ $slot }}
</div>
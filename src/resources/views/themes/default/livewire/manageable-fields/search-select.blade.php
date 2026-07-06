<div
    x-data="{ open: false }"
    x-on:keydown.escape.window="open = false"
    wire:key="search-select-{{ $name }}"
    class="relative w-full flex flex-col"
>
    {{-- Hidden form element carrying the selected value on submit --}}
    <input type="hidden" name="{{ $name }}" value="{{ $selectedId }}" />

    {{-- Cancel button (clears the underlying value back to null) --}}
    @if($canCancel && !$disabled && $selectedId !== null && $selectedId !== '')
        <button
            type="button"
            wire:click="cancel"
            wire:target="cancel"
            class="absolute right-0 -top-6 flex items-center gap-1 text-xs font-medium text-slate-400 hover:text-rose-500 transition focus:outline-none"
            wire:loading.class.remove="hover:text-rose-500"
            wire:loading.class="cursor-default"
        >
            <i wire:loading.remove class="fas fa-times"></i>
            <i wire:loading wire:target="cancel" class="fas fa-circle-notch animate-spin inline-block"></i>
            Cancel
        </button>
    @endif

    <div class="relative w-full" x-on:click.outside="open = false">
        {{-- Display field --}}
        <button
            type="button"
            @disabled($disabled)
            x-on:click="if ($el.disabled) return; open = !open; if (open) { $wire.runSearch(); $nextTick(() => $refs.searchInput?.focus()) }"
            @class([
                'flex items-center justify-between gap-2 w-full px-3 py-1.5 text-base font-normal text-left rounded-md shadow-sm border border-slate-400 dark:border-slate-500 bg-slate-200 dark:bg-slate-900 text-slate-800 dark:text-slate-100 transition focus:outline-none focus:ring-1 focus:ring-primary-500',
                'opacity-70 cursor-not-allowed' => $disabled,
            ])
        >
            <span @class([
                'truncate',
                'text-slate-800 dark:text-slate-100' => $selectedLabel !== '',
                'text-slate-400 dark:text-slate-500' => $selectedLabel === '',
            ])>
                {{ $selectedLabel !== '' ? $selectedLabel : $placeholder }}
            </span>

            <span class="shrink-0 flex items-center gap-2 text-slate-400">
                {{-- Loading spinner --}}
                <i
                    wire:loading
                    wire:target="search, runSearch, select, clear"
                    class="fas fa-circle-notch animate-spin inline-block text-primary-500"
                ></i>
                {{-- Chevron --}}
                <i
                    class="fas fa-chevron-down text-xs transition-transform"
                    x-bind:class="open ? 'rotate-180' : ''"
                ></i>
            </span>
        </button>

        {{-- Popout --}}
        <div
            x-cloak
            x-show="open"
            x-transition.opacity.duration.150ms
            class="absolute left-0 right-0 top-full z-30 mt-1 bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-600 rounded-md shadow-xl overflow-hidden"
        >
            {{-- Search --}}
            <div class="flex items-center gap-2 px-3 py-2 border-b border-slate-200 dark:border-slate-700">
                <i class="fas fa-search text-xs text-slate-400"></i>
                <input
                    type="text"
                    x-ref="searchInput"
                    wire:model.live.debounce.300ms="search"
                    autocomplete="off"
                    spellcheck="false"
                    placeholder="{{ $searchPlaceholder }}"
                    class="w-full bg-transparent border-none p-0 text-sm text-slate-700 dark:text-slate-200 placeholder-slate-400 focus:ring-0 focus:outline-none"
                />
            </div>

            {{-- List --}}
            <div class="max-h-64 overflow-y-auto py-1">
                @forelse($results as $result)
                    <button
                        type="button"
                        wire:key="result-{{ $result['id'] }}"
                        wire:click="select('{{ $result['id'] }}')"
                        x-on:click="open = false"
                        @class([
                            'group w-full flex items-center gap-2 text-left px-3 py-2 hover:bg-primary-50 dark:hover:bg-slate-700 transition',
                            'bg-primary-50/60 dark:bg-slate-700/60 font-semibold' => $result['selected'],
                        ])
                    >
                        <i @class([
                            'fas fa-check text-primary-500 text-sm shrink-0',
                            'invisible' => !$result['selected'],
                        ])></i>
                        <span class="truncate text-slate-700 dark:text-slate-200 group-hover:text-primary-700 dark:group-hover:text-primary-300">{{ $result['label'] }}</span>
                    </button>
                @empty
                    <div wire:loading.remove wire:target="search, runSearch" class="px-3 py-4 text-center text-slate-400 text-sm">
                        <i class="fa-solid fa-search mr-1"></i> No matches found.
                    </div>
                    <div wire:loading wire:target="search, runSearch" class="px-3 py-4 text-center text-slate-400 text-sm">
                        <i class="fa-solid fa-circle-notch animate-spin inline-block mr-1"></i> Searching&hellip;
                    </div>
                @endforelse
            </div>
        </div>
    </div>
</div>

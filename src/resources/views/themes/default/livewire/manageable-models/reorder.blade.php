<div
    class="flex flex-col gap-4"
    x-data="{
        items: @js($items),
        query: '',
        draggedId: null,
        get visibleItems() {
            const query = this.query.trim().toLowerCase();
            return query === '' ? this.items : this.items.filter((item) => item.search.includes(query));
        },
        reorderVisible(orderedVisibleIds) {
            const visibleIds = new Set(this.visibleItems.map((item) => item.id));
            const itemsById = Object.fromEntries(this.items.map((item) => [item.id, item]));
            let visibleIndex = 0;

            this.items = this.items.map((item) => {
                return visibleIds.has(item.id) ? itemsById[orderedVisibleIds[visibleIndex++]] : item;
            });
        },
        move(id, direction) {
            const visibleIds = this.visibleItems.map((item) => item.id);
            const currentIndex = visibleIds.indexOf(id);
            const targetIndex = currentIndex + direction;

            if (currentIndex < 0 || targetIndex < 0 || targetIndex >= visibleIds.length) return;

            [visibleIds[currentIndex], visibleIds[targetIndex]] = [visibleIds[targetIndex], visibleIds[currentIndex]];
            this.reorderVisible(visibleIds);
        },
        moveBefore(id, targetId) {
            if (!id || id === targetId) return;

            const visibleIds = this.visibleItems.map((item) => item.id).filter((visibleId) => visibleId !== id);
            const targetIndex = visibleIds.indexOf(targetId);

            if (targetIndex < 0) return;

            visibleIds.splice(targetIndex, 0, id);
            this.reorderVisible(visibleIds);
        },
    }"
>
    @unless($inModal)
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="text-xl font-semibold">
                <i class="{{ $manageableModelClass::getIcon() }} mr-2"></i>
                Reorder {{ $manageableModelClass::getDisplayName(true) }}
            </div>

            @themeComponent('forms.button', [
                'href' => $manageableModelClass::urlBrowse(),
                'text' => 'Back to browse',
                'size' => 'small',
                'color' => 'secondary',
                'icon' => 'fa fa-arrow-left',
            ])
        </div>
    @endunless

    <div class="flex flex-wrap items-center justify-between gap-3 border-y border-slate-200 py-3 dark:border-slate-700">
        <div class="relative min-w-64 flex-1 max-w-xl">
            <i class="fas fa-search pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-slate-400"></i>
            <input
                type="search"
                x-model="query"
                placeholder="Search {{ strtolower($manageableModelClass::getDisplayName(true)) }}"
                class="w-full rounded-md border border-slate-400 bg-slate-50 py-2 pl-9 pr-9 shadow-sm focus:outline-none focus:ring-1 focus:ring-primary-500 dark:border-slate-600 dark:bg-slate-900"
            />
            <button
                type="button"
                x-show="query !== ''"
                x-on:click="query = ''"
                class="absolute right-2 top-1/2 h-7 w-7 -translate-y-1/2 text-slate-400 hover:text-slate-700 dark:hover:text-slate-200"
                title="Clear search"
                aria-label="Clear search"
            >
                <i class="fas fa-xmark"></i>
            </button>
        </div>

        <div class="text-sm text-slate-500">
            <span x-text="visibleItems.length"></span>
            <span x-show="visibleItems.length !== items.length">of <span x-text="items.length"></span></span>
            records
        </div>
    </div>

    <div class="overflow-hidden rounded-md border border-slate-300 dark:border-slate-700">
        <template x-for="item in visibleItems" :key="item.id">
            <div
                draggable="true"
                x-on:dragstart="draggedId = item.id; $event.dataTransfer.effectAllowed = 'move'"
                x-on:dragend="draggedId = null"
                x-on:dragover.prevent="$event.dataTransfer.dropEffect = 'move'"
                x-on:drop.prevent="moveBefore(draggedId, item.id); draggedId = null"
                class="group flex min-h-12 items-center gap-3 border-b border-slate-200 bg-slate-50 px-3 py-2 last:border-b-0 hover:bg-slate-100 dark:border-slate-700 dark:bg-slate-800 dark:hover:bg-slate-700"
                :class="draggedId === item.id ? 'opacity-40' : ''"
            >
                <button type="button" class="w-7 cursor-grab text-slate-400 active:cursor-grabbing" title="Drag to reorder" aria-label="Drag to reorder">
                    <i class="fas fa-grip-vertical"></i>
                </button>

                <span class="w-12 shrink-0 text-right font-mono text-xs text-slate-400" x-text="{{ $startAt }} + items.findIndex((candidate) => candidate.id === item.id)"></span>
                <span class="min-w-0 flex-1 truncate text-sm" x-text="item.label"></span>

                <div class="flex shrink-0 items-center gap-1">
                    <button
                        type="button"
                        x-on:click="move(item.id, -1)"
                        :disabled="visibleItems[0]?.id === item.id"
                        class="h-8 w-8 rounded text-slate-500 hover:bg-slate-200 disabled:cursor-not-allowed disabled:opacity-25 dark:hover:bg-slate-700"
                        title="Move up"
                        aria-label="Move up"
                    >
                        <i class="fas fa-arrow-up"></i>
                    </button>
                    <button
                        type="button"
                        x-on:click="move(item.id, 1)"
                        :disabled="visibleItems[visibleItems.length - 1]?.id === item.id"
                        class="h-8 w-8 rounded text-slate-500 hover:bg-slate-200 disabled:cursor-not-allowed disabled:opacity-25 dark:hover:bg-slate-700"
                        title="Move down"
                        aria-label="Move down"
                    >
                        <i class="fas fa-arrow-down"></i>
                    </button>
                </div>
            </div>
        </template>

        <div x-show="visibleItems.length === 0" class="p-8 text-center text-sm text-slate-500">
            No matching records
        </div>
    </div>

    <div class="sticky bottom-0 z-10 flex justify-center border-t border-slate-200 bg-slate-50/95 py-4 backdrop-blur dark:border-slate-700 dark:bg-slate-900/95">
        <button
            type="button"
            x-on:click="$wire.saveOrder(items.map((item) => item.id))"
            wire:loading.attr="disabled"
            wire:target="saveOrder"
            class="rounded-md bg-primary-600 px-5 py-2 text-sm font-medium text-white shadow hover:bg-primary-700 disabled:cursor-wait disabled:opacity-60"
        >
            <i wire:loading.remove wire:target="saveOrder" class="fas fa-save mr-2"></i>
            <i wire:loading wire:target="saveOrder" class="fas fa-spinner fa-spin mr-2"></i>
            Save order
        </button>
    </div>
</div>
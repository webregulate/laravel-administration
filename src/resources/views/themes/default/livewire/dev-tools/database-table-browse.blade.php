<div class="flex flex-col gap-4">
    <div class="flex flex-wrap justify-between items-center gap-3">
        <h1 class="text-xl font-semibold min-w-0 break-all"><i class="fa-solid fa-table mr-2"></i>{{ $table }}</h1>
        <div class="flex flex-wrap items-center gap-4 text-sm text-slate-500">
            <span>Total: <span data-wrla-browse-total="{{ $models->total() }}">{{ $models->total() }}</span> records</span>
            <label class="flex items-center gap-2">Per page
                <select wire:model.live="perPage" class="px-2 py-1 border border-slate-400 dark:border-slate-500 bg-slate-50 dark:bg-slate-900 rounded-md">
                    @foreach($pageSizes as $size)
                        <option value="{{ $size }}">{{ $size }}</option>
                    @endforeach
                </select>
            </label>
            <button wire:click="$refresh" type="button" title="Refresh" aria-label="Refresh" class="w-8 h-8 rounded-md hover:bg-slate-200 dark:hover:bg-slate-700"><i class="fas fa-sync-alt text-primary-500" wire:loading.class="animate-spin"></i></button>
        </div>
    </div>

    <div class="flex flex-wrap items-center gap-3">
        @themeComponent('forms.button', ['text' => 'Tables', 'icon' => 'fa fa-arrow-left', 'size' => 'small', 'color' => 'secondary', 'attributes' => Arr::toAttributeBag(['href' => route('wrla.database.tables')])])
        @if($model->primaryKey !== null && $canCreate)
            @themeComponent('forms.button', ['text' => 'Create record', 'icon' => 'fa fa-plus', 'size' => 'small', 'attributes' => Arr::toAttributeBag(array_filter(['href' => route('wrla.database.record.create', ['connection' => $connection, 'table' => $table]), 'x-on:click' => $useModals ? "if (!\$event.ctrlKey && !\$event.metaKey && !\$event.shiftKey && !\$event.altKey) { \$event.preventDefault(); window.wrlaOpenDatabaseRecordModal(\$el, () => \$wire.openRecord('create')); }" : null]))])
        @endif
        <span class="text-sm text-slate-500 break-all">{{ $connection }}</span>
    </div>
    @if($model->primaryKey === null)
        <div class="text-sm text-amber-700 dark:text-amber-400" role="status">Read-only table: a single-column primary key is required for record actions.</div>
    @endif

    <div class="w-full min-w-0 rounded-lg px-3 pt-2 pb-3 bg-slate-100 shadow-md dark:bg-slate-800">
        @livewire('wrla.manageable-models.dynamic-browse-filters', ['schemaColumns' => $model->columnNames(), 'enableAllFields' => $enableAllFields, 'defaultDynamicFilters' => $dynamicFilterInputs], key($connection.'.'.$table.'.filters'))
    </div>
    @error('dynamicFilterInputs') <div class="text-sm text-rose-600">{{ $message }}</div> @enderror

    @if($pendingDelete !== null && $canDelete)
        <div role="alert" class="flex flex-wrap items-center gap-3 border border-rose-300 dark:border-rose-700 rounded-md p-3 text-sm">
            <span class="break-all">Permanently delete record {{ $pendingDelete }}? This cannot be undone.</span>
            @themeComponent('forms.button', ['text' => 'Delete record', 'icon' => 'fa fa-trash', 'color' => 'danger', 'size' => 'small', 'attributes' => Arr::toAttributeBag(['wire:click' => 'deleteRecord', 'wire:loading.attr' => 'disabled'])])
            <button type="button" wire:click="$set('pendingDelete', null)" class="hover:underline">Cancel</button>
        </div>
    @endif

    <div class="w-full overflow-x-auto rounded-md shadow-lg shadow-slate-300 dark:shadow-slate-850">
        <table class="w-full table-auto text-left border-separate border-spacing-0">
            <thead><tr>
                @foreach($model->schemaColumns as $column)
                    <th class="px-3 py-2 bg-slate-700 text-slate-100 border-b border-slate-400 text-sm whitespace-nowrap">
                        <button type="button" wire:click="reOrderAction(@js($column['name']))" class="flex items-center gap-3" title="Sort {{ $column['name'] }}">
                            {{ $column['name'] }} <i class="fas {{ $orderBy === $column['name'] ? ($orderDirection === 'asc' ? 'fa-sort-up' : 'fa-sort-down') : 'fa-sort' }}"></i>
                        </button>
                    </th>
                @endforeach
                @if($model->primaryKey !== null)<th class="sticky right-0 z-10 shadow-[-6px_0_8px_-4px_rgba(0,0,0,0.25)] dark:shadow-[-6px_0_8px_-4px_rgba(0,0,0,0.5)] px-3 py-2 bg-slate-700 text-slate-100 text-sm"></th>@endif
            </tr></thead>
            <tbody>
                @forelse($models as $record)
                    <tr class="bg-white dark:bg-slate-900 odd:bg-slate-100 dark:odd:bg-slate-800" wire:key="record-{{ $loop->index }}-{{ $record->getKey() }}">
                        @foreach($model->schemaColumns as $column)
                            @php
                                $value = $record->getRawOriginal($column['name']);
                                $binary = preg_match('/blob|binary|bytea|geometry|geography/i', $column['type_name']);
                            @endphp
                            <td class="px-3 py-2 text-sm max-w-80">
                                @if($value === null)<span class="italic text-slate-400">NULL</span>
                                @elseif($binary)<span class="text-slate-400">[binary]</span>
                                @else<div class="truncate" title="{{ \Illuminate\Support\Str::limit((string) $value, $tooltipMaxLength) }}">{{ \Illuminate\Support\Str::limit((string) $value, $valueMaxLength) }}</div>@endif
                            </td>
                        @endforeach
                        @if($model->primaryKey !== null)
                            <td class="sticky right-0 z-10 shadow-[-6px_0_8px_-4px_rgba(0,0,0,0.25)] dark:shadow-[-6px_0_8px_-4px_rgba(0,0,0,0.5)] px-3 py-2" style="background-color: inherit;">
                                <div class="flex justify-end gap-2">
                                    @foreach($canEdit ? ['view' => 'eye', 'edit' => 'pen'] : ['view' => 'eye'] as $action => $icon)
                                        <a class="inline-flex w-8 h-8 items-center justify-center rounded-md text-primary-600 hover:bg-slate-200 dark:hover:bg-slate-700" href="{{ $model->recordUrl($action, $record->getKey()) }}" @if($useModals) x-on:click="if (!$event.ctrlKey && !$event.metaKey && !$event.shiftKey && !$event.altKey) { $event.preventDefault(); window.wrlaOpenDatabaseRecordModal($el, () => $wire.openRecord(@js($action), @js(rtrim(strtr(base64_encode((string) $record->getKey()), '+/', '-_'), '=')))); }" @endif title="{{ ucfirst($action) }} record" aria-label="{{ ucfirst($action) }} record"><i class="fa fa-{{ $icon }}"></i></a>
                                    @endforeach
                                    @if($canDelete)
                                        <button type="button" class="inline-flex w-8 h-8 items-center justify-center rounded-md text-rose-600 hover:bg-slate-200 dark:hover:bg-slate-700" wire:click="$set('pendingDelete', @js((string) $record->getKey()))" title="Delete record" aria-label="Delete record"><i class="fa fa-trash"></i></button>
                                    @endif
                                </div>
                            </td>
                        @endif
                    </tr>
                @empty
                    <tr><td colspan="{{ count($model->schemaColumns) + 1 }}" class="px-3 py-8 text-center text-sm text-slate-500">No records found.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    {{ $models->links() }}
</div>
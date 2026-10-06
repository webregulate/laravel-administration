<div class="flex flex-col gap-4">
    <div class="flex flex-wrap justify-between items-center gap-3">
        <h1 class="text-xl font-semibold"><i class="fa-solid fa-database mr-2"></i>Database Browser</h1>
        <span class="text-sm text-slate-500">{{ count($tables) }} tables</span>
    </div>

    <div class="flex flex-col md:flex-row gap-4 rounded-lg px-3 pt-2 pb-3 bg-slate-100 shadow-md dark:bg-slate-800">
        @if(count($connections) > 1)
            @themeComponent('forms.input-select', [
                'label' => 'Connection',
                'items' => array_combine($connections, $connections),
                'attributes' => Arr::toAttributeBag([
                    'wire:model.live.change' => 'connection',
                    'value' => $connection,
                    'name' => 'wrlaDatabaseConnection',
                    'id' => 'wrlaDatabaseConnection',
                    'wire:loading.attr' => 'disabled',
                    'wire:target' => 'connection'
                ]),
            ])
        @elseif($connection !== '')
            <span class="text-sm text-slate-500 py-2">{{ $connection }}</span>
        @endif
        @themeComponent('forms.input-text', [
            'label' => 'Table search',
            'attributes' => Arr::toAttributeBag([
                'wire:model.live.debounce.300ms' => 'search',
                'name' => 'wrlaDatabaseTableSearch',
                'id' => 'wrlaDatabaseTableSearch',
                'placeholder' => 'Search tables...',
                'type' => 'search',
                'autofocus' => 'true',
            ]),
        ])
    </div>

    @if(count($unavailableConnections))
        <div class="text-sm text-amber-700 dark:text-amber-400" role="status">
            Unavailable connections: {{ implode(', ', $unavailableConnections) }}
        </div>
    @endif
    @if($connectionUnavailable)
        <div class="text-sm text-rose-600" role="alert">This connection is currently unavailable.</div>
    @endif

    <div role="status" aria-live="polite" class="flex items-center gap-2 w-full rounded-lg px-3 py-2 bg-slate-100 shadow-md dark:bg-slate-800" wire:loading.flex wire:target="connection,search">
        <i class="fas fa-spinner animate-spin text-slate-500 dark:text-slate-300" aria-hidden="true"></i>
        <span class="text-sm text-slate-600 dark:text-slate-300">Please wait...</span>
    </div>

    <div wire:key="database-tables-{{ $connection }}" wire:loading.class="opacity-50 pointer-events-none" wire:target="connection,search" class="w-full mb-8 overflow-x-auto rounded-md shadow-lg shadow-slate-300 dark:shadow-slate-850">
        <table class="w-full table-auto text-left border-separate border-spacing-0">
            <thead><tr class="bg-slate-700 text-slate-100 text-sm">
                <th class="px-3 py-2">Table</th>
                <th class="px-3 py-2">Size</th>
                <th class="sticky right-0 z-10 shadow-[-6px_0_8px_-4px_rgba(0,0,0,0.25)] dark:shadow-[-6px_0_8px_-4px_rgba(0,0,0,0.5)] px-3 py-2 bg-slate-700"></th>
            </tr></thead>
            <tbody>
                @forelse($tables as $table)
                    <tr wire:key="database-table-{{ $connection }}-{{ $table['schema_qualified_name'] }}" class="odd:bg-slate-100 dark:odd:bg-slate-800 text-sm">
                        <td class="px-3 py-2 break-all">
                            <a class="hover:underline text-primary-600 font-semibold" href="{{ route('wrla.database.table', ['connection' => $connection, 'table' => $table['schema_qualified_name']]) }}">{{ $table['schema_qualified_name'] }}</a>
                        </td>
                        <td class="px-3 py-2 whitespace-nowrap">{{ $table['size'] === null ? '-' : number_format($table['size'] / 1024, 1).' KB' }}</td>
                        <td class="sticky right-0 z-10 shadow-[-6px_0_8px_-4px_rgba(0,0,0,0.25)] dark:shadow-[-6px_0_8px_-4px_rgba(0,0,0,0.5)] px-3 py-2 text-right {{ $loop->odd ? 'bg-slate-100 dark:bg-slate-800' : 'bg-white dark:bg-slate-900' }}">
                            <a href="{{ route('wrla.database.table', ['connection' => $connection, 'table' => $table['schema_qualified_name']]) }}" title="Browse {{ $table['schema_qualified_name'] }}" aria-label="Browse {{ $table['schema_qualified_name'] }}" class="inline-flex w-8 h-8 items-center justify-center text-primary-600 hover:bg-slate-200 dark:hover:bg-slate-700 rounded-md"><i class="fa-solid fa-arrow-right"></i></a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="3" class="px-3 py-8 text-center text-sm text-slate-500">{{ count($connections) ? 'No matching tables.' : 'No database connections are available.' }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
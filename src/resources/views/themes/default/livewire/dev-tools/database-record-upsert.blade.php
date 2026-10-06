<div class="flex flex-col gap-4">
    <div class="flex flex-wrap items-center justify-between gap-3 {{ $inModal ? 'pr-8' : '' }}">
        <h1 class="text-xl font-semibold min-w-0 break-all"><i class="fa-solid {{ $readOnly ? 'fa-eye' : 'fa-pen' }} mr-2"></i>{{ $readOnly ? 'View' : ($recordId === null ? 'Create' : 'Edit') }} {{ $table }}</h1>
        @if(!$inModal)
            @themeComponent('forms.button', ['text' => 'Browse', 'icon' => 'fa fa-arrow-left', 'size' => 'small', 'color' => 'secondary', 'attributes' => Arr::toAttributeBag(['href' => route('wrla.database.table', ['connection' => $connection, 'table' => $table])])])
        @endif
    </div>
    <div class="flex flex-wrap gap-3 text-sm text-slate-500">
        <span>{{ $connection }}</span>
        @if($recordId !== null)<span class="break-all">{{ $model->primaryKey }}: {{ $recordId }}</span>@endif
    </div>
    @error('database')<div role="alert" class="text-sm text-rose-600">{{ $message }}</div>@enderror
    <form wire:submit="save" @class(['flex flex-col gap-4', 'p-2' => $inModal, 'p-4 bg-slate-100 dark:bg-slate-800 dark:border-slate-700 border shadow-slate-300 dark:shadow-slate-850 rounded-lg shadow-lg' => !$inModal])>
        @foreach($model->schemaColumns as $index => $column)
            @php
                $value = $model->model()->getRawOriginal($column['name']);
                $binary = preg_match('/blob|binary|bytea|geometry|geography/i', $column['type_name']);
                $disabled = $readOnly || $model->isReadOnly($column) || ($nullValues[$index] ?? false) || ($recordId === null && ($useDefaults[$index] ?? false));
                $field = $manageableFields[$column['name']];
                $field->setAttribute('disabled', $disabled);
                $field->setAttribute('value', $binary ? '[binary]' : ($values[$index] ?? ''));
            @endphp
            <div wire:key="database-field-{{ $index }}" class="flex flex-col gap-1 min-w-0">
                @if($readOnly)
                    <label class="text-sm font-medium">{{ $column['name'] }}</label>
                    <pre class="text-sm whitespace-pre-wrap break-all p-2 border border-slate-300 dark:border-slate-600 rounded-md max-h-96 overflow-auto">{{ $value === null ? 'NULL' : ($binary ? '[binary]' : $value) }}</pre>
                @else
                    {!! $field->render() !!}
                @endif
                <div class="flex flex-wrap items-center gap-3 text-xs text-slate-500">
                    <span class="break-all">{{ $column['type'] }}</span>
                    @if(!$readOnly && !$model->isReadOnly($column))
                        @if($column['nullable'])<label class="flex items-center gap-1"><input type="checkbox" wire:model.live="nullValues.{{ $index }}">NULL</label>@endif
                        @if($recordId === null && $column['default'] !== null)<label class="flex items-center gap-1"><input type="checkbox" wire:model.live="useDefaults.{{ $index }}">Use database default</label>@endif
                    @endif
                    @if($model->isReadOnly($column))<span>Read-only</span>@endif
                </div>
                @error('values.'.$index)<div class="text-sm text-rose-600">{{ $message }}</div>@enderror
            </div>
        @endforeach
        @if(!$readOnly)
            <div class="flex flex-wrap justify-center gap-4 mt-10">
                @themeComponent('forms.button', ['text' => 'Save', 'size' => 'medium', 'color' => 'primary', 'icon' => 'fa fa-edit', 'type' => 'submit', 'attributes' => Arr::toAttributeBag(['wire:loading.attr' => 'disabled'])])
                @themeComponent('forms.button', ['text' => 'Cancel', 'size' => 'medium', 'color' => 'secondary', 'icon' => 'fa fa-xmark', 'attributes' => Arr::toAttributeBag($inModal ? ['type' => 'button', 'wire:click.prevent.stop' => "\$dispatch('closeModal')"] : ['href' => route('wrla.database.table', ['connection' => $connection, 'table' => $table])])])
            </div>
        @elseif($recordId !== null && $canEdit)
            {{-- Nothing --}}
        @endif
    </form>
</div>
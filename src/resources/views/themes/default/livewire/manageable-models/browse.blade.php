<?php
    use WebRegulate\LaravelAdministration\Enums\AdditionalRenderPosition;
?>

{{-- Livewire browse models, a very modern style browse system, includes a search filter and a table with the models data. --}}
<div class="flex flex-col gap-4">

    {{-- Browse additional rendering --}}
    {!! $manageableModelClass::renderAdditionalRender(AdditionalRenderPosition::BROWSE_TOP) !!}

    {{-- Title and total records --}}

    <div class="flex justify-between items-center">
        <div class="text-xl font-semibold mb-2">
            <i class="{{ $manageableModelClass::getIcon() }} mr-2"></i>
            {{ $manageableModelClass::getDisplayName(true) }}
        </div>
        @php
            $wrlaBrowseTotal = $models instanceof \Illuminate\Pagination\LengthAwarePaginator ? $models?->total() : $models->count();
            $wrlaPerPageOptions = config('wr-laravel-administration.browse.pagination.perPage', [20, 30, 50, 75, 100]);
        @endphp
        <div class="flex items-center gap-4">
            <div wire:loading.flex class="items-center gap-2 text-sm">
                <i class="fas fa-spinner animate-spin text-primary-500"></i>
                <span>Loading...</span>
            </div>
            <div class="text-sm text-slate-500">
                Total:
                <span data-wrla-browse-total="{{ $wrlaBrowseTotal }}">{{ $wrlaBrowseTotal }}</span>
                records
            </div>
            <div class="flex items-center gap-2 text-sm text-slate-500">
                <label for="wrlaPerPage" class="whitespace-nowrap">Per page</label>
                <select id="wrlaPerPage" wire:model.live="perPage"
                    class="min-w-12 px-2 py-1 border border-slate-400 dark:border-slate-500 bg-slate-50 dark:bg-slate-900
                        focus:outline-none focus:ring-1 focus:ring-primary-500 dark:focus:ring-primary-500 rounded-md shadow-sm">
                    @foreach ($wrlaPerPageOptions as $wrlaPerPageOption)
                        <option value="{{ $wrlaPerPageOption }}">{{ $wrlaPerPageOption }}</option>
                    @endforeach
                </select>
            </div>
        </div>
    </div>

    @if ($successMessage)
        @themeComponent('alert', ['type' => 'success', 'message' => $successMessage])
    @elseif($errorMessage)
        @themeComponent('alert', ['type' => 'error', 'message' => $errorMessage])
    @endif

    {{-- Browse additional rendering --}}
    {!! $manageableModelClass::renderAdditionalRender(AdditionalRenderPosition::BROWSE_BELOW_HEADING) !!}

    {{-- Browse actions --}}
    <div class="flex flex-row gap-3">
        @foreach ($manageableModelClass::getBrowseActions() as $browseAction)
            {!! !is_string($browseAction) ? $browseAction->render() : $browseAction !!}
        @endforeach
    </div>

    {{-- Browse additional rendering --}}
    {!! $manageableModelClass::renderAdditionalRender(AdditionalRenderPosition::BROWSE_BELOW_ACTIONS) !!}

    {{-- Filters --}}
    <div class="relative w-full rounded-lg px-3 pt-2 pb-3 mb-2 bg-slate-100 shadow-md dark:bg-slate-800">
        <div class="flex flex-wrap justify-start items-stretch gap-x-4 gap-y-2">

            @if(!$manageableModelClass::getStaticOption($manageableModelClass, 'browse.useDynamicFilters'))
                @foreach ($manageableModelClass::getBrowseFilters() as $filter)
                    {!! $filter->render($filters) !!}
                @endforeach
            @else
                @livewire(
                    'wrla.manageable-models.dynamic-browse-filters',
                    [
                        'manageableModelClass' => $manageableModelClass,
                    ]
                )
            @endif

        </div>

        {{-- Reset filters button (just text) --}}
        @if ($hasFilters && !$manageableModelClass::getStaticOption($manageableModelClass, 'browse.useDynamicFilters'))
            <div class="absolute bottom-[-16px] right-0 px-2 pt-1 pb-1 bg-slate-100 shadow-md dark:bg-slate-800 text-xs rounded-b-lg">
                <button class="space-x-1 hover:underline text-slate-500 dark:text-slate-300" wire:click="resetFiltersAction">
                    <i wire:loading.remove wire:target="resetFiltersAction" class="fas fa-eraser"></i>
                    <i wire:loading wire:target="resetFiltersAction" class="fas fa-spinner animate-spin"></i>
                    Reset filters
                </button>
            </div>
        @endif
    </div>

    {{-- Browse additional rendering --}}
    {!! $manageableModelClass::renderAdditionalRender(AdditionalRenderPosition::BROWSE_BELOW_FILTERS) !!}

    @php
        $browseColumns = $manageableModelClass::make()->getBrowseColumnsFinal();
        $wrlaMultiSelectEnabled = $manageableModelClass::getMultiSelectEnabled();
        $wrlaMultiActionsModel = $wrlaMultiSelectEnabled ? collect($models->items())->first() : null;
        $wrlaMultiActionsManageableModel = $wrlaMultiSelectEnabled && $wrlaMultiActionsModel
            ? $manageableModelClass::make($wrlaMultiActionsModel)
            : null;
        $wrlaSelectedIdsForActions = array_map('strval', $wrlaSelectedIds);
        $wrlaMultiActions = $wrlaMultiActionsManageableModel
            ? $wrlaMultiActionsManageableModel->getMultiInstanceActionsFinal()
                ->filter(fn ($wrlaMultiAction) => $wrlaMultiAction->shouldShowForSelection($wrlaSelectedIdsForActions))
                ->values()
            : collect();
        $wrlaPageIds = $wrlaMultiSelectEnabled
            ? collect($models->items())->map(fn ($model) => (string) $model->getKey())->all()
            : [];
        $wrlaAllPageSelected = !empty($wrlaPageIds) && empty(array_diff($wrlaPageIds, array_map('strval', $wrlaSelectedIds)));
    @endphp

    {{-- Multi selection action toolbar --}}
    @if ($wrlaMultiSelectEnabled && $wrlaMultiActions->isNotEmpty())
        <div x-data="{ wrlaSelectedIds: @entangle('wrlaSelectedIds').live }" x-show="wrlaSelectedIds.length > 0" x-cloak class="w-full">
            <div class="flex flex-row flex-wrap justify-between items-center gap-3 w-full rounded-lg px-3 py-2 bg-slate-100 shadow-md dark:bg-slate-800" wire:loading.remove wire:target="wrlaSelectedIds,callMultiInstanceAction">
                {{-- Left --}}
                <div>
                    <button type="button" class="ml-auto text-sm text-slate-500 dark:text-slate-300 hover:underline" wire:click="$set('wrlaSelectedIds', [])">
                        <i class="fas fa-times mr-1"></i>
                        Clear multi selection
                    </button>
                </div>

                {{-- Right --}}
                <div class="flex flex-row flex-wrap items-center gap-2">
                    <span class="mr-1 text-sm text-slate-600 dark:text-slate-300">
                        {{ count($wrlaSelectedIds) }} selected
                    </span>
                    @foreach ($wrlaMultiActions as $wrlaMultiAction)
                        {!! $wrlaMultiAction->renderMultiActionButton() !!}
                    @endforeach
                </div>
            </div>

            {{-- Loading state --}}
            <div class="flex flex-row flex-wrap items-center gap-2 w-full rounded-lg px-3 py-2 bg-slate-100 shadow-md dark:bg-slate-800" wire:loading.flex wire:target="wrlaSelectedIds,callMultiInstanceAction">
                <i class="fas fa-spinner animate-spin text-slate-500 dark:text-slate-300"></i>
                <span class="text-sm text-slate-600 dark:text-slate-300">Please wait...</span>
            </div>
        </div>
    @endif


    {{-- Main data table --}}
    <div class="w-full block overflow-x-auto rounded-md shadow-lg shadow-slate-300 dark:shadow-slate-850">
        <table class="w-full table-auto text-left border-collapse" style="table-layout: auto /* fixed */;">
            <colgroup>
                @if ($wrlaMultiSelectEnabled)
                    <col style="width: 44px; min-width: 44px; max-width: 44px;" />
                @endif
                @foreach ($browseColumns as $column => $browseColumn)
                    @if($browseColumn === null)
                        <col style="width: auto;" />
                    @else
                        @php
                            $width = $browseColumn->getOption('width') ?? 'auto';
                            $width = (is_int($width) ? "{$width}px" : $width);
                            $minWidth = $browseColumn->getOption('minWidth') ?? 110;
                            $minWidth = $minWidth < $width ? $width : $minWidth;
                            $minWidth = (is_int($minWidth) ? "{$minWidth}px" : $minWidth);
                            $maxWidth = $browseColumn->getOption('maxWidth') ?? 'none';
                            $maxWidth = (is_int($maxWidth) ? "{$maxWidth}px" : $maxWidth);
                        @endphp
                        <col style="width: {{ $width }}; min-width: {{ $minWidth }}; max-width: {{ $maxWidth }};" />
                    @endif
                @endforeach
                <col style="width: auto;" />
            </colgroup>
            <thead>
                <tr>
                    @if ($wrlaMultiSelectEnabled)
                        <th class="px-3 py-2 bg-slate-700 dark:bg-slate-700 border-b border-slate-400 dark:border-slate-600" scope="col">
                            <div
                                class="flex items-center justify-center"
                                x-data="{ wrlaSelectedIds: @entangle('wrlaSelectedIds').live, wrlaPageIds: @js($wrlaPageIds) }"
                            >
                                <input
                                    type="checkbox"
                                    title="Select all on this page"
                                    class="w-4 h-4 cursor-pointer accent-primary-600"
                                    @checked($wrlaAllPageSelected)
                                    x-on:change="$wire.setSelectAllOnPage(@js($wrlaPageIds), $event.target.checked)"
                                    x-bind:checked="wrlaPageIds.length > 0 && wrlaPageIds.every((id) => wrlaSelectedIds.map(String).includes(String(id)))"
                                />
                            </div>
                        </th>
                    @endif
                    @foreach ($browseColumns as $column => $browseColumn)
                        @continue($browseColumn === null)
                        <th @if ($browseColumn->getOption('allowOrdering'))
                                title="Order by {{ $column }} {{ $orderDirection == 'asc' ? 'descending' : 'ascending' }}"
                            @endif
                            class="px-3 py-2 bg-slate-700 dark:bg-slate-700 text-slate-100 dark:text-slate-300 border-b border-slate-400 dark:border-slate-600 @if ($browseColumn->getOption('allowOrdering')) group hover:text-primary-500 @endif @if ($orderBy == $column) text-primary-500 @endif"
                            scope="col"
                        >
                            @php $wrlaHeaderClass = $browseColumn->getOption('headerClass') ?: 'justify-start'; @endphp
                            <div class="w-full text-ellipsis truncate text-sm font-bold">
                                @if ($browseColumn->getOption('allowOrdering'))
                                    <button class="flex items-center gap-3 w-full text-ellipsis truncate {{ $wrlaHeaderClass }}"
                                        wire:click="reOrderAction('{{ $column }}', '{{ $orderDirection == 'asc' ? 'desc' : 'asc' }}')">
                                        {{ $browseColumn->renderDisplayName() }}
                                        @if ($orderBy == $column)
                                            <i class="relative fas fa-sort-{{ $orderDirection == 'asc' ? 'up' : 'down' }} text-primary-500"
                                                style="{{ $orderDirection == 'asc' ? 'top: 3px;' : 'top: -3px;' }}"></i>
                                        @else
                                            <i class="fas fa-sort text-slate-400 group-hover:text-primary-500 dark:group-hover:text-slate-700"
                                                title="Order ascending"></i>
                                        @endif
                                    </button>
                                @else
                                    <div class="flex items-center gap-3 w-full {{ $wrlaHeaderClass }}">
                                        {{ $browseColumn->renderDisplayName() }}
                                    </div>
                                @endif
                            </div>
                        </th>
                    @endforeach
                    <th class="sticky right-0 z-[4] px-3 py-2 bg-slate-700 dark:bg-slate-700 border-b border-slate-400 dark:border-slate-600"></th>
                </tr>
            </thead>
            <tbody>
                @foreach ($models as $k => $model)
                    @php
                        $manageableModel = $manageableModelClass::make($model);
                    @endphp
                    <tr class="odd:bg-slate-100 dark:odd:bg-slate-800">
                        @if ($wrlaMultiSelectEnabled)
                            <td class="px-3 py-2 bg-inherit text-sm">
                                <div class="flex items-center justify-center">
                                    <input
                                        type="checkbox"
                                        class="w-4 h-4 cursor-pointer accent-primary-600"
                                        value="{{ $model->getKey() }}"
                                        wire:model.live="wrlaSelectedIds"
                                    />
                                </div>
                            </td>
                        @endif
                        @foreach ($manageableModel->getBrowseColumnsFinal() as $column => $browseColumn)
                            @continue($browseColumn === null)
                            @php
                                $isHTML = $browseColumn->getOption('renderHtml') ?? false;
                                $value = $browseColumn->renderValue($model, $column);
                                $value = !$isHTML ? str($value)->limit(300) : $value;
                                $wrlaColumnClass = $browseColumn->getOption('columnClass') ?: 'justify-start';
                                $wrlaTextAlign = str_contains($wrlaColumnClass, 'justify-center')
                                    ? 'text-center'
                                    : (str_contains($wrlaColumnClass, 'justify-end') ? 'text-right' : 'text-left');
                            @endphp
                            <td class="px-3 py-2 bg-inherit text-sm">
                                <div class="relative flex w-full @if(!$isHTML) h-[22px] @endif items-center overflow-hidden {{ $wrlaColumnClass }}">
                                    @if(!$isHTML)
                                        <div style="color: transparent;">{!! $value !!}</div>
                                        <div class="absolute top-0 left-0 w-full h-full whitespace-nowrap overflow-ellipsis truncate {{ $wrlaTextAlign }}">
                                            {!! $value !!}
                                        </div>
                                    @else
                                        {!! $value !!}
                                    @endif
                                </div>
                            </td>
                        @endforeach
                        <td class="px-3 py-2 sticky right-0 z-[4] @if($k % 2 == 0) bg-slate-100 dark:bg-slate-800 @else bg-slate-200 dark:bg-slate-900 @endif">
                            <div class="flex justify-end gap-2 text-sm">
                                @foreach ($manageableModel->getInstanceActionsFinal() as $instanceAction)
                                    {!! $instanceAction?->render() ?? '' !!}
                                @endforeach
                            </div>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    {{-- If empty, show message and link to create new model --}}
    @if ($models->isEmpty())
        <div class="flex flex-row gap-4 justify-center items-center mt-6 text-slate-700 dark:text-slate-300">
            @if (!$hasFilters)
                <span>No records exist in this table</span>
            @else
                <span>No records found with the current filters</span>
            @endif

            {{-- Check has create permissions --}}
            @if ($manageableModelClass::getPermission(\WebRegulate\LaravelAdministration\Enums\ManageableModelPermissions::CREATE))
                @themeComponent('forms.button', [
                    'href' => route('wrla.manageable-models.create', ['modelUrlAlias' => $manageableModelClass::getUrlAlias()]),
                    'size' => 'small',
                    'type' => 'button',
                    'text' => 'Create a new ' . $manageableModelClass::getDisplayName(),
                    'icon' => 'fa fa-plus py-2',
                    'class' => 'px-4',
                ])
            @endif
        </div>
    @else
        {{-- Pagination --}}
        <div class="mx-auto p-8 text-center">
            {{ $models->links($WRLAHelper::getViewPath('livewire.pagination.tailwind')) }}
        </div>
    @endif

    @if($WRLAHelper::userIsDev())
        <div class="flex-1 border border-slate-300 rounded-md p-2 mt-10 mb-8 text-slate-500 overflow-auto">
            <p class=" text-sm font-semibold">Debug Information:</p>
            <hr class="my-1 border-slate-300">
            {{ $debugMessage }}
        </div>
        {{-- @foreach($dynamicFilterInputs as $key => $browseFilterInput)
            <div>
                @dump($key, $browseFilterInput)
            </div>
        @endforeach --}}
    @endif

</div>

@push('append-body')
@endpush

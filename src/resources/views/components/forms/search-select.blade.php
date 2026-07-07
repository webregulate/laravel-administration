@props([
    'label' => null,
    'options' => [],
    'fieldName' => '',
    'manageableModelClass' => null,
    'modelId' => null,
    'serializedSearchQuery' => null,
    'serializedItemLabel' => null,
    'itemLabelColumn' => null,
    'value' => '',
    'wireModelTarget' => null,
    'placeholder' => 'Select...',
    'searchPlaceholder' => 'Search...',
    'searchLimit' => 50,
    'prependOption' => [],
    'canCancel' => true,
    'disabled' => false,
    'required' => false,
])

<div class="{{ $options['containerClass'] ?? 'w-full flex-1 flex flex-col gap-1 md:flex-auto' }}">

    @if(!empty($label))
        @themeComponent('forms.label', [
            'label' => $label,
            'attributes' => Arr::toAttributeBag([
                'class' => $options['labelClass'] ?? ''
            ])
        ])
    @endif

    @php
        $searchSelectParams = [
            'name' => $fieldName,
            'manageableModelClass' => $manageableModelClass,
            'modelId' => $modelId,
            'serializedSearchQuery' => $serializedSearchQuery,
            'serializedItemLabel' => $serializedItemLabel,
            'itemLabelColumn' => $itemLabelColumn,
            'value' => $value,
            'placeholder' => $placeholder,
            'searchPlaceholder' => $searchPlaceholder,
            'searchLimit' => $searchLimit,
            'prependOption' => $prependOption,
            'canCancel' => $canCancel,
            'disabled' => $disabled,
            'required' => $required,
        ];
    @endphp

    {{-- When bound to a parent component via setLivewireModel(), use the component tag
         syntax so wire:model can two-way bind to the parent's property (#[Modelable]).
         Otherwise fall back to the @livewire directive (standard manageable-model forms
         submit the selected value through the hidden input instead). --}}
    @if(!empty($wireModelTarget))
        <livewire:wrla.manageable-fields.search-select
            wire:model.live="{{ $wireModelTarget }}"
            :name="$fieldName"
            :manageableModelClass="$manageableModelClass"
            :modelId="$modelId"
            :serializedSearchQuery="$serializedSearchQuery"
            :serializedItemLabel="$serializedItemLabel"
            :itemLabelColumn="$itemLabelColumn"
            :value="$value"
            :placeholder="$placeholder"
            :searchPlaceholder="$searchPlaceholder"
            :searchLimit="$searchLimit"
            :prependOption="$prependOption"
            :canCancel="$canCancel"
            :disabled="$disabled"
            :required="$required"
            :key="'search-select-'.$fieldName"
        />
    @else
        @livewire('wrla.manageable-fields.search-select', $searchSelectParams, key('search-select-'.$fieldName))
    @endif

    {{-- Field notes (if options has notes key) --}}
    @if(!empty($options['notes']))
        @themeComponent('forms.field-notes', ['notes' => $options['notes']])
    @endif

    @error($fieldName)
        @themeComponent('alert', ['type' => 'error', 'message' => $message, 'class' => 'mt-2'])
    @enderror

</div>

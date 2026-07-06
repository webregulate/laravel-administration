@props([
    'label' => null,
    'options' => [],
    'fieldName' => '',
    'manageableModelClass' => null,
    'modelId' => null,
    'value' => '',
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

    @livewire('wrla.manageable-fields.search-select', [
        'name' => $fieldName,
        'manageableModelClass' => $manageableModelClass,
        'modelId' => $modelId,
        'value' => $value,
        'placeholder' => $placeholder,
        'searchPlaceholder' => $searchPlaceholder,
        'searchLimit' => $searchLimit,
        'prependOption' => $prependOption,
        'canCancel' => $canCancel,
        'disabled' => $disabled,
        'required' => $required,
    ], key('search-select-'.$fieldName))

    {{-- Field notes (if options has notes key) --}}
    @if(!empty($options['notes']))
        @themeComponent('forms.field-notes', ['notes' => $options['notes']])
    @endif

    @error($fieldName)
        @themeComponent('alert', ['type' => 'error', 'message' => $message, 'class' => 'mt-2'])
    @enderror

</div>

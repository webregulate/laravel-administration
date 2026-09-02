@props([
    'text' => 'Submit',
    'icon' => '',
    'size' => 'small',
    'color' => 'primary',
    'href' => null,
    'error' => null
])

@php
    // Set id from name if unset
    $id = empty($attributes->get('id')) ? 'wrinput-'.$attributes->get('name') : $attributes->get('id');
    $name = empty($attributes->get('name')) ? 'wrinput-'.rand(1000, 9999) : $attributes->get('name');

    // If href or attributes->href is set, set href to attributes->href
    $href = $href ?? $attributes->get('href') ?? null;

    // Set size classes
    if($size == 'large') $sizeClasses = 'w-full px-4 py-2';
    else if($size == 'medium') $sizeClasses = 'w-fit px-4 py-1';
    else if($size == 'small') $sizeClasses = 'w-fit px-2 text-[14px]';

    // Set colour classes
    if($color == 'primary') $colorClasses = 'bg-primary-600 dark:bg-primary-800 text-white dark:text-slate-200 hover:brightness-110 border-teal-500 dark:border-teal-600 shadow-lg shadow-slate-400 dark:shadow-slate-700';
    else if($color == 'secondary') $colorClasses = 'bg-slate-700 dark:bg-slate-700 text-slate-100 dark:text-slate-200 hover:brightness-110 border-slate-500 dark:border-slate-600 shadow-lg shadow-slate-400 dark:shadow-slate-700 bg-opacity-90';
    else if($color == 'muted') $colorClasses = 'bg-slate-500 dark:bg-slate-600 text-white dark:text-slate-200 hover:brightness-110 border-slate-700 dark:border-slate-500 shadow-lg shadow-slate-400 dark:shadow-slate-700';
    else if($color == 'danger') $colorClasses = 'bg-rose-500 dark:bg-rose-700 text-white dark:text-rose-100 hover:brightness-110 border-rose-500 shadow-lg shadow-slate-400 dark:shadow-slate-700';

    // Loading state can target a wire:click action or an explicit wire:target
    // (e.g. a form submit button targeting its wire:submit="save" action).
    $wireClick = $attributes->get('wire:click');
    $wireTarget = $attributes->get('wire:target') ?? $wireClick;
    if($wireTarget) {
        $attributes = $attributes->merge([
            'wire:loading.attr' => 'disabled',
            'wire:loading.class' => 'opacity-80 cursor-not-allowed',
            'wire:target' => $wireTarget
        ]);
    }
@endphp

@if(empty($href))
<button
@else
<a href="{{ $href }}"
@endif
    {{ $attributes->merge([
        'class' => "flex justify-center items-center gap-1 whitespace-nowrap $sizeClasses font-semibold border $colorClasses rounded-md shadow-sm whitespace-nowrap"
    ]) }}>
    @if(!empty($icon))
        <i class="{{ $icon }} text-[13px] mr-1" @if(!empty($wireTarget)) wire:loading.remove wire:target="{{ $wireTarget }}" @endif></i>
    @endif
    @if(!empty($wireTarget))
        <i class="fa fa-spinner animate-spin text-[13px] mr-1" wire:loading.flex wire:target="{{ $wireTarget }}"></i>
    @endif
    <div class="inline">{!! $text !!}</div>
@if(empty($href))
</button>
@else
</a>
@endif

@if(!empty($error))
    <p class="text-sm text-red-500 mt-2">{{ $error }}</p>
@endif

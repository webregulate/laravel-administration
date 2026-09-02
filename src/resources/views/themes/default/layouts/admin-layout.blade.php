<!DOCTYPE html>
<html
    lang="en"
    class="h-full"
    x-cloak
    x-data="{ darkMode: $persist({{ $WRLAThemeData->default_mode == 'dark' ? 'true' : 'false' }}) }"
    :class="{'dark': darkMode === true }">
<head>
    {{-- Meta data --}}
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta http-equiv="X-UA-Compatible" content="ie=edge" />
    <meta name="csrf-token" content="{{ csrf_token() }}" />
    <link rel="icon" href="{{ config('wr-laravel-administration.favicon_url') }}" />
    <title>{{ $WRLAHelper::buildPageTitle(
        $title ?? (app()->view->getSections()['title'] ?? '(page title not set)'),
    ) }}</title>
    

    <style>
        strong { font-weight: bold !important; }
    </style>

    {{-- Partial head --}}
    @include('wr-laravel-administration::themes.default.layouts.partials.partial-head')
</head>
<body
    class="flex flex-col w-full h-full antialiased font-light bg-slate-200 dark:bg-slate-900">

    {{-- Main container --}}
    <div
        x-data="{
            leftPanelOpen: window.innerWidth < 768 ? false : $persist(true).using(sessionStorage),
            leftPanelAttemptedWidth: window.innerWidth < 768 ? window.innerWidth : $persist(350),
            windowWidth: window.innerWidth,
        }"
        x-on:resize.window="windowWidth = window.innerWidth"
        x-on:orientationchange.window="windowWidth = window.innerWidth"
        class="relative flex flex-row w-full min-h-full overflow-x-auto text-slate-900 dark:text-slate-100">

        {{-- Left panel --}}
        @themeView('layouts.partials.partial-left-panel')

        {{-- Right container --}}
        @themeView('layouts.partials.partial-main-container')

    </div>

    {{-- Script stack --}}
    @stack('scripts')

    {{-- Wire elements modal --}}
    @livewire('wrla.wire-elements-modal')

    {{-- Append body stack --}}
    @stack('append-body')
</body>
</html>

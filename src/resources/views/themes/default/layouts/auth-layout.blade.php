<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    {{-- Meta data --}}
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <meta name="csrf-token" content="{{ csrf_token() }}"/>
    <title>{{ $WRLAHelper::buildPageTitle(
        app()->view->getSections()['title'] ?? '(page title not set)',
    ) }}</title>

    {{-- Partial head --}}
    @include('wr-laravel-administration::themes.default.layouts.partials.partial-head')
</head>
<body x-cloak x-data="{ darkMode: $persist({{ $WRLAThemeData->default_mode == 'dark' ? 'true' : 'false' }}) }" :class="{'dark': darkMode === true }" class="h-full antialiased">

    <div class="transition-all relative flex flex-col gap-8 w-full min-h-screen items-center py-20 bg-slate-200 text-slate-800 dark:bg-slate-900 dark:text-slate-400">
        {{-- Top right fixed corner show dark mode toggle using font awesome icons --}}
        <div class="fixed top-0 right-0">
            <button @click="darkMode = !darkMode" class="flex w-[50px] h-10 justify-center items-center rounded-bl-lg shadow-md bg-slate-50 text-slate-800 dark:bg-slate-800 dark:text-slate-400">
                <i class="fas fa-sun text-primary-500 dark:hidden"></i>
                <i class="fas fa-moon text-primary-500 hidden dark:block"></i>
            </button>
        </div>

        {{-- Logo --}}
        <div class="w-full md:w-7/12 lg:w-3/12 rounded-lg px-8 mx-6">
            <a href="{{ route('wrla.dashboard') }}">
                {!! $WRLAHelper::renderLogo() !!}
            </a>
        </div>

        {{-- Yield content --}}
        @yield('content')
    </div>

    @stack('scripts')
</body>
</html>

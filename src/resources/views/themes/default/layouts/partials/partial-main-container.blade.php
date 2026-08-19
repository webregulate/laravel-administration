{{-- Right container --}}
<div id="wrla_main_container"
    x-bind:style="'width: ' + (windowWidth < 768 ? windowWidth : windowWidth - leftPanelAttemptedWidth) + 'px;'"
    class="absolute md:relative flex-1 h-full">
    {{-- Top bar --}}
    <div style="z-index: 5;" class="relative left-0 flex w-full gap-5 h-9 justify-between items-center border-b-2 border-slate-300 dark:border-slate-700 shadow-md dark:shadow-slate-900 bg-slate-100 text-slate-800 dark:bg-slate-800 dark:text-slate-400">
        {{-- Documentation + Version --}}
        <div class="flex items-center">
            {{-- Version / Update --}}
            @if($WRLAHelper::showVersionUpdateBar())
                @php
                    $versionHandlerClass = \WebRegulate\LaravelAdministration\Classes\VersionHandler\VersionHandler::class;
                    $localComposerVersion = $versionHandlerClass::getLocalVersion();
                    $updateAvailable = $versionHandlerClass::isComposerUpdateAvailable() === true;
                    $wrlaVersion = $versionHandlerClass::getLatestWrlaVersion();
                @endphp
                <button type="button"
                    onclick="window.loadLivewireModal(this, 'dev-tools.handle-update-modal')"
                    class="hidden md:inline-flex items-center pl-6 text-xs text-gray-600 hover:text-sky-600 dark:hover:text-slate-400 transition-colors whitespace-nowrap cursor-pointer"
                    title="{{ $updateAvailable ? 'Pending version updates are available to run' : 'No pending updates' }}">
                    @if($updateAvailable)
                        <i class="fas fa-exclamation-triangle text-xs mr-1 text-sky-600"></i>
                    @else
                        <i class="fas fa-code-branch text-xs mr-1"></i>
                    @endif
                    Version: {{ $localComposerVersion ? 'v' . $localComposerVersion : 'unknown' }}
                    @if($updateAvailable)
                        <span class="pl-1.5 text-sky-600 font-semibold">(update available{{ $wrlaVersion ? ': v' . $wrlaVersion['version'] : '' }})</span>
                    @endif
                </button>
            @endif

            {{-- Documentation link --}}
            @if($WRLAHelper::showDocumentationLink())
                <a href="{{ route('wrla.documentation') }}"
                    class="hidden md:flex items-center gap-1.5 pl-6 text-xs text-gray-600 hover:text-sky-600 dark:hover:text-slate-400 transition-colors whitespace-nowrap">
                    <i class="fas fa-book"></i>
                    <span class="underline">Documentation</span>
                </a>
            @endif
        </div>

        <div class="flex flex-row h-full items-center">
            <div class="relative flex items-center gap-x-4 h-full text-slate-500 dark:text-slate-400 text-sm mr-4">
                <span class="text-slate-600 dark:text-slate-300 whitespace-nowrap">
                    <span>Logged in: </span>
                    <i class="fas fa-user mx-1"></i>
                    {!!
                        method_exists($user->wrlaUserData ?? new class {}, 'getHeaderDisplay')
                            ? $user->wrlaUserData?->getHeaderDisplay()
                            : $user->wrlaUserData?->getFullName()
                    !!}
                </span>
            </div>
            <button @click="darkMode = !darkMode" class="flex w-[40px] h-full justify-center items-center border-l border-slate-300 dark:border-slate-700 bg-slate-50 text-slate-800 dark:bg-slate-800 hover:bg-slate-100 dark:hover:bg-slate-900 dark:text-slate-400">
                <i class="fas fa-sun text-sm text-primary-500 dark:hidden"></i>
                <i class="fas fa-moon text-sm text-primary-500 hidden dark:block"></i>
            </button>
            <a href="{{ route('wrla.logout') }}" class="flex h-full justify-center items-center text-sm gap-2 px-3 border-l border-slate-300 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 hover:bg-slate-100 dark:hover:bg-slate-900 text-primary-500">
                <i class="fas fa-sign-out-alt"></i>
                Logout
            </a>
        </div>
    </div>

    {{-- Top bar gap, removed as we have temporarily stopped the top bar being fixed --}}
    {{-- <div class="block w-full h-9"></div> --}}

    {{-- Content container --}}
    <div class="flex flex-row w-full h-full">
        {{-- Yield content --}}
        <div class="relative w-full h-full flex flex-col pt-8 pl-3 pr-2 lg:pl-14 lg:pr-10">
            @if(session('success'))
                @themeComponent('alert', ['type' => 'success', 'message' => session('success')])
            @elseif(session('error'))
                @themeComponent('alert', ['type' => 'error', 'message' => session('error')])
            @endif

            @yield('content')
        </div>
    </div>
</div>

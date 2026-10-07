<x-wrla-modal-layout title="Developer Tools" icon="fa-solid fa-screwdriver-wrench">

    @if(!$authorised)
        <div class="p-4 rounded-lg bg-sky-50 dark:bg-sky-900/20 border border-sky-300 dark:border-sky-700 text-sky-700 dark:text-sky-300 text-sm inline-flex items-center gap-2">
            <i class="fa-solid fa-lock"></i> Developer tools are not available for your account.
        </div>
    @else

    {{-- While something is running, poll for the latest console output --}}
    @if($running)
        <div wire:poll.1000ms="pollOutput"></div>
    @endif

    @php
        $wrlaHelper = \WebRegulate\LaravelAdministration\Classes\WRLAHelper::class;
        $showDatabaseSchema = $wrlaHelper::databaseSchemaViewerEnabled() && \Illuminate\Support\Facades\Route::has('wrla.database-schema');
        $showScheduler = $wrlaHelper::schedulerEnabled() && \Illuminate\Support\Facades\Route::has('wrla.scheduler');
        $showDatabaseBrowser = $wrlaHelper::databaseBrowserEnabled() && \Illuminate\Support\Facades\Route::has('wrla.database.tables');
    @endphp

    <div class="mt-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div class="flex items-center gap-3 min-w-0">
            <i class="fa-solid fa-box text-emerald-600 dark:text-emerald-400" aria-hidden="true"></i>
            <div class="min-w-0">
                <h3 class="text-sm font-semibold text-slate-800 dark:text-slate-100 flex flex-wrap items-center gap-x-3 gap-y-1">
                    <span>WRLA Package</span>
                    @if($composerUpdateAvailable === true)
                        <span class="inline-flex items-center gap-1.5 text-xs font-medium text-amber-600 dark:text-amber-400" role="status">
                            <i class="fa-solid fa-circle-arrow-up" aria-hidden="true"></i> Update available
                        </span>
                    @elseif($composerUpdateAvailable === false)
                        <span class="inline-flex items-center gap-1.5 text-xs font-medium text-emerald-600 dark:text-emerald-400" role="status">
                            <i class="fa-solid fa-circle-check" aria-hidden="true"></i> Up to date
                        </span>
                    @else
                        <span class="inline-flex items-center gap-1.5 text-xs font-medium text-slate-500 dark:text-slate-400" role="status">
                            <i class="fa-solid fa-circle-question" aria-hidden="true"></i> Unable to check updates
                        </span>
                    @endif
                </h3>
                <p class="text-sm text-slate-500 dark:text-slate-400">
                    Installed version <span class="font-mono text-slate-700 dark:text-slate-200 break-all">{{ $currentVersion ?? 'Unknown' }}</span>
                </p>
            </div>
        </div>
        <div class="flex flex-wrap items-center gap-x-4 gap-y-2 text-sm">
            <a href="https://webregulate.github.io/laravel-administration/" target="_blank" rel="noopener noreferrer"
                class="inline-flex items-center gap-2 text-sky-600 dark:text-sky-400 hover:underline">
                <i class="fa-solid fa-book-open text-xs" aria-hidden="true"></i> Documentation
                <i class="fa-solid fa-arrow-up-right-from-square text-xs" aria-hidden="true"></i>
            </a>
            <a href="https://webregulate.github.io/laravel-administration/#versions/versions.html" target="_blank" rel="noopener noreferrer"
                class="inline-flex items-center gap-2 text-sky-600 dark:text-sky-400 hover:underline">
                <i class="fa-solid fa-clock-rotate-left text-xs" aria-hidden="true"></i> Version History
                <i class="fa-solid fa-arrow-up-right-from-square text-xs" aria-hidden="true"></i>
            </a>
        </div>
    </div>

    @if($showDatabaseSchema || $showScheduler || $showDatabaseBrowser)
        <div class="mt-4 border-t border-slate-200 dark:border-slate-700 pt-4">
            <h3 class="text-sm font-semibold text-slate-700 dark:text-slate-200 flex items-center gap-2 mb-3">
                <i class="fa-solid fa-magnifying-glass text-sky-600" aria-hidden="true"></i> Inspect &amp; Monitor
            </h3>
            <div class="flex flex-wrap gap-2">
                @if($showDatabaseBrowser)
                    <a href="{{ route('wrla.database.tables') }}"
                        class="inline-flex items-center gap-2 border border-primary-500 bg-transparent hover:border-primary-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary-500 text-slate-700 dark:text-slate-200 text-sm font-medium px-3 py-1.5 rounded transition-colors">
                        <i class="fa-solid fa-database text-xs text-primary-500" aria-hidden="true"></i> Database Browser
                    </a>
                @endif
                @if($showDatabaseSchema)
                    <a href="{{ route('wrla.database-schema') }}"
                        class="inline-flex items-center gap-2 border border-primary-500 bg-transparent hover:border-primary-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary-500 text-slate-700 dark:text-slate-200 text-sm font-medium px-3 py-1.5 rounded transition-colors">
                        <i class="fa-solid fa-diagram-project text-xs text-primary-500" aria-hidden="true"></i> Database Schema
                    </a>
                @endif
                @if($showScheduler)
                    <a href="{{ route('wrla.scheduler') }}"
                        class="inline-flex items-center gap-2 border border-primary-500 bg-transparent hover:border-primary-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary-500 text-slate-700 dark:text-slate-200 text-sm font-medium px-3 py-1.5 rounded transition-colors">
                        <i class="fa-solid fa-clock-rotate-left text-xs text-primary-500" aria-hidden="true"></i> Scheduler &amp; Jobs
                    </a>
                @endif
            </div>
        </div>
    @endif

    <div class="mt-4 border-t border-slate-200 dark:border-slate-700 pt-4">
        <h3 class="text-sm font-semibold text-slate-700 dark:text-slate-200 flex items-center gap-2 mb-3">
            <i class="fa-solid fa-terminal text-sky-600" aria-hidden="true"></i> Commands
        </h3>
        @if(count($commands) === 0)
            <p class="text-sm text-slate-500 dark:text-slate-400">No developer commands configured.</p>
        @else
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                @foreach($commands as $cmd)
                    <button type="button"
                        wire:key="dev-command-{{ $cmd['index'] }}"
                        wire:click="runCommand({{ $cmd['index'] }})"
                        wire:loading.attr="disabled"
                        @disabled($running)
                        class="group min-w-0 text-left w-full rounded-md border border-primary-500 bg-transparent hover:border-primary-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary-500 disabled:opacity-50 disabled:pointer-events-none transition-colors px-3 py-3"
                    >
                        <span class="flex items-center justify-between gap-2">
                            <span class="flex items-center gap-2 min-w-0 font-medium text-sm text-slate-700 dark:text-slate-100">
                                @if($running && $runningLabel === $cmd['label'])
                                    <i class="fa-solid fa-spinner animate-spin text-xs text-primary-500 shrink-0" aria-hidden="true"></i>
                                @else
                                    <i class="fa-solid fa-play text-xs text-primary-500 group-hover:text-primary-700 shrink-0" aria-hidden="true"></i>
                                @endif
                                <span class="break-words min-w-0">{{ $cmd['label'] }}</span>
                            </span>
                            <span wire:loading wire:target="runCommand({{ $cmd['index'] }})" class="shrink-0">
                                <i class="fa-solid fa-spinner animate-spin text-primary-500 text-xs" aria-hidden="true"></i>
                            </span>
                        </span>
                        <code class="block mt-1 text-xs text-slate-500 dark:text-slate-400 break-all">{{ $cmd['command'] }}</code>
                    </button>
                @endforeach
            </div>
        @endif
    </div>

    {{-- Console output --}}
    <div class="bg-slate-900 text-slate-200 p-4 rounded-lg mt-4">
        <h3 class="text-sm font-semibold mb-3 flex flex-wrap items-center justify-between gap-2">
            <span class="inline-flex items-center gap-2"><i class="fa-solid fa-terminal text-slate-400" aria-hidden="true"></i> Console Output</span>
            @if($running)
                <span class="inline-flex items-center gap-2 min-w-0 text-sky-400 text-xs font-normal">
                    <i class="fa-solid fa-spinner animate-spin shrink-0" aria-hidden="true"></i>
                    <span class="break-words min-w-0">{{ $runningLabel ? $runningLabel . ' running...' : 'Running...' }}</span>
                </span>
            @endif
        </h3>
        <div x-data="{
                scrollToBottom() {
                    this.$el.scrollTop = this.$el.scrollHeight;
                }
            }"
            x-init="
                scrollToBottom();
                new MutationObserver(() => scrollToBottom()).observe($el, { childList: true, subtree: true, characterData: true });
            "
            class="w-full max-h-96 overflow-auto">
            <pre class="whitespace-pre-wrap break-all text-xs sm:text-sm min-h-[3rem]" role="log" aria-live="polite">{{ $consoleOutput }}</pre>
        </div>

        {{-- Once an update has finished, prompt the user to refresh the page behind the modal --}}
        @if($updateCompleted && !$running)
            <div class="mt-4 pt-3 border-t border-slate-700 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                <span class="inline-flex items-center gap-2 text-emerald-300 text-sm"><i class="fa-solid fa-rotate-right shrink-0" aria-hidden="true"></i>
                    Update finished. Refresh the page to load any changes.
                </span>
                <button type="button" x-on:click="window.location.reload()"
                    class="self-start shrink-0 inline-flex items-center gap-2 whitespace-nowrap border border-primary-500 bg-transparent hover:border-primary-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary-500 text-white text-sm font-semibold px-3 py-1.5 rounded transition-colors">
                    <i class="fa-solid fa-rotate-right" aria-hidden="true"></i> Refresh page
                </button>
            </div>
        @endif

        {{-- Command finished notice --}}
        @if($commandCompleted && !$running)
            <div class="mt-4 pt-3 border-t border-slate-700 flex items-center gap-2 text-sky-300 text-sm">
                <i class="fa-solid fa-flag-checkered" aria-hidden="true"></i> Command finished.
            </div>
        @endif
    </div>

    @endif

</x-wrla-modal-layout>
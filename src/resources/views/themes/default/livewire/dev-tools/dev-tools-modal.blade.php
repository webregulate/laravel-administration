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

    {{-- Actions: two panels side-by-side (WRLA updates + commands) --}}
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">

        {{-- WRLA package updates --}}
        <div class="rounded-lg border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800/50 p-4 flex flex-col">
            <h3 class="text-sm font-semibold text-slate-700 dark:text-slate-200 flex items-center gap-2 mb-1">
                <i class="fa-solid fa-bolt text-sky-600"></i> WRLA Package Updates
            </h3>
            <p class="text-sm text-slate-600 dark:text-slate-300 mb-3">Keep the administration package up to date.</p>

            <div>
                @if($composerUpdateAvailable === true || $running)
                    @if($running && $runType === 'update')
                        <span class="inline-flex items-center gap-2 text-sky-500 text-sm">
                            <i class="fa-solid fa-hourglass animate-spin"></i>
                            <span class="font-medium">Update running...</span>
                        </span>
                    @elseif($composerUpdateAvailable === true)
                        <div class="flex flex-col gap-1">
                            <span class="text-slate-700 dark:text-white font-semibold text-sm">WRLA Update Available</span>
                            @if($latestVersion)
                                <span class="text-sky-600 dark:text-sky-400 text-sm">
                                    <span class="text-slate-700 dark:text-white">v{{ $latestVersion }} &mdash; </span>
                                    <a href="https://webregulate.github.io/laravel-administration/#versions/v{{ $latestVersion }}.html"
                                        target="_blank"
                                        class="inline-flex items-center gap-1 underline hover:text-sky-500">
                                        <i class="fa-solid fa-arrow-up-right-from-square text-xs mr-1"></i>
                                        <span>Review changes before updating</span>
                                    </a>
                                </span>
                            @endif
                            <button wire:click="runComposerOnly" wire:loading.attr="disabled" wire:target="runComposerOnly"
                                @disabled($running)
                                class="mt-2 self-start whitespace-nowrap disabled:opacity-50 inline-flex items-center gap-2 bg-emerald-600 hover:bg-emerald-500 text-white text-sm font-semibold px-3 py-1.5 rounded transition-colors">
                                <span wire:loading.remove wire:target="runComposerOnly" class="inline-flex items-center gap-2">
                                    <i class="fa-solid fa-box"></i> Click to update WRLA
                                </span>
                                <span wire:loading wire:target="runComposerOnly" class="inline-flex items-center gap-2">
                                    <i class="fa-solid fa-hourglass animate-spin"></i>
                                    <span class="font-medium">Update running...</span>
                                </span>
                            </button>
                        </div>
                    @endif
                @elseif($composerUpdateAvailable === false)
                    <span class="inline-flex items-center gap-2 text-emerald-600 dark:text-emerald-400 text-sm font-medium">
                        <i class="fa-solid fa-circle-check"></i>
                        WRLA package is up to date.
                    </span>
                @else
                    <span class="inline-flex items-center gap-2 text-slate-400 text-sm"><i class="fa-solid fa-circle-question"></i> Could not check composer status.</span>
                @endif
            </div>
        </div>

        {{-- Developer commands --}}
        <div class="rounded-lg border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800/50 p-4">
            <h3 class="text-sm font-semibold text-slate-700 dark:text-slate-200 flex items-center gap-2 mb-1">
                <i class="fa-solid fa-terminal text-sky-600"></i> Commands
            </h3>
            <p class="text-sm text-slate-600 dark:text-slate-300 mb-3">
                Configured commands: <code class="bg-slate-200 dark:bg-slate-700 px-1.5 py-0.5 rounded text-xs">wr-laravel-administration.developer.commands</code>.
            </p>

            @if(count($commands) === 0)
                <p class="text-sm text-slate-500 dark:text-slate-400 italic">No developer commands configured.</p>
            @else
                <div class="flex flex-col gap-2">
                    @foreach($commands as $cmd)
                        <button
                            wire:click="runCommand({{ $cmd['index'] }})"
                            wire:loading.attr="disabled"
                            @disabled($running)
                            class="group text-left w-full rounded-md border border-slate-200 dark:border-slate-600 bg-white dark:bg-slate-800 hover:border-sky-500 hover:bg-sky-50 dark:hover:bg-slate-700 disabled:opacity-50 disabled:pointer-events-none transition-colors px-3 py-2"
                        >
                            <span class="flex items-center justify-between gap-2">
                                <span class="inline-flex items-center gap-2 font-medium text-sm text-slate-700 dark:text-slate-100">
                                    <i class="fa-solid fa-play text-xs text-sky-600 group-hover:text-sky-500"></i>
                                    {{ $cmd['label'] }}
                                </span>
                                <span wire:loading wire:target="runCommand({{ $cmd['index'] }})">
                                    <i class="fa-solid fa-hourglass animate-spin text-sky-500 text-xs"></i>
                                </span>
                            </span>
                            <code class="block text-xs text-slate-500 dark:text-slate-400 truncate">
                                {{ $cmd['command'] }}
                            </code>
                        </button>
                    @endforeach
                </div>
            @endif
        </div>
    </div>

    {{-- Console output --}}
    <div class="bg-slate-900 text-slate-200 p-4 rounded-lg mt-4">
        <h3 class="text-lg font-semibold mb-2 flex items-center gap-2">
            Console Output:
            @if($running)
                <span class="inline-flex items-center text-sky-400 text-sm font-normal">
                    <span class="mr-2"><i class="fa-solid fa-hourglass animate-spin"></i></span>
                    <span class="font-medium">{{ $runningLabel ? $runningLabel . ' running...' : 'Running...' }}</span>
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
            <pre class="whitespace-pre-wrap">{{ $consoleOutput }}</pre>
        </div>

        {{-- Once an update has finished, prompt the user to refresh the page behind the modal --}}
        @if($updateCompleted && !$running)
            <div class="mt-4 p-3 rounded-lg bg-emerald-900/40 border border-emerald-700 flex items-center justify-between gap-4">
                <span class="inline-flex items-center gap-2 text-emerald-300 text-sm"><i class="fa-solid fa-circle-check"></i>
                    Update completed — refresh the page to load the latest changes.
                </span>
                <button type="button" x-on:click="window.location.reload()"
                    class="inline-flex items-center gap-2 whitespace-nowrap bg-emerald-600 hover:bg-emerald-500 text-white text-sm font-semibold px-3 py-1.5 rounded transition-colors">
                    <i class="fa-solid fa-rotate-right"></i> Refresh page
                </button>
            </div>
        @endif

        {{-- Command finished notice --}}
        @if($commandCompleted && !$running)
            <div class="mt-4 p-3 rounded-lg bg-sky-900/40 border border-sky-700 flex items-center gap-2 text-sky-300 text-sm">
                <i class="fa-solid fa-circle-check"></i> Command finished.
            </div>
        @endif
    </div>

    @endif

</x-wrla-modal-layout>
<x-wrla-modal-layout title="Update WRLA" icon='fa-solid fa-bolt'>
    
    {{-- Back button (wire elements modal) --}}
    <div class="flex justify-between items-center mb-4">
        <button wire:click="$dispatch('closeModal')" class="inline-flex items-center gap-2 text-gray-500 hover:text-gray-700">
            <i class="fa-solid fa-arrow-left"></i> Back
        </button>
    </div>

    {{-- While a live update is running, poll for the latest console output --}}
    @if($running)
        <div wire:poll.1000ms="pollOutput"></div>
    @endif

    <div class="bg-slate-900 text-slate-200 p-4 rounded-lg mt-4">
        <h3 class="text-lg font-semibold mb-2 flex items-center gap-2">
            Console Output:
            @if($running)
                <span class="inline-flex items-center text-amber-400 text-sm font-normal">
                    <span class="mr-2"><i class="fa-solid fa-hourglass animate-spin"></i></span>
                    <span class="font-medium">Update running...</span>
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

        <div class="flex justify-end items-end mt-4 gap-4">
            @if($authorised)
                <div class="flex flex-col gap-1 items-end text-right">
                    <span class="text-xs uppercase tracking-wide text-slate-500">WRLA Package Update</span>
                    @if($composerUpdateAvailable === true || $running)
                        <button wire:click="runComposerOnly" wire:loading.attr="disabled" wire:target="runComposerOnly"
                            @disabled($running) class="whitespace-nowrap disabled:opacity-50 text-amber-400 hover:text-amber-300 text-sm transition-colors">
                            <span wire:loading.remove wire:target="runComposerOnly" class="inline-flex items-center gap-2">
                                @if($running)
                                    <span class="mr-2"><i class="fa-solid fa-hourglass animate-spin"></i></span>
                                    <span class="font-medium">Update running...</span>
                                @else
                                    <i class="fa-solid fa-box"></i> WRLA Package not up to date — review changes and update
                                @endif
                            </span>
                            <span wire:loading wire:target="runComposerOnly" class="inline-flex items-center">
                                <span class="mr-2"><i class="fa-solid fa-hourglass animate-spin"></i></span>
                                <span class="font-medium">Update running...</span>
                            </span>
                        </button>
                    @elseif($composerUpdateAvailable === false)
                        <span class="inline-flex items-center gap-2 text-emerald-400 text-sm"><i class="fa-solid fa-circle-check"></i> WRLA Package is up to date.</span>
                    @else
                        <span class="inline-flex items-center gap-2 text-slate-400 text-sm"><i class="fa-solid fa-circle-question"></i> Could not check composer status.</span>
                    @endif
                </div>
            @else
                <span class="inline-flex items-center gap-2 text-amber-400 text-sm"><i class="fa-solid fa-lock"></i> Updates are not available for your account.</span>
            @endif
        </div>
    </div>

    <br />

</x-wrla-modal-layout>


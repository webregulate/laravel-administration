@php
    $defaultMode = $defaultMode ?? config('wr-laravel-administration.database_schema_viewer.default_mode', 'light');
@endphp

<div class="h-full -mt-7 -mx-1 md:-mx-2 lg:-ml-7 lg:-mr-5">
    {{-- Seed Truss's persisted theme (same-origin localStorage) before the iframe
        begins loading, so the embedded dashboard opens in the configured mode
        until the user toggles it themselves. --}}
    <script>
        (function () {
            var mode = @json($defaultMode);
            try {
                if ((mode === 'light' || mode === 'dark') && localStorage.getItem('truss-theme') === null) {
                    localStorage.setItem('truss-theme', mode);
                }
            } catch (e) {}
        })();
    </script>

    <iframe
        wire:ignore
        src="{{ $src }}"
        title="Database Schema"
        class="block w-full h-full border-0"
    ></iframe>
</div>


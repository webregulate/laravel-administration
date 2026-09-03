@php
    $defaultMode = $defaultMode ?? config('wr-laravel-administration.database_schema_viewer.default_mode', 'light');
@endphp

<div class="h-full">
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
        class="relative border-0"
        style="
            left: -50px;
            top: -30px;
            width: calc(100% + 87px);
            height: calc(100% + 0px);
        "
    ></iframe>
</div>


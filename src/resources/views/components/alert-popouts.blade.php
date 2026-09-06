@php
    $initialAlerts = collect(session('wrla_alerts', []));

    foreach (['success', 'error', 'warning', 'info'] as $legacyType) {
        if (session()->has($legacyType)) {
            $initialAlerts->push([
                'type' => $legacyType === 'error' ? 'danger' : $legacyType,
                'message' => session($legacyType),
                'url' => null,
                'ttl' => (int) config('wr-laravel-administration.alerts.ttl', 5000),
            ]);
        }
    }
@endphp

<script>
    window.wrlaAlertPopouts = function(initialAlerts, maxVisible, defaultTTL) {
        return {
            alerts: [],
            timers: {},
            maxVisible,
            storageKey: 'wrla-active-alerts',
            init() {
                this.readStoredAlerts().forEach(alert => this.push(alert, false));
                this.persist();
                initialAlerts.forEach(alert => this.push(alert));
            },
            readStoredAlerts() {
                try {
                    return JSON.parse(sessionStorage.getItem(this.storageKey) || '[]');
                } catch (error) {
                    sessionStorage.removeItem(this.storageKey);
                    return [];
                }
            },
            persist() {
                sessionStorage.setItem(this.storageKey, JSON.stringify(this.alerts.map(alert => ({
                    id: alert.id,
                    type: alert.type,
                    message: alert.message,
                    url: alert.url,
                    ttl: alert.ttl,
                    expiresAt: alert.expiresAt,
                }))));
            },
            push(alert, persist = true) {
                const id = alert.id || `${Date.now()}-${Math.random()}`;

                if (this.alerts.some(activeAlert => activeAlert.id === id)) {
                    return;
                }

                const ttl = Math.max(0, Number(alert.ttl ?? defaultTTL));
                const expiresAt = alert.expiresAt ?? (ttl > 0 ? Date.now() + ttl : null);
                const remaining = expiresAt === null ? 0 : Math.max(0, expiresAt - Date.now());

                if (expiresAt !== null && remaining === 0) {
                    if (persist) this.persist();
                    return;
                }

                alert = {
                    id,
                    type: alert.type === 'error' ? 'danger' : alert.type,
                    message: alert.message,
                    url: alert.url || null,
                    ttl,
                    expiresAt,
                    remaining,
                    progress: ttl > 0 ? Math.min(1, remaining / ttl) : 1,
                };

                this.alerts.push(alert);

                while (this.alerts.length > this.maxVisible) {
                    this.dismiss(this.alerts[0].id);
                }

                if (alert.expiresAt !== null) {
                    this.timers[alert.id] = setTimeout(() => this.dismiss(alert.id), alert.remaining);
                }

                if (persist) this.persist();
            },
            dismiss(id) {
                clearTimeout(this.timers[id]);
                delete this.timers[id];
                this.alerts = this.alerts.filter(alert => alert.id !== id);
                this.persist();
            },
            borderColor(type) {
                return {
                    success: 'border-emerald-500',
                    danger: 'border-rose-500',
                    warning: 'border-amber-500',
                    info: 'border-sky-500',
                }[type] || 'border-sky-500';
            },
            accentColor(type) {
                return {
                    success: 'text-emerald-600 dark:text-emerald-400',
                    danger: 'text-rose-600 dark:text-rose-400',
                    warning: 'text-amber-600 dark:text-amber-400',
                    info: 'text-sky-600 dark:text-sky-400',
                }[type] || 'text-sky-600 dark:text-sky-400';
            },
            icon(type) {
                return {
                    success: 'fas fa-circle-check',
                    danger: 'fas fa-circle-xmark',
                    warning: 'fas fa-triangle-exclamation',
                    info: 'fas fa-circle-info',
                }[type] || 'fas fa-circle-info';
            },
        };
    };
</script>

<div
    x-data="wrlaAlertPopouts(@js($initialAlerts->values()), {{ (int) config('wr-laravel-administration.alerts.max_visible', 5) }}, {{ (int) config('wr-laravel-administration.alerts.ttl', 5000) }})"
    x-on:wrla-alert.window="push($event.detail)"
    class="fixed right-3 sm:right-5 flex w-[calc(100%-1.5rem)] max-w-sm flex-col gap-3 pointer-events-none"
    style="top: {{ config('wr-laravel-administration.alerts.top_offset', '52px') }}; z-index: 2147483647;"
    aria-live="polite"
>
    <template x-for="alert in alerts" :key="alert.id">
        <section
            x-transition:enter="transition ease-out duration-200"
            x-transition:enter-start="opacity-0 translate-x-6"
            x-transition:enter-end="opacity-100 translate-x-0"
            x-transition:leave="transition ease-in duration-150"
            x-transition:leave-start="opacity-100 translate-x-0"
            x-transition:leave-end="opacity-0 translate-x-6"
            class="pointer-events-auto relative overflow-hidden rounded-lg border-l-4 bg-white text-slate-700 shadow-xl ring-1 ring-black/10 dark:bg-slate-800 dark:text-slate-200 dark:ring-white/10"
            :class="borderColor(alert.type)"
            :role="alert.type === 'danger' ? 'alert' : 'status'"
        >
            <div class="flex items-start gap-3 px-4 py-3.5">
                <i class="mt-0.5 text-lg" :class="[icon(alert.type), accentColor(alert.type)]" aria-hidden="true"></i>

                <div class="min-w-0 flex-1 text-sm leading-5">
                    <a x-show="alert.url" :href="alert.url" class="block text-inherit hover:underline">
                        <span x-html="alert.message"></span>
                    </a>
                    <span x-show="!alert.url" x-html="alert.message"></span>
                </div>

                <button
                    type="button"
                    class="-mr-1 flex h-7 w-7 shrink-0 items-center justify-center rounded text-slate-400 hover:bg-slate-100 hover:text-slate-700 focus:outline-none focus:ring-2 focus:ring-current dark:hover:bg-slate-700 dark:hover:text-white"
                    x-on:click="dismiss(alert.id)"
                    aria-label="Dismiss alert"
                    title="Dismiss alert"
                >
                    <i class="fas fa-xmark" aria-hidden="true"></i>
                </button>
            </div>

            <div x-show="alert.expiresAt !== null" class="h-1 bg-slate-200 dark:bg-slate-700" :class="accentColor(alert.type)">
                <div
                    class="h-full origin-left animate-[wrla-alert-countdown_linear_forwards] bg-current"
                    :style="`animation-duration: ${alert.remaining}ms; --wrla-alert-progress-start: ${alert.progress}`"
                ></div>
            </div>
        </section>
    </template>

    <style>
        @keyframes wrla-alert-countdown {
            from { transform: scaleX(var(--wrla-alert-progress-start, 1)); }
            to { transform: scaleX(0); }
        }
    </style>
</div>
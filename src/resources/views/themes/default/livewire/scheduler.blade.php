<div>
    {{-- Title + refresh --}}
    <div class="flex justify-between items-start gap-3">
        @themeComponent('heading', [
            'title' => 'Scheduler & Jobs',
            'icon' => 'fa-solid fa-clock-rotate-left',
        ])

        <div class="flex items-center gap-3 mt-1 shrink-0">
            @themeComponent('forms.button', [
                'type' => 'button',
                'size' => 'small',
                'text' => 'Refresh',
                'icon' => 'fas fa-sync-alt',
                'attributes' => Arr::toAttributeBag([
                    'wire:click' => 'refresh',
                ]),
            ])
        </div>
    </div>

    {{-- Flash message --}}
    @if($flashMessage)
        <div class="mb-4 p-3 rounded-lg text-sm inline-flex items-center gap-2 w-full
            {{ $flashType === 'error'
                ? 'bg-red-50 dark:bg-red-900/20 border border-red-300 dark:border-red-700 text-red-700 dark:text-red-300'
                : 'bg-emerald-50 dark:bg-emerald-900/20 border border-emerald-300 dark:border-emerald-700 text-emerald-700 dark:text-emerald-300' }}">
            <i class="fa-solid {{ $flashType === 'error' ? 'fa-triangle-exclamation' : 'fa-circle-check' }}"></i>
            <span>{{ $flashMessage }}</span>
        </div>
    @endif

    {{-- ============================= SCHEDULED TASKS ============================= --}}
    <div x-data="{ open: $persist(true).as('wrla_scheduler_scheduledTasks') }" class="mb-8 rounded-lg border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800/50 overflow-hidden">
        <button type="button" x-on:click="open = !open" class="w-full flex items-center gap-2 px-4 py-3 border-b border-slate-200 dark:border-slate-700 text-left" :class="{ 'border-b-0': !open }">
            <i class="fa-solid fa-calendar-days text-primary-500"></i>
            <h3 class="text-sm font-semibold text-slate-700 dark:text-slate-100">Scheduled Tasks</h3>
            <span class="text-xs text-slate-500 dark:text-slate-400">({{ count($scheduledTasks) }})</span>
            <i class="fa-solid fa-chevron-down ml-auto text-slate-400 dark:text-slate-500 transition-transform" :class="{ '-rotate-90': !open }"></i>
        </button>

        <div x-show="open" x-collapse>
        @if(count($scheduledTasks) === 0)
            <div class="px-4 py-6 text-sm text-slate-500 dark:text-slate-400 text-center">
                No scheduled tasks are currently registered.
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm text-left text-slate-700 dark:text-slate-200">
                    <thead class="text-xs uppercase bg-slate-100 dark:bg-slate-800 text-slate-500 dark:text-slate-400">
                        <tr>
                            <th class="px-4 py-2">Task</th>
                            <th class="px-4 py-2">Type / Flags</th>
                            <th class="px-4 py-2">Expression</th>
                            <th class="px-4 py-2">Next Run</th>
                            @if($canRunAdhoc)
                                <th class="px-4 py-2 text-right"></th>
                            @endif
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($scheduledTasks as $task)
                            <tr class="border-t border-slate-200 dark:border-slate-700 even:bg-slate-100/50 dark:even:bg-slate-800/40">
                                <td class="px-4 py-2 align-middle">
                                    @php
                                        // Description wins; when both exist the machine name is exposed as a tooltip.
                                        $primaryLabel = $task['description'] ?? $task['name'] ?? $task['summary'];
                                        $tooltipName = ($task['description'] && $task['name']) ? $task['name'] : null;
                                    @endphp
                                    <div class="font-medium text-slate-800 dark:text-white {{ $tooltipName ? 'cursor-help' : '' }}"
                                        @if($tooltipName) title="{{ $tooltipName }}" @endif>
                                        {{ $primaryLabel }}
                                    </div>
                                </td>
                                <td class="px-4 py-2 align-middle">
                                    <div class="flex flex-nowrap gap-x-1">
                                        <div
                                            class="text-xs text-slate-500 dark:text-slate-400 mt-0.5 cursor-help"
                                            title="{{ $task['description'] }}"
                                        >
                                            <span class="inline-block px-1.5 py-0.5 rounded bg-slate-200 dark:bg-slate-700 mr-1">{{ $task['type'] }}</span>
                                        </div>
                                        @if($task['withoutOverlapping'])
                                            <span class="text-nowrap text-xs px-1.5 py-0.5 rounded bg-amber-100 dark:bg-amber-900/40 text-amber-700 dark:text-amber-300">no overlap</span>
                                        @endif
                                        @if($task['onOneServer'])
                                            <span class="text-nowrap text-xs px-1.5 py-0.5 rounded bg-sky-100 dark:bg-sky-900/40 text-sky-700 dark:text-sky-300">one server</span>
                                        @endif
                                    </div>
                                </td>
                                <td class="px-4 py-2 align-middle">
                                    <span class="text-xs text-slate-700 dark:text-slate-200 cursor-help" title="{{ $task['expression'] }}">{{ $task['humanExpression'] }}</span>
                                </td>
                                <td class="px-4 py-2 align-middle whitespace-nowrap text-xs">
                                    {{ $task['nextRun'] ?? '—' }}
                                </td>
                                @if($canRunAdhoc)
                                    <td class="px-4 py-2 align-middle text-right whitespace-nowrap">
                                        <button type="button"
                                            wire:click="runTask('{{ $task['id'] }}')"
                                            wire:target="runTask('{{ $task['id'] }}')"
                                            wire:loading.attr="disabled"
                                            wire:loading.class="!pt-0"
                                            class="inline-flex items-center gap-1 transition-colors disabled:opacity-60 disabled:cursor-wait"
                                        >
                                            <span wire:loading.remove wire:target="runTask('{{ $task['id'] }}')" class="inline-flex items-center gap-1">
                                                <i class="fa-solid fa-play text-[12px] text-emerald-500"></i>
                                            </span>
                                            <span wire:loading wire:target="runTask('{{ $task['id'] }}')" class="inline-flex items-center gap-1">
                                                <i class="inline-block fa-solid fa-spinner animate-spin text-[12px] text-slate-500"></i>
                                            </span>
                                        </button>
                                    </td>
                                @endif
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
        </div>
    </div>

    {{-- ============================= PENDING JOBS ============================= --}}
    <div x-data="{ open: $persist(true).as('wrla_scheduler_pendingJobs') }" class="mb-8 rounded-lg border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800/50 overflow-hidden">
        <button type="button" x-on:click="open = !open" class="w-full flex items-center gap-2 px-4 py-3 border-b border-slate-200 dark:border-slate-700 text-left" :class="{ 'border-b-0': !open }">
            <i class="fa-solid fa-hourglass-half text-primary-500"></i>
            <h3 class="text-sm font-semibold text-slate-700 dark:text-slate-100">Pending Jobs</h3>
            <span class="text-xs text-slate-500 dark:text-slate-400">({{ count($pendingJobs) }})</span>
            <span class="ml-auto text-xs text-slate-400 dark:text-slate-500">queue driver: <code>{{ $queueDriver }}</code></span>
            <i class="fa-solid fa-chevron-down text-slate-400 dark:text-slate-500 transition-transform" :class="{ '-rotate-90': !open }"></i>
        </button>

        <div x-show="open" x-collapse>
        @if(! $pendingSupported)
            <div class="px-4 py-6 text-sm text-slate-500 dark:text-slate-400 text-center">
                Pending jobs can only be listed for the <code>database</code> queue driver. Current driver: <code>{{ $queueDriver }}</code>.
            </div>
        @elseif(count($pendingJobs) === 0)
            <div class="px-4 py-6 text-sm text-slate-500 dark:text-slate-400 text-center">
                No pending jobs on the queue.
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm text-left text-slate-700 dark:text-slate-200">
                    <thead class="text-xs uppercase bg-slate-100 dark:bg-slate-800 text-slate-500 dark:text-slate-400">
                        <tr>
                            <th class="px-4 py-2">Job</th>
                            <th class="px-4 py-2">Queue</th>
                            <th class="px-4 py-2">Attempts</th>
                            <th class="px-4 py-2">Available At</th>
                            <th class="px-4 py-2 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($pendingJobs as $job)
                            <tr class="border-t border-slate-200 dark:border-slate-700 even:bg-slate-100/50 dark:even:bg-slate-800/40">
                                <td class="px-4 py-2 align-top font-medium text-slate-800 dark:text-white">
                                    {{ $job['name'] }}
                                    @if($job['reserved'])
                                        <span class="ml-1 text-xs px-1.5 py-0.5 rounded bg-sky-100 dark:bg-sky-900/40 text-sky-700 dark:text-sky-300">reserved</span>
                                    @endif
                                </td>
                                <td class="px-4 py-2 align-top">{{ $job['queue'] }}</td>
                                <td class="px-4 py-2 align-top">{{ $job['attempts'] }}</td>
                                <td class="px-4 py-2 align-top whitespace-nowrap text-xs">{{ $job['availableAt'] ?? '—' }}</td>
                                <td class="px-4 py-2 align-top text-right">
                                    <button type="button"
                                        wire:click="deletePendingJob({{ $job['id'] }})"
                                        wire:confirm="Delete this pending job? This cannot be undone."
                                        class="inline-flex items-center gap-1 text-xs font-semibold px-2 py-1 rounded bg-red-600 hover:bg-red-500 text-white transition-colors">
                                        <i class="fas fa-trash text-[10px]"></i> Delete
                                    </button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
        </div>
    </div>

    {{-- ============================= FAILED JOBS ============================= --}}
    <div x-data="{ open: $persist(true).as('wrla_scheduler_failedJobs') }" class="mb-8 rounded-lg border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800/50 overflow-hidden">
        <div class="flex items-center gap-2 px-4 py-3 border-b border-slate-200 dark:border-slate-700" :class="{ 'border-b-0': !open }">
            <button type="button" x-on:click="open = !open" class="flex items-center gap-2 flex-1 text-left">
                <i class="fa-solid fa-circle-xmark text-red-500"></i>
                <h3 class="text-sm font-semibold text-slate-700 dark:text-slate-100">Failed Jobs</h3>
                <span class="text-xs text-slate-500 dark:text-slate-400">({{ count($failedJobs) }})</span>
            </button>

            @if(count($failedJobs) > 0)
                <div class="flex items-center gap-2">
                    <button type="button"
                        wire:click="retryAllFailed"
                        wire:confirm="Retry ALL failed jobs?"
                        class="inline-flex items-center gap-1 text-xs font-semibold px-2 py-1 rounded bg-primary-600 hover:bg-primary-500 text-white transition-colors">
                        <i class="fas fa-rotate-right text-[10px]"></i> Retry All
                    </button>
                    <button type="button"
                        wire:click="flushFailed"
                        wire:confirm="Permanently delete ALL failed jobs? This cannot be undone."
                        class="inline-flex items-center gap-1 text-xs font-semibold px-2 py-1 rounded bg-red-600 hover:bg-red-500 text-white transition-colors">
                        <i class="fas fa-trash text-[10px]"></i> Flush All
                    </button>
                </div>
            @endif
            <button type="button" x-on:click="open = !open" class="ml-1">
                <i class="fa-solid fa-chevron-down text-slate-400 dark:text-slate-500 transition-transform" :class="{ '-rotate-90': !open }"></i>
            </button>
        </div>

        <div x-show="open" x-collapse>
        @if(count($failedJobs) === 0)
            <div class="px-4 py-6 text-sm text-slate-500 dark:text-slate-400 text-center">
                No failed jobs. 🎉
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm text-left text-slate-700 dark:text-slate-200">
                    <thead class="text-xs uppercase bg-slate-100 dark:bg-slate-800 text-slate-500 dark:text-slate-400">
                        <tr>
                            <th class="px-4 py-2">Job</th>
                            <th class="px-4 py-2">Queue</th>
                            <th class="px-4 py-2">Failed At</th>
                            <th class="px-4 py-2">Exception</th>
                            <th class="px-4 py-2 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($failedJobs as $job)
                            <tr class="border-t border-slate-200 dark:border-slate-700 even:bg-slate-100/50 dark:even:bg-slate-800/40">
                                <td class="px-4 py-2 align-top font-medium text-slate-800 dark:text-white">
                                    {{ $job['name'] }}
                                    <div class="text-xs text-slate-400 dark:text-slate-500 font-normal">{{ $job['connection'] }}</div>
                                </td>
                                <td class="px-4 py-2 align-top">{{ $job['queue'] }}</td>
                                <td class="px-4 py-2 align-top whitespace-nowrap text-xs">{{ $job['failedAt'] }}</td>
                                <td class="px-4 py-2 align-top">
                                    <div class="text-xs text-red-600 dark:text-red-400 max-w-md break-words">{{ $job['exception'] }}</div>
                                </td>
                                <td class="px-4 py-2 align-top text-right whitespace-nowrap">
                                    <div class="inline-flex items-center gap-1">
                                        <button type="button"
                                            wire:click="retryFailed('{{ $job['uuid'] }}')"
                                            class="inline-flex items-center gap-1 text-xs font-semibold px-2 py-1 rounded bg-primary-600 hover:bg-primary-500 text-white transition-colors">
                                            <i class="fas fa-rotate-right text-[10px]"></i> Retry
                                        </button>
                                        <button type="button"
                                            wire:click="forgetFailed('{{ $job['uuid'] }}')"
                                            wire:confirm="Delete this failed job? This cannot be undone."
                                            class="inline-flex items-center gap-1 text-xs font-semibold px-2 py-1 rounded bg-red-600 hover:bg-red-500 text-white transition-colors">
                                            <i class="fas fa-trash text-[10px]"></i> Delete
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
        </div>
    </div>
</div>

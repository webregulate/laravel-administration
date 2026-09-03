<?php

namespace WebRegulate\LaravelAdministration\Livewire;

use Throwable;
use Carbon\Carbon;
use Cron\CronExpression;
use Livewire\Attributes\Title;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use WebRegulate\LaravelAdministration\Classes\WRLAHelper;
use WebRegulate\LaravelAdministration\Services\CronExpressionService;

#[Title('Scheduler & Jobs')]
class Scheduler extends WRLAPageComponent
{
    /**
     * A short status / feedback message shown after an action (retry, delete, etc).
     */
    public ?string $flashMessage = null;

    /**
     * Feedback style for the flash message: 'success' or 'error'.
     */
    public string $flashType = 'success';

    public function mount(): void
    {
        // Hidden entirely unless the current user may access the page.
        if (! WRLAHelper::schedulerEnabled()) {
            abort(404);
        }
    }

    public function render()
    {
        return view(WRLAHelper::getViewPath('livewire.scheduler'), [
            'scheduledTasks' => $this->getScheduledTasks(),
            'pendingJobs' => $this->getPendingJobs(),
            'failedJobs' => $this->getFailedJobs(),
            'queueDriver' => $this->getQueueDriver(),
            'pendingSupported' => $this->getQueueDriver() === 'database',
            'canRunAdhoc' => WRLAHelper::schedulerCanRunAdhoc(),
        ]);
    }

    /* Data
    --------------------------------------------------------------------------*/

    /**
     * Read the application's registered scheduled tasks.
     *
     * The schedule is defined via `withSchedule()` in bootstrap/app.php and is only
     * populated once the Artisan console application has been built (that is what
     * fires the `Artisan::starting` callback behind `withSchedule`). In a web request
     * that has not happened, so we build the console application (via `->all()`) to
     * force the schedule closure to run before reading the events, exactly as
     * `php artisan schedule:list` does.
     */
    protected function getScheduledTasks(): array
    {
        try {
            app(ConsoleKernel::class)->all();

            $schedule = app(Schedule::class);

            return collect($schedule->events())->map(function ($event) {
                $isCallback = $event instanceof CallbackEvent;
                $timezone = $event->timezone instanceof \DateTimeZone
                    ? $event->timezone->getName()
                    : (string) ($event->timezone ?: config('app.timezone'));

                $command = $this->cleanCommand($event->command);

                return [
                    // Stable identifier used to run the task on demand.
                    'id' => sha1($event->mutexName()),
                    'type' => $isCallback ? 'Callback' : 'Command',
                    // For command events the machine "name" is the artisan command; closures have none.
                    'name' => $isCallback ? null : $command,
                    'description' => $event->description ?: ($isCallback ? 'Closure' : null),
                    'command' => $command,
                    'expression' => $event->expression,
                    'humanExpression' => CronExpressionService::toHuman($event->expression),
                    'summary' => $event->getSummaryForDisplay(),
                    'timezone' => (string) $timezone,
                    'withoutOverlapping' => $event->withoutOverlapping,
                    'onOneServer' => $event->onOneServer,
                    'nextRun' => $this->nextRunDate($event->expression, $timezone),
                ];
            })->all();
        } catch (Throwable $e) {
            $this->flashType = 'error';
            $this->flashMessage = 'Unable to read scheduled tasks: '.$e->getMessage();

            return [];
        }
    }

    /**
     * Pending queued jobs. Only supported for the database queue driver, whose jobs
     * live in a readable table; other drivers (redis, sqs, ...) are opaque here.
     */
    protected function getPendingJobs(): array
    {
        if ($this->getQueueDriver() !== 'database') {
            return [];
        }

        $connection = config('queue.connections.'.config('queue.default').'.connection');
        $table = config('queue.connections.'.config('queue.default').'.table', 'jobs');

        try {
            if (! Schema::connection($connection)->hasTable($table)) {
                return [];
            }

            return DB::connection($connection)->table($table)
                ->orderBy('id')
                ->limit($this->maxRows())
                ->get()
                ->map(function ($job) {
                    $payload = json_decode($job->payload ?? '', true) ?: [];

                    return [
                        'id' => $job->id,
                        'name' => $payload['displayName'] ?? ($payload['job'] ?? 'Unknown'),
                        'queue' => $job->queue,
                        'attempts' => $job->attempts,
                        'reserved' => ! empty($job->reserved_at),
                        'availableAt' => $this->timestampToString($job->available_at ?? null),
                        'createdAt' => $this->timestampToString($job->created_at ?? null),
                    ];
                })->all();
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * Failed jobs from the queue failed-job store (when it is a database table).
     */
    protected function getFailedJobs(): array
    {
        if (! in_array(config('queue.failed.driver'), ['database', 'database-uuids'], true)) {
            return [];
        }

        $connection = config('queue.failed.database');
        $table = config('queue.failed.table', 'failed_jobs');

        try {
            if (! Schema::connection($connection)->hasTable($table)) {
                return [];
            }

            return DB::connection($connection)->table($table)
                ->orderByDesc('id')
                ->limit($this->maxRows())
                ->get()
                ->map(function ($job) {
                    $payload = json_decode($job->payload ?? '', true) ?: [];

                    return [
                        'uuid' => $job->uuid,
                        'name' => $payload['displayName'] ?? ($payload['job'] ?? 'Unknown'),
                        'connection' => $job->connection,
                        'queue' => $job->queue,
                        'exception' => str($job->exception ?? '')->limit(300)->toString(),
                        'failedAt' => (string) $job->failed_at,
                    ];
                })->all();
        } catch (Throwable $e) {
            return [];
        }
    }

    /* Actions
    --------------------------------------------------------------------------*/

    public function refresh(): void
    {
        $this->reset(['flashMessage', 'flashType']);
    }

    /**
     * Run a single scheduled task on demand, identified by its stable id (sha1 of the
     * event mutex name). Command events are dispatched through Artisan so their output
     * can be captured; closures are invoked directly via the event itself.
     */
    public function runTask(string $id): void
    {
        if (! WRLAHelper::schedulerCanRunAdhoc()) {
            abort(403);
        }

        try {
            app(ConsoleKernel::class)->all();

            $event = collect(app(Schedule::class)->events())
                ->first(fn ($event) => sha1($event->mutexName()) === $id);

            if ($event === null) {
                $this->flash('Task could not be found. Try refreshing the page.', 'error');

                return;
            }

            $label = $event->description ?: $this->cleanCommand($event->command) ?: 'Task';

            if (! ($event instanceof CallbackEvent) && $event->command) {
                Artisan::call($this->cleanCommand($event->command));
            } else {
                $event->run(app());
            }

            $this->flash('"'.$label.'" ran successfully.');
        } catch (Throwable $e) {
            $this->flash('Task failed: '.$e->getMessage(), 'error');
        }
    }

    public function retryFailed(string $uuid): void
    {
        $this->guard();

        try {
            Artisan::call('queue:retry', ['id' => [$uuid]]);
            $this->flash('Failed job pushed back onto the queue.');
        } catch (Throwable $e) {
            $this->flash('Could not retry job: '.$e->getMessage(), 'error');
        }
    }

    public function retryAllFailed(): void
    {
        $this->guard();

        try {
            Artisan::call('queue:retry', ['id' => ['all']]);
            $this->flash('All failed jobs pushed back onto the queue.');
        } catch (Throwable $e) {
            $this->flash('Could not retry jobs: '.$e->getMessage(), 'error');
        }
    }

    public function forgetFailed(string $uuid): void
    {
        $this->guard();

        try {
            Artisan::call('queue:forget', ['id' => $uuid]);
            $this->flash('Failed job deleted.');
        } catch (Throwable $e) {
            $this->flash('Could not delete job: '.$e->getMessage(), 'error');
        }
    }

    public function flushFailed(): void
    {
        $this->guard();

        try {
            Artisan::call('queue:flush');
            $this->flash('All failed jobs cleared.');
        } catch (Throwable $e) {
            $this->flash('Could not clear failed jobs: '.$e->getMessage(), 'error');
        }
    }

    public function deletePendingJob(int $id): void
    {
        $this->guard();

        if ($this->getQueueDriver() !== 'database') {
            return;
        }

        $connection = config('queue.connections.'.config('queue.default').'.connection');
        $table = config('queue.connections.'.config('queue.default').'.table', 'jobs');

        try {
            DB::connection($connection)->table($table)->where('id', $id)->delete();
            $this->flash('Pending job deleted.');
        } catch (Throwable $e) {
            $this->flash('Could not delete pending job: '.$e->getMessage(), 'error');
        }
    }

    /* Helpers
    --------------------------------------------------------------------------*/

    protected function guard(): void
    {
        if (! WRLAHelper::schedulerEnabled()) {
            abort(403);
        }
    }

    protected function flash(string $message, string $type = 'success'): void
    {
        $this->flashMessage = $message;
        $this->flashType = $type;
    }

    protected function getQueueDriver(): string
    {
        return (string) config('queue.connections.'.config('queue.default').'.driver', config('queue.default'));
    }

    protected function maxRows(): int
    {
        return (int) config('wr-laravel-administration.scheduler.max_rows', 100);
    }

    protected function cleanCommand(?string $command): ?string
    {
        if ($command === null) {
            return null;
        }

        // Strip the PHP binary + artisan path prefix, leaving just the artisan command.
        return trim(preg_replace("/^.*?artisan[\"']?\s+/", '', $command)) ?: $command;
    }

    protected function nextRunDate(string $expression, string $timezone): ?string
    {
        try {
            return Carbon::instance(
                (new CronExpression($expression))->getNextRunDate('now', 0, false, $timezone)
            )->toDateTimeString(); //.' ('.$timezone.')';
        } catch (Throwable $e) {
            return null;
        }
    }

    protected function timestampToString($timestamp): ?string
    {
        if (empty($timestamp)) {
            return null;
        }

        return Carbon::createFromTimestamp($timestamp)->toDateTimeString();
    }
}

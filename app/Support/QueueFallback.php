<?php

namespace App\Support;

use Carbon\CarbonInterface;
use Cron\CronExpression;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;

/**
 * Keeps the store's background work moving when the cron that runs the
 * scheduler stops firing.
 *
 * Every queued job here — the order SMS and invoice email, the BDCourier
 * auto-check, Meta events and catalogue syncs — waits for the scheduler's
 * minutely `queue:work` (routes/console.php, DEPLOY.md §4), and the scheduler
 * only runs when cPanel's cron fires it. On 3 Oct 2026 that cron stopped and
 * nothing in the app noticed: 78 jobs sat for a day, and new customers'
 * courier history was never looked up. The host also disables exec(), so
 * MetaQueueRunner's detached worker could never step in.
 *
 * So the scheduler now leaves a heartbeat every minute, and after a web
 * response has gone out (LiteSpeed's finish-request — the visitor is not kept
 * waiting) a missing heartbeat lets that request stand in for the cron:
 *
 *  - it works through the queue — at most once a minute across the site, for
 *    at most ~45 seconds, one job at a time (`--once`, so no worker signal
 *    handlers are installed in the web process);
 *  - then it runs the timed tasks (Steadfast sync, abandoned-cart reminders,
 *    Meta checks, the daily SMS passes). A request does not arrive every
 *    minute, so a task whose minute nobody visited runs at the next request
 *    instead of being skipped — once, however many of its minutes were missed.
 *
 * While the cron is healthy this costs one cache read.
 */
class QueueFallback
{
    public const HEARTBEAT = 'scheduler:heartbeat';

    /** How long without a heartbeat before the cron counts as missing. */
    public const STALE_AFTER = 180;

    /** The minute the web last ran the schedule for (a timestamp). */
    public const SCHEDULE_TICK = 'scheduler:web-tick';

    /** What the last web run did — for whoever is diagnosing it. */
    public const SCHEDULE_REPORT = 'scheduler:web-report';

    private const LOCK = 'queue:web-drain';

    private const SCHEDULE_LOCK = 'scheduler:web-run';

    private const BUDGET_SECONDS = 45;

    /**
     * Never run from the web: the heartbeat is the cron's own proof of life
     * (beating it here would hide that the cron is gone and stop this very
     * fallback), and the queue is drained above in-process.
     */
    private const CRON_ONLY = ['scheduler-heartbeat', 'meta-queue-drain'];

    /** Missed minutes further back than this are not made up. */
    private const CATCH_UP_SECONDS = 86400;

    /** The scheduler calls this every minute. */
    public static function beat(): void
    {
        Cache::put(self::HEARTBEAT, now()->getTimestamp(), now()->addDay());
    }

    /** Seconds since the scheduler last ran, or null when it has not been seen. */
    public static function schedulerAge(): ?int
    {
        $last = Cache::get(self::HEARTBEAT);

        return $last ? max(0, now()->getTimestamp() - (int) $last) : null;
    }

    public static function schedulerRunning(): bool
    {
        $age = static::schedulerAge();

        return $age !== null && $age < self::STALE_AFTER;
    }

    /** After a web response: do the cron's work in-process if the cron is missing. */
    public function afterResponse(): void
    {
        try {
            if (config('queue.default') !== 'database' || static::schedulerRunning()) {
                return;
            }

            // Links built from here on go into texts and emails, and must not
            // take the host of whichever request is standing in for the cron.
            // Steadfast still posts its webhook to the old meridianeclat.shop
            // address (exempt from the redirect), so a delivery's review
            // request went out pointing there — and a signed link signed for
            // that host fails its check once the redirect lands on this one.
            // The response has already gone, so nothing else sees this.
            URL::forceRootUrl(config('app.url'));
        } catch (\Throwable $e) {
            Log::error('Queue fallback could not start', ['error' => $e->getMessage()]);

            return;
        }

        $this->drainQueue();
        $this->runMissedSchedule();
    }

    private function drainQueue(): void
    {
        try {
            // One drain per minute for the whole site, whichever request gets
            // here first — but only a request with something to drain takes
            // the lock. An idle request holding it would make the order placed
            // ten seconds later wait out the minute as well.
            if (! $this->hasWaitingJobs() || ! Cache::add(self::LOCK, 1, 60)) {
                return;
            }

            ignore_user_abort(true);
            @set_time_limit(self::BUDGET_SECONDS + 150);

            $deadline = microtime(true) + self::BUDGET_SECONDS;
            while (microtime(true) < $deadline && $this->hasWaitingJobs()) {
                Artisan::call('queue:work', [
                    'connection' => 'database',
                    '--queue' => config('meta.sync.queue', 'default'),
                    '--once' => true,
                    '--sleep' => 0,
                    '--no-interaction' => true,
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('Queue fallback drain failed', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Run every scheduled task that has come due since the scheduler last ran,
     * whether the cron ran it (the heartbeat) or a request did (the tick).
     */
    private function runMissedSchedule(): void
    {
        $minute = Date::now()->startOfMinute();

        try {
            if ((int) Cache::get(self::SCHEDULE_TICK) >= $minute->getTimestamp()
                || ! Cache::add(self::SCHEDULE_LOCK, 1, 600)) {
                return;
            }
        } catch (\Throwable $e) {
            Log::error('Schedule fallback could not start', ['error' => $e->getMessage()]);

            return;
        }

        try {
            // Read again under the lock: another request may have finished
            // this minute between the check above and taking the lock.
            $tick = (int) Cache::get(self::SCHEDULE_TICK);
            if ($tick >= $minute->getTimestamp()) {
                return;
            }
            Cache::put(self::SCHEDULE_TICK, $minute->getTimestamp(), now()->addDays(2));

            if (app()->isDownForMaintenance()) {
                return;
            }

            ignore_user_abort(true);
            @set_time_limit(600);

            $since = max($tick, (int) Cache::get(self::HEARTBEAT));
            $since = $since > $minute->getTimestamp() - self::CATCH_UP_SECONDS ? $since : null;

            // routes/console.php defines the schedule, and a web request has
            // not loaded it.
            app(ConsoleKernel::class)->bootstrap();

            $report = [];
            foreach (app(Schedule::class)->events() as $event) {
                if (in_array($event->description, self::CRON_ONLY, true)
                    || ! $this->dueSince($event, $since, $minute)
                    || ! $event->filtersPass(app())) {
                    continue;
                }

                $report[$event->description ?: $event->command] = $this->runEvent($event);
            }

            if ($report) {
                Cache::put(self::SCHEDULE_REPORT, ['at' => now()->toIso8601String(), 'ran' => $report], now()->addDays(2));
            }
        } catch (\Throwable $e) {
            Log::error('Schedule fallback failed', ['error' => $e->getMessage()]);
        } finally {
            Cache::forget(self::SCHEDULE_LOCK);
        }
    }

    /**
     * Whether the task had a run due after $since and up to this minute. With
     * nothing to go on — no cron or request run in the last day — only the
     * tasks due this very minute count, so a long silence is not replayed.
     */
    private function dueSince(Event $event, ?int $since, CarbonInterface $minute): bool
    {
        if ($since === null) {
            return $event->isDue(app());
        }

        $at = $minute->copy();
        if ($event->timezone) {
            $at = $at->setTimezone($event->timezone);
        }

        $previous = (new CronExpression($event->expression))->getPreviousRunDate($at, 0, true);

        return $previous->getTimestamp() > $since && $event->runsInEnvironment(app()->environment());
    }

    /** Runs one task and says how it went; a failing task never stops the rest. */
    private function runEvent(Event $event): string
    {
        try {
            $event->run(app());

            if ($event->skippedBecauseOverlapping) {
                return 'still running from before';
            }

            if ($event->exitCode && ! $event instanceof CallbackEvent) {
                Log::error('Scheduled task failed in the web fallback', [
                    'task' => $event->description ?: $event->command,
                    'exit_code' => $event->exitCode,
                ]);

                return 'failed (exit '.$event->exitCode.')';
            }

            return 'ok';
        } catch (\Throwable $e) {
            report($e);

            return 'failed: '.$e->getMessage();
        }
    }

    /** A job that is due and not held by a live worker. */
    private function hasWaitingJobs(): bool
    {
        $now = now()->getTimestamp();
        $retryAfter = (int) config('queue.connections.database.retry_after', 90);

        return DB::table(config('queue.connections.database.table', 'jobs'))
            ->where('queue', config('meta.sync.queue', 'default'))
            ->where('available_at', '<=', $now)
            ->where(fn ($q) => $q->whereNull('reserved_at')->orWhere('reserved_at', '<=', $now - $retryAfter))
            ->exists();
    }
}

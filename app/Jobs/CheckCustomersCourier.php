<?php

namespace App\Jobs;

use App\Models\CourierCheck;
use App\Models\Customer;
use App\Rules\BdPhone;
use App\Services\BdCourierService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * "Check next 50 customers": BDCourier lookups for a batch of customers
 * nobody has checked yet, worked through in the background.
 *
 * Owner, 2026-09-17. Only 39 of 670 customers had ever been looked up, every
 * lookup costs a plan credit, and she chose "Batches when I click" over
 * checking everyone automatically — so nothing here runs unless she presses
 * the button, and one press spends at most fifty credits.
 *
 * Why queued: fifty lookups one after another are fifty HTTP calls of up to
 * twenty seconds each. Cloudflare drops a request after a hundred seconds and
 * the shared host's PHP gives up sooner, so doing them inside the click would
 * die part way through with the credits already spent and nothing on screen
 * to say how far it got.
 *
 * Why in short runs: the database queue hands a reserved job to the next
 * worker once `retry_after` (90 s) has passed, so one job grinding through
 * all fifty could be picked up a second time and pay twice. A run spends at
 * most `$budgetSeconds` on lookups, then passes the customers still to do to a
 * fresh job. Only one of these jobs is ever queued at a time, so a batch never
 * makes two lookups at once, and the progress below needs no locking.
 *
 * Progress lives in the cache, not the database — the customer list's banner
 * polls it, and it is worth nothing once the batch is over. A deploy's
 * `optimize:clear` wiping it mid-run just ends the batch: the job sees its
 * record has gone and stops, the lookups already made are safe in
 * `courier_checks`, and the customers it had not reached are still "never
 * checked", so the next press picks them up.
 */
class CheckCustomersCourier implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Customers one press looks up — and so the most credits it can spend. */
    public const SIZE = 50;

    /** The batch's progress record. */
    public const STATE_KEY = 'customers.courier-batch';

    /** Held while a batch runs; refreshed by every step, so a dead batch lets go. */
    public const RUNNING_KEY = 'customers.courier-batch.running';

    /**
     * A batch that has not moved for this long is treated as dead, and a new
     * one may start. Generous: the scheduler only drains the queue once a
     * minute, and other work can be queued ahead of this.
     */
    public const STALE_MINUTES = 10;

    /** How long the finished result stays on the customer list. */
    public const KEEP_RESULT_MINUTES = 60;

    /**
     * Never retried: a retry would repeat lookups that were already paid for.
     * A run that dies is reported through failed(), and the customers it had
     * not reached are simply still unchecked.
     */
    public int $tries = 1;

    /** Budget plus one slow lookup (20 s) plus slack, and under retry_after. */
    public int $timeout = 80;

    /** Seconds one run may spend before handing the rest to a fresh job. */
    public int $budgetSeconds = 40;

    /** @param  array<int, int>  $customerIds  still to look up, in order */
    public function __construct(public string $batchId, public array $customerIds) {}

    /**
     * Claim the single batch slot and queue the work.
     *
     * Returns null when a batch is already running. `Cache::add` is atomic in
     * the database cache store, so a double-click or a second admin pressing
     * at the same moment cannot start two batches over the same customers.
     *
     * @param  array<int, int>  $customerIds
     */
    public static function start(array $customerIds): ?string
    {
        $batchId = (string) Str::uuid();

        if (! Cache::add(self::RUNNING_KEY, $batchId, now()->addMinutes(self::STALE_MINUTES))) {
            return null;
        }

        $ids = array_values(array_map('intval', $customerIds));
        $now = now()->toIso8601String();

        Cache::put(self::STATE_KEY, [
            'id' => $batchId,
            'total' => count($ids),
            // done = every customer dealt with; the three after it say how.
            'done' => 0,
            'checked' => 0,
            'failed' => 0,
            'skipped' => 0,
            'error' => null,
            'stopped' => false,
            'started_at' => $now,
            'updated_at' => $now,
            'finished_at' => null,
        ], now()->addHours(6));

        try {
            self::dispatch($batchId, $ids);
        } catch (\Throwable $e) {
            // Nothing was queued, so nothing is running: let go of the slot.
            Cache::forget(self::RUNNING_KEY);
            Cache::forget(self::STATE_KEY);

            throw $e;
        }

        return $batchId;
    }

    /**
     * The latest batch as the customer list shows it, or null if there is
     * none worth mentioning.
     *
     * `interrupted` is a batch that never finished and whose running flag has
     * lapsed — a worker that died, or a job stuck behind a long queue. Saying
     * so beats a progress bar that sits at "12 done" forever.
     *
     * @return array<string, mixed>|null
     */
    public static function status(): ?array
    {
        $state = Cache::get(self::STATE_KEY);

        if (! is_array($state) || ! isset($state['id'])) {
            return null;
        }

        $finished = filled($state['finished_at'] ?? null);
        $running = ! $finished && Cache::get(self::RUNNING_KEY) === $state['id'];

        return $state + [
            'running' => $running,
            'finished' => $finished,
            'interrupted' => ! $finished && ! $running,
        ];
    }

    public function handle(BdCourierService $bdCourier): void
    {
        $state = $this->state();

        if ($state === null) {
            return; // finished, superseded by a newer batch, or wiped by a deploy
        }

        if (! $bdCourier->isConfigured()) {
            $this->finish($state, 'BDCourier is not configured. Add the API key under Admin → Integrations.', stopped: true);

            return;
        }

        $startedAt = microtime(true);
        $ids = array_values($this->customerIds);
        $lookups = 0;

        while ($ids !== []) {
            // At least one lookup per run, so a run always moves the batch on.
            if ($lookups > 0 && microtime(true) - $startedAt >= $this->budgetSeconds) {
                self::dispatch($this->batchId, $ids);

                return;
            }

            // Read again before every paid call: a newer batch, or a cache
            // flush, means this one no longer has anyone watching it.
            if ($this->state() === null) {
                return;
            }

            $phone = $this->checkablePhone((int) array_shift($ids));
            $state['done']++;

            if ($phone === null) {
                $state['skipped']++;
                $this->save($state);

                continue;
            }

            try {
                $result = $bdCourier->check($phone);
            } catch (\Throwable $e) {
                // check() catches the HTTP failures itself; this is storing the
                // result going wrong. One bad row must not end the batch.
                report($e);
                $result = ['ok' => false, 'error' => 'Could not save the courier history for '.$phone.'.'];
            }
            $lookups++;

            if ($result['ok'] ?? false) {
                $state['checked']++;
                $this->save($state);

                continue;
            }

            $error = (string) ($result['error'] ?? 'BDCourier lookup failed.');
            $state['failed']++;
            $state['error'] ??= $error;

            // Exactly BdCourierService::checkMany()'s early stop: a rejected key
            // or an exhausted quota fails for every number after this one too,
            // and forty-nine more refusals would tell the owner nothing new.
            if (str_contains($error, 'API key') || str_contains($error, 'quota')) {
                $this->finish($state, $error, stopped: true);

                return;
            }

            $this->save($state);
        }

        $this->finish($state);
    }

    /** The run died (a timeout, a worker killed mid-lookup): say where it got to. */
    public function failed(?\Throwable $e = null): void
    {
        $state = $this->state();

        if ($state !== null) {
            $this->finish($state, 'the background job stopped unexpectedly. Press “Check next '.self::SIZE.' customers” to carry on.', stopped: true);
        }
    }

    /**
     * The number to look up, or null when this customer should not cost a
     * credit after all: the number was removed or is not a Bangladeshi mobile,
     * or someone checked it — from the order page, the customer page — in the
     * minutes since the batch was chosen. Paying twice buys the same answer.
     */
    protected function checkablePhone(int $customerId): ?string
    {
        $phone = (string) Customer::whereKey($customerId)->value('phone');

        if ($phone === '' || ! preg_match(BdPhone::PATTERN, $phone)) {
            return null;
        }

        return CourierCheck::where('phone', $phone)->exists() ? null : $phone;
    }

    /** This batch's progress record, or null if it is no longer the live one. */
    protected function state(): ?array
    {
        $state = Cache::get(self::STATE_KEY);

        return is_array($state) && ($state['id'] ?? null) === $this->batchId && blank($state['finished_at'] ?? null)
            ? $state
            : null;
    }

    protected function save(array $state): void
    {
        $state['updated_at'] = now()->toIso8601String();

        Cache::put(self::STATE_KEY, $state, now()->addHours(6));
        Cache::put(self::RUNNING_KEY, $this->batchId, now()->addMinutes(self::STALE_MINUTES));
    }

    protected function finish(array $state, ?string $error = null, bool $stopped = false): void
    {
        $state['error'] = $error ?? $state['error'];
        $state['stopped'] = $stopped;
        $state['updated_at'] = $state['finished_at'] = now()->toIso8601String();

        Cache::put(self::STATE_KEY, $state, now()->addMinutes(self::KEEP_RESULT_MINUTES));

        if (Cache::get(self::RUNNING_KEY) === $this->batchId) {
            Cache::forget(self::RUNNING_KEY);
        }
    }
}

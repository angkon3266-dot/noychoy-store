<?php

namespace App\Console\Commands;

use App\Jobs\SendReviewRequest;
use App\Models\Order;
use App\Models\Setting;
use Illuminate\Console\Command;

/**
 * Catch-up for the post-delivery review request.
 *
 *   php artisan reviews:request --dry     (list who would be asked)
 *   php artisan reviews:request
 *
 * Since 4 Oct 2026 the request itself goes the moment Steadfast confirms a
 * delivery (SteadfastService::afterSettled → App\Support\ReviewRequests). This
 * nightly pass only picks up courier-confirmed deliveries that were missed —
 * the automation was off at the time, or the send reached nobody and was
 * un-stamped. An order marked delivered by hand is never asked.
 *
 * Off by default. This sends paid SMS, so the day it is switched on every
 * courier-delivered order inside the max-age window becomes due at once —
 * hence the window and the per-run cap. Dry-run first.
 */
class RunReviewRequests extends Command
{
    protected $signature = 'reviews:request {--dry : List the orders that would be asked, without sending}';

    protected $description = 'Catch up review requests for courier-confirmed deliveries that were missed.';

    public function handle(): int
    {
        if (! (bool) Setting::get('review_request_enabled', false)) {
            $this->info('Post-delivery review requests are turned off.');

            return self::SUCCESS;
        }

        $delayDays = max(1, (int) Setting::get('review_request_delay_days', 3));
        $maxDays = max($delayDays + 1, (int) Setting::get('review_request_max_days', 30));
        $perRun = max(1, (int) Setting::get('review_request_per_run', 100));

        $due = static::dueQuery($delayDays, $maxDays)
            ->orderBy('id')
            ->limit($perRun)
            ->get();

        if ($due->isEmpty()) {
            $this->info('No delivered orders are due for a review request right now.');

            return self::SUCCESS;
        }

        if ($this->option('dry')) {
            $this->info("Would ask {$due->count()} order(s): ".$due->pluck('order_number')->implode(', '));

            return self::SUCCESS;
        }

        foreach ($due as $order) {
            // Stamp BEFORE dispatching. At-most-once is the right failure mode
            // for a paid SMS: a crash between these two lines costs one silent
            // miss, whereas the other order costs the shop a duplicate send to
            // a customer who has already been asked.
            $order->forceFill(['review_request_sent_at' => now()])->saveQuietly();
            SendReviewRequest::dispatch($order);
        }

        $this->info("Review requests queued for {$due->count()} order(s).");

        return self::SUCCESS;
    }

    /**
     * Orders the courier confirmed delivered inside the asking window and that
     * have not been asked yet. "Confirmed" means a Steadfast consignment of the
     * order reads delivered (not partial, not awaiting approval) — a status
     * someone set by hand does not count.
     *
     * The delivery time is read straight out of order_status_history, which
     * TransitionOrderStatus already writes on every status change — so orders
     * delivered before this feature shipped need no backfill.
     */
    public static function dueQuery(int $delayDays, int $maxDays)
    {
        $from = now()->subDays($maxDays);
        $until = now()->subDays($delayDays);

        return Order::query()
            ->where('status', 'delivered')
            ->whereNull('review_request_sent_at')
            ->whereNotNull('customer_phone')
            ->whereDoesntHave('customer', fn ($q) => $q->where('blacklisted', true))
            ->whereHas('shipments', fn ($q) => $q
                ->where('status', 'like', '%delivered%')
                ->where('status', 'not like', '%partial%')
                ->where('status', 'not like', '%approval_pending'))
            ->whereExists(fn ($q) => $q->selectRaw('1')
                ->from('order_status_history')
                ->whereColumn('order_status_history.order_id', 'orders.id')
                ->where('order_status_history.status', 'delivered')
                ->whereBetween('order_status_history.created_at', [$from, $until]));
    }
}

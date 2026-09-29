<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Services\SteadfastService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Brings every order with an open Steadfast consignment up to date with what
 * the courier says: delivered, partially delivered or cancelled orders move
 * there by themselves, and picked-up ones move to shipped.
 *
 * The webhook does this as callbacks arrive. This sweep exists because many of
 * them never do, and a missed "delivered" callback left the order sitting at
 * "shipped" until someone opened it (see
 * SteadfastService::syncCurrentConsignment()).
 */
class SyncSteadfastStatuses extends Command
{
    protected $signature = 'steadfast:sync {--days=45 : Only consignments booked within this many days}
        {--no-sms : Do not text customers (for catching up a backlog)}';

    protected $description = 'Move orders to delivered / partially delivered / cancelled from their live Steadfast status.';

    /** Orders whose status the courier can no longer change by itself. */
    protected const DONE = ['delivered', 'partially_delivered', 'cancelled', 'returned'];

    public function handle(SteadfastService $steadfast): int
    {
        if (! $steadfast->isConfigured()) {
            $this->info('Steadfast is not configured.');

            return self::SUCCESS;
        }

        $orders = Order::query()
            ->whereNotIn('status', self::DONE)
            ->whereHas('shipment', fn ($s) => $s->whereNotNull('consignment_id')
                ->whereNull('superseded_at')
                ->where('created_at', '>=', now()->subDays(max(1, (int) $this->option('days')))))
            ->get();

        $moved = 0;
        $failed = 0;

        foreach ($orders as $order) {
            if ($order->shipment?->isSettled()) {
                // Settled at the courier but the order did not follow — a
                // held-back cancellation, say. Nothing new to learn by asking.
                continue;
            }

            try {
                $from = $order->status;
                if ($steadfast->syncCurrentConsignment($order, 'Courier sync', ! $this->option('no-sms'))) {
                    $moved++;
                    $this->line($order->order_number.': '.$from.' → '.$order->fresh()->status);
                }
            } catch (\Throwable $e) {
                $failed++;
                $this->warn($order->order_number.': '.$e->getMessage());
            }
        }

        // Production logs at error level only, so a warning would vanish.
        if ($failed) {
            Log::error('steadfast:sync could not read some consignments', ['failed' => $failed, 'checked' => $orders->count()]);
        }

        $this->info("Checked {$orders->count()}, moved {$moved}, failed {$failed}.");

        return self::SUCCESS;
    }
}

<?php

namespace App\Console\Commands;

use App\Jobs\SendAbandonedCartSms;
use App\Models\AbandonedCart;
use App\Models\Setting;
use Illuminate\Console\Command;

/**
 * Text shoppers who left a full cart behind.
 *
 *   php artisan sms:abandoned-cart --dry
 *   php artisan sms:abandoned-cart
 *
 * Off by default — it spends SMS credit. The same three brakes as the review
 * requests: a delay so nobody is chased while they are still deciding, a
 * max-age window so switching it on does not text months of history at once,
 * and a per-run cap.
 */
class RunAbandonedCartSms extends Command
{
    protected $signature = 'sms:abandoned-cart {--dry : List who would be texted, without sending}';

    protected $description = 'Text shoppers who typed their phone at checkout and left without ordering.';

    public function handle(): int
    {
        if (! (bool) Setting::get('abandoned_sms_enabled', false)) {
            $this->info('Abandoned-cart SMS is turned off.');

            return self::SUCCESS;
        }

        $delayMinutes = max(15, (int) Setting::get('abandoned_sms_delay_minutes', 60));
        $maxHours = max(2, (int) Setting::get('abandoned_sms_max_hours', 48));
        $perRun = max(1, (int) Setting::get('abandoned_sms_per_run', 50));

        $due = static::dueQuery($delayMinutes, $maxHours)
            ->orderBy('id')
            ->limit($perRun)
            ->get();

        if ($due->isEmpty()) {
            $this->info('Nobody is due an abandoned-cart reminder right now.');

            return self::SUCCESS;
        }

        if ($this->option('dry')) {
            $this->info("Would text {$due->count()} cart(s): ".$due->pluck('phone')->implode(', '));

            return self::SUCCESS;
        }

        foreach ($due as $cart) {
            // Stamped before dispatch so a crash costs one silent miss rather
            // than a duplicate paid message; the job clears it if the gateway
            // refused, so an outage does not exclude anyone permanently.
            $cart->forceFill(['sms_reminded_at' => now()])->saveQuietly();
            SendAbandonedCartSms::dispatch($cart);
        }

        $this->info("Abandoned-cart reminders queued for {$due->count()} cart(s).");

        return self::SUCCESS;
    }

    /** Carts old enough to chase, young enough to be worth chasing. */
    public static function dueQuery(int $delayMinutes, int $maxHours)
    {
        return AbandonedCart::query()
            ->where('recovered', false)
            ->whereNull('sms_reminded_at')
            ->whereNotNull('phone')
            ->where('item_count', '>', 0)
            ->where('updated_at', '<=', now()->subMinutes($delayMinutes))
            ->where('updated_at', '>=', now()->subHours($maxHours));
    }
}

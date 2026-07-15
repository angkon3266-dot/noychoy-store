<?php

namespace App\Console\Commands;

use App\Models\AbandonedCart;
use App\Models\Customer;
use App\Services\NotificationService;
use App\Services\PushTemplateService;
use Illuminate\Console\Command;

/**
 * Push a reminder to members who left items in their cart. Runs on a schedule;
 * a no-op unless web push is ready and the abandoned_cart template is enabled.
 */
class RunAbandonedCartPush extends Command
{
    protected $signature = 'push:abandoned-cart {--minutes=60 : Only carts abandoned at least this long ago}';

    protected $description = 'Send a web-push reminder to members with abandoned carts (once each).';

    public function handle(NotificationService $notifications, PushTemplateService $templates): int
    {
        if (! app(\App\Services\WebPushService::class)->ready() || ! $templates->enabled('abandoned_cart')) {
            $this->info('Abandoned-cart push is off or web push is not ready.');

            return self::SUCCESS;
        }

        $cutoff = now()->subMinutes(max(5, (int) $this->option('minutes')));

        $carts = AbandonedCart::where('recovered', false)
            ->whereNull('push_reminded_at')
            ->whereNotNull('phone')
            ->where('updated_at', '<=', $cutoff)
            ->get();

        $sent = 0;
        foreach ($carts as $cart) {
            $member = Customer::where('phone', $cart->phone)->whereNotNull('password')->first();
            if (! $member) {
                continue;
            }

            $items = collect($cart->items ?? []);
            $first = $items->first();
            $name = $first['name'] ?? $first['title'] ?? 'your items';
            $summary = $items->count() > 1 ? $name.' + '.($items->count() - 1).' more' : $name;

            $payload = $templates->forCart($member->name, $summary);
            if ($payload) {
                $notifications->pushToCustomer($member->id, $payload);
                $sent++;
            }
            $cart->forceFill(['push_reminded_at' => now()])->saveQuietly();
        }

        $this->info("Abandoned-cart push: {$sent} reminder(s) sent.");

        return self::SUCCESS;
    }
}

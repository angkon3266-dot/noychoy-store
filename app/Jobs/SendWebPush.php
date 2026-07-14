<?php

namespace App\Jobs;

use App\Models\PushSubscription;
use App\Services\WebPushService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Deliver one web-push payload to a batch of subscription IDs. Endpoints that
 * report gone (404/410) are pruned so the list stays clean.
 */
class SendWebPush implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @param  array<int>  $subscriptionIds
     * @param  array<string, mixed>  $payload
     */
    public function __construct(public array $subscriptionIds, public array $payload) {}

    public function handle(WebPushService $push): void
    {
        if (! $push->ready()) {
            return;
        }

        PushSubscription::whereIn('id', $this->subscriptionIds)->each(function (PushSubscription $sub) use ($push) {
            $status = $push->send($sub, $this->payload);

            if (in_array($status, [404, 410], true)) {
                $sub->delete();                 // subscription is gone for good
            } elseif ($status >= 200 && $status < 300) {
                $sub->forceFill(['last_used_at' => now()])->saveQuietly();
            }
        });
    }
}

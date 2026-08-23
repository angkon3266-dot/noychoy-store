<?php

namespace App\Jobs;

use App\Mail\ReviewRequestMail;
use App\Models\Order;
use App\Services\SmsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;

/**
 * Ask the buyer to rate what they bought, a few days after the courier
 * confirmed delivery. Queued from `reviews:request`, never inline.
 *
 * Each channel is wrapped separately: a dead SMS gateway must not stop the
 * email going out, and neither must fail the job — the order has already been
 * stamped as asked, so a throw here would just burn the retry.
 */
class SendReviewRequest implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 120;

    public bool $deleteWhenMissingModels = true;

    public function __construct(public Order $order) {}

    public function handle(SmsService $sms): void
    {
        $order = $this->order->fresh('items') ?? $this->order;

        // Signed rather than gated on login: this store is cash-on-delivery and
        // most buyers never register, so a link that demands an account would
        // reach almost nobody.
        $link = URL::signedRoute('order.review', ['orderNumber' => $order->order_number]);

        try {
            $sms->sendTemplate('review_request', $order, ['{link}' => $link]);
        } catch (\Throwable $e) {
            report($e);
        }

        // Email is optional at checkout — phone is the required field.
        if (filled($order->customer_email)) {
            try {
                Mail::to($order->customer_email)->send(new ReviewRequestMail($order, $link));
            } catch (\Throwable $e) {
                report($e);
            }
        }
    }
}

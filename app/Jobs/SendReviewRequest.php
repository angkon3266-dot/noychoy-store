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
use Illuminate\Support\Facades\Log;
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

        $smsOk = false;
        $mailOk = false;

        try {
            // sendTemplate returns false — it does not throw — when the gateway
            // is off, the balance is spent, or the provider rejects the message.
            $smsOk = (bool) $sms->sendTemplate('review_request', $order, ['{link}' => $link]);
        } catch (\Throwable $e) {
            report($e);
        }

        // Email is optional at checkout — phone is the required field.
        if (filled($order->customer_email)) {
            try {
                Mail::to($order->customer_email)->send(new ReviewRequestMail($order, $link));
                $mailOk = true;
            } catch (\Throwable $e) {
                report($e);
            }
        }

        // The order was stamped as asked BEFORE this job ran, so that a crash
        // costs one silent miss rather than a duplicate paid SMS. But if nothing
        // actually went out, that stamp is a lie that never expires: the order
        // is excluded from every future run for good. One pass with the SMS
        // balance at zero would otherwise burn the entire backlog permanently.
        if (! $smsOk && ! $mailOk) {
            $order->forceFill(['review_request_sent_at' => null])->saveQuietly();

            Log::warning('Review request reached nobody; un-stamped so it can be retried.', [
                'order' => $order->order_number,
                'had_email' => filled($order->customer_email),
            ]);
        }
    }
}

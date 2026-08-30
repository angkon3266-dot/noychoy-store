<?php

namespace App\Jobs;

use App\Http\Controllers\Shop\ReviewController;
use App\Mail\ReviewRequestMail;
use App\Models\Order;
use App\Services\ReviewThankYouOffer;
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

    public function handle(SmsService $sms, ReviewThankYouOffer $offers): void
    {
        $order = $this->order->fresh('items') ?? $this->order;

        // Signed rather than gated on login: this store is cash-on-delivery and
        // most buyers never register, so a link that demands an account would
        // reach almost nobody.
        $link = URL::signedRoute('order.review', ['orderNumber' => $order->order_number]);

        // The SMS gets the short form of the same link. A full signature is
        // 119 characters with the path — most of a segment — and the message
        // has to carry a coupon code as well. Email has no such budget and
        // keeps the signed URL.
        $smsLink = ReviewController::shortLink($order->order_number);

        // Minted before the send, because the code has to be inside the
        // message. Deriving it from the order number keeps a retry from
        // issuing her a second one.
        //
        // Wrapped like every other step: the order is stamped as asked BEFORE
        // this job runs, and the un-stamp net below sits after the sends — so
        // a throw here would exit past it and exclude her from every future
        // run, costing the review request itself over a discount that is only
        // a bonus.
        $offerLine = '';

        try {
            if ($coupon = $offers->forOrder($order)) {
                $offerLine = $offers->smsLine($coupon);
            }
        } catch (\Throwable $e) {
            report($e);
        }

        $smsOk = false;
        $mailOk = false;

        try {
            $template = $sms->template('review_request');

            // The owner can rewrite this template in Admin → Integrations. If
            // she has, and her wording predates the thank-you offer, the code
            // would silently never reach anyone — so append the placeholder
            // rather than quietly dropping a discount we already created.
            if ($template && $offerLine !== '' && ! str_contains($template, '{offer}')) {
                $template = rtrim($template).'{offer}';
            }

            // sendTemplate returns false — it does not throw — when the gateway
            // is off, the balance is spent, or the provider rejects the message.
            $smsOk = (bool) $sms->sendTemplate('review_request', $order, [
                // First name only. Extras win the array_merge in
                // sendTemplate(), so this narrows {name} for this message
                // alone — a four-word name would otherwise be the difference
                // between one paid segment and two.
                '{name}' => str($order->customer_name)->trim()->explode(' ')->first(),
                '{link}' => $smsLink,
                // Carries its own leading space so the template reads
                // "{link}{offer}" and loses nothing when there is no offer.
                '{offer}' => $offerLine === '' ? '' : ' '.$offerLine,
            ], $template);
        } catch (\Throwable $e) {
            report($e);
        }

        // Email is optional at checkout — phone is the required field.
        if (filled($order->customer_email)) {
            try {
                Mail::to($order->customer_email)->send(new ReviewRequestMail($order, $link, $offerLine));
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

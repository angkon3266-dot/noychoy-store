<?php

namespace App\Support;

use App\Jobs\SendReviewRequest;
use App\Models\Order;
use App\Models\Setting;

/**
 * When a customer is asked to review what they bought.
 *
 * Owner's rule (4 Oct 2026): after the "order received" text, the only SMS a
 * customer gets is the review request, and it goes the moment Steadfast
 * confirms the parcel was delivered — not a "your order has been delivered"
 * text, and not a day-counted pass afterwards. The nightly `reviews:request`
 * is left as a catch-up for courier-confirmed deliveries that were missed
 * (gateway down, automation off at the time); it never asks for an order
 * someone marked delivered by hand.
 */
class ReviewRequests
{
    public static function enabled(): bool
    {
        return (bool) Setting::get('review_request_enabled', false);
    }

    /**
     * Ask for this order's review now, if the automation is on and the order
     * can be asked. Stamped BEFORE dispatch, like the nightly pass: at-most-once
     * is the right failure mode for a paid SMS.
     *
     * @return bool True when a request was queued.
     */
    public static function askNow(Order $order): bool
    {
        if (! static::enabled()) {
            return false;
        }

        $order = $order->fresh() ?? $order;

        if ($order->status !== 'delivered' || $order->review_request_sent_at || blank($order->customer_phone)) {
            return false;
        }

        if ($order->customer?->blacklisted) {
            return false;
        }

        $order->forceFill(['review_request_sent_at' => now()])->saveQuietly();
        SendReviewRequest::dispatch($order);

        return true;
    }
}

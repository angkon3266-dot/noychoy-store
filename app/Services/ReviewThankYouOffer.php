<?php

namespace App\Services;

use App\Models\Coupon;
use App\Models\Order;
use App\Models\Setting;
use Illuminate\Support\Carbon;

/**
 * The thank-you discount that rides along with a post-delivery review request.
 *
 * A private percentage code, reserved to the buyer's own phone number and
 * valid for a few weeks. She is given it for having bought, not for leaving a
 * review — the review is the ask, this is the thank-you, and tying the reward
 * to a five-star rating is how stores end up buying their own ratings.
 *
 * The code is derived from the order number, so asking twice (a retried job,
 * a re-run after an SMS outage) mints the same coupon instead of a second one.
 */
class ReviewThankYouOffer
{
    /** No O/0, no I/L/1 — nothing left in here that can be misread off a screen. */
    protected const ALPHABET = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';

    protected const CODE_LENGTH = 6;

    public function enabled(): bool
    {
        return (bool) Setting::get('review_offer_enabled', false) && $this->percent() > 0;
    }

    public function percent(): float
    {
        return max(0, min(90, (float) Setting::get('review_offer_percent', 10)));
    }

    public function days(): int
    {
        return max(1, (int) Setting::get('review_offer_days', 30));
    }

    /**
     * Her coupon for this order — created on first ask, returned unchanged
     * afterwards. Null when the feature is off or the order has no phone to
     * reserve it against.
     */
    public function forOrder(Order $order): ?Coupon
    {
        if (! $this->enabled() || blank($order->customer_phone)) {
            return null;
        }

        $phone = bd_phone($order->customer_phone);
        $code = $this->codeFor($order);
        $existing = Coupon::where('code', $code)->first();

        if ($existing) {
            // Asked again for the same order — hand back the same coupon
            // rather than a second discount.
            if (bd_phone((string) $existing->reserved_for_phone) === $phone) {
                return $this->stillUsable($existing);
            }

            // A hash collision would otherwise hand this buyer somebody
            // else's private code: her SMS would disclose it, and checkout
            // would refuse her because the number does not match. Vanishingly
            // rare, silently wrong, so mint a distinct code instead.
            $code = $this->distinctCode($order);
        }

        return Coupon::create([
            'code' => $code,
            'label' => 'Review thank-you · order '.$order->order_number,
            'type' => 'percent',
            'value' => $this->percent(),
            'applies_to' => 'all',
            'usage_limit' => 1,
            'per_customer_limit' => 1,
            'reserved_for_phone' => $phone,
            // Hers alone, and it does not pile on top of the offers the
            // storefront is already running — the better of the two wins.
            'is_exclusive' => true,
            'expires_at' => Carbon::now()->addDays($this->days())->endOfDay(),
            'is_active' => true,
        ]);
    }

    /**
     * A re-used coupon must still be worth having when the message finally
     * goes out. The request can be re-queued days later — an SMS balance at
     * zero un-stamps the order and it comes round again — and the expiry
     * clock started at the first mint, so the text could otherwise promise a
     * date that has already passed.
     */
    protected function stillUsable(Coupon $coupon): Coupon
    {
        $floor = Carbon::now()->addDays(min(7, $this->days()))->endOfDay();

        if ($coupon->expires_at === null || $coupon->expires_at->lt($floor)) {
            $coupon->update(['expires_at' => Carbon::now()->addDays($this->days())->endOfDay()]);
        }

        return $coupon;
    }

    /** A code that is free right now, for the collision path. */
    protected function distinctCode(Order $order): string
    {
        do {
            $code = $this->encode(random_bytes(self::CODE_LENGTH));
        } while (Coupon::where('code', $code)->exists());

        return $code;
    }

    /**
     * A stable, unguessable-enough code per order.
     *
     * Derived from the app key so it cannot be enumerated from the order
     * number alone, and hex so there is no O/0 or I/1 to misread off a phone
     * screen. Guessing it buys nothing anyway — the code is refused unless the
     * checkout phone matches.
     */
    public function codeFor(Order $order): string
    {
        $digest = hash_hmac('sha256', 'review-thanks:'.$order->order_number, (string) config('app.key'), true);

        return $this->encode($digest);
    }

    /**
     * Six characters from a 31-symbol alphabet — about 887 million codes.
     *
     * Short because she has to read it off a text message and type it into a
     * phone with the other hand. The alphabet is the reason it can be short
     * and still not be mistyped: no O or 0, no I, L or 1, so there is no pair
     * left to confuse. Guessing is not the threat model — a code is refused
     * unless the checkout phone matches — so the length only has to keep
     * collisions rare, and a collision is caught and re-minted anyway.
     */
    protected function encode(string $bytes): string
    {
        $code = '';

        for ($i = 0; $i < self::CODE_LENGTH; $i++) {
            $code .= self::ALPHABET[ord($bytes[$i]) % strlen(self::ALPHABET)];
        }

        return $code;
    }

    /**
     * The offer half of her SMS, in as few characters as it can be said.
     *
     * Every word here is paid for: with the review link this has to fit
     * inside one 160-character segment, so the sentence carries only the four
     * things she needs — how much, on what, the code, and until when. Plain
     * GSM-7 characters only (no em dash, no curly quotes), or the gateway
     * silently switches the whole message to unicode and 160 becomes 70.
     */
    public function smsLine(Coupon $coupon): string
    {
        $percent = rtrim(rtrim(number_format((float) $coupon->value, 2), '0'), '.');

        // expires_at is nullable on the table and the admin coupon form lets
        // it be cleared, so this cannot assume a date exists — dereferencing
        // null here would throw inside a queued job that has already been
        // stamped as sent, silencing the request for good.
        $until = $coupon->expires_at ? ' till '.$coupon->expires_at->format('j M') : '';

        return $percent.'% off next order: '.$coupon->code.$until;
    }
}

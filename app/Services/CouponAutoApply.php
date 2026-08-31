<?php

namespace App\Services;

use App\Models\Coupon;

/**
 * Picks the coupon a shopper should get without typing anything.
 *
 * Keyed on the phone entered at checkout rather than a login, because almost
 * nobody here logs in: this is a cash-on-delivery shop and the phone is the
 * only identity an order actually carries. Until a phone is known, only an
 * "every order" coupon can apply — a coupon aimed at one person must not
 * reveal itself to whoever happens to be browsing.
 *
 * What it returns is an ordinary Coupon. The cart then weighs it against a
 * typed code and keeps whichever saves more, and PlaceOrder validates and
 * spends it through exactly the path a typed code takes — usage counts,
 * per-phone limits and the row lock all included.
 */
class CouponAutoApply
{
    /** Coupons are re-resolved on every cart price; the candidate set is not. */
    protected ?\Illuminate\Support\Collection $candidates = null;

    /**
     * The best auto-applying coupon for this shopper and this cart, or null.
     *
     * "Best" is measured in money off *including* delivery, for the same reason
     * the exclusive-coupon comparison is: a code worth 5% and free delivery
     * beats a flat 10% on a small order, and comparing only the percentages
     * would hand the customer the worse deal and call it the better one.
     */
    public function bestFor(?string $phone, CartService $cart, float $base): ?Coupon
    {
        $best = null;
        $bestValue = 0.0;

        foreach ($this->candidates() as $coupon) {
            if (! $coupon->audienceIncludes($phone)) {
                continue;
            }
            if (! $coupon->isValidFor($base, $cart)) {
                continue;
            }
            // A coupon issued to one person is still governed by that; the
            // audience says who it is *for*, this says who may spend it.
            if ($coupon->reservedForSomeoneElse($phone)) {
                continue;
            }
            if ($coupon->customerLimitReached($phone)) {
                continue;
            }

            $value = $coupon->discountFor($base, $cart) + $cart->deliveryValueOf($coupon);

            if ($value > $bestValue) {
                $bestValue = $value;
                $best = $coupon;
            }
        }

        return $best;
    }

    /**
     * Live auto-applying coupons. A shop has a handful of these at most, so one
     * query per request beats a query per coupon per pricing pass — and the
     * cart prices itself several times over a single checkout render.
     */
    protected function candidates(): \Illuminate\Support\Collection
    {
        return $this->candidates ??= Coupon::autoApplying()->orderByDesc('id')->take(50)->get();
    }

    /** Drop the memo — for tests and for long-running console work. */
    public function flush(): void
    {
        $this->candidates = null;
    }
}

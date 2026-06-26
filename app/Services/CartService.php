<?php

namespace App\Services;

use App\Models\Coupon;
use App\Models\Offer;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Setting;
use Illuminate\Support\Collection;

/**
 * Session-backed shopping cart. Lightweight — no DB rows until checkout.
 */
class CartService
{
    protected string $sessionKey = 'cart';
    protected string $couponKey = 'cart_coupon';
    protected string $pointsKey = 'cart_points';

    public function items(): Collection
    {
        return collect(session($this->sessionKey, []));
    }

    public function add(Product $product, ?ProductVariant $variant, int $qty = 1): void
    {
        $qty = max(1, $qty);
        $key = $this->lineKey($product->id, $variant?->id);
        $items = session($this->sessionKey, []);

        $price = $variant?->effective_price ?? (float) $product->price;

        if (isset($items[$key])) {
            $items[$key]['qty'] += $qty;
        } else {
            $items[$key] = [
                'key' => $key,
                'product_id' => $product->id,
                'variant_id' => $variant?->id,
                'name' => $product->name,
                'slug' => $product->slug,
                'sku' => $variant?->sku ?? $product->sku,
                'price' => $price,
                'qty' => $qty,
                'attributes' => $variant?->attributes ?? [],
                'image' => $product->thumbnail,
                'offers' => $product->offerTiers(),
                'category_id' => $product->category_id,
                'on_sale' => $product->is_on_sale,
            ];
        }

        session([$this->sessionKey => $items]);
    }

    public function update(string $key, int $qty): void
    {
        $items = session($this->sessionKey, []);
        if (! isset($items[$key])) {
            return;
        }
        if ($qty <= 0) {
            unset($items[$key]);
        } else {
            $items[$key]['qty'] = $qty;
        }
        session([$this->sessionKey => $items]);
    }

    public function remove(string $key): void
    {
        $items = session($this->sessionKey, []);
        unset($items[$key]);
        session([$this->sessionKey => $items]);
    }

    public function clear(): void
    {
        session()->forget([$this->sessionKey, $this->couponKey, $this->pointsKey]);
    }

    public function count(): int
    {
        return (int) $this->items()->sum('qty');
    }

    public function isEmpty(): bool
    {
        return $this->items()->isEmpty();
    }

    public function subtotal(): float
    {
        return (float) $this->items()->sum(fn ($i) => $i['price'] * $i['qty']);
    }

    // ── Quantity / bundle offers ────────────────────────────────────────────

    /** Best applicable offer percent for a single line, given its quantity. */
    public function lineOfferPercent(array $item): float
    {
        $best = 0.0;
        foreach ($item['offers'] ?? [] as $tier) {
            if (($item['qty'] ?? 0) >= ($tier['min_qty'] ?? PHP_INT_MAX)) {
                $best = max($best, (float) ($tier['percent'] ?? 0));
            }
        }

        return $best;
    }

    /** Money saved on a single line by its quantity offer. */
    public function lineOfferSaving(array $item): float
    {
        return round($item['price'] * $item['qty'] * $this->lineOfferPercent($item) / 100, 2);
    }

    /** Total saved across the cart from quantity/bundle offers. */
    public function offerDiscount(): float
    {
        return (float) $this->items()->sum(fn ($i) => $this->lineOfferSaving($i));
    }

    // ── Automatic offers (Admin → Offers) ──────────────────────────────────

    protected ?Collection $offerCache = null;

    protected function isMember(): bool
    {
        return auth('customer')->check();
    }

    /** Base that order-level offers apply to: subtotal after per-product offers. */
    protected function promoBase(): float
    {
        return max(0, $this->subtotal() - $this->offerDiscount());
    }

    /** All active offers whose conditions the current cart meets. */
    public function matchingOffers(): Collection
    {
        $this->offerCache ??= Offer::active()->get();
        $member = $this->isMember();

        return $this->offerCache->filter(fn (Offer $o) => $o->matches($this, $member))->values();
    }

    /**
     * Auto-offer discount: best non-member percentage offer (on its eligible items)
     * plus the best members-only offer (stacks). Scoped offers only discount their items.
     */
    public function promoDiscount(): float
    {
        $offers = $this->matchingOffers()->where('type', 'order_percent');
        $best = (float) $offers->where('members_only', false)->max(fn (Offer $o) => $o->discountAmount($this));
        $member = (float) $offers->where('members_only', true)->max(fn (Offer $o) => $o->discountAmount($this));

        return round(min($this->promoBase(), $best + $member), 2);
    }

    /** Effective discount percentage (for display only). */
    public function promoPercent(): float
    {
        $base = $this->promoBase();

        return $base > 0 ? round($this->promoDiscount() / $base * 100, 1) : 0;
    }

    public function hasFreeShippingOffer(): bool
    {
        return $this->matchingOffers()->contains(fn (Offer $o) => $o->type === 'free_shipping');
    }

    /** Extra "thanks for registering" discount for logged-in customers (Admin → Offers). */
    public function memberSignupDiscount(): float
    {
        if (! auth('customer')->check()) {
            return 0.0;
        }
        $pct = (float) Setting::get('register_offer_percent', config('loyalty.register_discount_percent', 0));
        if ($pct <= 0) {
            return 0.0;
        }

        return round($this->promoBase() * $pct / 100, 2);
    }

    // ── Coupons ───────────────────────────────────────────────────────────
    public function applyCoupon(Coupon $coupon): void
    {
        session([$this->couponKey => $coupon->code]);
    }

    public function removeCoupon(): void
    {
        session()->forget($this->couponKey);
    }

    /** Base the coupon is calculated against: subtotal after product + auto offers. */
    protected function couponBase(): float
    {
        return max(0, $this->subtotal() - $this->offerDiscount() - $this->promoDiscount() - $this->memberSignupDiscount());
    }

    public function coupon(): ?Coupon
    {
        $code = session($this->couponKey);
        if (! $code) {
            return null;
        }
        $coupon = Coupon::where('code', $code)->first();
        return ($coupon && $coupon->isValidFor($this->couponBase(), $this)) ? $coupon : null;
    }

    /** Discount from the applied coupon only. */
    public function couponDiscount(): float
    {
        $coupon = $this->coupon();
        return $coupon ? $coupon->discountFor($this->couponBase(), $this) : 0.0;
    }

    // ── Loyalty points redemption ───────────────────────────────────────────

    /** Customer requests to redeem N points (snapped/clamped when read). */
    public function redeemPoints(int $points): void
    {
        session([$this->pointsKey => max(0, $points)]);
    }

    public function clearPoints(): void
    {
        session()->forget($this->pointsKey);
    }

    /** Discountable base before any points are applied. */
    protected function baseBeforePoints(): float
    {
        return max(0, $this->subtotal() - $this->offerDiscount() - $this->promoDiscount() - $this->memberSignupDiscount() - $this->couponDiscount());
    }

    /** Whole points that will actually be redeemed for this cart (0 for guests). */
    public function redeemablePoints(): int
    {
        $loyalty = app(LoyaltyService::class);
        if (! $loyalty->enabled()) {
            return 0;
        }
        $customer = auth('customer')->user();
        $requested = (int) session($this->pointsKey, 0);
        if (! $customer || $requested <= 0) {
            return 0;
        }

        return $loyalty->clampRedeemable($requested, (int) $customer->points, $this->baseBeforePoints());
    }

    /** Taka value of the redeemed points. */
    public function pointsDiscount(): float
    {
        return app(LoyaltyService::class)->pointsValue($this->redeemablePoints());
    }

    /** Total discount = quantity offers + auto promo offers + member + coupon + points. */
    public function discount(): float
    {
        return round($this->offerDiscount() + $this->promoDiscount() + $this->memberSignupDiscount() + $this->couponDiscount() + $this->pointsDiscount(), 2);
    }

    /**
     * Human-readable breakdown of why a discount was applied, so customers
     * understand each saving. Each entry: ['label' => string, 'amount' => float].
     *
     * @return array<int, array{label:string, amount:float}>
     */
    public function discountLines(): array
    {
        $lines = [];
        $offers = $this->matchingOffers()->where('type', 'order_percent');

        $bestNon = $offers->where('members_only', false)->sortByDesc(fn (Offer $o) => $o->discountAmount($this))->first();
        if ($bestNon && $bestNon->discountAmount($this) > 0) {
            $lines[] = ['label' => $bestNon->title ?: (rtrim(rtrim((string) $bestNon->percent, '0'), '.').'% off'), 'amount' => round($bestNon->discountAmount($this), 2)];
        }

        $bestMember = $offers->where('members_only', true)->sortByDesc(fn (Offer $o) => $o->discountAmount($this))->first();
        if ($bestMember && $bestMember->discountAmount($this) > 0) {
            $lines[] = ['label' => ($bestMember->title ?: 'Member discount').' · members', 'amount' => round($bestMember->discountAmount($this), 2)];
        }

        if ($this->offerDiscount() > 0) {
            $lines[] = ['label' => 'Quantity / bundle offer', 'amount' => round($this->offerDiscount(), 2)];
        }

        if ($this->memberSignupDiscount() > 0) {
            $lines[] = ['label' => 'Member discount', 'amount' => round($this->memberSignupDiscount(), 2)];
        }

        if (($coupon = $this->coupon()) && $this->couponDiscount() > 0) {
            $lines[] = ['label' => 'Coupon '.$coupon->code, 'amount' => round($this->couponDiscount(), 2)];
        }

        if ($this->pointsDiscount() > 0) {
            $lines[] = ['label' => $this->redeemablePoints().' points redeemed', 'amount' => round($this->pointsDiscount(), 2)];
        }

        return $lines;
    }

    /** True if free delivery is currently unlocked (coupon, offer or threshold). */
    public function hasFreeShipping(): bool
    {
        if ($this->coupon()?->free_shipping || $this->hasFreeShippingOffer()) {
            return true;
        }
        $threshold = config('store.shipping.free_threshold');

        return $threshold !== null && $this->subtotal() >= $threshold;
    }

    /**
     * Almost-unlocked offers to nudge the customer ("Add ৳X more to get …").
     *
     * @return array<int, string>
     */
    public function offerHints(): array
    {
        $hints = [];
        $member = $this->isMember();
        $subtotal = $this->subtotal();

        foreach (Offer::active()->get() as $offer) {
            if ($offer->members_only && ! $member) {
                continue;
            }
            if ($offer->matches($this, $member)) {
                continue; // already applied
            }
            if ($offer->min_subtotal !== null) {
                $base = $offer->applies_to === 'all' ? $subtotal : $offer->eligibleSubtotal($this);
                $remaining = $offer->remainingToUnlock($base);
                if ($remaining > 0 && $remaining <= max(1500, (float) $offer->min_subtotal)) {
                    $reward = $offer->type === 'free_shipping' ? 'free delivery' : ($offer->title ?: 'a discount');
                    $hints[] = 'Add '.money($remaining).' more to unlock '.$reward.'.';
                }
            }
        }

        return array_slice($hints, 0, 2);
    }

    public function shipping(bool $insideDhaka = false): float
    {
        // Free shipping from a coupon or an active offer overrides everything.
        if ($this->coupon()?->free_shipping || $this->hasFreeShippingOffer()) {
            return 0.0;
        }
        $threshold = config('store.shipping.free_threshold');
        if ($threshold !== null && $this->subtotal() >= $threshold) {
            return 0.0;
        }
        // Rates come from Admin → Settings (so they always match what the
        // checkout page shows), with config/.env as the fallback default.
        return (float) ($insideDhaka
            ? Setting::get('shipping_inside', config('store.shipping.inside_dhaka'))
            : Setting::get('shipping_outside', config('store.shipping.outside_dhaka')));
    }

    public function total(bool $insideDhaka = false): float
    {
        return max(0, $this->subtotal() - $this->discount() + $this->shipping($insideDhaka));
    }

    protected function lineKey(int $productId, ?int $variantId): string
    {
        return $productId.':'.($variantId ?? '0');
    }
}

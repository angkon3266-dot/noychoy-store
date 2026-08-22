<?php

namespace App\Services;

use App\Models\Coupon;
use App\Models\CustomerOffer;
use App\Models\Offer;
use App\Models\Order;
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

    /**
     * Per-request memo. This service is a singleton, so one cart render used to
     * recompute the whole discount cascade a dozen times — `discount()` alone
     * cost 11 queries, six of them the identical member-usage count, because
     * couponBase() and baseBeforePoints() each re-derived every earlier stage.
     * Every mutating method clears this.
     */
    protected array $memo = [];

    protected function memo(string $key, \Closure $fn)
    {
        return $this->memo[$key] ??= $fn();
    }

    /** Drop memoised totals — called by every method that changes the cart. */
    protected function forgetMemo(): void
    {
        $this->memo = [];
        $this->offerCache = null;
        $this->resolvedCustomerOffer = null;
        $this->customerOfferResolved = false;
    }

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
        $this->forgetMemo();
    }

    /** Overwrite a line's snapshotted unit price (checkout re-validation). */
    public function repriceLine(string $key, float $price): void
    {
        $items = session($this->sessionKey, []);
        if (isset($items[$key])) {
            $items[$key]['price'] = $price;
            session([$this->sessionKey => $items]);
            $this->forgetMemo();
        }
    }

    /**
     * Overwrite a line's snapshotted quantity-offer tiers (checkout re-validation).
     *
     * Tiers are captured when the item is added, so a cart could otherwise carry
     * a withdrawn "buy 2, save 30%" offer for as long as the session lived.
     */
    public function refreshLineOffers(string $key, array $offers): void
    {
        $items = session($this->sessionKey, []);
        if (isset($items[$key])) {
            $items[$key]['offers'] = $offers;
            session([$this->sessionKey => $items]);
            $this->forgetMemo();
        }
    }

    /**
     * Stable signature of a line's offer tiers — min_qty and percent only, so a
     * JSON round-trip through the session (ints becoming floats and back) does
     * not read as a change.
     */
    public static function offerSignature(array $tiers): string
    {
        return collect($tiers)
            ->map(fn ($t) => ((int) ($t['min_qty'] ?? 1)).':'.number_format((float) ($t['percent'] ?? 0), 2, '.', ''))
            ->sort()->implode(',');
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
        $this->forgetMemo();
    }

    public function remove(string $key): void
    {
        $items = session($this->sessionKey, []);
        unset($items[$key]);
        session([$this->sessionKey => $items]);
        $this->forgetMemo();
    }

    public function clear(): void
    {
        session()->forget([$this->sessionKey, $this->couponKey, $this->pointsKey]);
        $this->forgetMemo();
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

    /** The offer tier currently applying to a line — used for its label. */
    public function lineOfferTier(array $item): ?array
    {
        $best = null;
        foreach ($item['offers'] ?? [] as $tier) {
            if (($item['qty'] ?? 0) >= ($tier['min_qty'] ?? PHP_INT_MAX)
                && (float) ($tier['percent'] ?? 0) >= (float) ($best['percent'] ?? 0)) {
                $best = $tier;
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
    protected function rawPromoDiscount(): float
    {
        return $this->memo('promo_raw', function () {
            $offers = $this->matchingOffers()->where('type', 'order_percent');
            $best = (float) $offers->where('members_only', false)->max(fn (Offer $o) => $o->discountAmount($this));
            $member = (float) $offers->where('members_only', true)->max(fn (Offer $o) => $o->discountAmount($this));

            return round(min($this->promoBase(), $best + $member), 2);
        });
    }

    /** Auto-offer discount actually applied (capped to the remaining base). */
    public function promoDiscount(): float
    {
        return $this->cascade()['promo'];
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

    /**
     * The full discount cascade, computed once per request.
     *
     * Discounts apply in order, and each is capped to whatever is LEFT of the
     * subtotal. That cap is the point: every stage used to compute against the
     * raw subtotal independently, so a 20% quantity offer plus a 20% coupon
     * took 20% of the original price twice, and a large enough stack drove
     * `discount` past `subtotal` — storing a negative profit and a total
     * floored at zero, i.e. a free order with free shipping.
     *
     * @return array{offer:float, promo:float, member:float, customer:float,
     *               coupon:float, coupon_model:?Coupon, points:float,
     *               points_redeemed:int, base_before_coupon:float, total:float}
     */
    protected function cascade(): array
    {
        return $this->memo('cascade', function () {
            $remaining = $this->subtotal();

            // Take an amount off the remaining base, never more than is left.
            $take = function (float $amount) use (&$remaining): float {
                $amount = round(max(0, min($amount, $remaining)), 2);
                $remaining -= $amount;

                return $amount;
            };

            $offer = $take($this->offerDiscount());
            $promo = $take($this->rawPromoDiscount());
            $member = $take($this->rawMemberSignupDiscount());
            $customer = $take($this->rawCustomerOfferDiscount());

            // The coupon is validated against — and limited to — what is left,
            // so "20% off" never means 20% of a price already discounted away.
            $baseBeforeCoupon = $remaining;
            $couponModel = $this->couponFor($baseBeforeCoupon);
            $coupon = $take($couponModel ? $couponModel->discountFor($baseBeforeCoupon, $this) : 0.0);

            // Points are clamped to their own remaining base, in whole steps.
            $redeemed = $this->clampPoints($remaining);
            $points = $take(app(LoyaltyService::class)->pointsValue($redeemed));

            return [
                'offer' => $offer,
                'promo' => $promo,
                'member' => $member,
                'customer' => $customer,
                'coupon' => $coupon,
                'coupon_model' => $couponModel,
                'points' => $points,
                'points_redeemed' => $redeemed,
                'base_before_coupon' => round($baseBeforeCoupon, 2),
                'total' => round($offer + $promo + $member + $customer + $coupon + $points, 2),
            ];
        });
    }

    /**
     * Extra "thanks for registering" discount for logged-in customers (Admin →
     * Offers). Capped to 2 orders per rolling 7 days per customer.
     */
    protected function rawMemberSignupDiscount(): float
    {
        return $this->memo('member_raw', function () {
            $customer = auth('customer')->user();
            if (! $customer) {
                return 0.0;
            }
            $pricing = app(MemberPricingService::class);
            if (! $pricing->enabled()) {
                return 0.0;
            }
            // Cap: at most `max_uses` orders in a rolling `window_days` window per
            // customer (both editable in Admin → Offers). max_uses = 0 → no limit.
            $maxUses = (int) Setting::get('register_offer_max_uses', 2);
            if ($maxUses > 0) {
                $windowDays = max(1, (int) Setting::get('register_offer_window_days', 7));
                $usedInWindow = Order::where('customer_id', $customer->id)
                    ->where('created_at', '>=', now()->subDays($windowDays))
                    ->count();
                if ($usedInWindow >= $maxUses) {
                    return 0.0;
                }
            }

            // Per-line member discount so per-category / per-product overrides apply.
            $total = 0.0;
            foreach ($this->items() as $item) {
                $pct = $pricing->percentForLine((int) $item['product_id'], $item['category_id'] ?? null);
                if ($pct > 0) {
                    $total += $item['price'] * $item['qty'] * $pct / 100;
                }
            }

            return round($total, 2);
        });
    }

    /** Member discount actually applied (capped to the remaining base). */
    public function memberSignupDiscount(): float
    {
        return $this->cascade()['member'];
    }

    // ── Personalized customer offers (auto-applied best eligible) ──────────────

    /** The single best live personalized offer for the logged-in customer. */
    public function customerOffer(): ?CustomerOffer
    {
        if ($this->customerOfferResolved) {
            return $this->resolvedCustomerOffer;
        }
        $this->customerOfferResolved = true;
        $this->resolvedCustomerOffer = null;

        $customer = auth('customer')->user();
        if (! $customer) {
            return null;
        }

        $best = null;
        $bestAmount = -1.0;
        foreach ($customer->offers()->live()->get() as $offer) {
            $amount = $offer->discountFor($this);
            $value = $amount > 0 ? $amount : ($offer->grantsFreeShipping($this) ? 0.01 : -1);
            if ($value > $bestAmount) {
                $bestAmount = $value;
                $best = $offer;
            }
        }

        return $this->resolvedCustomerOffer = $best;
    }

    protected function rawCustomerOfferDiscount(): float
    {
        return round((float) ($this->customerOffer()?->discountFor($this) ?? 0), 2);
    }

    /** Personalised offer discount actually applied (capped to the remaining base). */
    public function customerOfferDiscount(): float
    {
        return $this->cascade()['customer'];
    }

    public function hasCustomerFreeShipping(): bool
    {
        return (bool) $this->customerOffer()?->grantsFreeShipping($this);
    }

    protected ?CustomerOffer $resolvedCustomerOffer = null;

    protected bool $customerOfferResolved = false;

    // ── Coupons ───────────────────────────────────────────────────────────
    public function applyCoupon(Coupon $coupon): void
    {
        session([$this->couponKey => $coupon->code]);
        $this->forgetMemo();
    }

    public function removeCoupon(): void
    {
        session()->forget($this->couponKey);
        $this->forgetMemo();
    }

    /**
     * What a coupon is actually calculated against: the subtotal after quantity
     * offers, auto offers, member and personalised discounts. Public so the
     * apply-coupon endpoint can validate against the SAME base the cart will
     * use — it used to check the raw subtotal, report "Coupon applied", then
     * silently drop the coupon when this lower base failed its minimum spend.
     */
    public function couponBase(): float
    {
        return $this->cascade()['base_before_coupon'];
    }

    /** The stored coupon, if it is valid against the given remaining base. */
    protected function couponFor(float $base): ?Coupon
    {
        $code = session($this->couponKey);
        if (! $code) {
            return null;
        }
        $coupon = $this->memo('coupon_row', fn () => Coupon::where('code', $code)->first());

        return ($coupon && $coupon->isValidFor($base, $this)) ? $coupon : null;
    }

    public function coupon(): ?Coupon
    {
        return $this->cascade()['coupon_model'];
    }

    /** Discount from the applied coupon only. */
    public function couponDiscount(): float
    {
        return $this->cascade()['coupon'];
    }

    // ── Loyalty points redemption ───────────────────────────────────────────

    /** Customer requests to redeem N points (snapped/clamped when read). */
    public function redeemPoints(int $points): void
    {
        session([$this->pointsKey => max(0, $points)]);
        $this->forgetMemo();
    }

    public function clearPoints(): void
    {
        session()->forget($this->pointsKey);
        $this->forgetMemo();
    }

    /** Whole points redeemable against a given remaining base (0 for guests). */
    protected function clampPoints(float $base): int
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

        return $loyalty->clampRedeemable($requested, (int) $customer->points, $base);
    }

    /** Whole points that will actually be redeemed for this cart (0 for guests). */
    public function redeemablePoints(): int
    {
        return $this->cascade()['points_redeemed'];
    }

    /** Taka value of the redeemed points. */
    public function pointsDiscount(): float
    {
        return $this->cascade()['points'];
    }

    /** Total discount — capped, so it can never exceed the subtotal. */
    public function discount(): float
    {
        return $this->cascade()['total'];
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
        $cascade = $this->cascade();

        // The auto-offer lines are shown at the amount actually applied: when the
        // cap bites, the raw offer amounts are scaled down so the breakdown still
        // adds up to discount() instead of over-explaining the saving.
        $bestNon = $offers->where('members_only', false)->sortByDesc(fn (Offer $o) => $o->discountAmount($this))->first();
        $bestMember = $offers->where('members_only', true)->sortByDesc(fn (Offer $o) => $o->discountAmount($this))->first();
        $rawNon = $bestNon ? round($bestNon->discountAmount($this), 2) : 0.0;
        $rawMember = $bestMember ? round($bestMember->discountAmount($this), 2) : 0.0;
        $rawPromo = $rawNon + $rawMember;
        $scale = $rawPromo > 0 ? $cascade['promo'] / $rawPromo : 0.0;

        if ($rawNon > 0 && round($rawNon * $scale, 2) > 0) {
            $lines[] = ['label' => $bestNon->title ?: (rtrim(rtrim((string) $bestNon->percent, '0'), '.').'% off'), 'amount' => round($rawNon * $scale, 2)];
        }

        if ($rawMember > 0 && round($rawMember * $scale, 2) > 0) {
            $lines[] = ['label' => ($bestMember->title ?: 'Member discount').' · members', 'amount' => round($rawMember * $scale, 2)];
        }

        if ($cascade['offer'] > 0) {
            $lines[] = ['label' => 'Quantity / bundle offer', 'amount' => $cascade['offer']];
        }

        if ($this->memberSignupDiscount() > 0) {
            $pct = (float) Setting::get('register_offer_percent', config('loyalty.register_discount_percent', 0));
            $label = 'Member discount'.($pct > 0 ? ' ('.rtrim(rtrim(number_format($pct, 2), '0'), '.').'% off)' : '');
            $lines[] = ['label' => $label, 'amount' => round($this->memberSignupDiscount(), 2)];
        }

        if (($cOffer = $this->customerOffer()) && $this->customerOfferDiscount() > 0) {
            $lines[] = ['label' => ($cOffer->title ?: 'Your exclusive offer').' · for you', 'amount' => round($this->customerOfferDiscount(), 2)];
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
        if ($this->coupon()?->free_shipping || $this->hasFreeShippingOffer() || $this->hasCustomerFreeShipping()) {
            return true;
        }
        $threshold = free_shipping_threshold();

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
        if ($this->coupon()?->free_shipping || $this->hasFreeShippingOffer() || $this->hasCustomerFreeShipping()) {
            return 0.0;
        }
        $threshold = free_shipping_threshold();
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

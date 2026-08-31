<?php

namespace App\Models;

use App\Services\CartService;
use Illuminate\Database\Eloquent\Model;

class Coupon extends Model
{
    /** Who an auto-applying coupon is for. */
    public const AUDIENCES = [
        'all' => 'Every order',
        'phones' => 'A list of phone numbers',
        'rule' => 'Anyone matching a condition',
    ];

    protected $fillable = [
        'code', 'label', 'type', 'value', 'applies_to', 'category_ids', 'product_ids',
        'exclude_sale_items', 'min_order', 'min_qty', 'max_qty', 'usage_limit',
        'per_customer_limit', 'reserved_for_phone', 'used_count', 'free_shipping',
        'is_exclusive', 'starts_at', 'expires_at', 'is_active',
        'auto_apply', 'audience', 'audience_rules',
    ];

    protected $casts = [
        'value' => 'decimal:2',
        'min_order' => 'decimal:2',
        'category_ids' => 'array',
        'product_ids' => 'array',
        'exclude_sale_items' => 'boolean',
        'free_shipping' => 'boolean',
        'is_exclusive' => 'boolean',
        'min_qty' => 'integer',
        'max_qty' => 'integer',
        'per_customer_limit' => 'integer',
        'starts_at' => 'datetime',
        'expires_at' => 'datetime',
        'is_active' => 'boolean',
        'auto_apply' => 'boolean',
        'audience_rules' => 'array',
    ];

    public function recipients(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(CouponRecipient::class);
    }

    /**
     * Validity check. When a cart is supplied, scope/quantity rules are enforced;
     * otherwise only the global gates (active, dates, usage, min spend) are checked.
     */
    public function isValidFor(float $subtotal, $cart = null): bool
    {
        if (! $this->is_active) {
            return false;
        }
        if ($this->starts_at && $this->starts_at->isFuture()) {
            return false;
        }
        if ($this->expires_at && $this->expires_at->isPast()) {
            return false;
        }
        if ($this->usage_limit !== null && $this->used_count >= $this->usage_limit) {
            return false;
        }
        if ($this->min_order !== null && $subtotal < (float) $this->min_order) {
            return false;
        }

        if ($cart instanceof CartService) {
            $eligible = $this->eligibleItems($cart);
            if ($eligible->isEmpty()) {
                return false; // nothing in cart matches this coupon's scope
            }
            $qty = (int) $eligible->sum('qty');
            if ($this->min_qty !== null && $qty < $this->min_qty) {
                return false;
            }
            if ($this->max_qty !== null && $qty > $this->max_qty) {
                return false;
            }
        }

        return true;
    }

    /**
     * Discount amount for the current subtotal / cart (excludes free shipping).
     *
     * $subtotal is the caller's remaining discountable base — what is still left
     * to discount after quantity offers, auto offers, member and personalised
     * discounts. The coupon's own scope narrows that further, but it can never
     * widen it: taking a percentage of the raw eligible subtotal meant a 20%
     * coupon stacked on a 20% offer took 20% of the ORIGINAL price, and enough
     * stacked discounts drove the order total below zero.
     */
    public function discountFor(float $subtotal, $cart = null): float
    {
        $base = $cart instanceof CartService
            ? min((float) $this->eligibleSubtotal($cart), max(0, $subtotal))
            : $subtotal;

        $discount = $this->type === 'percent'
            ? $base * ((float) $this->value / 100)
            : (float) $this->value;

        return (float) round(max(0, min($discount, $base)), 2);
    }

    /**
     * True when this code belongs to somebody else.
     *
     * A blank phone is NOT a refusal: a guest has not typed one yet when the
     * code is applied in the cart, and `PlaceOrder` re-checks with the real
     * number before the order is written. That is the same shape as
     * `customerLimitReached()`, and it keeps the cart from rejecting a
     * legitimate owner who simply has not reached the checkout form.
     */
    public function reservedForSomeoneElse(?string $phone): bool
    {
        if (blank($this->reserved_for_phone) || blank($phone)) {
            return false;
        }

        return bd_phone($phone) !== bd_phone($this->reserved_for_phone);
    }

    /**
     * Is this coupon meant for the person checking out with this phone?
     *
     * Only asked of coupons that apply themselves. A typed code is governed by
     * `reserved_for_phone` as before — this is the audience test, which is a
     * different question from "may this person spend a code they were given".
     *
     * A blank phone means we do not know who is shopping yet: `all` still
     * matches (it is for everybody), the targeted audiences do not, so nothing
     * personal is shown to a stranger before they identify themselves.
     */
    public function audienceIncludes(?string $phone): bool
    {
        $phone = filled($phone) ? bd_phone($phone) : null;

        return match ($this->audience ?? 'all') {
            'phones' => filled($phone) && $this->recipients()->where('phone', $phone)->exists(),
            'rule' => filled($phone) && $this->ruleMatches($phone),
            default => true,
        };
    }

    /**
     * A standing condition, evaluated against whatever the shop knows about
     * this number right now.
     *
     * Someone who has never ordered has no customer row at all, which is the
     * point: "first order" has to match a person who does not exist yet.
     */
    protected function ruleMatches(string $phone): bool
    {
        $r = (array) ($this->audience_rules ?? []);
        $customer = Customer::where('phone', $phone)->first();

        $orders = (int) ($customer->total_orders ?? 0);
        $spent = (float) ($customer->total_spent ?? 0);

        if (! empty($r['first_order_only']) && $orders > 0) {
            return false;
        }
        if (! empty($r['members_only']) && blank($customer?->password)) {
            return false;
        }
        if (isset($r['min_orders']) && $r['min_orders'] !== '' && $orders < (int) $r['min_orders']) {
            return false;
        }
        if (isset($r['min_spend']) && $r['min_spend'] !== '' && $spent < (float) $r['min_spend']) {
            return false;
        }
        if (isset($r['lapsed_days']) && $r['lapsed_days'] !== '') {
            // "Has not ordered in N days" only means something for someone who
            // has ordered before — otherwise every new shopper is "lapsed".
            $last = $customer?->last_order_at;
            if ($orders < 1 || ($last && $last->gt(now()->subDays((int) $r['lapsed_days'])))) {
                return false;
            }
        }

        return true;
    }

    /** Coupons that apply themselves and are live right now. */
    public function scopeAutoApplying($q)
    {
        return $q->where('auto_apply', true)->where('is_active', true)
            ->where(fn ($w) => $w->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn ($w) => $w->whereNull('expires_at')->orWhere('expires_at', '>=', now()));
    }

    /** Whether a per-customer usage cap has been reached for the given phone. */
    public function customerLimitReached(?string $phone): bool
    {
        if ($this->per_customer_limit === null || blank($phone)) {
            return false;
        }
        $used = Order::where('coupon_code', $this->code)
            ->where('customer_phone', $phone)
            ->count();

        return $used >= $this->per_customer_limit;
    }

    // ── Scope helpers ───────────────────────────────────────────────────────

    /**
     * Cart line items this coupon applies to (respects scope + sale exclusion).
     *
     * `discountableItems()`, not `items()`: a gift-ladder unit the customer is
     * not paying for must not earn a percentage, and must not count toward
     * `min_qty` either. Offer and CustomerOffer already price this way — this
     * was the last place free units were still billable.
     */
    public function eligibleItems(CartService $cart)
    {
        return $cart->discountableItems()->filter(fn ($item) => $this->itemEligible($item));
    }

    public function eligibleSubtotal(CartService $cart): float
    {
        return (float) $this->eligibleItems($cart)->sum(fn ($i) => $i['price'] * $i['qty']);
    }

    protected function itemEligible(array $item): bool
    {
        if ($this->exclude_sale_items && ! empty($item['on_sale'])) {
            return false;
        }

        return match ($this->applies_to) {
            'products' => in_array((int) $item['product_id'], array_map('intval', $this->product_ids ?? []), true),
            'categories' => in_array((int) ($item['category_id'] ?? 0), array_map('intval', $this->category_ids ?? []), true),
            default => true, // 'all'
        };
    }
}

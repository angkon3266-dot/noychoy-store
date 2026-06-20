<?php

namespace App\Models;

use App\Services\CartService;
use Illuminate\Database\Eloquent\Model;

class Coupon extends Model
{
    protected $fillable = [
        'code', 'type', 'value', 'applies_to', 'category_ids', 'product_ids',
        'exclude_sale_items', 'min_order', 'min_qty', 'max_qty', 'usage_limit',
        'per_customer_limit', 'used_count', 'free_shipping', 'starts_at', 'expires_at', 'is_active',
    ];

    protected $casts = [
        'value' => 'decimal:2',
        'min_order' => 'decimal:2',
        'category_ids' => 'array',
        'product_ids' => 'array',
        'exclude_sale_items' => 'boolean',
        'free_shipping' => 'boolean',
        'min_qty' => 'integer',
        'max_qty' => 'integer',
        'per_customer_limit' => 'integer',
        'starts_at' => 'datetime',
        'expires_at' => 'datetime',
        'is_active' => 'boolean',
    ];

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

    /** Discount amount for the current subtotal / cart (excludes free shipping). */
    public function discountFor(float $subtotal, $cart = null): float
    {
        $base = $cart instanceof CartService
            ? (float) $this->eligibleSubtotal($cart)
            : $subtotal;

        $discount = $this->type === 'percent'
            ? $base * ((float) $this->value / 100)
            : (float) $this->value;

        return (float) round(min($discount, $base), 2);
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

    /** Cart line items this coupon applies to (respects scope + sale exclusion). */
    public function eligibleItems(CartService $cart)
    {
        return $cart->items()->filter(fn ($item) => $this->itemEligible($item));
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

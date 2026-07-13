<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerOffer extends Model
{
    public const TYPES = [
        'percent' => 'Percentage discount',
        'fixed' => 'Fixed amount off',
        'free_shipping' => 'Free shipping',
        'points' => 'Bonus points',
    ];

    public const SCOPES = [
        'all' => 'Whole order',
        'categories' => 'Specific categories',
        'products' => 'Specific products',
    ];

    protected $fillable = [
        'customer_id', 'title', 'description', 'message', 'type', 'value', 'code',
        'applies_to', 'category_ids', 'product_ids',
        'min_subtotal', 'starts_at', 'expires_at', 'is_active', 'redeemed_at',
        'max_redemptions', 'redemptions',
    ];

    protected $casts = [
        'value' => 'decimal:2',
        'min_subtotal' => 'decimal:2',
        'category_ids' => 'array',
        'product_ids' => 'array',
        'starts_at' => 'datetime',
        'expires_at' => 'datetime',
        'redeemed_at' => 'datetime',
        'max_redemptions' => 'integer',
        'redemptions' => 'integer',
        'is_active' => 'boolean',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** Live = active, started, not expired, uses remaining. */
    public function scopeLive(Builder $q): Builder
    {
        $now = now();

        return $q->where('is_active', true)
            ->where(fn ($w) => $w->whereNull('max_redemptions')->orWhereColumn('redemptions', '<', 'max_redemptions'))
            ->where(fn ($w) => $w->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
            ->where(fn ($w) => $w->whereNull('expires_at')->orWhere('expires_at', '>=', $now));
    }

    public function isLive(): bool
    {
        $now = now();

        return $this->is_active
            && $this->hasUsesLeft()
            && ($this->starts_at === null || $this->starts_at <= $now)
            && ($this->expires_at === null || $this->expires_at >= $now);
    }

    /** null max = unlimited until expiry; otherwise redemptions must be below the cap. */
    public function hasUsesLeft(): bool
    {
        return $this->max_redemptions === null || (int) $this->redemptions < (int) $this->max_redemptions;
    }

    /** Human usage summary, e.g. "Unlimited until expiry" or "1 of 3 used". */
    public function usageLabel(): string
    {
        if ($this->max_redemptions === null) {
            return 'Unlimited until expiry';
        }

        return (int) $this->redemptions.' of '.(int) $this->max_redemptions.' used';
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }

    /** Short human value, e.g. "10% off", "৳200 off", "Free shipping", "500 points". */
    public function rewardText(): string
    {
        return match ($this->type) {
            'percent' => rtrim(rtrim(number_format((float) $this->value, 2), '0'), '.').'% off',
            'fixed' => money($this->value).' off',
            'free_shipping' => 'Free shipping',
            'points' => (int) $this->value.' bonus points',
            default => $this->title,
        };
    }

    // ── Scope + checkout application ─────────────────────────────────────────

    public function scopeLabel(): string
    {
        return self::SCOPES[$this->applies_to] ?? 'Whole order';
    }

    /** Cart lines that fall within this offer's scope. */
    public function eligibleItems($cart)
    {
        return $cart->items()->filter(fn ($i) => $this->lineEligible($i));
    }

    public function lineEligible(array $item): bool
    {
        return match ($this->applies_to) {
            'categories' => in_array((int) ($item['category_id'] ?? 0), array_map('intval', $this->category_ids ?? []), true),
            'products' => in_array((int) ($item['product_id'] ?? 0), array_map('intval', $this->product_ids ?? []), true),
            default => true,
        };
    }

    public function eligibleSubtotal($cart): float
    {
        return (float) $this->eligibleItems($cart)->sum(fn ($i) => $i['price'] * $i['qty']);
    }

    /** Money-off for this cart (percent/fixed). free_shipping/points give 0 here. */
    public function discountFor($cart): float
    {
        if (! $this->isLive()) {
            return 0.0;
        }
        if ($this->min_subtotal !== null && (float) $cart->subtotal() < (float) $this->min_subtotal) {
            return 0.0;
        }
        $base = $this->eligibleSubtotal($cart);
        if ($base <= 0) {
            return 0.0;
        }

        return match ($this->type) {
            'percent' => round($base * (float) $this->value / 100, 2),
            'fixed' => round(min((float) $this->value, $base), 2),
            default => 0.0,
        };
    }

    /** Does this offer grant free shipping and match the cart's scope + minimum? */
    public function grantsFreeShipping($cart): bool
    {
        if ($this->type !== 'free_shipping' || ! $this->isLive()) {
            return false;
        }
        if ($this->min_subtotal !== null && (float) $cart->subtotal() < (float) $this->min_subtotal) {
            return false;
        }

        return $this->applies_to === 'all' || $this->eligibleItems($cart)->isNotEmpty();
    }

    /** Should this offer be highlighted on the given product's page? */
    public function appliesToProduct(Product $product): bool
    {
        if ($this->applies_to === 'products') {
            return in_array((int) $product->id, array_map('intval', $this->product_ids ?? []), true);
        }
        if ($this->applies_to === 'categories') {
            $wanted = array_map('intval', $this->category_ids ?? []);
            $cats = $product->relationLoaded('categories')
                ? $product->categories->pluck('id')->all()
                : $product->categories()->pluck('categories.id')->all();
            $cats[] = (int) $product->category_id;

            return (bool) array_intersect($wanted, array_map('intval', array_filter($cats)));
        }

        return true;
    }
}

<?php

namespace App\Models;

use App\Services\CartService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Offer extends Model
{
    public const TYPES = [
        'order_percent' => 'Percentage discount',
        'free_shipping' => 'Free shipping',
    ];

    public const SCOPES = [
        'all' => 'Whole order',
        'categories' => 'Specific categories',
        'products' => 'Specific products',
    ];

    protected static function booted(): void
    {
        // Deals of the Day is cached (it costs 2-3 queries per offer), so an
        // edit has to clear it — otherwise the owner changes a deal and waits
        // ten minutes wondering why the homepage disagrees with her. The listed
        // prices hold their offers for the request, and let go the same way.
        $bust = function () {
            \App\Support\DailyDeals::flushCache();
            offer_pricing()->forget();
        };

        static::saved($bust);
        static::deleted($bust);
    }

    protected $fillable = [
        'title', 'description', 'type', 'applies_to', 'category_ids', 'product_ids',
        'percent', 'min_subtotal', 'min_qty',
        'members_only', 'badge_label', 'image', 'show_on_pdp', 'is_active', 'ends_at', 'sort',
    ];

    protected $casts = [
        'category_ids' => 'array',
        'product_ids' => 'array',
        'percent' => 'decimal:2',
        'min_subtotal' => 'decimal:2',
        'min_qty' => 'integer',
        'members_only' => 'boolean',
        'show_on_pdp' => 'boolean',
        'is_active' => 'boolean',
        'ends_at' => 'datetime',
        'sort' => 'integer',
    ];

    /**
     * The offers running right now.
     *
     * A deadline is part of being active, not decoration: the shop prints a
     * countdown from `ends_at` (20 Sep 2026), so when it passes the offer has
     * to stop everywhere the same second — the listed prices, the product-page
     * note, the checkout discount and the deal card all read this scope.
     */
    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_active', true)
            ->where(fn (Builder $w) => $w->whereNull('ends_at')->orWhere('ends_at', '>', now()))
            ->orderBy('sort');
    }

    /** Has this offer's deadline passed? */
    public function hasEnded(): bool
    {
        return $this->ends_at !== null && $this->ends_at->isPast();
    }

    /** The deadline as a unix second for the browser's countdown, or null. */
    public function endsAtUnix(): ?int
    {
        return $this->ends_at?->getTimestamp();
    }

    /**
     * Should this offer be shown on the given product's page? Respects the
     * offer's scope so category/product-specific offers don't leak onto every
     * product. Checks the product's primary category AND any pivot categories.
     */
    public function appliesToProduct(Product $product): bool
    {
        if ($this->applies_to === 'products') {
            return in_array((int) $product->id, array_map('intval', $this->product_ids ?? []), true);
        }

        if ($this->applies_to === 'categories') {
            $wanted = array_map('intval', $this->category_ids ?? []);
            $productCats = $product->relationLoaded('categories')
                ? $product->categories->pluck('id')->map(fn ($i) => (int) $i)->all()
                : $product->categories()->pluck('categories.id')->map(fn ($i) => (int) $i)->all();
            $productCats[] = (int) $product->category_id;

            return (bool) array_intersect($wanted, array_filter($productCats));
        }

        return true; // 'all' — whole-order offer, show everywhere
    }

    /**
     * Can a single piece promise this offer? No minimum cart value and no
     * minimum count — so App\Support\OfferPricing may take it off the price a
     * product lists at, and the cart will honour that for a piece on its own.
     */
    public function isUnconditional(): bool
    {
        return (float) $this->percent > 0
            && (float) ($this->min_subtotal ?? 0) <= 0
            && (int) ($this->min_qty ?? 0) <= 1;
    }

    /**
     * Does a single cart line fall within this offer's scope?
     *
     * A line carries every category its product is filed under once the cart
     * has looked them up (`category_ids`, CartService::withCategories) — the
     * same set appliesToProduct() checks for the product page. A line without
     * them falls back to its primary category.
     */
    public function lineEligible(array $item): bool
    {
        return match ($this->applies_to) {
            'categories' => (bool) array_intersect(
                array_map('intval', $item['category_ids'] ?? [$item['category_id'] ?? 0]),
                array_map('intval', $this->category_ids ?? []),
            ),
            'products' => in_array((int) ($item['product_id'] ?? 0), array_map('intval', $this->product_ids ?? []), true),
            default => true,
        };
    }

    /**
     * Subtotal of the cart lines this offer applies to. Gift-ladder units the
     * customer is not paying for are excluded — a percentage of a ৳0 piece is
     * money out of the store's pocket, not a discount.
     */
    public function eligibleSubtotal(CartService $cart): float
    {
        return (float) $cart->discountableItems()
            ->filter(fn ($i) => $this->lineEligible($i))
            ->sum(fn ($i) => $i['price'] * $i['qty']);
    }

    /** Total quantity of eligible (paid) cart lines. */
    public function eligibleQty(CartService $cart): int
    {
        return (int) $cart->discountableItems()
            ->filter(fn ($i) => $this->lineEligible($i))
            ->sum('qty');
    }

    /** Does this offer's conditions match the current cart? */
    public function matches(CartService $cart, bool $isMember): bool
    {
        if ($this->members_only && ! $isMember) {
            return false;
        }

        $subtotal = $this->eligibleSubtotal($cart);
        $qty = $this->eligibleQty($cart);

        // Scoped offers need at least one eligible item.
        if ($this->applies_to !== 'all' && $qty === 0) {
            return false;
        }
        if ($this->min_subtotal !== null && $subtotal < (float) $this->min_subtotal) {
            return false;
        }
        if ($this->min_qty !== null && $qty < (int) $this->min_qty) {
            return false;
        }

        return true;
    }

    /** How much more (whole-order) the customer must spend to unlock this offer. */
    public function remainingToUnlock(float $subtotal): float
    {
        if ($this->min_subtotal === null) {
            return 0;
        }

        return max(0, (float) $this->min_subtotal - $subtotal);
    }
}

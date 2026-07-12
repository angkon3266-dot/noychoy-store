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

    protected $fillable = [
        'title', 'description', 'type', 'applies_to', 'category_ids', 'product_ids',
        'percent', 'min_subtotal', 'min_qty',
        'members_only', 'badge_label', 'show_on_pdp', 'is_active', 'sort',
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
        'sort' => 'integer',
    ];

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_active', true)->orderBy('sort');
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

    /** Does a single cart line fall within this offer's scope? */
    public function lineEligible(array $item): bool
    {
        return match ($this->applies_to) {
            'categories' => in_array((int) ($item['category_id'] ?? 0), array_map('intval', $this->category_ids ?? []), true),
            'products' => in_array((int) ($item['product_id'] ?? 0), array_map('intval', $this->product_ids ?? []), true),
            default => true,
        };
    }

    /** Subtotal of the cart lines this offer applies to. */
    public function eligibleSubtotal(CartService $cart): float
    {
        return (float) $cart->items()
            ->filter(fn ($i) => $this->lineEligible($i))
            ->sum(fn ($i) => $i['price'] * $i['qty']);
    }

    /** Total quantity of eligible cart lines. */
    public function eligibleQty(CartService $cart): int
    {
        return (int) $cart->items()
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

    /** Discount amount this percentage offer gives on its eligible items. */
    public function discountAmount(CartService $cart): float
    {
        if ($this->type !== 'order_percent') {
            return 0.0;
        }

        return round($this->eligibleSubtotal($cart) * (float) $this->percent / 100, 2);
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

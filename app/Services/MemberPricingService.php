<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Setting;

/**
 * Global member pricing: every logged-in customer gets a discount on the shown
 * price. A base percent (reuses the "register offer" percent) applies to
 * everything, with optional per-category / per-product overrides.
 *
 * Overrides are stored in Setting 'member_discount_overrides' as:
 *   ['products' => [id => pct, ...], 'categories' => [id => pct, ...]]
 */
class MemberPricingService
{
    protected ?array $cachedOverrides = null;

    /** Base member discount % (shared with the register-for-discount offer). */
    public function basePercent(): float
    {
        return max(0, (float) Setting::get('register_offer_percent', config('loyalty.register_discount_percent', 0)));
    }

    public function overrides(): array
    {
        if ($this->cachedOverrides === null) {
            $o = Setting::get('member_discount_overrides', []);
            $this->cachedOverrides = is_array($o) ? $o : [];
        }

        return $this->cachedOverrides;
    }

    public function hasCategoryOverrides(): bool
    {
        return ! empty($this->overrides()['categories'] ?? []);
    }

    public function enabled(): bool
    {
        if ($this->basePercent() > 0) {
            return true;
        }
        $o = $this->overrides();

        return collect($o['products'] ?? [])->contains(fn ($p) => (float) $p > 0)
            || collect($o['categories'] ?? [])->contains(fn ($p) => (float) $p > 0);
    }

    /**
     * The member-discount weekly usage status for a customer.
     *
     * @return array{capped:bool, max:int, used:int, remaining:?int, resets_at:?\Illuminate\Support\Carbon, percent:float, window_days:int}
     */
    /** Per-request memo so the layout + cart don't both query it. */
    protected array $usageMemo = [];

    public function usageStatus(\App\Models\Customer $customer): array
    {
        if (isset($this->usageMemo[$customer->id])) {
            return $this->usageMemo[$customer->id];
        }

        $max = (int) Setting::get('register_offer_max_uses', 2);
        $windowDays = max(1, (int) Setting::get('register_offer_window_days', 7));
        $percent = $this->basePercent();

        if ($max <= 0) {
            return $this->usageMemo[$customer->id] = ['capped' => false, 'max' => 0, 'used' => 0, 'remaining' => null, 'resets_at' => null, 'percent' => $percent, 'window_days' => $windowDays];
        }

        $orders = \App\Models\Order::where('customer_id', $customer->id)
            ->where('created_at', '>=', now()->subDays($windowDays));
        $used = (clone $orders)->count();
        $oldest = (clone $orders)->orderBy('created_at')->first();

        return $this->usageMemo[$customer->id] = [
            'capped' => true,
            'max' => $max,
            'used' => $used,
            'remaining' => max(0, $max - $used),
            'resets_at' => $oldest?->created_at?->copy()->addDays($windowDays),
            'percent' => $percent,
            'window_days' => $windowDays,
        ];
    }

    /**
     * The member % for a piece — the one rule the cards, the cart and the
     * checkout all use (2026-11-28).
     *
     * A product override wins. Otherwise every category the piece is filed
     * under counts, primary or extra, and the best override among them
     * applies; an override of 0 is a real "no member price here". Pieces with
     * no override get the base %.
     *
     * Until now the two halves disagreed: the cart read only the primary
     * category snapshotted when the piece was added, so a piece filed under
     * an overridden second category showed "10% off for members" on its card
     * and was charged at the base rate; and the card skipped a 0 override the
     * cart honoured, so a category excluded from member pricing still showed
     * a member price.
     *
     * @param  array<int, int|null>  $categoryIds
     */
    public function percentFor(int $productId, array $categoryIds): float
    {
        $o = $this->overrides();
        if (isset($o['products'][$productId])) {
            return $this->clamp((float) $o['products'][$productId]);
        }

        $hits = [];
        foreach (array_unique(array_filter(array_map('intval', $categoryIds))) as $cid) {
            if (isset($o['categories'][$cid])) {
                $hits[] = (float) $o['categories'][$cid];
            }
        }

        return $hits ? $this->clamp(max($hits)) : $this->basePercent();
    }

    /**
     * Effective % for a cart line. Pass the line's `category_ids` (every
     * category, stamped by CartService) — a lone primary id still works.
     *
     * @param  array<int, int|null>|int|null  $categories
     */
    public function percentForLine(int $productId, array|int|null $categories): float
    {
        return $this->percentFor($productId, (array) $categories);
    }

    /** Effective % for a product page/card (checks all of the product's categories). */
    public function percentForProduct(Product $product): float
    {
        $cats = [(int) $product->category_id];

        // Only touch the categories relation when category overrides actually exist.
        if ($this->hasCategoryOverrides()) {
            $cats = array_merge($cats, $product->relationLoaded('categories')
                ? $product->categories->pluck('id')->all()
                : $product->categories()->pluck('categories.id')->all());
        }

        return $this->percentFor((int) $product->id, $cats);
    }

    /**
     * Does this member still get the member price? The cart stops it once she
     * has used it on `register_offer_max_uses` orders in the window; the
     * cards now stop showing it at the same moment, rather than promising a
     * price the cart will not charge.
     */
    public function availableTo(?\App\Models\Customer $customer): bool
    {
        if (! $customer || ! $this->enabled()) {
            return false;
        }

        $usage = $this->usageStatus($customer);

        return ! $usage['capped'] || ($usage['remaining'] ?? 0) > 0;
    }

    public function memberPrice(Product $product, ?float $price = null): float
    {
        $price = $price ?? (float) $product->price;

        return round($price * (1 - $this->percentForProduct($product) / 100), 2);
    }

    public function savings(Product $product, ?float $price = null): float
    {
        $price = $price ?? (float) $product->price;

        return round($price - $this->memberPrice($product, $price), 2);
    }

    protected function clamp(float $pct): float
    {
        return max(0, min(90, $pct));
    }
}

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

    /** Effective % for a cart line — no DB hit (uses stored product/category id). */
    public function percentForLine(int $productId, ?int $categoryId): float
    {
        $o = $this->overrides();
        if (isset($o['products'][$productId])) {
            return $this->clamp((float) $o['products'][$productId]);
        }
        if ($categoryId && isset($o['categories'][$categoryId])) {
            return $this->clamp((float) $o['categories'][$categoryId]);
        }

        return $this->basePercent();
    }

    /** Effective % for a product page/card (checks all of the product's categories). */
    public function percentForProduct(Product $product): float
    {
        $o = $this->overrides();
        $pid = (int) $product->id;
        if (isset($o['products'][$pid])) {
            return $this->clamp((float) $o['products'][$pid]);
        }

        // Only touch the categories relation when category overrides actually exist.
        if ($this->hasCategoryOverrides()) {
            $cats = $product->relationLoaded('categories')
                ? $product->categories->pluck('id')->all()
                : $product->categories()->pluck('categories.id')->all();
            $cats[] = (int) $product->category_id;
            $best = 0.0;
            foreach (array_filter($cats) as $cid) {
                if (isset($o['categories'][$cid])) {
                    $best = max($best, (float) $o['categories'][$cid]);
                }
            }
            if ($best > 0) {
                return $this->clamp($best);
            }
        }

        return $this->basePercent();
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

<?php

namespace App\Support\Storefront;

use App\Models\Product;

/**
 * The product-card payload for the React storefront — one place that decides
 * what a card knows, mirroring what components/product-card.blade.php read.
 */
class ProductCardData
{
    public static function make(Product $p): array
    {
        $memberPct = (is_member() && member_pricing()->enabled())
            ? member_pricing()->percentForProduct($p)
            : 0;

        return [
            'id' => $p->id,
            'name' => $p->name,
            'url' => route('product.show', $p),
            'thumb' => $p->thumbnail,
            'thumb450' => image_variant($p->thumbnail),
            'price_text' => money($p->price),
            'compare_text' => $p->is_on_sale ? money($p->compare_at_price) : null,
            'on_sale' => (bool) $p->is_on_sale,
            'discount_percent' => $p->is_on_sale ? $p->discount_percent : null,
            'preorder' => $p->isPreorder(),
            'available' => $p->isAvailable(),
            'has_variants' => (bool) $p->has_variants,
            'rating' => $p->average_rating ? (float) $p->average_rating : null,
            'review_count' => (int) $p->review_count,
            'member' => $memberPct > 0 ? [
                'price_text' => money(member_pricing()->memberPrice($p)),
                'pct' => rtrim(rtrim(number_format($memberPct, 1), '0'), '.'),
            ] : null,
            'add_url' => route('cart.add', $p),
            'buynow_url' => route('cart.buynow', $p),
        ];
    }

    /** @param  iterable<Product>  $products */
    public static function collection($products): array
    {
        return collect($products)->map(fn ($p) => self::make($p))->values()->all();
    }
}

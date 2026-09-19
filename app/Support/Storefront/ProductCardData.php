<?php

namespace App\Support\Storefront;

use App\Models\Product;
use App\Support\OfferPricing;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

/**
 * The product-card payload for the React storefront — one place that decides
 * what a card knows, mirroring what components/product-card.blade.php read.
 */
class ProductCardData
{
    public static function make(Product $p): array
    {
        // The listed price: less the live offer every shopper gets, with the
        // regular price struck through (App\Support\OfferPricing).
        $quote = offer_pricing()->quote($p);

        // A signed-in member pays the member discount and any members-only
        // offer on top of it. The cart takes every one of them off the full
        // price, so they add up rather than compound.
        $memberPct = 0.0;
        if (is_member()) {
            $memberPct = (member_pricing()->enabled() ? member_pricing()->percentForProduct($p) : 0)
                + (float) (offer_pricing()->memberOfferFor($p)?->percent ?? 0);
        }

        return [
            'id' => $p->id,
            'name' => $p->name,
            'url' => route('product.show', $p),
            'thumb' => $p->thumbnail,
            'thumb450' => image_variant($p->thumbnail),
            'srcset' => image_srcset($p->thumbnail),
            // Raw, for the AddToCart pixel value and the ladder hint's arrow.
            'price' => $quote['price'],
            'price_text' => money($quote['price']),
            'compare_text' => $quote['was'] !== null ? money($quote['was']) : null,
            'on_sale' => $quote['was'] !== null,
            'discount_percent' => $quote['was'] !== null ? OfferPricing::percentValue($quote['percent']) : null,
            'preorder' => $p->isPreorder(),
            'available' => $p->isAvailable(),
            'has_variants' => (bool) $p->has_variants,
            'rating' => $p->average_rating ? (float) $p->average_rating : null,
            'review_count' => (int) $p->review_count,
            'member' => $memberPct > 0 ? [
                'price_text' => money(OfferPricing::less((float) $p->price, $quote['offer'] ? $quote['percent'] + $memberPct : $memberPct)),
                'pct' => OfferPricing::percentText($memberPct),
            ] : null,
            'add_url' => route('cart.add', $p),
            'buynow_url' => route('cart.buynow', $p),
        ];
    }

    /** @param  iterable<Product>  $products */
    public static function collection($products): array
    {
        // A category offer asks each card for its categories: one query for
        // the lot instead of one per card.
        if ($products instanceof EloquentCollection && offer_pricing()->needsCategories()) {
            $products->loadMissing('categories');
        }

        return collect($products)->map(fn ($p) => self::make($p))->values()->all();
    }
}

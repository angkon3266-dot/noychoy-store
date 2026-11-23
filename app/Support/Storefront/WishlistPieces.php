<?php

namespace App\Support\Storefront;

use App\Models\Product;

/**
 * What the wishlist needs beside each card to put the piece in the bag with
 * everything else (Nov 2026): for a piece that comes in one option (a ring
 * size), each value and the variant behind it, so "Add all to bag" sends the
 * right size in one request (CartController::addMany). A piece with several
 * options is chosen on its own page instead.
 */
class WishlistPieces
{
    /** @return array<string, mixed> */
    public static function make(Product $p): array
    {
        $price = offer_pricing()->priceFor($p);
        $options = collect($p->options ?? [])->filter(fn ($o) => is_array($o) && filled($o['name'] ?? null))->values();
        $variants = $p->relationLoaded('variants') ? $p->variants : $p->variants()->where('is_active', true)->get();

        $sizes = null;
        if ($p->has_variants && $options->count() === 1) {
            $option = $options->first();
            $sizes = [
                'name' => $option['name'],
                'values' => collect($option['values'] ?? [])->map(function ($value) use ($p, $variants, $option) {
                    $v = $variants->first(fn ($v) => (string) (($v->attributes ?? [])[$option['name']] ?? '') === (string) $value);

                    // In stock as the product page reads it (CatalogController).
                    return $v ? [
                        'value' => (string) $value,
                        'variant_id' => $v->id,
                        'in_stock' => (int) $v->stock_quantity > 0,
                        'price' => offer_pricing()->priceFor($p, $v),
                    ] : null;
                })->filter()->values()->all(),
            ];
        }

        return [
            'id' => $p->id,
            'name' => $p->name,
            'url' => route('product.show', $p),
            'price' => $price,
            'content_id' => meta_content_id($p),
            'available' => $p->isAvailable(),
            'needs_page' => $p->has_variants && $sizes === null,
            'sizes' => $sizes,
        ];
    }
}

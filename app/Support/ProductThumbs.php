<?php

namespace App\Support;

use App\Models\Product;

/**
 * The small product picture the admin's reports and alerts show beside a name
 * (owner, 2026-09-19: "add product image for easy ref"). The 450px copy where
 * one exists, the original otherwise — the same choice the abandoned-cart
 * pages already make.
 */
final class ProductThumbs
{
    /** One product's picture. Load `images` first when there are many. */
    public static function url(?Product $product): ?string
    {
        $thumb = $product?->thumbnail;

        return $thumb ? (image_variant($thumb, 450) ?: $thumb) : null;
    }

    /**
     * Pictures for many products in one query, keyed by id. Deleted products
     * are included: a report can still name a piece that has since gone.
     *
     * Looked up fresh rather than stored with each report — the reports'
     * caches hold plain rows for minutes, and a photo changed today should
     * show today.
     *
     * @param  iterable<int|string|null>  $ids
     * @return array<int, string>
     */
    public static function for(iterable $ids): array
    {
        $ids = collect($ids)->filter()->map(fn ($id) => (int) $id)->unique()->values();

        if ($ids->isEmpty()) {
            return [];
        }

        return Product::withTrashed()->with('images')->whereIn('id', $ids)->get()
            ->mapWithKeys(fn (Product $product) => [$product->id => static::url($product)])
            ->filter()
            ->all();
    }
}

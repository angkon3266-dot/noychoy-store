<?php

namespace App\Support\Storefront;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

/**
 * The product payload shared by the style tools (Nov 2026): the bangle & ring
 * stacker, the earring try-on, Shop the look and the shared wishlist.
 *
 * Each piece knows its kind (from its category words, walking up to the
 * parent, like SizeGuide), the image to draw it with, and — for pieces that
 * come in sizes — its one option and the variant behind each value, so a tool
 * can add the right size to the bag in one request (CartController::addMany).
 */
class StylePieces
{
    public const KINDS = ['earring', 'ring', 'bangle', 'bracelet', 'necklace', 'anklet'];

    /** Published, buyable pieces of these kinds, best first. */
    public static function ofKinds(array $kinds, int $limit = 60): Collection
    {
        return Product::published()
            ->with(['images', 'category.parent.parent', 'categories', 'variants' => fn ($q) => $q->where('is_active', true)])
            ->orderByDesc('is_featured')->orderByDesc('loves_count')->orderByDesc('id')
            ->get()
            ->filter(fn (Product $p) => $p->isAvailable() && in_array(static::kind($p), $kinds, true))
            ->take($limit)
            ->values();
    }

    public static function kind(Product $product): ?string
    {
        $categories = collect([$product->category])->merge($product->categories ?? [])->filter();

        foreach ($categories as $category) {
            for ($depth = 0, $c = $category; $c && $depth < 5; $depth++, $c = $c->parent) {
                if ($kind = static::kindFromWords(strtolower($c->name.' '.$c->slug))) {
                    return $kind;
                }
            }
        }

        return static::kindFromWords(strtolower($product->name));
    }

    private static function kindFromWords(string $words): ?string
    {
        // Order matters: "earring" before "ring", "bangle" before "bracelet".
        // "Drop" and "dangle" come last: a "Pear Drop Necklace" is a necklace.
        return match (true) {
            (bool) preg_match('/ear\s?rings?|jhumk|\bstuds?\b|\bhoops?\b|\bhuggies?\b/', $words) => 'earring',
            (bool) preg_match('/\b(bangles?|churi|chudi|kada|kara)\b/', $words) => 'bangle',
            (bool) preg_match('/\brings?\b/', $words) => 'ring',
            (bool) preg_match('/\bbracelets?\b|\bcuffs?\b/', $words) => 'bracelet',
            (bool) preg_match('/\banklets?\b|\bpayel\b/', $words) => 'anklet',
            (bool) preg_match('/necklaces?|pendants?|\bchains?\b|chokers?|lockets?/', $words) => 'necklace',
            (bool) preg_match('/\bdangles?\b|\bdrops?\b/', $words) => 'earring',
            default => null,
        };
    }

    /** @return array<string, mixed> */
    public static function make(Product $p): array
    {
        $price = offer_pricing()->priceFor($p);
        $option = collect($p->options ?? [])->first(fn ($o) => is_array($o) && filled($o['name'] ?? null));
        $variants = $p->relationLoaded('variants') ? $p->variants : $p->variants()->where('is_active', true)->get();

        $sizes = null;
        if ($p->has_variants && $option && count($p->options ?? []) === 1) {
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
            'kind' => static::kind($p),
            'price' => $price,
            'price_text' => money($price),
            'thumb' => image_variant($p->thumbnail) ?: $p->thumbnail,
            'draw' => static::drawImage($p),
            'cutout' => filled($p->cutout_image),
            'content_id' => meta_content_id($p),
            'available' => $p->isAvailable(),
            // Several options (colour × size) are chosen on the product page.
            'needs_page' => $p->has_variants && $sizes === null,
            'sizes' => $sizes,
            'size_guide' => SizeGuide::for($p),
        ];
    }

    /**
     * What the tools draw: the cut-out when the owner has uploaded one, else
     * the plainest photo there is. Since Oct 2026 the primary photo is usually
     * the lifestyle shot (a model wearing it), so the first non-primary photo
     * — most often the piece on its own — is preferred over it.
     */
    public static function drawImage(Product $p): ?string
    {
        if (filled($p->cutout_image)) {
            return str_starts_with($p->cutout_image, 'http') ? $p->cutout_image : Storage::disk('public')->url($p->cutout_image);
        }

        $images = $p->relationLoaded('images') ? $p->images : $p->images()->get();
        $plain = $images->count() > 1
            ? $images->sortBy('position')->first(fn ($i) => ! $i->is_primary)
            : $images->first();
        $url = $plain?->url ?? $p->thumbnail;

        return $url ? (image_variant($url, 450) ?: $url) : null;
    }
}

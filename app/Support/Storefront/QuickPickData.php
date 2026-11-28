<?php

namespace App\Support\Storefront;

use App\Models\Product;
use App\Support\OfferPricing;

/**
 * What the "Choose" pop-up needs to put a piece with options in the bag
 * without leaving the page (Nov 2026): every option and value, and for each
 * active variant its listed price, whether it is in stock, and its photo.
 *
 * Prices are the ones the product page and the cards print — the live offer
 * off, the regular (or compare-at) price struck through — and, for a signed-in
 * member, the member price the cart will charge, worked out the way
 * ProductCardData does it. Stock is a boolean only, as on the product page.
 */
class QuickPickData
{
    /** @return array<string, mixed> */
    public static function make(Product $p): array
    {
        $p->loadMissing(['images', 'variants' => fn ($q) => $q->where('is_active', true), 'category']);

        $memberPct = 0.0;
        if (is_member()) {
            $memberPct = (member_pricing()->availableTo(auth('customer')->user()) ? member_pricing()->percentForProduct($p) : 0)
                + (float) (offer_pricing()->memberOfferFor($p)?->percent ?? 0);
        }

        $priced = function ($variant = null) use ($p, $memberPct) {
            $quote = offer_pricing()->quote($p, $variant);
            $unit = $variant?->price !== null ? (float) $variant->price : (float) $p->price;
            $member = $memberPct > 0
                ? OfferPricing::less($unit, $quote['offer'] ? $quote['percent'] + $memberPct : $memberPct)
                : null;

            return [
                'price' => $quote['price'],
                'price_text' => money($quote['price']),
                'compare_text' => $quote['was'] !== null ? money($quote['was']) : null,
                'member_text' => $member !== null ? money($member) : null,
            ];
        };

        // Only the options that still have a live variant behind them: an
        // option value whose every variant was switched off is not for sale.
        $options = collect($p->options ?? [])
            ->filter(fn ($o) => is_array($o) && filled($o['name'] ?? null))
            ->map(fn ($o) => [
                'name' => (string) $o['name'],
                'values' => collect($o['values'] ?? [])->map(fn ($v) => (string) $v)
                    ->filter(fn ($value) => $p->variants->contains(fn ($v) => (string) (($v->attributes ?? [])[$o['name']] ?? '') === $value))
                    ->values()->all(),
            ])
            ->filter(fn ($o) => $o['values'] !== [])
            ->values();

        $from = $p->variants->isNotEmpty()
            ? $p->variants->sortBy(fn ($v) => offer_pricing()->priceFor($p, $v))->first()
            : null;

        return [
            'id' => $p->id,
            'name' => $p->name,
            'url' => route('product.show', $p),
            'image' => $p->images->first()?->url,
            'has_variants' => (bool) $p->has_variants && $options->isNotEmpty(),
            'available' => $p->isAvailable(),
            'preorder' => $p->isPreorder(),
            'content_id' => meta_content_id($p),
            'add_url' => route('cart.add', $p),
            'from' => $priced($from),
            'member_pct' => $memberPct > 0 ? OfferPricing::percentText($memberPct) : null,
            'options' => $options->all(),
            'variants' => $p->variants->map(fn ($v) => [
                'id' => $v->id,
                'attrs' => collect($v->attributes ?? [])->map(fn ($value) => (string) $value)->all(),
                'in_stock' => (int) $v->stock_quantity > 0,
                'image' => $v->image_id ? $p->images->firstWhere('id', $v->image_id)?->url : null,
                'content_id' => meta_content_id($p, $v),
            ] + $priced($v))->values()->all(),
        ];
    }
}

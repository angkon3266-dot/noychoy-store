<?php

namespace App\Support\Storefront;

use App\Models\Category;
use App\Models\Product;

/**
 * Whether a product page offers the "Find my size" finder, and for what.
 *
 * Rings are sold in US sizes (6–10 on noychoy.com in Nov 2026) and bangles in
 * the subcontinent's 2.2 / 2.4 / 2.6 … sizes. A shopper buying a ring as a
 * gift, on a phone, on cash on delivery, has no way to know her size — and a
 * wrong size is a returned parcel the store pays both ways for. The finder
 * (resources/js/Shared/SizeFinder.jsx) measures on the screen and picks the
 * matching option for her.
 *
 * Decided here rather than in the browser so the rule is testable and a
 * category rename cannot quietly switch it off for every ring at once:
 *
 * - The kind comes from the product's categories (walking up to the parent),
 *   by word: "Fashion Ring", "925 Silver Ring", "Rings" are rings; "Earring"
 *   is not. "Bangle" / "Bangles" / "Churi" are bangles. Bracelets are sized by
 *   length, not diameter, so they get no finder.
 * - Without a category hint, an option named "Size" whose values all read as
 *   ring sizes still makes it a ring.
 * - `attribute` names the option the finder may select. It is the option
 *   called size, else the only option whose every value reads as a size of
 *   that kind. With no such option (a one-size or adjustable piece) there is
 *   nothing to pick and the whole thing is null.
 */
class SizeGuide
{
    public const RING = 'ring';

    public const BANGLE = 'bangle';

    /** US ring sizes the finder will name; outside this a value is not a ring size. */
    private const RING_MIN = 3.0;

    private const RING_MAX = 13.5;

    /** @return array{kind: string, attribute: ?string}|null */
    public static function for(Product $product): ?array
    {
        $options = collect($product->options ?? [])
            ->filter(fn ($o) => is_array($o) && filled($o['name'] ?? null))
            ->values();

        $kind = static::kindFromCategories($product);

        if (! $kind) {
            $sizeOption = $options->first(fn ($o) => static::isSizeName($o['name']));
            if ($sizeOption && static::allAre(self::RING, $sizeOption['values'] ?? [])) {
                $kind = self::RING;
            }
        }

        if (! $kind) {
            return null;
        }

        $named = $options->first(fn ($o) => static::isSizeName($o['name']) && static::allAre($kind, $o['values'] ?? []));
        $numeric = $options->filter(fn ($o) => static::allAre($kind, $o['values'] ?? []));
        $attribute = $named['name'] ?? ($numeric->count() === 1 ? $numeric->first()['name'] : null);

        // Nothing to pick (a one-size or adjustable piece): no finder, so the
        // page does not grow a link that leads nowhere.
        return $attribute ? ['kind' => $kind, 'attribute' => $attribute] : null;
    }

    private static function kindFromCategories(Product $product): ?string
    {
        $categories = collect([$product->category])->merge($product->categories ?? [])->filter();

        foreach ($categories as $category) {
            if ($kind = static::kindOf($category)) {
                return $kind;
            }
        }

        return null;
    }

    private static function kindOf(?Category $category): ?string
    {
        // Depth guard, as in PdpPoints: a parent loop must not hang the page.
        for ($depth = 0; $category && $depth < 5; $depth++, $category = $category->parent) {
            $words = strtolower($category->name.' '.$category->slug);

            if (preg_match('/\b(bangles?|churi|chudi)\b/', $words)) {
                return self::BANGLE;
            }
            if (preg_match('/\brings?\b/', $words)) {
                return self::RING;
            }
        }

        return null;
    }

    private static function isSizeName(string $name): bool
    {
        return (bool) preg_match('/\bsize\b|সাইজ/iu', $name);
    }

    /**
     * Every numbered value reads as a size of this kind ("7", "US 7.5", "2.6",
     * "Size 2.10"), and there is at least one. A wordy value beside them
     * ("Adjustable", "Free size") is passed over rather than disqualifying the
     * option; the finder just never picks it.
     */
    private static function allAre(string $kind, array $values): bool
    {
        $numbered = 0;

        foreach ($values as $value) {
            if (! preg_match('/(\d+(?:\.\d+)?)/', (string) $value, $m)) {
                continue;
            }
            $numbered++;
            $n = (float) $m[1];

            $ok = $kind === self::BANGLE
                ? preg_match('/^2\.(2|4|6|8|10|12|14)$/', $m[1]) === 1
                : $n >= self::RING_MIN && $n <= self::RING_MAX;

            if (! $ok) {
                return false;
            }
        }

        return $numbered > 0;
    }
}

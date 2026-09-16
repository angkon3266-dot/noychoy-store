<?php

namespace App\Support\Storefront;

use App\Models\Category;
use App\Models\Product;

/**
 * The "why buy from us" list beside the product page's buy button.
 *
 * One list is shown, never a blend: the product's own list if it has one,
 * else its category's (walking up to the parent), else the store-wide list
 * from Appearance. A bangle with no stones should not be promised a claw
 * setting just because the earrings are.
 *
 * Deliberately separate from `theme.trust_badges`, which still feeds the
 * footer strip and the ad landing pages and wants to stay three short lines.
 */
class PdpPoints
{
    public const MAX = 10;

    /** @return array{heading: ?string, items: array<int, array{icon: ?string, title: string, text: ?string}>} */
    public static function for(Product $product): array
    {
        return [
            'heading' => filled(theme('pdp_points_heading')) ? theme('pdp_points_heading') : null,
            'items' => static::clean($product->pdp_points)
                ?: static::fromCategories($product)
                ?: static::clean(theme('pdp_points')),
        ];
    }

    /** What a product would show if it had no list of its own — for the admin editor. */
    public static function inheritedFor(?Product $product, ?int $categoryId = null): array
    {
        if ($product) {
            return static::fromCategories($product) ?: static::clean(theme('pdp_points'));
        }

        return static::fromCategory($categoryId ? Category::find($categoryId) : null)
            ?: static::clean(theme('pdp_points'));
    }

    /** What a category would show if it had no list of its own. */
    public static function inheritedForCategory(?Category $category): array
    {
        return static::fromCategory($category?->parent) ?: static::clean(theme('pdp_points'));
    }

    private static function fromCategories(Product $product): array
    {
        if ($points = static::fromCategory($product->category)) {
            return $points;
        }

        foreach ($product->categories as $category) {
            if ($points = static::fromCategory($category)) {
                return $points;
            }
        }

        return [];
    }

    private static function fromCategory(?Category $category): array
    {
        // Depth guard: a parent loop in the data must not hang a product page.
        for ($depth = 0; $category && $depth < 5; $depth++, $category = $category->parent) {
            if ($points = static::clean($category->pdp_points)) {
                return $points;
            }
        }

        return [];
    }

    /**
     * Rows without a title are dropped, so a half-filled editor row never
     * renders as a bare icon.
     */
    public static function clean(mixed $rows): array
    {
        return collect(is_array($rows) ? $rows : [])
            ->filter(fn ($r) => is_array($r) && filled($r['title'] ?? null))
            ->map(fn ($r) => [
                'icon' => filled($r['icon'] ?? null) ? trim((string) $r['icon']) : null,
                'title' => trim((string) $r['title']),
                'text' => filled($r['text'] ?? null) ? trim((string) $r['text']) : null,
            ])
            ->take(static::MAX)
            ->values()
            ->all();
    }

    /** Validation rules for an editor posting `pdp_points[i][icon|title|text]`. */
    public static function rules(): array
    {
        return [
            'pdp_points_custom' => ['nullable', 'boolean'],
            'pdp_points' => ['nullable', 'array', 'max:'.static::MAX],
            'pdp_points.*.icon' => ['nullable', 'string', 'max:24'],
            'pdp_points.*.title' => ['nullable', 'string', 'max:80'],
            'pdp_points.*.text' => ['nullable', 'string', 'max:140'],
        ];
    }

    /** The value to store on a product or category: null means "inherit". */
    public static function fromRequest(array $validated): ?array
    {
        if (empty($validated['pdp_points_custom'])) {
            return null;
        }

        return static::clean($validated['pdp_points'] ?? []) ?: null;
    }
}

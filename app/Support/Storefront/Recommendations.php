<?php

namespace App\Support\Storefront;

use App\Models\Product;
use App\Models\ProductLove;
use App\Support\CoPurchase;
use App\Support\GiftProfile;

/**
 * Per-visitor product picks for the home page, from signals the store
 * already collects: the gift finder's answers, loved pieces (by visitor
 * token, so guests count), what they recently looked at, and what other
 * customers bought alongside those. Ids only — never cached, because the
 * cards carry member pricing and the signals are personal.
 */
class Recommendations
{
    /** @return array<int, int> most recent first */
    public static function recentlyViewedIds(int $limit = 8): array
    {
        return collect(session('recently_viewed', []))->map(fn ($id) => (int) $id)->filter()->unique()->take($limit)->values()->all();
    }

    /** @return array<int, int> */
    public static function pickedForYouIds(int $limit = 8, array $exclude = []): array
    {
        $ids = collect();

        // 1. The gift finder: the occasion's tag within the budget.
        if ($profile = GiftProfile::current()) {
            $q = Product::published();
            if ($tag = GiftProfile::occasionTag($profile)) {
                $q->where('tags', 'like', '%'.$tag.'%');
            }
            if (! empty($profile['max'])) {
                $q->where('price', '<=', (float) $profile['max']);
            }
            if (! empty($profile['min'])) {
                $q->where('price', '>=', (float) $profile['min']);
            }
            $ids = $ids->merge($q->orderByDesc('views')->take($limit)->pluck('id'));
        }

        // 2. Loved + recently viewed seed the co-purchase engine.
        $token = request()->cookie('visitor_token');
        $loved = $token ? ProductLove::where('visitor_token', $token)->latest()->take(6)->pluck('product_id') : collect();
        $seeds = $loved->merge(self::recentlyViewedIds())->map(fn ($id) => (int) $id)->unique()->take(8)->values()->all();

        if ($seeds !== []) {
            $ids = $ids->merge(CoPurchase::idsFor($seeds, $seeds, $limit));

            // 3. A young store has little co-purchase history: fill from the
            //    same categories as the seeds, most viewed first.
            if ($ids->unique()->count() < $limit) {
                $categoryIds = Product::whereIn('id', $seeds)->pluck('category_id')->filter()->unique()->values();
                if ($categoryIds->isNotEmpty()) {
                    $ids = $ids->merge(Product::published()->whereIn('category_id', $categoryIds)
                        ->whereNotIn('id', $seeds)->orderByDesc('views')->take($limit)->pluck('id'));
                }
            }
        }

        return $ids->map(fn ($id) => (int) $id)->unique()
            ->reject(fn ($id) => in_array($id, $exclude, true))
            ->take($limit)->values()->all();
    }
}

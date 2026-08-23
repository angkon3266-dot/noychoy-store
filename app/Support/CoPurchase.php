<?php

namespace App\Support;

use App\Models\OrderItem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * "People who bought this also bought…" — read out of real order lines.
 *
 * Lives here rather than inside a controller because two very different pages
 * want the same answer: the product page's frequently-bought-together block,
 * and the cart's suggestions. The cart used to show four products picked with
 * inRandomOrder(), which is the one thing this data was already good enough to
 * replace.
 *
 * The lookback is capped deliberately. Scanning every order line a product ever
 * appeared in means the best-selling piece — the one the ads push hardest — gets
 * the slowest page, and gets slower every month it sells. Recent orders are also
 * the better signal: what sold together last month beats what sold together two
 * years ago.
 */
class CoPurchase
{
    /** How many recent orders containing the seed products to look back over. */
    public const LOOKBACK_ORDERS = 500;

    /**
     * Product ids most often bought alongside the given ones, most-frequent first.
     *
     * @param  array<int,int>  $productIds  the seed products (a cart, or one product)
     * @param  array<int,int>  $excludeIds  ids to keep out of the answer
     * @return Collection<int,int>
     */
    public static function idsFor(array $productIds, array $excludeIds = [], int $limit = 4): Collection
    {
        $productIds = array_values(array_filter(array_map('intval', $productIds)));

        if (empty($productIds) || $limit < 1) {
            return collect();
        }

        $orderIds = OrderItem::whereIn('product_id', $productIds)
            ->orderByDesc('order_id')
            ->limit(self::LOOKBACK_ORDERS)
            ->pluck('order_id');

        if ($orderIds->isEmpty()) {
            return collect();
        }

        $exclude = array_values(array_unique(array_merge($productIds, array_map('intval', $excludeIds))));

        return OrderItem::whereIn('order_id', $orderIds)
            ->whereNotIn('product_id', $exclude)
            ->whereNotNull('product_id')
            ->select('product_id', DB::raw('COUNT(*) as c'))
            ->groupBy('product_id')
            ->orderByDesc('c')
            ->limit($limit)
            ->pluck('product_id');
    }
}

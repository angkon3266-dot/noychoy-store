<?php

namespace App\Support\Storefront;

use App\Models\Product;
use App\Services\CartService;
use App\Support\GiftLadder;

/**
 * The reward ladder, quoted before the shopper adds anything.
 *
 * The owner's call on 17 Sep 2026: the ৳50 off the first piece was told to
 * nobody until the cart, and the Frequently-bought-together total ignored the
 * ladder altogether — so the page advertised a price the cart then undercut,
 * which reads as a mistake rather than a reward. From here the product page
 * shows the reward on its price and in a ladder row under Add to cart, and
 * Frequently bought together prices each piece net of the rungs it opens.
 *
 * One builder feeds all of them — the product page props and the JSON refresh
 * the page calls after the cart changes — so the two can never disagree. It
 * quotes against the live session cart (the fourth piece earns a different
 * rung from the first) through GiftLadder::quote(), which runs the real solver
 * on an in-memory copy of the cart. Nothing here writes to the session, fires
 * tracking, or leaves a funnel row; pricing comes from CartService::lineFor(),
 * the same line the cart itself would store.
 *
 * Rung counts and values are never assumed: the owner edits the ladder in
 * Admin → Offers, and every number below is read from it.
 */
class LadderQuoteData
{
    /** Rows per price never run past this, however tall the ladder. */
    public const MAX_QTY = 20;

    /** Frequently bought together shows four tiles at most. */
    public const MAX_FBT = 4;

    /** A product with a price per variant still quotes a bounded amount of work. */
    public const MAX_PRICES = 12;

    /**
     * The product page's `ladderQuote` prop: for each quantity the shopper
     * might add, what the ladder would take off and which rungs would open.
     *
     * Null when the ladder is off or the product cannot be bought — there is
     * nothing to promise on a piece that cannot go in the cart.
     *
     * @return array{for_units:int, for_signature:?string, max_qty:int, percent:float, default_price_key:string,
     *               by_price:array<string, array<int, array{qty:int, saving:float, saving_text:string, first_piece:int,
     *               opened:array<int, string>, free_delivery_unlocked:bool, gift_unlocked:bool}>>}|null
     */
    public static function product(Product $product): ?array
    {
        $ladder = app(GiftLadder::class);
        if (! $ladder->enabled() || ! static::buyable($product)) {
            return null;
        }

        $cart = app(CartService::class);
        $forUnits = $ladder->resolve($cart)['paid_units'];

        // Enough rows to climb from where the cart stands to the top rung. A
        // live gift rung earns one spare row: once the gift unit goes free it
        // stops counting, so the top can sit one piece further away.
        $top = (int) max(array_column($ladder->tiers(), 'threshold'));
        $giftSlack = $ladder->giftTier() !== null && $ladder->giftIds() !== [] ? 1 : 0;
        $maxQty = min(self::MAX_QTY, max(1, $top - $forUnits + $giftSlack));

        $defaultLine = CartService::lineFor($product, null, 1);
        $defaultKey = static::priceKey($defaultLine['price']);
        $defaultRows = static::rows($ladder, $cart, $defaultLine, $maxQty);
        $byPrice = [$defaultKey => $defaultRows];

        // A variant priced differently can earn a different saving — a percent
        // rung takes a share of what is paid, a free gift is worth the piece,
        // and a flat rung is capped at the cart's subtotal. Each distinct price
        // is quoted, but only kept when its rows actually differ: the page
        // falls back to the default price's rows for a missing key, so leaving
        // out an identical set costs nothing and keeps a flat ladder's payload
        // to a single list.
        if ($product->has_variants) {
            $seen = [$defaultKey => true];
            foreach ($product->variants->where('is_active', true) as $variant) {
                if (count($seen) >= self::MAX_PRICES) {
                    break;
                }

                // A variant without its own price falls back to the product's,
                // which it reads through its `product` relation — one query per
                // variant unless the relation is already there. It is set on a
                // copy: set on the loaded variant itself, the product would hold
                // a variant that holds the product, and anything that serialises
                // the product afterwards would chase that loop for ever.
                $priced = (clone $variant)->setRelation('product', $product);
                $line = CartService::lineFor($product, $priced, 1);
                $key = static::priceKey($line['price']);
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;

                $rows = static::rows($ladder, $cart, $line, $maxQty);
                if ($rows !== $defaultRows) {
                    $byPrice[$key] = $rows;
                }
            }
        }

        // Past the last row the page reuses that row, which is right for a flat
        // rung (it is paid once) but not for a percent rung: that takes its
        // share of every further piece too. With [1: ৳50 off, 2: 10% off] and a
        // ৳1,000 piece there are two rows, and three pieces would have shown
        // row two's ৳250 while the cart took ৳350. So the quote names the
        // percent that is open once the last row's pieces are in, and the page
        // adds that share of each piece beyond it. Only the rungs that row has
        // actually opened count — when MAX_QTY cuts a tall ladder short, a
        // percent rung further up is not promised early; the page then errs
        // low, never high. The same for every price: a gift unit goes free on
        // the piece count, not the price, so each price's last row has the
        // same paid pieces. (Both solves behind this are memoised, and rows()
        // has just run them.)
        $lastPaid = $ladder->quote($cart, [['qty' => $maxQty] + $defaultLine])['paid_units_after'];
        $percent = 0.0;
        foreach ($ladder->tiers() as $tier) {
            if ($tier['type'] === 'percent' && $tier['threshold'] <= $lastPaid) {
                $percent += (float) $tier['value'];
            }
        }

        return [
            'for_units' => $forUnits,
            // The cart this was quoted for, as GiftLadder::progressFor() names
            // it — see there, and useLadderQuote() on the page.
            'for_signature' => static::signature($cart),
            'max_qty' => $maxQty,
            'percent' => round($percent, 2),
            'default_price_key' => $defaultKey,
            'by_price' => $byPrice,
        ];
    }

    /**
     * `fbt.ladder`: the ladder quote for every combination of ticked tiles in
     * Frequently bought together, so the bundle total can move as the shopper
     * ticks and unticks without asking the server again.
     *
     * Only the tiles that can be ticked count — in stock and without options
     * to choose, the same rule the tiles use. Subset keys are product ids in
     * display order joined by "-". `per_item` splits the saving piece by piece
     * in that order (each piece gets what it adds on top of the ones before
     * it), so every tile can show its own net price and the tiles add up to
     * the bundle.
     *
     * @param  iterable<Product>  $products  the tiles, in display order
     * @return array{for_units:int, for_signature:?string, subsets:array<string, array{saving:float, saving_text:string,
     *               per_item:array<int, float>, free_delivery_unlocked:bool, gift_unlocked:bool}>}|null
     */
    public static function fbt(iterable $products): ?array
    {
        $ladder = app(GiftLadder::class);
        if (! $ladder->enabled()) {
            return null;
        }

        $cart = app(CartService::class);

        $selectable = collect($products)
            ->filter(fn ($p) => $p instanceof Product && $p->isAvailable() && ! $p->has_variants)
            ->unique('id')
            ->take(self::MAX_FBT)
            ->values();

        $ids = $selectable->pluck('id')->map(fn ($id) => (int) $id)->all();
        $lines = $selectable->map(fn (Product $p) => CartService::lineFor($p, null, 1))->all();
        $n = count($ids);

        // Every non-empty subset, once. A prefix of a subset (in display
        // order) is itself a subset, so the piece-by-piece split below reads
        // its running totals from here instead of solving again.
        $quotes = [];
        for ($mask = 1; $mask < (1 << $n); $mask++) {
            $pick = array_values(array_filter(range(0, $n - 1), fn ($i) => ($mask >> $i) & 1));
            $quotes[static::subsetKey($pick, $ids)] = [
                'pick' => $pick,
                'quote' => $ladder->quote($cart, array_map(fn ($i) => $lines[$i], $pick)),
            ];
        }

        $subsets = [];
        foreach ($quotes as $key => ['pick' => $pick, 'quote' => $quote]) {
            $marginals = [];
            $running = 0.0;
            foreach ($pick as $j => $i) {
                $sofar = $quotes[static::subsetKey(array_slice($pick, 0, $j + 1), $ids)]['quote']['saving'];
                $marginals[] = round($sofar - $running, 2);
                $running = $sofar;
            }

            $perItem = [];
            foreach (static::floorMarginals($marginals) as $j => $amount) {
                $perItem[$ids[$pick[$j]]] = $amount;
            }

            $subsets[$key] = [
                'saving' => $quote['saving'],
                'saving_text' => money($quote['saving']),
                'per_item' => $perItem,
                'free_delivery_unlocked' => $quote['free_delivery_unlocked'],
                'gift_unlocked' => $quote['gift_unlocked'],
            ];
        }

        return [
            'for_units' => $ladder->resolve($cart)['paid_units'],
            'for_signature' => static::signature($cart),
            'subsets' => $subsets,
        ];
    }

    /**
     * The cart's signature from the shared ladder payload, so a quote and the
     * `gift` the page compares it with are stamped by one piece of code.
     */
    protected static function signature(CartService $cart): ?string
    {
        return $cart->giftProgress()['signature'] ?? null;
    }

    /** Can this piece go in the cart at all — published, and in stock or on pre-order. */
    public static function buyable(Product $product): bool
    {
        return $product->status === 'published' && ($product->isAvailable() || $product->isPreorder());
    }

    /** "1450.00": the unit price as a map key, two decimals and no separators. */
    public static function priceKey(float|int|string $price): string
    {
        return number_format((float) $price, 2, '.', '');
    }

    /** One row per quantity, 1…$maxQty, for a single line. */
    protected static function rows(GiftLadder $ladder, CartService $cart, array $line, int $maxQty): array
    {
        $rows = [];
        for ($q = 1; $q <= $maxQty; $q++) {
            $quote = $ladder->quote($cart, [['qty' => $q] + $line]);

            $rows[] = [
                'qty' => $q,
                'saving' => $quote['saving'],
                'saving_text' => money($quote['saving']),
                // The piece number the first added piece is, counted from the
                // paid pieces already in the cart — "your 2nd piece" when one
                // is in. The same for every row, so the page can name it once.
                'first_piece' => $quote['paid_units_before'] + 1,
                'opened' => $quote['opened'],
                'free_delivery_unlocked' => $quote['free_delivery_unlocked'],
                'gift_unlocked' => $quote['gift_unlocked'],
            ];
        }

        return $rows;
    }

    /**
     * Piece-by-piece savings with no piece below zero, still adding up to the
     * bundle's saving.
     *
     * A piece can lower the ladder's total in one odd case: a cheaper gift
     * piece takes the free slot from a dearer one already ticked. Shown as it
     * is, that tile would wear a price above its own. So its negative share is
     * set to nothing and taken back from the pieces before it, latest first —
     * they are what the bundle is really giving up — and the tiles still sum to
     * the bundle total the shopper will pay. The running total before any
     * piece is never negative (every quote is floored), so there is always
     * enough earlier saving to take it from.
     *
     * @param  array<int, float>  $marginals  in display order
     * @return array<int, float>
     */
    protected static function floorMarginals(array $marginals): array
    {
        foreach ($marginals as $j => $amount) {
            if ($amount >= 0) {
                continue;
            }
            $owed = -$amount;
            $marginals[$j] = 0.0;
            for ($k = $j - 1; $k >= 0 && $owed > 0; $k--) {
                $take = min($marginals[$k], $owed);
                $marginals[$k] = round($marginals[$k] - $take, 2);
                $owed = round($owed - $take, 2);
            }
        }

        return $marginals;
    }

    /** @param  array<int, int>  $pick  indexes into $ids, ascending */
    protected static function subsetKey(array $pick, array $ids): string
    {
        return implode('-', array_map(fn ($i) => $ids[$i], $pick));
    }
}

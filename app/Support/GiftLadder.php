<?php

namespace App\Support;

use App\Models\Collection as ProductCollection;
use App\Models\Setting;
use App\Services\CartService;
use App\Services\CollectionService;

/**
 * The milestone gift ladder: every {buy} qualifying pieces unlock 1 free piece
 * from a gift collection, up to {max} per order — the meridianeclat.com
 * "Every 3rd Piece Free" mechanic, rebuilt for this cart.
 *
 * Two admin-picked collections define it, mirroring the Shopify setup:
 * a QUALIFYING collection (blank = every product counts) and a GIFTS
 * collection (the pieces a customer may take free). The customer adds the
 * gift to the cart like any other item; the discount zeroes the cheapest
 * eligible gift units. Everything is resolved server-side from the session
 * cart — the client sends no pricing and cannot influence eligibility.
 *
 * Registered as a singleton so the collection lookups and the solver run at
 * most once per request however many times the cart re-renders.
 */
class GiftLadder
{
    /** @var array<string, mixed> per-request memo */
    protected array $memo = [];

    public function enabled(): bool
    {
        if (! Setting::get('gift_ladder_enabled', false) || $this->giftIds() === []) {
            return false;
        }

        // A qualifying collection that was configured but no longer resolves
        // (deactivated, deleted) must fail CLOSED — treating it as "blank"
        // would silently widen a giveaway the admin scoped narrowly. The
        // gifts collection already fails closed via the giftIds() check.
        $qualifyingId = (int) Setting::get('gift_ladder_qualifying_collection_id', 0);
        if ($qualifyingId > 0 && $this->qualifyingCollection() === null) {
            return false;
        }

        return true;
    }

    /** Paid qualifying pieces required per free gift. */
    public function buyCount(): int
    {
        return max(1, (int) Setting::get('gift_ladder_buy', 2));
    }

    /** Maximum free gifts per order. */
    public function maxGifts(): int
    {
        return max(1, (int) Setting::get('gift_ladder_max', 3));
    }

    public function giftCollection(): ?ProductCollection
    {
        return $this->memo['gift_collection'] ??= ProductCollection::active()
            ->find((int) Setting::get('gift_ladder_gifts_collection_id', 0));
    }

    public function qualifyingCollection(): ?ProductCollection
    {
        return $this->memo['qualifying_collection'] ??= ProductCollection::active()
            ->find((int) Setting::get('gift_ladder_qualifying_collection_id', 0));
    }

    /** @return array<int, int> product ids a customer may take free */
    public function giftIds(): array
    {
        return $this->memo['gift_ids'] ??= ($c = $this->giftCollection())
            ? app(CollectionService::class)->query($c)->pluck('products.id')->map(fn ($i) => (int) $i)->all()
            : [];
    }

    /** @return ?array<int, int> qualifying product ids, or null = everything qualifies */
    public function qualifyingIds(): ?array
    {
        return $this->memo['qualifying_ids'] ??= ($c = $this->qualifyingCollection())
            ? app(CollectionService::class)->query($c)->pluck('products.id')->map(fn ($i) => (int) $i)->all()
            : null;
    }

    /**
     * Solve the ladder against the cart, Shopify-BxGy style: each application
     * consumes {buy} qualifying units plus 1 gift unit, a gift unit is never
     * double-counted as its own qualifier, and the cheapest gift units go
     * free first.
     *
     * @return array{count:int, value:float, free:array<int, array{name:string, price:float, qty:int}>, free_by_line:array<string, int>, potential:int, qualifying_units:int, gift_units:int, gifts_qualify:bool}
     */
    public function resolve(CartService $cart): array
    {
        $key = 'resolve:'.md5(json_encode($cart->items()->map(fn ($i) => [$i['product_id'], $i['qty'], $i['price']])->values()));

        return $this->memo[$key] ??= $this->solve($cart);
    }

    protected function solve(CartService $cart): array
    {
        $empty = [
            'count' => 0, 'value' => 0.0, 'free' => [], 'free_by_line' => [],
            'potential' => 0, 'qualifying_units' => 0, 'gift_units' => 0, 'gifts_qualify' => true,
        ];

        if (! $this->enabled()) {
            return $empty;
        }

        $giftIds = $this->giftIds();
        $qualifyingIds = $this->qualifyingIds();
        $qualifies = fn (array $item) => $qualifyingIds === null
            || in_array((int) $item['product_id'], $qualifyingIds, true);

        // Expand lines into single units so a qty-3 gift line can be part
        // free, part paid.
        $giftUnits = [];
        $qualifyingUnits = 0;

        foreach ($cart->items() as $item) {
            $isGift = in_array((int) $item['product_id'], $giftIds, true);
            $isQualifying = $qualifies($item);

            if ($isQualifying) {
                $qualifyingUnits += (int) $item['qty'];
            }
            if ($isGift) {
                for ($u = 0; $u < (int) $item['qty']; $u++) {
                    $giftUnits[] = [
                        'key' => $item['key'],
                        'name' => $item['name'],
                        'price' => (float) $item['price'],
                        'qualifies' => $isQualifying,
                    ];
                }
            }
        }

        usort($giftUnits, fn ($a, $b) => $a['price'] <=> $b['price']);

        $buy = $this->buyCount();
        $cap = $this->maxGifts();

        // How many of the first $a gift units also sit in the qualifying pool
        // (they are consumed as gifts, so they cannot count as qualifiers).
        $consumedQualifying = function (int $a) use ($giftUnits): int {
            $q = 0;
            for ($u = 0; $u < min($a, count($giftUnits)); $u++) {
                $q += $giftUnits[$u]['qualifies'] ? 1 : 0;
            }

            return $q;
        };

        $count = 0;
        for ($a = min($cap, count($giftUnits)); $a >= 1; $a--) {
            if ($qualifyingUnits - $consumedQualifying($a) >= $a * $buy) {
                $count = $a;
                break;
            }
        }

        // How many gifts the cart has EARNED, whether or not the gift pieces
        // are in it yet — this powers "you've unlocked a gift, pick it".
        $potential = 0;
        for ($a = $cap; $a >= 1; $a--) {
            if ($qualifyingUnits - $consumedQualifying(min($a, count($giftUnits))) >= $a * $buy) {
                $potential = $a;
                break;
            }
        }

        $freeUnits = array_slice($giftUnits, 0, $count);
        $free = [];
        $freeByLine = [];
        foreach ($freeUnits as $unit) {
            $k = $unit['name'].'|'.$unit['price'];
            $free[$k] ??= ['name' => $unit['name'], 'price' => $unit['price'], 'qty' => 0];
            $free[$k]['qty']++;
            $freeByLine[$unit['key']] = ($freeByLine[$unit['key']] ?? 0) + 1;
        }

        $giftsQualify = $qualifyingIds === null
            || count(array_intersect($giftIds, $qualifyingIds)) === count($giftIds);

        return [
            'count' => $count,
            'value' => round(array_sum(array_column($freeUnits, 'price')), 2),
            'free' => array_values($free),
            'free_by_line' => $freeByLine,
            'potential' => max($potential, $count),
            'qualifying_units' => $qualifyingUnits,
            'gift_units' => count($giftUnits),
            'gifts_qualify' => $giftsQualify,
        ];
    }

    /**
     * How many free units the ladder zeroed on each cart line, keyed by line
     * key — the map CartService uses to price later discount stages against
     * what is actually paid.
     *
     * @return array<string, int>
     */
    public function freeUnitsByLine(CartService $cart): array
    {
        return $this->resolve($cart)['free_by_line'];
    }

    /** Taka zeroed off the cart by unlocked gifts. */
    public function discountFor(CartService $cart): float
    {
        return $this->resolve($cart)['value'];
    }

    /** @return array<int, array{label:string, amount:float}> one line per free piece */
    public function discountLinesFor(CartService $cart): array
    {
        return collect($this->resolve($cart)['free'])->map(fn ($f) => [
            'label' => 'Free gift — '.$f['name'].($f['qty'] > 1 ? ' × '.$f['qty'] : ''),
            'amount' => round($f['price'] * $f['qty'], 2),
        ])->all();
    }

    /**
     * Everything the milestone progress bar needs, or null when the ladder is
     * off or the cart is empty.
     */
    public function progressFor(CartService $cart): ?array
    {
        if (! $this->enabled() || $cart->isEmpty()) {
            return null;
        }

        $r = $this->resolve($cart);
        $buy = $this->buyCount();
        $cap = $this->maxGifts();

        // With gifts inside the qualifying pool a milestone is every
        // (buy + 1) pieces in the cart — the .com "3rd, 6th, 9th". With a
        // disjoint qualifying set it is every {buy} qualifying pieces, and
        // the gift piece rides along free.
        $step = $r['gifts_qualify'] ? $buy + 1 : $buy;
        $units = $r['qualifying_units'];

        $next = $r['potential'] < $cap
            ? max(1, ($r['potential'] + 1) * $step - $units)
            : null;

        return [
            'unlocked' => $r['count'],
            'potential' => $r['potential'],
            'cap' => $cap,
            'units' => $units,
            'milestones' => collect(range(1, $cap))->map(fn ($k) => $k * $step)->all(),
            'next_more' => $next,
            'pick_needed' => $r['potential'] > $r['count'],
            'saved_text' => $r['value'] > 0 ? money($r['value']) : null,
            'collection' => ($c = $this->giftCollection()) ? [
                'name' => $c->name,
                'url' => $c->url(),
            ] : null,
        ];
    }

    /**
     * The bold "Shop More, Unlock Up to ৳X in Gifts" badge for the product
     * page. "Up to" is honest arithmetic: the cap times the priciest piece a
     * customer could take free.
     */
    public function pdpBadge(): ?array
    {
        if (! $this->enabled()) {
            return null;
        }

        return $this->memo['pdp_badge'] ??= (function () {
            $collection = $this->giftCollection();
            $maxPrice = (float) app(CollectionService::class)->query($collection)->max('products.price');

            if ($maxPrice <= 0) {
                return null;
            }

            return [
                'label' => 'Shop More, Unlock Up to '.money($maxPrice * $this->maxGifts()).' in Gifts',
                'url' => $collection->url(),
            ];
        })();
    }
}

<?php

namespace App\Services;

use App\Models\AbandonedCart;
use App\Models\Product;
use App\Models\Visit;
use App\Support\DateRange;
use App\Support\TrafficSource;
use Illuminate\Support\Collection;

/**
 * The shoppers who filled a basket and never told us who they are.
 *
 * The abandoned-cart desk can only show people who typed a phone number into
 * the checkout. Far more get as far as the cart and leave without one — the
 * store has their behaviour and nothing else, so there is nobody to ring. They
 * are invisible on every other screen, yet they are the largest group in the
 * funnel and the only evidence of which pieces get picked up and put back down.
 *
 * "Why did they leave" is the question this cannot answer: nobody was asked.
 * What it can do is name the measurable things that were true when they left —
 * where they stopped, whether a delivery fee applied, whether the piece is even
 * in stock now — and let the owner draw the conclusion.
 */
class AnonymousCartInsight
{
    /** Sessions to list. The aggregate counts are over the whole window. */
    public const PAGE = 40;

    public function report(DateRange $range, int $limit = self::PAGE): array
    {
        $summary = $this->summary($range);

        return [
            'summary' => $summary,
            'products' => $this->products($range),
            'sessions' => $this->sessions($range, $limit),
            'signals' => $this->signals($range, $summary),
            'threshold' => free_shipping_threshold(),
        ];
    }

    /**
     * Visitor tokens with cart activity that never left a contact detail.
     *
     * A shopper who types a valid phone is captured as a lead the moment she
     * blurs the field — so "has no lead row" is the same set as "we cannot
     * reach her", and it excludes buyers too, since ordering means typing that
     * number first.
     */
    protected function anonymous($query)
    {
        return $query->whereNotIn('visitor_token', function ($sub) {
            $sub->select('visitor_token')->from('abandoned_carts')->whereNotNull('visitor_token');
        });
    }

    /** Tokens that reached the checkout page, as a subquery rather than a list. */
    protected function reachedCheckout($query, DateRange $range)
    {
        return $query->whereIn('visitor_token', function ($sub) use ($range) {
            $sub->select('visitor_token')->from('visits')->where('event', 'checkout_start');
            $range->constrain($sub);
        });
    }

    protected function summary(DateRange $range): array
    {
        $sessions = $this->anonymous(
            $range->constrain(Visit::query())->whereIn('event', ['cart_add', 'checkout_start'])
        )->distinct()->count('visitor_token');

        $checkout = $this->anonymous(
            $range->constrain(Visit::query())->where('event', 'checkout_start')
        )->distinct()->count('visitor_token');

        // Money is summed over cart_add events, so one shopper who added three
        // pieces carries three pieces' worth — the same convention the funnel
        // panel uses. Rows recorded before the value column existed are null,
        // and null is not zero, so they are counted and declared rather than
        // quietly summed away.
        $value = $this->anonymous(
            $range->constrain(Visit::query())->where('event', 'cart_add')
        )->selectRaw('SUM(value) as total, SUM(CASE WHEN value IS NULL THEN 1 ELSE 0 END) as unmeasured')->first();

        return [
            'sessions' => $sessions,
            'reached_checkout' => $checkout,
            'cart_only' => max(0, $sessions - $checkout),
            'value' => round((float) ($value->total ?? 0), 2),
            'unmeasured' => (int) ($value->unmeasured ?? 0),
            // For the "and this many did leave a number" comparison.
            'leads' => $range->constrain(AbandonedCart::query())->count(),
        ];
    }

    /**
     * What they picked up, most-wanted first.
     *
     * Ranked by sessions rather than adds: ten adds from one undecided shopper
     * is one person, and a piece ten different people reached for is the real
     * signal.
     */
    protected function products(DateRange $range): Collection
    {
        $rows = $this->anonymous(
            $range->constrain(Visit::query())->where('event', 'cart_add')->whereNotNull('product_id')
        )
            ->selectRaw('product_id, COUNT(*) as adds, COUNT(DISTINCT visitor_token) as sessions, SUM(value) as value')
            ->groupBy('product_id')->orderByDesc('sessions')->orderByDesc('adds')->take(25)->get();

        if ($rows->isEmpty()) {
            return collect();
        }

        // The same tally, restricted to sessions that got as far as checkout —
        // "picked up 12 times, reached checkout twice" is a different problem
        // from "picked up 12 times, 11 reached checkout".
        $reached = $this->reachedCheckout(
            $this->anonymous(
                $range->constrain(Visit::query())->where('event', 'cart_add')->whereNotNull('product_id')
            ),
            $range,
        )->selectRaw('product_id, COUNT(DISTINCT visitor_token) as sessions')
            ->groupBy('product_id')->pluck('sessions', 'product_id');

        $products = Product::with('images')->whereIn('id', $rows->pluck('product_id')->all())->get()->keyBy('id');

        return $rows->map(function ($row) use ($products, $reached) {
            $product = $products->get($row->product_id);
            $thumb = $product?->thumbnail;

            return [
                'id' => (int) $row->product_id,
                'name' => $product?->name ?? 'Removed product #'.$row->product_id,
                'adds' => (int) $row->adds,
                'sessions' => (int) $row->sessions,
                'reached_checkout' => (int) ($reached[$row->product_id] ?? 0),
                'value' => round((float) $row->value, 2),
                'url' => $product && $product->status === 'published' ? route('product.show', $product) : null,
                'thumb' => $thumb ? (image_variant($thumb, 450) ?: $thumb) : null,
                'in_stock' => $this->sellable($product),
                'gone' => $product === null || $product->status !== 'published',
            ];
        });
    }

    /** Can a shopper buy this right now? Null when the product has vanished. */
    protected function sellable(?Product $product): ?bool
    {
        if (! $product) {
            return null;
        }

        if (! $product->manage_stock) {
            return (bool) $product->in_stock;
        }

        return (int) $product->stock_quantity > 0;
    }

    /** One row per anonymous session, newest first. */
    protected function sessions(DateRange $range, int $limit): Collection
    {
        $rows = $this->anonymous(
            $range->constrain(Visit::query())->whereIn('event', ['cart_add', 'checkout_start'])
        )
            ->selectRaw(
                'visitor_token,'
                .' SUM(CASE WHEN event = \'cart_add\' THEN 1 ELSE 0 END) as adds,'
                .' MAX(CASE WHEN event = \'checkout_start\' THEN 1 ELSE 0 END) as reached_checkout,'
                .' SUM(CASE WHEN event = \'cart_add\' THEN value ELSE 0 END) as cart_value,'
                .' MAX(CASE WHEN event = \'checkout_start\' THEN value ELSE NULL END) as checkout_value,'
                .' MIN(created_at) as first_at, MAX(created_at) as last_at'
            )
            ->groupBy('visitor_token')
            ->orderByDesc('last_at')->take($limit)->get();

        if ($rows->isEmpty()) {
            return collect();
        }

        // Resolved once for the whole page. Inside the map these would be one
        // pair of queries per row, and memoising them on the method would hand
        // the next call another page's answers.
        $tokens = $rows->pluck('visitor_token')->all();
        $items = $this->itemsFor($tokens);
        $sources = $this->sourceFor($tokens);

        return $rows->map(fn ($row) => $this->session($row, $items, $sources));
    }

    /** Product names each session picked up, keyed by token. */
    protected function itemsFor(array $tokens): Collection
    {
        $rows = Visit::whereIn('visitor_token', $tokens)
            ->where('event', 'cart_add')->whereNotNull('product_id')
            ->selectRaw('visitor_token, product_id, COUNT(*) as adds')
            ->groupBy('visitor_token', 'product_id')->get();

        $products = Product::whereIn('id', $rows->pluck('product_id')->unique()->all())
            ->get(['id', 'name', 'status', 'in_stock', 'manage_stock', 'stock_quantity'])->keyBy('id');

        return $rows->groupBy('visitor_token')->map(fn ($lines) => $lines->map(fn ($l) => [
            'name' => $products->get($l->product_id)?->name ?? 'Removed product #'.$l->product_id,
            'adds' => (int) $l->adds,
            'sellable' => $this->sellable($products->get($l->product_id)),
        ])->all());
    }

    /**
     * Where each session came from.
     *
     * The first non-direct touch, matching how an order is attributed: a direct
     * hit partway through a session should not erase the ad click that started
     * it.
     */
    protected function sourceFor(array $tokens): Collection
    {
        return Visit::whereIn('visitor_token', $tokens)
            ->whereNotNull('source')->where('source', '!=', '')->where('source', '!=', 'direct')
            ->selectRaw('visitor_token, source, campaign, MIN(created_at) as seen')
            ->groupBy('visitor_token', 'source', 'campaign')
            ->orderBy('seen')->get()->groupBy('visitor_token')
            ->map(fn ($rows) => $rows->first());
    }

    protected function session($row, Collection $items, Collection $sources): array
    {
        $lines = collect($items[$row->visitor_token] ?? []);
        $source = $sources[$row->visitor_token] ?? null;
        $reached = (bool) $row->reached_checkout;
        // The checkout figure is what the basket was actually worth at the
        // moment she got there, discounts applied; the cart sum is every add.
        $value = (float) ($row->checkout_value ?? $row->cart_value ?? 0);

        return [
            'token' => $row->visitor_token,
            // A cookie value, not a person — shortened so the table stays
            // readable and nobody mistakes it for something identifying.
            'short' => strtoupper(substr((string) $row->visitor_token, 0, 6)),
            'adds' => (int) $row->adds,
            'reached_checkout' => $reached,
            'value' => round($value, 2),
            'items' => $lines->all(),
            'first_at' => $row->first_at,
            'last_at' => $row->last_at,
            'channel' => $source->source ?? 'direct',
            'channel_label' => TrafficSource::label($source->source ?? 'direct'),
            'campaign' => $source->campaign ?? null,
            'signal' => $this->signal($reached, $value, $lines),
        ];
    }

    /**
     * The most specific thing that was measurably true when she left.
     *
     * Deliberately not called a reason. Nobody asked her, and the honest
     * version of this is "here is what was true", not "here is why".
     */
    protected function signal(bool $reached, float $value, Collection $lines): array
    {
        $threshold = free_shipping_threshold();
        $unsellable = $lines->contains(fn ($l) => $l['sellable'] === false || $l['sellable'] === null);

        if ($unsellable) {
            return ['label' => 'A piece is unbuyable now', 'tone' => 'bg-red-100 text-red-700',
                'note' => 'Out of stock or withdrawn — she may have hit the same wall.'];
        }

        $paysDelivery = $threshold !== null && $value > 0 && $value < $threshold;

        if ($reached && $paysDelivery) {
            return ['label' => 'Delivery fee on a small basket', 'tone' => 'bg-amber-100 text-amber-700',
                'note' => 'Reached checkout under the '.money($threshold).' free-delivery line, so postage was added.'];
        }

        if ($reached) {
            return ['label' => 'Left at the checkout form', 'tone' => 'bg-amber-100 text-amber-700',
                'note' => 'Saw the total and the form, and did not finish.'];
        }

        if ($paysDelivery) {
            return ['label' => 'Never reached checkout · under free delivery', 'tone' => 'bg-ink-100 text-ink-700',
                'note' => 'Basket was under '.money($threshold).', and she never got as far as the form.'];
        }

        return ['label' => 'Never reached checkout', 'tone' => 'bg-ink-100 text-ink-700',
            'note' => 'Added to the cart and left without opening the checkout.'];
    }

    /**
     * The same signals counted across the whole window, so a pattern is
     * visible without reading forty rows.
     */
    protected function signals(DateRange $range, array $summary): array
    {
        $threshold = free_shipping_threshold();

        $out = [
            [
                'label' => 'Left at the checkout form',
                'count' => $summary['reached_checkout'],
                'note' => 'They saw the delivery charge and the total. This is the step to make easier.',
            ],
            [
                'label' => 'Never opened the checkout',
                'count' => $summary['cart_only'],
                'note' => 'The cart was as far as they went — a browsing or price decision, not a form problem.',
            ],
        ];

        if ($threshold !== null) {
            // Baskets that would have paid postage, counted at the checkout
            // moment because that is when the fee becomes visible.
            $under = $this->anonymous(
                $range->constrain(Visit::query())->where('event', 'checkout_start')
                    ->whereNotNull('value')->where('value', '>', 0)->where('value', '<', $threshold)
            )->distinct()->count('visitor_token');

            $out[] = [
                'label' => 'Reached checkout under '.money($threshold),
                'count' => $under,
                'note' => 'Delivery was charged on these. Lowering the free-delivery line would have covered them.',
            ];
        }

        return $out;
    }
}

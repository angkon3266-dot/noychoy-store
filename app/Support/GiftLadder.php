<?php

namespace App\Support;

use App\Models\Collection as ProductCollection;
use App\Models\Setting;
use App\Services\CartService;
use App\Services\CollectionService;
use Illuminate\Support\Collection;

/**
 * The reward ladder: every paid piece in the cart climbs one rung, and each
 * rung unlocks a reward that stays unlocked as the cart grows — ৳50 off at
 * the 1st piece, 2% off at the 2nd, free delivery at the 3rd, … a free gift
 * at the 9th. The "add more, save more" mechanic the owner runs on the
 * Shopify store, rebuilt for this cart.
 *
 * Four reward types: `flat` (৳ off), `percent` (% off what is paid),
 * `free_delivery`, and `free_gift` — the customer adds a piece from the
 * admin-picked gifts collection and the cheapest such unit goes to ৳0. A free
 * gift unit never counts as a paid piece, so it cannot climb the ladder for
 * itself. Everything is resolved server-side from the session cart; the
 * client sends no pricing and cannot influence eligibility.
 *
 * Registered as a singleton so the collection lookups and the solver run at
 * most once per request however many times the cart re-renders.
 */
class GiftLadder
{
    public const TYPES = ['flat', 'percent', 'free_delivery', 'free_gift'];

    public const MAX_TIERS = 12;

    /** @var array<string, mixed> per-request memo */
    protected array $memo = [];

    public function enabled(): bool
    {
        return (bool) Setting::get('gift_ladder_enabled', false) && $this->tiers() !== [];
    }

    /**
     * The ladder, one row per rung, sorted by threshold, invalid rows dropped.
     *
     * @return array<int, array{n:int, threshold:int, type:string, value:?float, label:string, short:string}>
     */
    public function tiers(): array
    {
        return $this->memo['tiers'] ??= static::normalise(
            Setting::get('gift_ladder_tiers', config('ladder.tiers', []))
        );
    }

    /**
     * Sanitise a raw tier list (settings, an admin form, a config file) into
     * the canonical shape. Shared with the admin save so what is stored is
     * exactly what the solver will read.
     *
     * @return array<int, array{n:int, threshold:int, type:string, value:?float, label:string, short:string}>
     */
    public static function normalise(mixed $rows): array
    {
        $tiers = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $threshold = (int) ($row['threshold'] ?? 0);
            $type = (string) ($row['type'] ?? '');
            $value = isset($row['value']) && is_numeric($row['value']) ? round((float) $row['value'], 2) : null;

            if ($threshold < 1 || ! in_array($type, self::TYPES, true)) {
                continue;
            }
            if (in_array($type, ['flat', 'percent'], true) && ($value === null || $value <= 0)) {
                continue;
            }
            if ($type === 'percent' && $value > 90) {
                $value = 90.0;
            }
            if (! in_array($type, ['flat', 'percent'], true)) {
                $value = null;
            }

            $tiers[] = ['threshold' => $threshold, 'type' => $type, 'value' => $value];
        }

        usort($tiers, fn ($a, $b) => $a['threshold'] <=> $b['threshold']);
        $tiers = array_slice($tiers, 0, self::MAX_TIERS);

        foreach ($tiers as $i => &$tier) {
            $tier = ['n' => $i + 1] + $tier + [
                'label' => static::label($tier),
                'short' => static::short($tier),
            ];
        }

        return $tiers;
    }

    /** "৳50 off", "2% off", "Free delivery", "Free gift". */
    public static function label(array $tier): string
    {
        return match ($tier['type']) {
            'flat' => money($tier['value']).' off',
            'percent' => static::pct($tier['value']).'% off',
            'free_delivery' => 'Free delivery',
            default => 'Free gift',
        };
    }

    /** The stepper caption: "৳50", "2%", "Delivery", "Gift". */
    public static function short(array $tier): string
    {
        return match ($tier['type']) {
            'flat' => money($tier['value']),
            'percent' => static::pct($tier['value']).'%',
            'free_delivery' => 'Delivery',
            default => 'Gift',
        };
    }

    protected static function pct(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }

    public function giftCollection(): ?ProductCollection
    {
        return $this->memo['gift_collection'] ??= ProductCollection::active()
            ->find((int) Setting::get('gift_ladder_gifts_collection_id', 0));
    }

    /** @return array<int, int> product ids a customer may take free */
    public function giftIds(): array
    {
        return $this->memo['gift_ids'] ??= ($c = $this->giftCollection())
            ? app(CollectionService::class)->query($c)->pluck('products.id')->map(fn ($i) => (int) $i)->all()
            : [];
    }

    /** The rung that hands out a free gift, if the ladder has one. */
    public function giftTier(): ?array
    {
        foreach ($this->tiers() as $tier) {
            if ($tier['type'] === 'free_gift') {
                return $tier;
            }
        }

        return null;
    }

    /**
     * Solve the ladder against the cart.
     *
     * @return array{units:int, paid_units:int, tier:int, value:float, base:float, free_delivery:bool,
     *               rewards:array<int, array{n:int, threshold:int, type:string, value:?float, label:string, short:string, unlocked:bool, pending:bool, amount:float}>,
     *               free:?array{key:string, name:string, price:float}, free_by_line:array<string, int>, gift_pending:bool}
     */
    public function resolve(CartService $cart): array
    {
        return $this->resolveItems($cart->items());
    }

    /**
     * The memoised solve for any list of cart lines. The line key and name
     * ride in the memo key because the free gift reports both: two lines of
     * one product at one price (two variants, say) must not share an answer
     * that names the wrong line.
     */
    protected function resolveItems(Collection $items): array
    {
        $key = 'resolve:'.md5(json_encode($items->map(fn ($i) => [$i['key'] ?? null, $i['product_id'], $i['qty'], $i['price'], $i['name'] ?? null])->values()));

        return $this->memo[$key] ??= $this->solveItems($items);
    }

    /**
     * The solver itself, as a pure function of the cart lines — it reads the
     * lines and the ladder settings and nothing else, so it can price a cart
     * that does not exist yet ("what if she adds two more?") without touching
     * the session. The product page's reward row and the Frequently-bought-
     * together totals are quoted this way; see quote().
     */
    public function solveItems(Collection $items): array
    {
        $empty = [
            'units' => 0, 'paid_units' => 0, 'tier' => 0, 'value' => 0.0, 'base' => 0.0,
            'free_delivery' => false, 'rewards' => [], 'free' => null, 'free_by_line' => [], 'gift_pending' => false,
        ];

        if (! $this->enabled()) {
            return $empty;
        }

        $tiers = $this->tiers();

        // An empty cart still knows the ladder — every rung locked — so the
        // header strip can name the first milestone before anything is added.
        if ($items->isEmpty()) {
            return ['rewards' => array_map(fn ($t) => $t + ['unlocked' => false, 'pending' => false, 'amount' => 0.0], $tiers)] + $empty;
        }

        $units = (int) $items->sum('qty');
        $subtotal = (float) $items->sum(fn ($i) => $i['price'] * $i['qty']);

        // The free gift: the cheapest unit from the gifts collection, and only
        // once the OTHER pieces have reached the gift rung — a gift unit can
        // never be its own qualifier. Free gifts need a populated collection;
        // without one the rung simply never opens and the money rungs carry on.
        $giftTier = $this->giftTier();
        $giftIds = $giftTier ? $this->giftIds() : [];
        $free = null;
        if ($giftIds !== [] && $units - 1 >= $giftTier['threshold']) {
            foreach ($items as $item) {
                if (! in_array((int) $item['product_id'], $giftIds, true)) {
                    continue;
                }
                if ($free === null || (float) $item['price'] < $free['price']) {
                    $free = ['key' => $item['key'], 'name' => $item['name'], 'price' => (float) $item['price']];
                }
            }
        }

        $paidUnits = $units - ($free ? 1 : 0);
        $base = round($subtotal - ($free['price'] ?? 0.0), 2);

        $reached = 0;
        $money = 0.0;
        $freeDelivery = false;
        $giftPending = false;
        $rewards = [];

        foreach ($tiers as $tier) {
            $unlocked = $paidUnits >= $tier['threshold'];
            $amount = 0.0;
            $pending = false;

            if ($unlocked) {
                $reached = $tier['n'];
                switch ($tier['type']) {
                    case 'flat':
                        $amount = (float) $tier['value'];
                        break;
                    case 'percent':
                        $amount = round($base * (float) $tier['value'] / 100, 2);
                        break;
                    case 'free_delivery':
                        $freeDelivery = true;
                        break;
                    case 'free_gift':
                        $amount = $free['price'] ?? 0.0;
                        $pending = $free === null && $giftIds !== [];
                        $giftPending = $giftPending || $pending;
                        break;
                }
                $money += $amount;
            }

            $rewards[] = $tier + ['unlocked' => $unlocked, 'pending' => $pending, 'amount' => round($amount, 2)];
        }

        return [
            'units' => $units,
            'paid_units' => $paidUnits,
            'tier' => $reached,
            'value' => round(min($money, $subtotal), 2),
            'base' => $base,
            'free_delivery' => $freeDelivery,
            'rewards' => $rewards,
            'free' => $free,
            'free_by_line' => $free ? [$free['key'] => 1] : [],
            'gift_pending' => $giftPending,
        ];
    }

    /**
     * What adding these lines to the cart would do to the ladder, without
     * adding them.
     *
     * The owner's call on 17 Sep 2026: the ৳50 first-piece reward was
     * communicated nowhere on a product, and the Frequently-bought-together
     * total ignored the ladder entirely, so the shopper only discovered the
     * saving in the cart. Both now quote it up front — and a quote is only
     * worth showing if it is the number the cart will actually take off. So
     * this runs the real solver on an in-memory copy of the cart lines, never
     * a parallel sum: the lines are merged exactly as CartService::add() merges
     * them (same line key → the quantities add, the cart's snapshotted price
     * stands), and the result is diffed against the cart as it is.
     *
     * Nothing here writes. The session cart is read, never touched — not an
     * add-then-remove, not a scoped cart — so a quote can run on every page
     * view without a trace in the abandoned-cart or funnel reports.
     *
     * @param  array<int, array>  $lines  cart lines as CartService::lineFor() builds them
     * @return array{saving:float, opened:array<int, string>, free_delivery_unlocked:bool, gift_unlocked:bool,
     *               paid_units_before:int, paid_units_after:int}|null null when the ladder is off
     */
    public function quote(CartService $cart, array $lines): ?array
    {
        if (! $this->enabled()) {
            return null;
        }

        $before = $this->resolve($cart);

        $merged = $cart->items()->keyBy('key')->all();
        foreach ($lines as $line) {
            if (isset($merged[$line['key']])) {
                $merged[$line['key']]['qty'] += (int) $line['qty'];
            } else {
                $merged[$line['key']] = $line;
            }
        }
        $after = $this->resolveItems(collect($merged));

        // A free-gift rung with nothing in the gifts collection hands out
        // nothing, so it is never announced as opened — the same honesty the
        // product-page promise keeps in pdpBadge(). The collection is only
        // looked up when such a rung actually opens: a quote runs once per
        // row, and a ladder without a gift rung should not pay for one.
        $opened = [];
        $giftUnlocked = false;
        foreach ($after['rewards'] as $i => $reward) {
            if (! $reward['unlocked'] || ($before['rewards'][$i]['unlocked'] ?? false)) {
                continue;
            }
            if ($reward['type'] === 'free_gift') {
                if ($this->giftIds() === []) {
                    continue;
                }
                $giftUnlocked = true;
            }
            $opened[] = $reward['label'];
        }

        return [
            // Adding pieces almost never lowers what the ladder takes off —
            // the exception is a cheaper gift piece taking the free slot from
            // a dearer one — and a negative "saving" on a price tag would read
            // as a surcharge, so it is floored rather than shown.
            'saving' => max(0.0, round($after['value'] - $before['value'], 2)),
            'opened' => $opened,
            'free_delivery_unlocked' => $after['free_delivery'] && ! $before['free_delivery'],
            'gift_unlocked' => $giftUnlocked,
            'paid_units_before' => $before['paid_units'],
            'paid_units_after' => $after['paid_units'],
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

    /** Taka the unlocked rungs take off the cart (flat + percent + the free gift). */
    public function discountFor(CartService $cart): float
    {
        return $this->resolve($cart)['value'];
    }

    /** Whether an unlocked rung makes delivery free. */
    public function freeDeliveryFor(CartService $cart): bool
    {
        return $this->resolve($cart)['free_delivery'];
    }

    /** The rung the cart has climbed to (0 = none). */
    public function tierFor(CartService $cart): int
    {
        return $this->resolve($cart)['tier'];
    }

    /**
     * One line per unlocked rung that is worth money, so the customer sees
     * where each saving came from. Free delivery is not a line — the shipping
     * row shows ৳0 instead.
     *
     * @return array<int, array{label:string, amount:float}>
     */
    public function discountLinesFor(CartService $cart): array
    {
        $r = $this->resolve($cart);
        $lines = [];
        foreach ($r['rewards'] as $reward) {
            if (! $reward['unlocked'] || $reward['amount'] <= 0) {
                continue;
            }
            $lines[] = [
                'label' => $reward['type'] === 'free_gift'
                    ? 'Free gift — '.$r['free']['name']
                    : 'Ladder · '.$reward['label'].' ('.$reward['threshold'].($reward['threshold'] === 1 ? ' piece' : ' pieces').')',
                'amount' => $reward['amount'],
            ];
        }

        return $lines;
    }

    /**
     * Every unlocked reward as a compact record for the order — what the
     * customer was promised on the day, kept even if the ladder changes later.
     *
     * @return array<int, array{n:int, label:string, amount:float}>
     */
    public function rewardsFor(CartService $cart): array
    {
        $r = $this->resolve($cart);
        $out = [];
        foreach ($r['rewards'] as $reward) {
            if (! $reward['unlocked']) {
                continue;
            }
            $out[] = [
                'n' => $reward['n'],
                'label' => $reward['type'] === 'free_gift' && $r['free'] ? 'Free gift — '.$r['free']['name'] : $reward['label'],
                'amount' => $reward['amount'],
            ];
        }

        return $out;
    }

    /**
     * Everything the ladder bar and the always-on strip need, or null when
     * the ladder is off. An empty cart still gets a payload (rung 0, first
     * rung next) so the strip can invite the first piece.
     */
    public function progressFor(CartService $cart): ?array
    {
        if (! $this->enabled()) {
            return null;
        }

        $r = $this->resolve($cart);
        $tiers = $r['rewards'];
        $next = null;
        foreach ($tiers as $tier) {
            if (! $tier['unlocked']) {
                $next = [
                    'n' => $tier['n'],
                    'threshold' => $tier['threshold'],
                    'label' => $tier['label'],
                    'more' => max(1, $tier['threshold'] - $r['paid_units']),
                ];
                break;
            }
        }

        $unlocked = array_values(array_filter($tiers, fn ($t) => $t['unlocked']));
        $collection = $this->giftCollection();

        return [
            'units' => $r['paid_units'],
            // The cart's lines in short — key, quantity and price, the only
            // things a ladder quote reads — for the product page to tell
            // whether its reward-ladder quote (stamped with the same value as
            // `for_signature`) still describes this cart. `units` alone could
            // not: a free gift piece is not a paid unit, so adding or removing
            // one left the count where it was and the page kept promising "you
            // pay ৳0" for a second gift. Swapping one piece for a dearer one
            // moves a percent rung without moving the count either.
            'signature' => substr(md5(json_encode($cart->items()
                ->map(fn ($i) => [(string) ($i['key'] ?? ''), (int) $i['qty'], round((float) $i['price'], 2)])
                ->values())), 0, 16),
            'tier' => $r['tier'],
            'count' => count($tiers),
            'tiers' => array_map(fn ($t) => [
                'n' => $t['n'],
                'threshold' => $t['threshold'],
                'type' => $t['type'],
                'label' => $t['label'],
                'short' => $t['short'],
                // The rung's own number — ৳ for flat, % for percent, null for
                // the two switches. Added 17 Sep 2026 so product cards and the
                // product page can work out "৳50 off your first piece" from the
                // shared prop without re-parsing the label text.
                'value' => $t['value'],
                'unlocked' => $t['unlocked'],
                'pending' => $t['pending'],
            ], $tiers),
            'next' => $next,
            'summary' => implode(' · ', array_map(fn ($t) => $t['label'], $unlocked)),
            // Free delivery is money the customer keeps, so it counts towards
            // what the ladder has saved her — valued at the lower zone rate.
            'saved_text' => ($saved = $r['value'] + ($r['free_delivery'] ? $cart->deliveryRate() : 0.0)) > 0
                ? money($saved) : null,
            'free_delivery' => $r['free_delivery'],
            'gift' => [
                'pick_needed' => $r['gift_pending'],
                'name' => $r['free']['name'] ?? null,
                'collection' => $collection ? ['name' => $collection->name, 'url' => $collection->url()] : null,
                // Whether a free gift can actually be handed out: a gift rung
                // AND a gifts collection with something published in it. An
                // empty collection still has a name and a URL above, but the
                // solver never gives a piece from it, so a product card must
                // not promise "+ Free gift" on the strength of the collection
                // alone — the same test quote() and pdpBadge() apply.
                'available' => $this->giftTier() !== null && $this->giftIds() !== [],
            ],
        ];
    }

    /**
     * The one-line promise for the product page: the first money rung, the
     * delivery rung and the gift rung, each with the piece that opens it.
     */
    public function pdpBadge(): ?array
    {
        if (! $this->enabled()) {
            return null;
        }

        return $this->memo['pdp_badge'] ??= (function () {
            $parts = [];
            $seen = [];
            foreach ($this->tiers() as $tier) {
                $kind = in_array($tier['type'], ['flat', 'percent'], true) ? 'money' : $tier['type'];
                if (isset($seen[$kind])) {
                    continue;
                }
                if ($tier['type'] === 'free_gift' && $this->giftIds() === []) {
                    continue;
                }
                $seen[$kind] = true;
                $piece = static::ordinal($tier['threshold']).' piece';
                $parts[] = match ($tier['type']) {
                    'free_delivery' => 'free delivery from the '.$piece,
                    'free_gift' => 'a free gift at the '.$piece,
                    default => strtolower($tier['label']).' from the '.$piece,
                };
            }

            if ($parts === []) {
                return null;
            }

            $collection = $this->giftCollection();

            return [
                'label' => 'Add more, save more — '.implode(', ', $parts),
                'url' => $collection && $this->giftIds() !== [] ? $collection->url() : route('shop'),
            ];
        })();
    }

    public static function ordinal(int $n): string
    {
        $suffix = ($n % 100 >= 11 && $n % 100 <= 13) ? 'th' : match ($n % 10) {
            1 => 'st', 2 => 'nd', 3 => 'rd', default => 'th',
        };

        return $n.$suffix;
    }
}

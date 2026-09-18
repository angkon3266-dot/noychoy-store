<?php

namespace App\Services;

use App\Models\AbandonedCart;
use App\Models\AbandonedCartContact;
use App\Models\AssistantConversation;
use App\Models\AssistantMessage;
use App\Models\CallReminder;
use App\Models\Coupon;
use App\Models\CourierCheck;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Setting;
use App\Models\Shipment;
use App\Models\SmsLog;
use App\Models\Visit;
use App\Services\Ai\AssistantService;
use App\Services\Ai\ChatOrder;
use App\Support\DateRange;
use App\Support\DeliveryEstimate;
use App\Support\GiftLadder;
use App\Support\TrafficSource;
use App\Support\UpcomingOccasions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The second layer of dashboard analytics: the money the courier is holding,
 * how fast parcels move, where the discounts go, which channel and which
 * product actually earn, who to ring today, and whether the paid tools (SMS,
 * the AI assistant) bring anything back.
 *
 * The owner asked on 2026-09-18 to "add other analytical info on the
 * dashboard". DashboardAnalytics already answers "how much did we sell and
 * where did visitors come from"; every figure here answers a decision that
 * panel leaves open — chase the courier or not, keep a rung of the ladder or
 * not, reorder a piece or clear it, confirm an order by phone before booking.
 *
 * Rules every method keeps:
 *   - plain arrays and scalars only (config/cache.php has
 *     serializable_classes = false, so a cached model or Collection comes
 *     back as __PHP_Incomplete_Class — see remember());
 *   - one grouped query per family of figures, medians and percentiles in
 *     PHP over the fetched rows, so it runs the same on production MySQL and
 *     the in-memory SQLite the tests use;
 *   - an honest empty state expressed in the data: an `n` beside every rate,
 *     and null rather than 0 % where the rows are too few to say anything.
 */
class DashboardInsights
{
    /**
     * Cache key prefix. Separate from DashboardAnalytics' so the two never
     * collide, and versioned so a bad payload written by an older build
     * becomes unreachable after a fix rather than being served.
     */
    protected const CACHE_PREFIX = 'dash.ins.v1.';

    /** The call list is a to-do list; a minute is as stale as it may get. */
    public const CALL_LIST_SECONDS = 60;

    /** Below this many rows a median or a share is a coin toss, not a figure. */
    public const MIN_N = 3;

    /** Rates over outcomes (RTO, recovery, reply) need a few more rows still. */
    public const MIN_OUTCOMES = 5;

    /** A courier-history result older than this no longer vouches for the number. */
    public const STALE_CHECK_DAYS = 90;

    /** An order this old with no consignment is one the owner should ring about. */
    public const CONFIRM_AFTER_HOURS = 2;

    /** "Reorder" badge: stock runs out within this many days at the window's pace. */
    public const REORDER_DAYS = 14;

    /** "Reorder" badge: one sale in a window makes everything urgent, so ask for two. */
    public const REORDER_MIN_UNITS = 2;

    /** "Slow" badge: sell-through since arrival under this, listed for over SLOW_DAYS. */
    public const SLOW_SELL_THROUGH = 50.0;

    public const SLOW_DAYS = 60;

    /**
     * Products sharing one created_at date in at least this number were
     * imported together (the Woo import), so "listed N days ago" would be the
     * import date, not the arrival of the piece.
     */
    public const IMPORT_BATCH = 20;

    /** Order statuses under which a live consignment cannot still be carrying cash. */
    protected const CLOSED_ORDER_STATUSES = ['cancelled', 'returned', 'delivered', 'partially_delivered'];

    /** Raw courier states that mean "registered, not yet picked up" — SteadfastService::describeStatus. */
    protected const JUST_BOOKED_STATES = ['in_review', '', 'unknown'];

    /** KhudeBarta's accepted codes, exactly as SmsService::send() judges them. */
    protected const SMS_ACCEPTED = ['0', '00'];

    /** Risk tags on the confirm-before-booking list, most urgent first. */
    public const RISK_ORDER = ['blacklisted', 'risky', 'warning', 'unchecked', 'first_timer', 'ok'];

    public function __construct(protected DashboardAnalytics $analytics) {}

    // ── Plumbing ────────────────────────────────────────────────────────────

    /**
     * Cache a computed figure — the same contract as
     * DashboardAnalytics::remember(): plain data in, plain data out, and a
     * stored entry that contains an object is discarded and recomputed rather
     * than handed to the caller.
     */
    protected function remember(string $key, \Closure $fn, int $seconds = 300)
    {
        $cacheKey = self::CACHE_PREFIX.$key;
        $miss = new \stdClass;

        $cached = Cache::get($cacheKey, $miss);

        if ($cached !== $miss && self::isPlainData($cached)) {
            return $cached;
        }

        $fresh = $fn();

        if (! self::isPlainData($fresh)) {
            report(new \RuntimeException(
                "DashboardInsights::remember('{$key}') produced objects; not cached. "
                .'Return plain arrays — cache.serializable_classes is false.'
            ));

            return $fresh;
        }

        Cache::put($cacheKey, $fresh, $seconds);

        return $fresh;
    }

    /**
     * How long a window's figures may sit in the cache.
     *
     * DateRange::cacheSeconds() gives a closed window (yesterday, last month,
     * a past custom span) an hour, because the orders in it never change.
     * Some panels, though, mix that window's figures with "right now" ones —
     * what is out with the courier today, what is on the shelf today, the
     * courier wallet — and the 2026-09-18 review found those live figures
     * frozen for an hour whenever the owner looked at a closed window. A
     * panel carrying any live figure therefore caps its TTL at five minutes
     * whatever the window says; a panel that is purely historical keeps the
     * window's own TTL.
     */
    public function ttlFor(DateRange $range, bool $live = false): int
    {
        $seconds = $range->cacheSeconds();

        return $live ? min(300, $seconds) : $seconds;
    }

    /** True when $value is built only from scalars, null and arrays. */
    protected static function isPlainData(mixed $value, int $depth = 0): bool
    {
        if ($depth > 6) {
            return false;
        }
        if (is_object($value)) {
            return false;
        }
        if (is_array($value)) {
            foreach ($value as $item) {
                if (! self::isPlainData($item, $depth + 1)) {
                    return false;
                }
            }
        }

        return true;
    }

    /** Orders that count as real sales — the same rule as DashboardAnalytics::sold(). */
    protected function sold()
    {
        return Order::whereNotIn('orders.status', ['cancelled', 'returned']);
    }

    /** Order lines on sold orders in the window, with the order joined on. */
    protected function soldItems(DateRange $range)
    {
        return $range->constrain(
            OrderItem::query()
                ->join('orders', 'orders.id', '=', 'order_items.order_id')
                ->whereNull('orders.deleted_at')
                ->whereNotIn('orders.status', ['cancelled', 'returned']),
            'orders.created_at',
        );
    }

    /** The cost snapshot on a line, as DashboardAnalytics::profit() prices it. */
    protected const LINE_COST = '(COALESCE(order_items.cost_price, 0) + COALESCE(order_items.transport_cost, 0)) * order_items.quantity';

    /**
     * Whole days in the window, or for "Maximum" the age of the oldest order
     * — the same fallback DashboardAnalytics::operations() uses, so a per-day
     * rate here matches a per-day rate there.
     */
    protected function spanDays(DateRange $range): int
    {
        return $range->days() ?? max(1, (int) Carbon::parse(
            Order::min('created_at') ?: now()
        )->diffInDays(now(), true) + 1);
    }

    /** Share of $part in $whole as a percentage, or null when there is no whole. */
    protected static function share(float|int $part, float|int $whole, int $decimals = 1): ?float
    {
        return $whole > 0 ? round($part / $whole * 100, $decimals) : null;
    }

    /** @param  array<int, float|int>  $values */
    protected static function median(array $values): ?float
    {
        if ($values === []) {
            return null;
        }
        sort($values);
        $n = count($values);
        $mid = intdiv($n, 2);

        return $n % 2 ? (float) $values[$mid] : ($values[$mid - 1] + $values[$mid]) / 2;
    }

    /**
     * Nearest-rank percentile: the value at position ceil(p × n). Over a
     * handful of rows that is the honest choice — interpolating between two
     * parcels would print a delivery time no parcel actually took.
     *
     * @param  array<int, float|int>  $values
     */
    protected static function percentile(array $values, float $p): ?float
    {
        if ($values === []) {
            return null;
        }
        sort($values);
        $rank = max(1, (int) ceil($p * count($values)));

        return (float) $values[$rank - 1];
    }

    /** The database driver, for the few expressions SQL cannot spell portably. */
    protected function driver(): string
    {
        return DB::connection()->getDriverName();
    }

    protected function hasTable(string $table): bool
    {
        try {
            return Schema::hasTable($table);
        } catch (\Throwable $e) {
            return false;
        }
    }

    // ── 1. Cash out with the courier ────────────────────────────────────────

    /**
     * How much of the shop's money is riding in parcels right now, how long
     * each parcel has been out, and what actually came in during the window.
     *
     * Decision: which parcels to ring Steadfast about, and whether the courier
     * is sitting on cash. The population is every CURRENT consignment
     * (superseded_at null) that Shipment::isSettled() calls unsettled, on a
     * non-deleted order — the "approval_pending" exception is honoured because
     * the model decides, not SQL. It is split by shipment status, never by
     * orders.status, because nothing moves booked → shipped automatically and
     * a hand-edited order status must not hide a live consignment.
     *
     * "Stale" consignments sit on orders already delivered, cancelled or
     * returned by other means: the owner should cancel those at Steadfast, as
     * the 2026-09-17 re-booking work warned. "Collected" is money by delivery
     * date (the first 'delivered' history row), not by order date — those
     * rows exist since Aug 2026.
     *
     * The courier wallet is READ from the cache entry SteadfastService::
     * balance() keeps warm for the orders page ('steadfast.balance', five
     * minutes), never fetched here: the 2026-09-18 review found the dashboard
     * render blocked for up to the client's 30-second timeout whenever
     * Steadfast was slow, because the call sat inside this cached closure.
     * balance is null when nothing is cached (the orders page has not been
     * opened lately, Steadfast is not connected, or its last answer was the
     * outage sentinel); balance_available says whether Steadfast is
     * configured at all, so the view can caption a null as "unavailable right
     * now" rather than "not connected" when the keys are in place.
     *
     * @return array{
     *   live: array{count:int, amount:float,
     *     stages: array{booked_waiting: array{count:int, amount:float}, out_for_delivery: array{count:int, amount:float}},
     *     ages: array{le3: array{count:int, amount:float}, d4_7: array{count:int, amount:float}, over7: array{count:int, amount:float}}},
     *   stale: array{count:int, amount:float},
     *   rebooked: array{count:int, delivered_after_replacement:int},
     *   balance: ?float,
     *   balance_available: bool,
     *   collected: array{count:int, amount:float}
     * }
     */
    public function cashAtCourier(DateRange $range): array
    {
        return $this->remember('cash.'.$range->cacheKey(), function () use ($range) {
            // The SQL only drops rows isSettled() would certainly call
            // settled; the PHP call is the one that decides, so the two can
            // never disagree on an approval_pending parcel.
            $open = Shipment::query()
                ->join('orders', 'orders.id', '=', 'shipments.order_id')
                ->whereNull('shipments.superseded_at')
                ->whereNull('orders.deleted_at')
                ->where(function ($q) {
                    $q->whereNull('shipments.status')
                        ->orWhereRaw("LOWER(shipments.status) LIKE '%approval_pending'")
                        ->orWhere(function ($q) {
                            $q->whereRaw("LOWER(shipments.status) NOT LIKE '%deliver%'")
                                ->whereRaw("LOWER(shipments.status) NOT LIKE '%cancel%'")
                                ->whereRaw("LOWER(shipments.status) NOT LIKE '%return%'");
                        });
                })
                ->get([
                    'shipments.id', 'shipments.status', 'shipments.cod_amount', 'shipments.created_at',
                    'orders.status as order_status',
                ])
                ->reject(fn (Shipment $s) => $s->isSettled());

            $live = $open->reject(fn (Shipment $s) => in_array($s->order_status, self::CLOSED_ORDER_STATUSES, true));
            $stale = $open->filter(fn (Shipment $s) => in_array($s->order_status, self::CLOSED_ORDER_STATUSES, true));

            $sum = fn ($rows) => [
                'count' => $rows->count(),
                'amount' => round((float) $rows->sum(fn (Shipment $s) => (float) $s->cod_amount), 2),
            ];

            $stage = fn (Shipment $s) => in_array(strtolower((string) $s->status), self::JUST_BOOKED_STATES, true)
                ? 'booked_waiting'
                : 'out_for_delivery';

            $age = function (Shipment $s) {
                $days = $s->created_at ? (int) floor($s->created_at->diffInDays(now(), true)) : 0;

                return match (true) {
                    $days <= 3 => 'le3',
                    $days <= 7 => 'd4_7',
                    default => 'over7',
                };
            };

            $stages = [];
            foreach (['booked_waiting', 'out_for_delivery'] as $key) {
                $stages[$key] = $sum($live->filter(fn ($s) => $stage($s) === $key));
            }

            $ages = [];
            foreach (['le3', 'd4_7', 'over7'] as $key) {
                $ages[$key] = $sum($live->filter(fn ($s) => $age($s) === $key));
            }

            // Re-bookings in the window, and how many old parcels were still
            // delivered after being replaced — each of those left a newer,
            // unused consignment the owner has to cancel at Steadfast.
            $rebooked = (int) $range->constrain(Shipment::whereNotNull('superseded_at'), 'superseded_at')->count();
            $deliveredAfter = (int) $range->constrain(
                Shipment::whereNotNull('delivered_after_superseded_at'), 'delivered_after_superseded_at',
            )->count();

            $collected = $this->deliveredInWindow($range)
                ->selectRaw('COUNT(*) as n, COALESCE(SUM(orders.total), 0) as amount')
                ->first();

            // The same key SteadfastService::balance() writes. It stores
            // `false` as an outage sentinel, and is_numeric(false) is false,
            // so an outage reads as "no balance" here too.
            $rawBalance = Cache::get('steadfast.balance');

            return [
                'live' => $sum($live) + ['stages' => $stages, 'ages' => $ages],
                'stale' => $sum($stale),
                'rebooked' => ['count' => $rebooked, 'delivered_after_replacement' => $deliveredAfter],
                'balance' => is_numeric($rawBalance) ? (float) $rawBalance : null,
                'balance_available' => app(SteadfastService::class)->isConfigured(),
                'collected' => [
                    'count' => (int) ($collected->n ?? 0),
                    'amount' => round((float) ($collected->amount ?? 0), 2),
                ],
            ];
        }, $this->ttlFor($range, live: true));
    }

    /**
     * Delivered, non-deleted orders whose FIRST 'delivered' history row falls
     * in the window, with that moment joined on as first_delivery.delivered_at.
     */
    protected function deliveredInWindow(DateRange $range)
    {
        $first = DB::table('order_status_history')
            ->selectRaw('order_id, MIN(created_at) as delivered_at')
            ->where('status', 'delivered')
            ->groupBy('order_id');

        return $range->constrain(
            Order::query()
                ->where('orders.status', 'delivered')
                ->joinSub($first, 'first_delivery', 'first_delivery.order_id', '=', 'orders.id'),
            'first_delivery.delivered_at',
        );
    }

    // ── 2. Delivery speed ───────────────────────────────────────────────────

    /**
     * Whether slow deliveries are the shop's doing (late booking) or the
     * courier's (long transit), by zone, and whether the promise on the
     * product page is being kept.
     *
     * Population: delivered orders by delivery date (first 'delivered'
     * history row in the window). Two booking moments are read, because a
     * re-booked order has two consignments and the 2026-09-18 review found
     * the shop's own delay between them being charged to the courier:
     *   - shop → courier runs from placing to the order's EARLIEST shipment
     *     row (the first API call), falling back to a 'booked' history row
     *     only because the 2026-08-12 backfill wrote no history and before
     *     then booking wrote 'shipped' directly;
     *   - courier → door runs from the CURRENT consignment (superseded_at
     *     null) — the parcel that was actually carried to the door — and
     *     falls back to the first booking when no current row exists.
     * Orders with no booking time at all (delivered by hand) or delivered
     * before the carried consignment was booked are dropped.
     *
     * The promise is DeliveryEstimate::for(zone, placed_at) when the estimate
     * is shown to customers, else the theme's plain calendar days; a delivery
     * on the promised day counts as on time. Medians and p90 are nearest-rank
     * over the fetched pairs, and a zone with fewer than MIN_N deliveries
     * reports null rather than a median of two.
     *
     * Caveat for the view: 'delivered' is stamped when the courier reported
     * it, not when the rider handed over, so a quiet webhook makes transit
     * look longer than it was.
     *
     * @return array{
     *   n: int,
     *   shop_to_courier: array{median_hours: ?float, n: int},
     *   courier_to_door: array{
     *     overall: array{median_days: ?float, p90_days: ?float, n: int},
     *     inside: array{median_days: ?float, p90_days: ?float, n: int},
     *     outside: array{median_days: ?float, p90_days: ?float, n: int}},
     *   on_time: array{pct: ?float, n: int},
     *   distribution: array{le1: int, d2: int, d3: int, d4_6: int, over7: int},
     *   zones: array{
     *     inside: array{orders: int, revenue: float, aov: ?float, delivered: int, resolved: int, delivered_pct: ?float},
     *     outside: array{orders: int, revenue: float, aov: ?float, delivered: int, resolved: int, delivered_pct: ?float}},
     *   rto: array{pct: ?float, settled_n: int, came_back: int}
     * }
     */
    public function deliverySpeed(DateRange $range): array
    {
        return $this->remember('speed.'.$range->cacheKey(), function () use ($range) {
            $firstShipment = DB::table('shipments')
                ->selectRaw('order_id, MIN(created_at) as booked_at')
                ->groupBy('order_id');
            // MAX rather than a bare column: one current row per order is
            // the rule, but MySQL strict mode wants an aggregate under the
            // GROUP BY either way, and MAX is the right answer if the rule
            // is ever broken.
            $currentShipment = DB::table('shipments')
                ->selectRaw('order_id, MAX(created_at) as booked_at')
                ->whereNull('superseded_at')
                ->groupBy('order_id');
            $firstBooked = DB::table('order_status_history')
                ->selectRaw('order_id, MIN(created_at) as booked_at')
                ->where('status', 'booked')
                ->groupBy('order_id');

            $rows = $this->deliveredInWindow($range)
                ->leftJoinSub($firstShipment, 'first_shipment', 'first_shipment.order_id', '=', 'orders.id')
                ->leftJoinSub($currentShipment, 'current_shipment', 'current_shipment.order_id', '=', 'orders.id')
                ->leftJoinSub($firstBooked, 'first_booked', 'first_booked.order_id', '=', 'orders.id')
                ->toBase()
                ->get([
                    'orders.id', 'orders.created_at', 'orders.is_inside_dhaka',
                    'first_delivery.delivered_at',
                    'first_shipment.booked_at as shipped_at',
                    'current_shipment.booked_at as current_shipped_at',
                    'first_booked.booked_at as booked_hist_at',
                ]);

            $shopHours = [];
            $transit = ['overall' => [], 'inside' => [], 'outside' => []];
            $distribution = ['le1' => 0, 'd2' => 0, 'd3' => 0, 'd4_6' => 0, 'over7' => 0];
            $onTime = 0;
            $n = 0;

            foreach ($rows as $r) {
                $firstRaw = $r->shipped_at ?? $r->booked_hist_at;
                if (! $firstRaw) {
                    continue;
                }

                $placed = Carbon::parse($r->created_at);
                $booked = Carbon::parse($firstRaw);
                $carried = Carbon::parse($r->current_shipped_at ?? $firstRaw);
                $delivered = Carbon::parse($r->delivered_at);

                if ($delivered->lt($carried)) {
                    continue;
                }

                $n++;
                $inside = (bool) $r->is_inside_dhaka;

                $shopHours[] = round($placed->diffInSeconds($booked, true) / 3600, 2);

                $days = $carried->diffInSeconds($delivered, true) / 86400;
                $transit['overall'][] = $days;
                $transit[$inside ? 'inside' : 'outside'][] = $days;

                $whole = max(1, (int) ceil($days));
                $distribution[match (true) {
                    $whole <= 1 => 'le1',
                    $whole === 2 => 'd2',
                    $whole === 3 => 'd3',
                    $whole <= 6 => 'd4_6',
                    default => 'over7',
                }]++;

                if ($delivered->lte($this->promisedBy($inside, $placed))) {
                    $onTime++;
                }
            }

            $stats = fn (array $values) => [
                'median_days' => count($values) >= self::MIN_N ? round(self::median($values), 1) : null,
                'p90_days' => count($values) >= self::MIN_N ? round(self::percentile($values, 0.9), 1) : null,
                'n' => count($values),
            ];

            return [
                'n' => $n,
                'shop_to_courier' => [
                    'median_hours' => count($shopHours) >= self::MIN_N ? round(self::median($shopHours), 1) : null,
                    'n' => count($shopHours),
                ],
                'courier_to_door' => [
                    'overall' => $stats($transit['overall']),
                    'inside' => $stats($transit['inside']),
                    'outside' => $stats($transit['outside']),
                ],
                'on_time' => ['pct' => $n >= self::MIN_N ? self::share($onTime, $n) : null, 'n' => $n],
                'distribution' => $distribution,
                'zones' => $this->zones($range),
                'rto' => $this->rto($range),
            ];
        }, $range->cacheSeconds());
    }

    /**
     * The last moment a delivery still counts as on time: the end (in Dhaka)
     * of the day the customer was promised.
     */
    protected function promisedBy(bool $inside, Carbon $placed): Carbon
    {
        $estimate = DeliveryEstimate::for($inside, $placed);

        if ($estimate) {
            return ($estimate->to ?? $estimate->from)->copy()->endOfDay();
        }

        $days = (int) ($inside ? theme('delivery_days_inside_max', 2) : theme('delivery_days_max', 4));

        return store_time($placed)->addDays(max(0, $days))->endOfDay();
    }

    /**
     * Inside vs outside Dhaka on orders, revenue and delivery success, by
     * order date. district is null on nearly every order, so is_inside_dhaka
     * is the only zone the data can stand behind.
     *
     * @return array<string, array{orders:int, revenue:float, aov:?float, delivered:int, resolved:int, delivered_pct:?float}>
     */
    protected function zones(DateRange $range): array
    {
        $sales = $range->constrain($this->sold())
            ->selectRaw('is_inside_dhaka, COUNT(*) as orders, COALESCE(SUM(total), 0) as revenue')
            ->groupBy('is_inside_dhaka')->get()
            ->keyBy(fn ($r) => (int) (bool) $r->is_inside_dhaka);

        $outcomes = $range->constrain(Order::query())
            ->whereIn('status', ['delivered', 'cancelled', 'returned'])
            ->selectRaw('is_inside_dhaka, status, COUNT(*) as n')
            ->groupBy('is_inside_dhaka', 'status')->get()
            ->groupBy(fn ($r) => (int) (bool) $r->is_inside_dhaka);

        $out = [];
        foreach (['inside' => 1, 'outside' => 0] as $key => $flag) {
            $row = $sales[$flag] ?? null;
            $orders = (int) ($row->orders ?? 0);
            $revenue = round((float) ($row->revenue ?? 0), 2);

            $counts = collect($outcomes[$flag] ?? [])->pluck('n', 'status');
            $delivered = (int) ($counts['delivered'] ?? 0);
            $resolved = $delivered + (int) ($counts['cancelled'] ?? 0) + (int) ($counts['returned'] ?? 0);

            $out[$key] = [
                'orders' => $orders,
                'revenue' => $revenue,
                'aov' => $orders > 0 ? round($revenue / $orders) : null,
                'delivered' => $delivered,
                'resolved' => $resolved,
                'delivered_pct' => $resolved >= self::MIN_N ? self::share($delivered, $resolved) : null,
            ];
        }

        return $out;
    }

    /**
     * Return-to-origin share: current consignments booked in the window that
     * the courier settled as cancelled or returned, over all it settled.
     *
     * @return array{pct:?float, settled_n:int, came_back:int}
     */
    protected function rto(DateRange $range): array
    {
        $settled = $range->constrain(Shipment::whereNull('superseded_at'), 'created_at')
            ->get(['id', 'status'])
            ->filter(fn (Shipment $s) => $s->isSettled());

        $cameBack = $settled->filter(function (Shipment $s) {
            $status = strtolower((string) $s->status);

            return str_contains($status, 'cancel') || str_contains($status, 'return');
        })->count();

        return [
            'pct' => $settled->count() >= self::MIN_OUTCOMES ? self::share($cameBack, $settled->count()) : null,
            'settled_n' => $settled->count(),
            'came_back' => $cameBack,
        ];
    }

    // ── 3. Where the discounts go ───────────────────────────────────────────

    /**
     * The single "discounts" figure on Revenue & profit, taken apart by who
     * gave it away — ladder, member pricing, points, coupons and offers, free
     * delivery — as a share of revenue, plus whether ladder orders are the
     * bigger baskets the ladder was meant to buy.
     *
     * Decision: which incentive to keep. orders.discount is the capped total
     * CartService::cascade() stores (gift + offer + promo + member + customer
     * + coupon + points), so coupons & offers is the residual after the
     * three exactly-stored parts are taken off. Free delivery is not inside
     * orders.discount (the ladder's free_delivery rung writes amount 0), so
     * valuing it at the zone's shipping setting is additive, not
     * double-counted. ladder_rewards is decoded in PHP — no JSON_TABLE, to
     * keep SQLite happy. Points issued are written on delivery
     * (LoyaltyService::awardForOrder), so the view captions them "on
     * delivered orders" and they stay out of the given-away total.
     *
     * The window's orders are read as plain rows (toBase), not hydrated
     * models: "Maximum" loads every order the shop has ever taken, and the
     * 2026-09-18 review measured the Eloquent casts (decimal, datetime, the
     * array cast on ladder_rewards) as the bulk of that panel's time. Only
     * the columns used are selected and the JSON column is decoded by hand.
     *
     * @return array{
     *   revenue: float, orders: int,
     *   rows: array<string, array{label:string, amount:float, pct_of_revenue:?float, orders:int}>,
     *   given_away: array{amount:float, pct:?float},
     *   points_issued: array{points:int, value:float},
     *   ladder_rungs: array<int, array{n:int, label:string, count:int}>,
     *   ladder_payoff: array{
     *     with: array{aov:?float, items_per_order:?float, n:int},
     *     without: array{aov:?float, items_per_order:?float, n:int}},
     *   coupons: array{
     *     codes: array<int, array{code:string, uses:int, revenue:float, discount_on_orders:float}>,
     *     active_unused_count: int}
     * }
     */
    public function discountLeakage(DateRange $range): array
    {
        return $this->remember('leak.'.$range->cacheKey(), function () use ($range) {
            // toBase() after the Eloquent builder is built, so the model's
            // SoftDeletes scope (deleted_at IS NULL) is still applied.
            $orders = $range->constrain($this->sold())->toBase()->get([
                'id', 'subtotal', 'total', 'discount', 'member_discount', 'points_discount', 'points_redeemed',
                'points_earned', 'ladder_tier', 'ladder_rewards', 'coupon_code', 'shipping_cost',
                'is_inside_dhaka', 'source', 'source_channel',
            ]);

            // Pieces per order, for the ladder pay-off line — one grouped
            // query rather than an id list, so "Maximum" stays cheap.
            $pieces = $this->soldItems($range)
                ->selectRaw('order_items.order_id as order_id, SUM(order_items.quantity) as qty')
                ->groupBy('order_items.order_id')
                ->pluck('qty', 'order_id');

            $shipInside = (float) Setting::get('shipping_inside', config('store.shipping.inside_dhaka'));
            $shipOutside = (float) Setting::get('shipping_outside', config('store.shipping.outside_dhaka'));

            $revenue = 0.0;
            $discount = 0.0;
            $ladder = ['amount' => 0.0, 'orders' => 0];
            $member = ['amount' => 0.0, 'orders' => 0];
            $points = ['amount' => 0.0, 'orders' => 0];
            $coupons = ['orders' => 0];
            $free = ['amount' => 0.0, 'orders' => 0];
            $pointsIssued = 0;
            $rungCounts = [];
            $payoff = ['with' => ['revenue' => 0.0, 'items' => 0, 'n' => 0], 'without' => ['revenue' => 0.0, 'items' => 0, 'n' => 0]];
            $codes = [];

            foreach ($orders as $o) {
                $revenue += (float) $o->subtotal;
                $discount += (float) $o->discount;
                $pointsIssued += (int) $o->points_earned;

                // The array cast is not there on a base row: decode the JSON
                // column by hand, and treat anything unreadable as no rewards.
                $rewards = is_string($o->ladder_rewards) ? json_decode($o->ladder_rewards, true) : $o->ladder_rewards;
                $rewards = is_array($rewards) ? $rewards : [];
                $ladderAmount = 0.0;
                foreach ($rewards as $reward) {
                    $ladderAmount += (float) ($reward['amount'] ?? 0);
                }
                $ladder['amount'] += $ladderAmount;
                if ((int) $o->ladder_tier > 0) {
                    $ladder['orders']++;
                }
                $rungCounts[(int) $o->ladder_tier] = ($rungCounts[(int) $o->ladder_tier] ?? 0) + 1;

                $member['amount'] += (float) $o->member_discount;
                if ((float) $o->member_discount > 0) {
                    $member['orders']++;
                }

                $points['amount'] += (float) $o->points_discount;
                if ((int) $o->points_redeemed > 0) {
                    $points['orders']++;
                }

                $code = trim((string) $o->coupon_code);
                if ($code !== '') {
                    $coupons['orders']++;
                    $key = strtoupper($code);
                    $codes[$key] ??= ['code' => $code, 'uses' => 0, 'revenue' => 0.0, 'discount_on_orders' => 0.0];
                    $codes[$key]['uses']++;
                    $codes[$key]['revenue'] += (float) $o->total;
                    $codes[$key]['discount_on_orders'] += (float) $o->discount;
                }

                if ((float) $o->shipping_cost == 0 && (float) $o->subtotal > 0) {
                    $free['amount'] += $o->is_inside_dhaka ? $shipInside : $shipOutside;
                    $free['orders']++;
                }

                // Only the web checkout climbs the ladder: staff-entered and
                // chat orders would sit in "without" and skew the baseline.
                if ($o->source_channel !== 'admin' && $o->source !== 'chat') {
                    $side = (int) $o->ladder_tier > 0 ? 'with' : 'without';
                    $payoff[$side]['revenue'] += (float) $o->total;
                    $payoff[$side]['items'] += (int) ($pieces[$o->id] ?? 0);
                    $payoff[$side]['n']++;
                }
            }

            $residual = max(0.0, $discount - $ladder['amount'] - $member['amount'] - $points['amount']);
            $givenAway = $discount + $free['amount'];

            $row = fn (string $label, float $amount, int $touched) => [
                'label' => $label,
                'amount' => round($amount, 2),
                'pct_of_revenue' => self::share($amount, $revenue),
                'orders' => $touched,
            ];

            // Every rung the ladder has today, plus any rung an old order
            // reached under an earlier ladder, so no order is left uncounted.
            $tiers = collect(app(GiftLadder::class)->tiers())->keyBy('n');
            $rungs = [['n' => 0, 'label' => 'No ladder', 'count' => (int) ($rungCounts[0] ?? 0)]];
            $seen = collect(array_keys($rungCounts))->merge($tiers->keys())->unique()->filter(fn ($n) => $n > 0)->sort()->values();
            foreach ($seen as $n) {
                $rungs[] = [
                    'n' => (int) $n,
                    'label' => (string) ($tiers[$n]['label'] ?? 'Rung '.$n),
                    'count' => (int) ($rungCounts[$n] ?? 0),
                ];
            }

            $side = fn (array $s) => [
                'aov' => $s['n'] > 0 ? round($s['revenue'] / $s['n']) : null,
                'items_per_order' => $s['n'] > 0 ? round($s['items'] / $s['n'], 1) : null,
                'n' => $s['n'],
            ];

            $active = Coupon::query()
                ->where('is_active', true)
                ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
                ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>=', now()))
                ->pluck('code')
                ->map(fn ($c) => strtoupper(trim((string) $c)));

            return [
                'revenue' => round($revenue, 2),
                'orders' => $orders->count(),
                'rows' => [
                    'ladder' => $row('Reward ladder', $ladder['amount'], $ladder['orders']),
                    'member' => $row('Member pricing', $member['amount'], $member['orders']),
                    'points' => $row('Points redeemed', $points['amount'], $points['orders']),
                    'coupons_offers' => $row('Coupons & offers', $residual, $coupons['orders']),
                    'free_delivery' => $row('Free delivery', $free['amount'], $free['orders']),
                ],
                'given_away' => ['amount' => round($givenAway, 2), 'pct' => self::share($givenAway, $revenue)],
                'points_issued' => [
                    'points' => $pointsIssued,
                    'value' => app(LoyaltyService::class)->pointsValue($pointsIssued),
                ],
                'ladder_rungs' => $rungs,
                'ladder_payoff' => ['with' => $side($payoff['with']), 'without' => $side($payoff['without'])],
                'coupons' => [
                    'codes' => collect($codes)
                        ->map(fn ($c) => ['code' => $c['code'], 'uses' => $c['uses'], 'revenue' => round($c['revenue'], 2), 'discount_on_orders' => round($c['discount_on_orders'], 2)])
                        ->sortByDesc('revenue')->values()->all(),
                    'active_unused_count' => $active->reject(fn ($code) => isset($codes[$code]))->count(),
                ],
            ];
        }, $range->cacheSeconds());
    }

    // ── 4. Channel economics ────────────────────────────────────────────────

    /**
     * What each traffic channel's orders are worth AFTER the order: profit,
     * delivery success and how many of its buyers are new to the shop.
     *
     * Decision: where the next ad taka goes. A channel can win on order count
     * and lose on cash-on-delivery refusals and margin; nothing in "Where
     * visitors come from" shows that. Rows are keyed by the same channel key
     * DashboardAnalytics::trafficSources() uses — COALESCE(NULLIF(
     * source_channel, ''), 'direct') — so the view can lay these beside its
     * rows. First-time buyers: the order's customer_phone has no earlier
     * non-cancelled order (its MIN(created_at) is this order). No spend or
     * ROAS: ad spend is stored nowhere in the codebase.
     *
     * @return array<string, array{
     *   channel:string, label:string, orders:int, revenue:float, aov:?float,
     *   profit:float, profit_per_order:?float, margin:?float,
     *   delivered:int, cancelled:int, returned:int, resolved:int, delivered_pct:?float,
     *   first_time_pct:?float, first_time_n:int
     * }> keyed by channel, sorted by profit desc
     */
    public function channelEconomics(DateRange $range): array
    {
        return $this->remember('chan.'.$range->cacheKey(), function () use ($range) {
            $channel = "COALESCE(NULLIF(orders.source_channel, ''), 'direct')";

            $firsts = Order::query()
                ->where('status', '!=', 'cancelled')
                ->selectRaw('customer_phone, MIN(created_at) as first_at')
                ->groupBy('customer_phone');

            $sales = $range->constrain($this->sold(), 'orders.created_at')
                ->leftJoinSub($firsts, 'first_orders', 'first_orders.customer_phone', '=', 'orders.customer_phone')
                ->selectRaw(
                    "{$channel} as channel, COUNT(*) as orders, COALESCE(SUM(orders.total), 0) as revenue, "
                    .'SUM(CASE WHEN first_orders.first_at IS NOT NULL AND orders.created_at = first_orders.first_at THEN 1 ELSE 0 END) as first_time'
                )
                ->groupBy('channel')->get()->keyBy('channel');

            $profit = $this->soldItems($range)
                ->selectRaw("{$channel} as channel, COALESCE(SUM(order_items.subtotal), 0) as revenue, COALESCE(SUM(".self::LINE_COST.'), 0) as cost')
                ->groupBy('channel')->get()->keyBy('channel');

            $outcomes = $range->constrain(Order::query(), 'orders.created_at')
                ->whereIn('orders.status', ['delivered', 'cancelled', 'returned'])
                ->selectRaw("{$channel} as channel, orders.status as status, COUNT(*) as n")
                ->groupBy('channel', 'orders.status')->get()
                ->groupBy('channel');

            $rows = [];
            $keys = $sales->keys()->merge($profit->keys())->merge($outcomes->keys())->unique();

            foreach ($keys as $key) {
                $s = $sales[$key] ?? null;
                $p = $profit[$key] ?? null;
                $counts = collect($outcomes[$key] ?? [])->pluck('n', 'status');

                $orders = (int) ($s->orders ?? 0);
                $revenue = round((float) ($s->revenue ?? 0), 2);
                $lineRevenue = (float) ($p->revenue ?? 0);
                $gross = round($lineRevenue - (float) ($p->cost ?? 0), 2);
                $firstTime = (int) ($s->first_time ?? 0);

                $delivered = (int) ($counts['delivered'] ?? 0);
                $cancelled = (int) ($counts['cancelled'] ?? 0);
                $returned = (int) ($counts['returned'] ?? 0);
                $resolved = $delivered + $cancelled + $returned;

                $rows[(string) $key] = [
                    'channel' => (string) $key,
                    'label' => TrafficSource::label((string) $key),
                    'orders' => $orders,
                    'revenue' => $revenue,
                    'aov' => $orders > 0 ? round($revenue / $orders) : null,
                    'profit' => $gross,
                    'profit_per_order' => $orders > 0 ? round($gross / $orders) : null,
                    'margin' => $lineRevenue > 0 ? round($gross / $lineRevenue * 100, 1) : null,
                    'delivered' => $delivered,
                    'cancelled' => $cancelled,
                    'returned' => $returned,
                    'resolved' => $resolved,
                    'delivered_pct' => $resolved >= self::MIN_N ? self::share($delivered, $resolved) : null,
                    'first_time_pct' => $orders > 0 ? self::share($firstTime, $orders) : null,
                    'first_time_n' => $firstTime,
                ];
            }

            uasort($rows, fn ($a, $b) => [$b['profit'], $b['revenue']] <=> [$a['profit'], $a['revenue']]);

            return $rows;
        }, $range->cacheSeconds());
    }

    // ── 5. Earners & reorder ────────────────────────────────────────────────

    /**
     * Products ranked by the profit they made in the window, with what is
     * left on the shelf and how fast it is going — one list for the
     * reorder-or-stop decision that Top products (units), Running out soon
     * (days) and Dead stock (names) each answer a third of.
     *
     * Sales, profit and days-left are windowed; stock, sell-through and
     * arrival age are global. Days left reuses operations()' per-day maths.
     * Sell-through since arrival is all-time units ÷ (all-time units +
     * stock). listed_days is null for products that share a created_at date
     * with IMPORT_BATCH others — the Woo import stamped one date on hundreds
     * of pieces, and "listed 90 days ago" would be a lie for those. Variant
     * stock-outs make a piece that "has stock" but has lost its popular size
     * visible. cost_missing means a blank cost is inflating the margin, and
     * the overall count lets the view say so. `deleted` is true for a product
     * that is soft-deleted or gone altogether — it still earned what it
     * earned, but the 2026-09-18 review found the view linking its name to a
     * 404, so the row says when there is no product page behind it.
     *
     * @return array{
     *   rows: array<int, array{
     *     id:int, slug:string, name:string, deleted:bool, units:int, revenue:float, profit:float, margin:?float,
     *     cost_missing:bool, stock_left:?int, days_left:?int, sell_through_pct:?float, listed_days:?int,
     *     variants:?array{out:int, active:int}, badges:array{reorder:bool, slow:bool}}>,
     *   overall: array{margin:?float, cost_missing_items:int, products:int}
     * }
     */
    public function topEarners(DateRange $range, int $limit = 12): array
    {
        return $this->remember('earn.'.$range->cacheKey().'.'.$limit, function () use ($range, $limit) {
            $sold = $this->soldItems($range)
                ->whereNotNull('order_items.product_id')
                ->selectRaw(
                    'order_items.product_id as product_id, SUM(order_items.quantity) as units, '
                    .'COALESCE(SUM(order_items.subtotal), 0) as revenue, COALESCE(SUM('.self::LINE_COST.'), 0) as cost, '
                    .'SUM(CASE WHEN order_items.cost_price IS NULL THEN 1 ELSE 0 END) as cost_missing'
                )
                ->groupBy('order_items.product_id')->get()
                ->map(fn ($r) => [
                    'product_id' => (int) $r->product_id,
                    'units' => (int) $r->units,
                    'revenue' => round((float) $r->revenue, 2),
                    'profit' => round((float) $r->revenue - (float) $r->cost, 2),
                    'cost_missing' => (int) $r->cost_missing,
                ]);

            $overall = $this->analytics->profit($range);
            $costMissingItems = (int) $sold->sum('cost_missing');

            $top = $sold->sortByDesc(fn ($r) => [$r['profit'], $r['revenue']])->take($limit)->values();
            $ids = $top->pluck('product_id')->all();

            if ($ids === []) {
                return ['rows' => [], 'overall' => ['margin' => $overall['margin'], 'cost_missing_items' => $costMissingItems, 'products' => 0]];
            }

            // A deleted product still earned what it earned; it just has no shelf.
            $products = Product::withTrashed()->whereIn('id', $ids)
                ->get(['id', 'slug', 'name', 'manage_stock', 'stock_quantity', 'has_variants', 'created_at', 'deleted_at'])
                ->keyBy('id');

            $allTime = OrderItem::query()
                ->join('orders', 'orders.id', '=', 'order_items.order_id')
                ->whereNull('orders.deleted_at')
                ->whereNotIn('orders.status', ['cancelled', 'returned'])
                ->whereIn('order_items.product_id', $ids)
                ->selectRaw('order_items.product_id as product_id, SUM(order_items.quantity) as qty')
                ->groupBy('order_items.product_id')->pluck('qty', 'product_id');

            // `out_n`, not `out`: OUT is a reserved word on MySQL, and the
            // SQLite test suite would never notice.
            $variants = ProductVariant::query()
                ->whereIn('product_id', $ids)->where('is_active', true)
                ->selectRaw('product_id, COUNT(*) as active_n, SUM(CASE WHEN stock_quantity <= 0 THEN 1 ELSE 0 END) as out_n')
                ->groupBy('product_id')->get()->keyBy('product_id');

            $batchDates = $this->importBatchDates(
                $products->pluck('created_at')->filter()->map(fn ($d) => $d->toDateString())->unique()->values()->all()
            );

            $spanDays = $this->spanDays($range);
            $overallMargin = $overall['margin'];

            $rows = $top->map(function ($r) use ($products, $allTime, $variants, $batchDates, $spanDays, $overallMargin) {
                $p = $products->get($r['product_id']);
                $stocked = $p && $p->manage_stock && $p->deleted_at === null;
                $stock = $stocked ? (int) $p->stock_quantity : null;

                $perDay = $r['units'] / max(1, $spanDays);
                $daysLeft = $stock !== null && $perDay > 0 ? (int) floor($stock / $perDay) : null;

                $everSold = (int) ($allTime[$r['product_id']] ?? $r['units']);
                $sellThrough = $stock !== null && ($everSold + $stock) > 0
                    ? round($everSold / ($everSold + $stock) * 100, 1)
                    : null;

                $listedDays = $p && $p->created_at && ! in_array($p->created_at->toDateString(), $batchDates, true)
                    ? (int) floor($p->created_at->diffInDays(now(), true))
                    : null;

                $margin = $r['revenue'] > 0 ? round($r['profit'] / $r['revenue'] * 100, 1) : null;
                $v = $p && $p->has_variants ? $variants->get($r['product_id']) : null;

                return [
                    'id' => $r['product_id'],
                    'slug' => (string) ($p->slug ?? ''),
                    'name' => (string) ($p->name ?? 'Deleted product'),
                    'deleted' => $p === null || $p->deleted_at !== null,
                    'units' => $r['units'],
                    'revenue' => $r['revenue'],
                    'profit' => $r['profit'],
                    'margin' => $margin,
                    'cost_missing' => $r['cost_missing'] > 0,
                    'stock_left' => $stock,
                    'days_left' => $daysLeft,
                    'sell_through_pct' => $sellThrough,
                    'listed_days' => $listedDays,
                    'variants' => $p && $p->has_variants
                        ? ['out' => (int) ($v->out_n ?? 0), 'active' => (int) ($v->active_n ?? 0)]
                        : null,
                    'badges' => [
                        'reorder' => $daysLeft !== null && $daysLeft <= self::REORDER_DAYS
                            && $r['units'] >= self::REORDER_MIN_UNITS
                            && $margin !== null && $overallMargin !== null && $margin >= $overallMargin,
                        'slow' => $sellThrough !== null && $sellThrough < self::SLOW_SELL_THROUGH
                            && $listedDays !== null && $listedDays > self::SLOW_DAYS,
                    ],
                ];
            })->values()->all();

            return [
                'rows' => $rows,
                'overall' => [
                    'margin' => $overallMargin,
                    'cost_missing_items' => $costMissingItems,
                    'products' => $sold->count(),
                ],
            ];
        }, $this->ttlFor($range, live: true));   // stock_left and days_left are today's shelf
    }

    /**
     * Of the given calendar dates, those on which IMPORT_BATCH or more
     * products were created — an import, not an arrival.
     *
     * @param  array<int, string>  $dates  Y-m-d
     * @return array<int, string>
     */
    protected function importBatchDates(array $dates): array
    {
        if ($dates === []) {
            return [];
        }

        return Product::withTrashed()
            ->selectRaw('DATE(created_at) as d, COUNT(*) as c')
            ->whereIn(DB::raw('DATE(created_at)'), $dates)
            ->groupBy('d')
            ->having('c', '>=', self::IMPORT_BATCH)
            ->pluck('c', 'd')
            ->keys()->map(fn ($d) => (string) $d)->all();
    }

    /**
     * The same profit ranking by category, with the cash tied up on the shelf
     * in each category beside what it earned — "earning ৳X, ৳Y on the shelf"
     * on one line. Line revenue is before order-level discounts, the same
     * basis as Revenue & profit. Products with no category, or lines whose
     * product is gone, sit under "Uncategorised". Sorted by profit, largest
     * first.
     *
     * @return array<int, array{id:?int, name:string, units:int, revenue:float, profit:float, margin:?float, stock_at_cost:float}>
     */
    public function earnersByCategory(DateRange $range): array
    {
        return $this->remember('earncat.'.$range->cacheKey(), function () use ($range) {
            $sold = $this->soldItems($range)
                ->leftJoin('products', 'products.id', '=', 'order_items.product_id')
                ->leftJoin('categories', 'categories.id', '=', 'products.category_id')
                ->selectRaw(
                    'categories.id as category_id, categories.name as category_name, SUM(order_items.quantity) as units, '
                    .'COALESCE(SUM(order_items.subtotal), 0) as revenue, COALESCE(SUM('.self::LINE_COST.'), 0) as cost'
                )
                ->groupBy('categories.id', 'categories.name')->get();

            $stock = [];
            $shelf = Product::query()
                ->where('manage_stock', true)->where('stock_quantity', '>', 0)
                ->selectRaw('category_id, COALESCE(SUM(stock_quantity * (COALESCE(cost_price, 0) + COALESCE(transport_cost, 0))), 0) as amount')
                ->groupBy('category_id')->get();
            foreach ($shelf as $r) {
                $stock[(int) ($r->category_id ?? 0)] = round((float) $r->amount, 2);
            }

            return $sold->map(function ($r) use ($stock) {
                $revenue = (float) $r->revenue;
                $profit = round($revenue - (float) $r->cost, 2);
                $id = $r->category_id !== null ? (int) $r->category_id : null;

                return [
                    'id' => $id,
                    'name' => $id !== null ? (string) $r->category_name : 'Uncategorised',
                    'units' => (int) $r->units,
                    'revenue' => round($revenue, 2),
                    'profit' => $profit,
                    'margin' => $revenue > 0 ? round($profit / $revenue * 100, 1) : null,
                    'stock_at_cost' => $stock[$id ?? 0] ?? 0.0,
                ];
            })->sortByDesc(fn ($r) => [$r['profit'], $r['revenue']])->values()->all();
        }, $this->ttlFor($range, live: true));   // stock_at_cost is today's shelf
    }

    // ── 6. Who to call today ────────────────────────────────────────────────

    /**
     * The phone calls worth making today, in order of urgency: risky COD
     * orders to confirm before booking, reminders that have come due,
     * abandoned carts worth a ring, occasions this week, and buyers going
     * quiet. Global — a to-do list has no window — and cached for
     * CALL_LIST_SECONDS only.
     *
     * The risk tag on an order comes from the stored courier_checks row
     * through BdCourierService::risk(), so the owner's Safe/Warning
     * thresholds under Integrations apply; a row older than STALE_CHECK_DAYS
     * or no row at all reads "unchecked". This method NEVER calls
     * BdCourierService::check() — every lookup spends a plan credit, and the
     * customer page has the click-to-check button for that.
     *
     * Each section is {count, rows (top 3), extra, link}; a section with
     * count 0 is the honest "nothing here" line, never a hidden card. The
     * slipping section is the RFM at_risk and needs_attention buckets
     * together — both are "went quiet three to six months ago", and a
     * one-time buyer going quiet is exactly who a jewellery shop rings.
     *
     * @return array{
     *   total: int,
     *   confirm: array{count:int, rows:array<int, array{order_id:int, order_number:string, name:string, phone:string, area:?string, total:float, risk:string, hours_waiting:int}>, extra:array{cod_at_risk:float}, link:array{route:string, params:array}},
     *   reminders: array{count:int, rows:array<int, array{id:int, name:string, phone:string, items:array<int,string>, overdue:bool}>, extra:array{done_week:int, orders_from_calls:int}, link:array{route:string, params:array}},
     *   carts: array{count:int, rows:array<int, array{id:int, name:?string, phone:string, subtotal:float, item_count:int, last_step:string, hours_ago:int, sms_sent:bool}>, extra:array{open_now:array{count:int, amount:float, oldest_days:?int}}, link:array{route:string, params:array}},
     *   occasions: array{count:int, rows:array<int, array{customer_id:int, name:string, phone:?string, type:string, days_until:int, date:string}>, extra:array{coverage:array{customers:int, birthday:int, anniversary:int}}, link:array{route:string, params:array}},
     *   slipping: array{count:int, rows:array<int, array{id:int, name:string, phone:?string, total_spent:float, days_since:?int}>, extra:array{rule:string}, link:array{route:string, params:array}}
     * }
     */
    public function callList(): array
    {
        return $this->remember('calls', function () {
            $confirm = $this->confirmBeforeBooking();
            $reminders = $this->remindersDue();
            $carts = $this->cartsWorthACall();
            $occasions = $this->occasionsThisWeek();
            $slipping = $this->slippingBuyers();

            return [
                'total' => $confirm['count'] + $reminders['count'] + $carts['count'] + $occasions['count'] + $slipping['count'],
                'confirm' => $confirm,
                'reminders' => $reminders,
                'carts' => $carts,
                'occasions' => $occasions,
                'slipping' => $slipping,
            ];
        }, self::CALL_LIST_SECONDS);
    }

    /** Unbooked orders older than CONFIRM_AFTER_HOURS, riskiest first. */
    protected function confirmBeforeBooking(): array
    {
        $orders = Order::query()
            ->whereIn('status', ['pending', 'processing'])
            ->where('created_at', '<', now()->subHours(self::CONFIRM_AFTER_HOURS))
            ->whereDoesntHave('shipments', fn ($q) => $q->whereNull('superseded_at'))
            ->orderBy('created_at')
            ->take(200)
            ->get(['id', 'order_number', 'customer_id', 'customer_name', 'customer_phone', 'area', 'total', 'created_at']);

        if ($orders->isEmpty()) {
            return [
                'count' => 0, 'rows' => [], 'extra' => ['cod_at_risk' => 0.0],
                'link' => ['route' => 'admin.orders.index', 'params' => ['status' => 'processing']],
            ];
        }

        $phones = $orders->pluck('customer_phone')->filter()->unique()->values();

        $blacklisted = Customer::query()->where('blacklisted', true)
            ->where(fn ($q) => $q->whereIn('phone', $phones)
                ->orWhereIn('id', $orders->pluck('customer_id')->filter()->unique()->values()))
            ->get(['id', 'phone']);

        $checks = CourierCheck::whereIn('phone', $phones)->get()->keyBy('phone');
        $courier = app(BdCourierService::class);

        $tag = function (Order $o) use ($blacklisted, $checks, $courier) {
            if ($blacklisted->contains(fn ($c) => $c->phone === $o->customer_phone || ($o->customer_id && $c->id === $o->customer_id))) {
                return 'blacklisted';
            }

            $check = $checks[$o->customer_phone] ?? null;
            if (! $check || ! $check->checked_at || $check->checked_at->lt(now()->subDays(self::STALE_CHECK_DAYS))) {
                return 'unchecked';
            }

            $level = $courier->risk([
                'total_parcel' => (int) $check->total_parcel,
                'success_ratio' => (float) $check->success_ratio,
            ])['level'];

            return match ($level) {
                'unknown' => 'first_timer',
                'safe' => 'ok',
                'warning' => 'warning',
                'risky' => 'risky',
                default => 'unchecked',
            };
        };

        $rank = array_flip(self::RISK_ORDER);

        $rows = $orders->map(fn (Order $o) => [
            'order_id' => (int) $o->id,
            'order_number' => (string) $o->order_number,
            'name' => (string) $o->customer_name,
            'phone' => (string) $o->customer_phone,
            'area' => $o->area,
            'total' => round((float) $o->total, 2),
            'risk' => $tag($o),
            'hours_waiting' => (int) floor($o->created_at->diffInHours(now(), true)),
            '_created' => $o->created_at->getTimestamp(),
        ])->sortBy(fn ($r) => [$rank[$r['risk']] ?? 99, $r['_created']])->values();

        return [
            'count' => $rows->count(),
            'rows' => $rows->take(3)->map(fn ($r) => collect($r)->except('_created')->all())->values()->all(),
            'extra' => [
                'cod_at_risk' => round((float) $rows->whereIn('risk', ['risky', 'warning', 'blacklisted'])->sum('total'), 2),
            ],
            'link' => ['route' => 'admin.orders.index', 'params' => ['status' => 'processing']],
        ];
    }

    /**
     * Call reminders due by the end of the shop's today, and how the week's
     * calls went. The shop's week starts on Saturday — the order clock
     * rotates its weekdays Saturday-first for the same reason — so "done
     * this week" counts from the most recent Saturday midnight in Dhaka, not
     * Carbon's default Monday, which the 2026-09-18 review caught dropping
     * Saturday's and Sunday's calls from the count on the shop's busiest days.
     */
    protected function remindersDue(): array
    {
        $tz = config('app.timezone');
        $endOfToday = store_time(now())->endOfDay()->setTimezone($tz);
        $weekStart = store_time(now())->startOfWeek(Carbon::SATURDAY)->setTimezone($tz);

        $due = CallReminder::query()->whereNull('done_at')->where('due_at', '<=', $endOfToday);

        $rows = (clone $due)->with('customer')->orderBy('due_at')->orderBy('id')->take(3)->get()
            ->map(fn (CallReminder $r) => [
                'id' => (int) $r->id,
                'name' => $r->displayName(),
                'phone' => (string) $r->phone,
                'items' => collect($r->items ?? [])->pluck('name')->filter()->map(fn ($n) => (string) $n)->take(2)->values()->all(),
                'overdue' => $r->isOverdue(),
            ])->values()->all();

        $doneThisWeek = CallReminder::query()->whereNotNull('done_at')->where('done_at', '>=', $weekStart);

        return [
            'count' => (clone $due)->count(),
            'rows' => $rows,
            'extra' => [
                'done_week' => (clone $doneThisWeek)->count(),
                'orders_from_calls' => (clone $doneThisWeek)->whereNotNull('order_id')->count(),
            ],
            'link' => ['route' => 'admin.reminders.index', 'params' => ['tab' => 'due']],
        ];
    }

    /** Open carts from the last week, biggest first, plus everything still open. */
    protected function cartsWorthACall(): array
    {
        $recent = AbandonedCart::query()->open()->whereNotNull('phone')
            ->where('created_at', '>=', now()->subDays(7));

        $rows = (clone $recent)->orderByDesc('subtotal')->orderByDesc('id')->take(3)->get()
            ->map(fn (AbandonedCart $c) => [
                'id' => (int) $c->id,
                'name' => $c->name,
                'phone' => (string) $c->phone,
                'subtotal' => round((float) $c->subtotal, 2),
                'item_count' => (int) $c->item_count,
                'last_step' => $c->stepLabel(),
                'hours_ago' => $c->created_at ? (int) floor($c->created_at->diffInHours(now(), true)) : 0,
                'sms_sent' => $c->sms_reminded_at !== null,
            ])->values()->all();

        $open = AbandonedCart::query()->open()
            ->selectRaw('COUNT(*) as n, COALESCE(SUM(subtotal), 0) as amount, MIN(created_at) as oldest')
            ->first();

        return [
            'count' => (clone $recent)->count(),
            'rows' => $rows,
            'extra' => ['open_now' => [
                'count' => (int) ($open->n ?? 0),
                'amount' => round((float) ($open->amount ?? 0), 2),
                'oldest_days' => ($open->oldest ?? null)
                    ? (int) floor(Carbon::parse($open->oldest)->diffInDays(now(), true))
                    : null,
            ]],
            'link' => ['route' => 'admin.abandoned.index', 'params' => ['filter' => 'open']],
        ];
    }

    /** Birthdays and anniversaries in the next seven days that have not been wished yet. */
    protected function occasionsThisWeek(): array
    {
        $rows = UpcomingOccasions::upcoming(7)
            ->reject(fn (array $r) => UpcomingOccasions::sentAt($r['customer'], $r['type'], 'wish', $r['date']) !== null)
            ->map(fn (array $r) => [
                'customer_id' => (int) $r['customer']->id,
                'name' => (string) $r['customer']->name,
                'phone' => $r['customer']->phone,
                'type' => (string) $r['type'],
                'days_until' => (int) $r['days_until'],
                'date' => $r['date']->toDateString(),
            ])->values();

        return [
            'count' => $rows->count(),
            'rows' => $rows->take(3)->all(),
            'extra' => ['coverage' => UpcomingOccasions::coverage()],
            'link' => ['route' => 'admin.customers.occasions', 'params' => []],
        ];
    }

    /** Repeat and one-time buyers who went quiet three to six months ago. */
    protected function slippingBuyers(): array
    {
        $rfm = app(RfmService::class);

        $query = Customer::query()->where('blacklisted', false)
            ->where(fn ($q) => $q
                ->where(fn ($b) => $rfm->applyBucket($b, 'at_risk'))
                ->orWhere(fn ($b) => $rfm->applyBucket($b, 'needs_attention')));

        $rows = (clone $query)->orderByDesc('total_spent')->orderBy('id')->take(3)->get()
            ->map(fn (Customer $c) => [
                'id' => (int) $c->id,
                'name' => (string) $c->name,
                'phone' => $c->phone,
                'total_spent' => round((float) $c->total_spent, 2),
                'days_since' => $c->last_order_at ? (int) floor($c->last_order_at->diffInDays(now(), true)) : null,
            ])->values()->all();

        return [
            'count' => (clone $query)->count(),
            'rows' => $rows,
            'extra' => ['rule' => 'Bought before, last order '.(RfmService::R_WARM + 1).'–'.RfmService::R_COOL.' days ago'],
            'link' => ['route' => 'admin.customers.index', 'params' => ['lapsed' => 1, 'lapsed_days' => RfmService::R_WARM]],
        ];
    }

    // ── 7. Abandoned carts & recovery ───────────────────────────────────────

    /**
     * Whether chasing leads (calls, WhatsApp, paid SMS) brings sales back.
     * Windowed on abandoned_carts.created_at — the named-lead desk, as
     * opposed to the funnel panel's anonymous "left at checkout" money.
     *
     * Recovered value prefers the linked order's total (orders.abandoned_
     * cart_id, set by the admin "Convert to order" path since 12 Sep 2026)
     * and falls back to the cart's own subtotal for carts that recovered on
     * their own; includes_cart_values says when that fallback happened so the
     * view can caption it "cart value". Shares are null under MIN_OUTCOMES
     * captured carts.
     *
     * @return array{
     *   captured: array{count:int, amount:float},
     *   contacted: array{count:int, pct:?float},
     *   attempts: array<string, array{label:string, count:int}>,
     *   reminders: array{sms:int, push:int},
     *   recovered: array{count:int, pct:?float, by_hand:int, on_their_own:int},
     *   recovered_value: array{amount:float, includes_cart_values:bool},
     *   outcomes: array<string, array{label:string, count:int}>
     * }
     */
    public function leadRecovery(DateRange $range): array
    {
        return $this->remember('leads.'.$range->cacheKey(), function () use ($range) {
            // Aliases deliberately avoid the model's own column names: an
            // aggregate called `recovered` would go through the boolean cast
            // and read 1 for any count.
            $captured = $range->constrain(AbandonedCart::query())
                ->selectRaw(
                    'COUNT(*) as n, COALESCE(SUM(subtotal), 0) as amount, '
                    .'SUM(CASE WHEN contacted = 1 THEN 1 ELSE 0 END) as contacted_n, '
                    .'SUM(CASE WHEN recovered = 1 THEN 1 ELSE 0 END) as recovered_n, '
                    .'SUM(CASE WHEN sms_reminded_at IS NOT NULL THEN 1 ELSE 0 END) as sms_n, '
                    .'SUM(CASE WHEN push_reminded_at IS NOT NULL THEN 1 ELSE 0 END) as push_n'
                )->first();

            $n = (int) ($captured->n ?? 0);
            $contactedN = (int) ($captured->contacted_n ?? 0);
            $recoveredN = (int) ($captured->recovered_n ?? 0);

            $value = $range->constrain(AbandonedCart::query()->where('recovered', true), 'abandoned_carts.created_at')
                ->leftJoinSub($this->linkedOrderTotals(), 'linked', 'linked.abandoned_cart_id', '=', 'abandoned_carts.id')
                ->selectRaw(
                    'COUNT(*) as n, SUM(CASE WHEN linked.n IS NULL THEN 1 ELSE 0 END) as on_their_own, '
                    .'COALESCE(SUM(COALESCE(linked.total, abandoned_carts.subtotal)), 0) as amount'
                )->first();

            $onTheirOwn = (int) ($value->on_their_own ?? 0);

            $attempts = $range->constrain(AbandonedCartContact::query())
                ->selectRaw('channel, COUNT(*) as n')->groupBy('channel')->pluck('n', 'channel');

            $outcomeRows = $range->constrain(AbandonedCartContact::query())
                ->selectRaw('outcome, COUNT(*) as n')->groupBy('outcome')->get();

            $outcomes = [];
            foreach (AbandonedCartContact::OUTCOMES + ['no_note' => 'No note'] as $key => $label) {
                $outcomes[$key] = ['label' => $label, 'count' => 0];
            }
            foreach ($outcomeRows as $r) {
                $key = $r->outcome === null || $r->outcome === '' ? 'no_note' : (string) $r->outcome;
                $outcomes[$key] ??= ['label' => ucfirst(str_replace('_', ' ', $key)), 'count' => 0];
                $outcomes[$key]['count'] += (int) $r->n;
            }

            $channels = [];
            foreach (AbandonedCartContact::CHANNELS as $key => $label) {
                $channels[$key] = ['label' => $label, 'count' => (int) ($attempts[$key] ?? 0)];
            }

            $enough = $n >= self::MIN_OUTCOMES;

            return [
                'captured' => ['count' => $n, 'amount' => round((float) ($captured->amount ?? 0), 2)],
                'contacted' => [
                    'count' => $contactedN,
                    'pct' => $enough ? self::share($contactedN, $n) : null,
                ],
                'attempts' => $channels,
                'reminders' => ['sms' => (int) ($captured->sms_n ?? 0), 'push' => (int) ($captured->push_n ?? 0)],
                'recovered' => [
                    'count' => $recoveredN,
                    'pct' => $enough ? self::share($recoveredN, $n) : null,
                    'by_hand' => max(0, $recoveredN - $onTheirOwn),
                    'on_their_own' => $onTheirOwn,
                ],
                'recovered_value' => [
                    'amount' => round((float) ($value->amount ?? 0), 2),
                    'includes_cart_values' => $onTheirOwn > 0,
                ],
                'outcomes' => $outcomes,
            ];
        }, $range->cacheSeconds());
    }

    /**
     * Per abandoned cart, how many orders were converted from it and what
     * the ones that stuck (not cancelled or returned) were worth.
     */
    protected function linkedOrderTotals()
    {
        return Order::query()
            ->whereNotNull('abandoned_cart_id')
            ->selectRaw(
                'abandoned_cart_id, COUNT(*) as n, '
                ."COALESCE(SUM(CASE WHEN status NOT IN ('cancelled', 'returned') THEN total ELSE 0 END), 0) as total"
            )
            ->groupBy('abandoned_cart_id');
    }

    // ── 8. Assistant & SMS ──────────────────────────────────────────────────

    /**
     * Whether the two paid tools earn their keep: what the AI assistant and
     * the SMS gateway did in the window, and what came back from each.
     *
     * Every SMS purpose is read from the table that owns the outcome — the
     * cart's sms_reminded_at and its recovery, the order's review request and
     * the review on that phone, the customer's occasion stamp and their next
     * order — never from message text, which the owner can rewrite. Sent is
     * SUM(recipients) over rows whose provider_status is one of KhudeBarta's
     * accepted codes ('0', '00'), exactly as SmsService::send() judges a
     * send; the status column holds the gateway's own text. Segments count
     * GSM-7 against UCS-2 (any Bangla makes a text UCS-2, 70 characters a
     * part). Cost needs the per-segment rate typed under Settings
     * (sms_cost_per_segment) and is null until then; the balance is read from
     * the bell's cache key and never fetched here. Assistant cost is not
     * tracked anywhere (AssistantService discards the usage block), so
     * cost_tracked is false and the view says so.
     *
     * @return array{
     *   assistant: array{
     *     enabled:bool, ordering_enabled:bool, conversations:int, failed:int, blocked:int, product_replies:int,
     *     chat_orders:array{count:int, revenue:float},
     *     assisted_orders:array{count:int, note:string},
     *     guest_assisted_at_least:int, cost_tracked:bool},
     *   sms: array{
     *     enabled:bool, sent:int, rejected:int, top_rejection_text:?string, segments:int, cost:?float, balance:?float},
     *   by_purpose: array{
     *     cart_reminders:array{sent:int, recovered:int, recovered_pct:?float, revenue:float},
     *     review_requests:array{sent:int, replied:int, pct:?float},
     *     occasion_texts:array{sent:int, ordered_within_7d:int},
     *     order_texts:array{sent:int},
     *     broadcasts:array{sent:int}}
     * }
     */
    public function assistantAndSms(DateRange $range): array
    {
        return $this->remember('aisms.'.$range->cacheKey(), function () use ($range) {
            return [
                'assistant' => $this->assistantFigures($range),
                'sms' => $this->smsFigures($range),
                'by_purpose' => $this->smsByPurpose($range),
            ];
        }, $range->cacheSeconds());
    }

    protected function assistantFigures(DateRange $range): array
    {
        $conversations = fn () => $range->constrain(AssistantConversation::query(), 'assistant_conversations.created_at');

        $chat = $range->constrain($this->sold())->where('source', 'chat')
            ->selectRaw('COUNT(*) as n, COALESCE(SUM(total), 0) as revenue')->first();

        // Members only: an order carries no visitor token, so only a chat
        // that knew its customer can be tied to what they bought afterwards.
        $orders = $range->constrain($this->sold())->whereNotNull('customer_id')
            ->get(['id', 'customer_id', 'created_at']);
        $assisted = 0;
        if ($orders->isNotEmpty()) {
            $chats = AssistantConversation::query()
                ->whereIn('customer_id', $orders->pluck('customer_id')->unique()->values())
                ->whereNotNull('last_message_at')
                ->get(['customer_id', 'last_message_at'])
                ->groupBy('customer_id');

            foreach ($orders as $o) {
                $hit = collect($chats[$o->customer_id] ?? [])->contains(fn ($c) => $c->last_message_at->lte($o->created_at)
                    && $c->last_message_at->gte($o->created_at->copy()->subDays(7)));
                if ($hit) {
                    $assisted++;
                }
            }
        }

        // Guests: the chat's visitor token → the cart it left → the order
        // that cart became. A floor, never the whole picture.
        $guest = (int) $range->constrain($this->sold(), 'orders.created_at')
            ->join('abandoned_carts', 'abandoned_carts.id', '=', 'orders.abandoned_cart_id')
            ->join('assistant_conversations', 'assistant_conversations.visitor_token', '=', 'abandoned_carts.visitor_token')
            ->whereNotNull('abandoned_carts.visitor_token')
            ->distinct()->count('orders.id');

        return [
            'enabled' => app(AssistantService::class)->enabled(),
            'ordering_enabled' => app(ChatOrder::class)->enabled(),
            'conversations' => (int) $conversations()->whereHas('messages', fn ($m) => $m->where('role', 'user'))->count(),
            'failed' => (int) $conversations()->where('had_failure', true)->count(),
            'blocked' => (int) $conversations()->whereNotNull('blocked_at')->count(),
            'product_replies' => (int) $range->constrain(AssistantMessage::query(), 'assistant_messages.created_at')
                ->where('role', 'assistant')->whereNotNull('products')->count(),
            'chat_orders' => ['count' => (int) ($chat->n ?? 0), 'revenue' => round((float) ($chat->revenue ?? 0), 2)],
            'assisted_orders' => ['count' => $assisted, 'note' => 'known customers only'],
            'guest_assisted_at_least' => $guest,
            'cost_tracked' => false,
        ];
    }

    protected function smsFigures(DateRange $range): array
    {
        $accepted = "provider_status IN ('0', '00')";

        $agg = $range->constrain(SmsLog::query())
            ->selectRaw(
                "COALESCE(SUM(CASE WHEN {$accepted} THEN recipients ELSE 0 END), 0) as sent, "
                ."COALESCE(SUM(CASE WHEN {$accepted} THEN 0 ELSE recipients END), 0) as rejected"
            )->first();

        $topRejection = $range->constrain(SmsLog::query())
            ->where(fn ($q) => $q->whereNull('provider_status')->orWhereNotIn('provider_status', self::SMS_ACCEPTED))
            ->selectRaw('status, COALESCE(SUM(recipients), 0) as n')
            ->groupBy('status')->orderByDesc('n')->first();

        // Segment counting needs the text, so this is the one figure that
        // reads rows rather than an aggregate — in chunks, and only the
        // accepted sends, which are the ones the gateway billed.
        $segments = 0;
        $range->constrain(SmsLog::query())->whereIn('provider_status', self::SMS_ACCEPTED)
            ->select(['id', 'message', 'recipients'])
            ->chunkById(500, function ($rows) use (&$segments) {
                foreach ($rows as $r) {
                    $segments += self::smsSegments((string) $r->message) * max(1, (int) $r->recipients);
                }
            });

        $rate = Setting::get('sms_cost_per_segment');
        $balance = Cache::get('admin.alerts.sms_balance');
        $available = is_array($balance)
            ? ($balance['statusInfo']['availablebalance'] ?? $balance['availablebalance'] ?? null)
            : null;

        return [
            'enabled' => app(SmsService::class)->isEnabled(),
            'sent' => (int) ($agg->sent ?? 0),
            'rejected' => (int) ($agg->rejected ?? 0),
            'top_rejection_text' => filled($topRejection?->status) ? (string) $topRejection->status : null,
            'segments' => $segments,
            // 0 is a real rate (a bundled plan), so only a blank means "not set".
            'cost' => is_numeric($rate) ? round($segments * (float) $rate, 2) : null,
            'balance' => is_numeric($available) ? (float) $available : null,
        ];
    }

    protected function smsByPurpose(DateRange $range): array
    {
        $accepted = "provider_status IN ('0', '00')";

        $logs = $range->constrain(SmsLog::query())
            ->selectRaw(
                "COALESCE(SUM(CASE WHEN {$accepted} AND order_id IS NOT NULL THEN recipients ELSE 0 END), 0) as order_texts, "
                ."COALESCE(SUM(CASE WHEN {$accepted} AND recipients > 1 THEN recipients ELSE 0 END), 0) as broadcasts"
            )->first();

        $carts = $range->constrain(AbandonedCart::query()->whereNotNull('abandoned_carts.sms_reminded_at'), 'abandoned_carts.sms_reminded_at')
            ->leftJoinSub($this->linkedOrderTotals(), 'linked', 'linked.abandoned_cart_id', '=', 'abandoned_carts.id')
            ->selectRaw(
                'COUNT(*) as sent, SUM(CASE WHEN abandoned_carts.recovered = 1 THEN 1 ELSE 0 END) as recovered_n, '
                .'COALESCE(SUM(linked.total), 0) as revenue'
            )->first();
        $cartSent = (int) ($carts->sent ?? 0);
        $cartRecovered = (int) ($carts->recovered_n ?? 0);

        // Reviews keep the phone as it was typed while orders store it
        // canonically, so '+880 1712-345678' would never match in SQL. Match
        // in PHP on bd_phone() instead; the sets are small (review requests in
        // the window, reviews since the earliest of them).
        $requested = $range->constrain(Order::query()->whereNotNull('orders.review_request_sent_at'), 'orders.review_request_sent_at')
            ->get(['orders.id', 'orders.customer_phone', 'orders.review_request_sent_at']);
        $replied = 0;
        if ($requested->isNotEmpty()) {
            $byPhone = [];
            foreach (DB::table('reviews')->where('created_at', '>', $requested->min('review_request_sent_at'))->get(['phone', 'created_at']) as $review) {
                $byPhone[bd_phone((string) $review->phone)][] = $review->created_at;
            }
            foreach ($requested as $order) {
                foreach ($byPhone[bd_phone((string) $order->customer_phone)] ?? [] as $at) {
                    if ($at > $order->review_request_sent_at) {
                        $replied++;
                        break;
                    }
                }
            }
        }
        $reviews = (object) ['sent' => $requested->count(), 'replied' => $replied];

        $reviewSent = (int) ($reviews->sent ?? 0);

        return [
            'cart_reminders' => [
                'sent' => $cartSent,
                'recovered' => $cartRecovered,
                'recovered_pct' => $cartSent >= self::MIN_OUTCOMES ? self::share($cartRecovered, $cartSent) : null,
                'revenue' => round((float) ($carts->revenue ?? 0), 2),
            ],
            'review_requests' => [
                'sent' => $reviewSent,
                'replied' => (int) ($reviews->replied ?? 0),
                'pct' => $reviewSent >= self::MIN_OUTCOMES ? self::share((int) ($reviews->replied ?? 0), $reviewSent) : null,
            ],
            'occasion_texts' => $this->occasionTexts($range),
            'order_texts' => ['sent' => (int) ($logs->order_texts ?? 0)],
            'broadcasts' => ['sent' => (int) ($logs->broadcasts ?? 0)],
        ];
    }

    /**
     * Occasion wishes stamped in the window (customers.*_wished_at, one per
     * customer per occasion) and how many were followed by an order from
     * that customer within seven days.
     *
     * @return array{sent:int, ordered_within_7d:int}
     */
    protected function occasionTexts(DateRange $range): array
    {
        $columns = ['birthday_wished_at', 'anniversary_wished_at'];

        $customers = Customer::query()
            ->where(function ($q) use ($range, $columns) {
                foreach ($columns as $i => $column) {
                    $q->{$i === 0 ? 'where' : 'orWhere'}(fn ($b) => $range->constrain($b->whereNotNull($column), $column));
                }
            })
            ->get(array_merge(['id'], $columns));

        $stamps = [];
        foreach ($customers as $c) {
            foreach ($columns as $column) {
                $at = $c->{$column};
                if ($at && ($range->isAllTime() || $at->between($range->start, $range->end))) {
                    $stamps[] = ['customer_id' => (int) $c->id, 'at' => $at];
                }
            }
        }

        if ($stamps === []) {
            return ['sent' => 0, 'ordered_within_7d' => 0];
        }

        $orders = $this->sold()
            ->whereIn('customer_id', collect($stamps)->pluck('customer_id')->unique()->values())
            ->get(['customer_id', 'created_at'])
            ->groupBy('customer_id');

        $followed = 0;
        foreach ($stamps as $stamp) {
            $hit = collect($orders[$stamp['customer_id']] ?? [])->contains(fn ($o) => $o->created_at->gte($stamp['at'])
                && $o->created_at->lte($stamp['at']->copy()->addDays(7)));
            if ($hit) {
                $followed++;
            }
        }

        return ['sent' => count($stamps), 'ordered_within_7d' => $followed];
    }

    /**
     * How many billable parts one message is: GSM-7 (160 characters, 153 a
     * part when concatenated; the extension characters ^{}\[~]|€ cost two)
     * or UCS-2 (70, then 67 a part) as soon as any character — every Bangla
     * letter, every emoji — falls outside the GSM alphabet.
     */
    public static function smsSegments(string $message): int
    {
        if ($message === '') {
            return 0;
        }

        static $basic = null;
        $basic ??= array_flip(preg_split('//u', "@£\$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !\"#¤%&'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà", -1, PREG_SPLIT_NO_EMPTY));
        static $extension = null;
        $extension ??= array_flip(preg_split('//u', "^{}\\[~]|€\f", -1, PREG_SPLIT_NO_EMPTY));

        $length = 0;
        foreach (preg_split('//u', $message, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $char) {
            if (isset($basic[$char])) {
                $length++;
            } elseif (isset($extension[$char])) {
                $length += 2;
            } else {
                // UTF-16 code units, not code points: an emoji above U+FFFF
                // (every modern one) costs two, and the gateway bills by units.
                $ucs2 = intdiv(strlen(mb_convert_encoding($message, 'UTF-16BE', 'UTF-8')), 2);

                return $ucs2 <= 70 ? 1 : (int) ceil($ucs2 / 67);
            }
        }

        return $length <= 160 ? 1 : (int) ceil($length / 153);
    }

    // ── 9. When orders come in ──────────────────────────────────────────────

    /**
     * Orders and checkout starts by hour of the day, and orders by weekday,
     * in the shop's own clock — when to boost ads, send SMS or push, and be
     * at the phone.
     *
     * Rows are stored in UTC (config/app.php) and the shop lives in
     * config('store.timezone'); the offset is taken from Carbon at call time
     * and applied in SQL, driver-switched (MySQL `HOUR(created_at + INTERVAL
     * n MINUTE)`, SQLite `strftime('%H', created_at, '+n minutes')`), with a
     * PHP fallback for any other driver. Weekdays are Sunday first (0 =
     * Sunday … 6 = Saturday); the view rotates for a Saturday-first week.
     *
     * @return array{
     *   hours: array<int, int>, checkouts: array<int, int>, weekdays: array<int, int>,
     *   weekday_first: string, best_hours: array<int, string>,
     *   n: array{orders:int, checkouts:int}, timezone: string, tracking: bool
     * }
     */
    public function orderClock(DateRange $range): array
    {
        return $this->remember('clock.'.$range->cacheKey(), function () use ($range) {
            $tz = config('store.timezone', 'Asia/Dhaka');
            $minutes = (int) round(now()->setTimezone($tz)->offset / 60);

            $hours = $this->bucketByLocal($range->constrain($this->sold()), 'orders.created_at', 'hour', $minutes, 24);
            $weekdays = $this->bucketByLocal($range->constrain($this->sold()), 'orders.created_at', 'weekday', $minutes, 7);

            $tracking = $this->hasTable('visits');
            $checkouts = $tracking
                ? $this->bucketByLocal($range->constrain(Visit::where('event', 'checkout_start')), 'visits.created_at', 'hour', $minutes, 24)
                : array_fill(0, 24, 0);

            $best = collect($hours)->filter(fn ($n) => $n > 0)
                ->sortBy(fn ($n, $h) => [-$n, $h])->take(2)->keys()
                ->map(fn ($h) => Carbon::createFromTime((int) $h, 0, 0, $tz)->format('g a'))
                ->values()->all();

            return [
                'hours' => $hours,
                'checkouts' => $checkouts,
                'weekdays' => $weekdays,
                'weekday_first' => 'sunday',
                'best_hours' => $best,
                'n' => ['orders' => array_sum($hours), 'checkouts' => array_sum($checkouts)],
                'timezone' => $tz,
                'tracking' => $tracking,
            ];
        }, $range->cacheSeconds());
    }

    /**
     * Count rows per local hour (0–23) or weekday (0 = Sunday), shifting the
     * stored UTC timestamp by $minutes in SQL where the driver can.
     *
     * @return array<int, int>
     */
    protected function bucketByLocal($query, string $column, string $unit, int $minutes, int $size): array
    {
        $out = array_fill(0, $size, 0);
        $driver = $this->driver();

        $expr = match (true) {
            $driver === 'mysql' && $unit === 'hour' => "HOUR({$column} + INTERVAL {$minutes} MINUTE)",
            $driver === 'mysql' => "DAYOFWEEK({$column} + INTERVAL {$minutes} MINUTE) - 1",
            $driver === 'sqlite' && $unit === 'hour' => "CAST(strftime('%H', {$column}, '".sprintf('%+d', $minutes)." minutes') AS INTEGER)",
            $driver === 'sqlite' => "CAST(strftime('%w', {$column}, '".sprintf('%+d', $minutes)." minutes') AS INTEGER)",
            default => null,
        };

        if ($expr === null) {
            // A driver without a known expression: shift and bucket in PHP.
            foreach ($query->pluck($column) as $at) {
                $local = Carbon::parse($at)->addMinutes($minutes);
                $out[$unit === 'hour' ? $local->hour : $local->dayOfWeek]++;
            }

            return $out;
        }

        $rows = $query->selectRaw("{$expr} as bucket, COUNT(*) as n")->groupByRaw($expr)->pluck('n', 'bucket');

        foreach ($rows as $bucket => $n) {
            $i = (int) $bucket;
            if ($i >= 0 && $i < $size) {
                $out[$i] += (int) $n;
            }
        }

        return $out;
    }

    // ── 10. Cash in stock ───────────────────────────────────────────────────

    /**
     * How much money is on the shelf, how fast it turns, and how much of it
     * has not moved at all in the window — the purchase-order-or-clearance
     * decision with numbers.
     *
     * The stocked set is manage_stock = 1, stock_quantity > 0, not deleted:
     * the KPI tile's set. Stock at cost uses cost + transport as the tile
     * does; stock at sell uses the product price and ignores variant price
     * overrides (the view says so). Weeks of cover divides units on hand by
     * the window's weekly sales pace, with operations()' span fallback for
     * "Maximum"; it is null when nothing sold. cost_gap_count is how many
     * stocked products carry no cost price, which makes every ৳ here an
     * underestimate.
     *
     * @return array{
     *   stock_at_cost: float, stock_at_sell: float, units_on_hand: int, units_sold: int,
     *   sell_through_pct: ?float, weeks_of_cover: ?float, span_days: int,
     *   sitting_still: array{amount:float, pct_of_cost:?float, units:int},
     *   sitting_still_by_category: array<int, array{name:string, amount:float}>,
     *   cost_gap_count: int
     * }
     */
    public function stockHealth(DateRange $range): array
    {
        return $this->remember('stock.'.$range->cacheKey(), function () use ($range) {
            $landed = '(COALESCE(products.cost_price, 0) + COALESCE(products.transport_cost, 0))';

            $shelf = $this->stocked()
                ->selectRaw(
                    "COALESCE(SUM(products.stock_quantity * {$landed}), 0) as at_cost, "
                    .'COALESCE(SUM(products.stock_quantity * COALESCE(products.price, 0)), 0) as at_sell, '
                    .'COALESCE(SUM(products.stock_quantity), 0) as units, '
                    .'SUM(CASE WHEN products.cost_price IS NULL THEN 1 ELSE 0 END) as cost_gap'
                )->first();

            $unitsSold = (int) $this->soldItems($range)->whereNotNull('order_items.product_id')->sum('order_items.quantity');

            $soldIds = $this->soldItems($range)->whereNotNull('order_items.product_id')->select('order_items.product_id');

            $still = $this->stocked()->whereNotIn('products.id', $soldIds)
                ->selectRaw("COALESCE(SUM(products.stock_quantity * {$landed}), 0) as amount, COALESCE(SUM(products.stock_quantity), 0) as units")
                ->first();

            $byCategory = $this->stocked()->whereNotIn('products.id', $soldIds)
                ->leftJoin('categories', 'categories.id', '=', 'products.category_id')
                ->selectRaw("categories.name as name, COALESCE(SUM(products.stock_quantity * {$landed}), 0) as amount")
                ->groupBy('categories.id', 'categories.name')
                ->orderByDesc('amount')->take(3)->get()
                ->map(fn ($r) => ['name' => (string) ($r->name ?? 'Uncategorised'), 'amount' => round((float) $r->amount, 2)])
                ->values()->all();

            $atCost = round((float) ($shelf->at_cost ?? 0), 2);
            $onHand = (int) ($shelf->units ?? 0);
            $spanDays = $this->spanDays($range);
            $perWeek = $unitsSold / ($spanDays / 7);
            $stillAmount = round((float) ($still->amount ?? 0), 2);

            return [
                'stock_at_cost' => $atCost,
                'stock_at_sell' => round((float) ($shelf->at_sell ?? 0), 2),
                'units_on_hand' => $onHand,
                'units_sold' => $unitsSold,
                'sell_through_pct' => self::share($unitsSold, $unitsSold + $onHand),
                'weeks_of_cover' => $perWeek > 0 ? round($onHand / $perWeek, 1) : null,
                'span_days' => $spanDays,
                'sitting_still' => [
                    'amount' => $stillAmount,
                    'pct_of_cost' => self::share($stillAmount, $atCost),
                    'units' => (int) ($still->units ?? 0),
                ],
                'sitting_still_by_category' => $byCategory,
                'cost_gap_count' => (int) ($shelf->cost_gap ?? 0),
            ];
        }, $this->ttlFor($range, live: true));   // the shelf is always today's
    }

    /** The products the stock figures are about: tracked, on the shelf, not deleted. */
    protected function stocked()
    {
        return Product::query()->where('products.manage_stock', true)->where('products.stock_quantity', '>', 0);
    }
}

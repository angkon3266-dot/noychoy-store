<?php

namespace App\Services;

use App\Models\AbandonedCart;
use App\Models\AdminAlertRead;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\Review;
use App\Models\User;
use App\Services\Meta\Credentials\MetaCredentialResolver;
use App\Support\DateRange;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Everything in the shop that wants someone's attention, in one list.
 *
 * Alerts are *derived from live data*, not rows written when something happens.
 * A low-stock warning is simply "this product's stock is low right now", so it
 * appears when it becomes true and disappears when the product is restocked —
 * no backlog to clear, nothing to go stale, and no events to miss because a
 * queue worker was down.
 *
 * The only thing persisted is the human decision to stop being told: each
 * alert carries a stable key, and AdminAlertRead records which admin has read
 * which key.
 *
 * Every source is wrapped so that one failing — a table missing after a partial
 * deploy, a courier API down — costs its own section and not the whole bell.
 */
class AdminAlerts
{
    /** How long the computed list is reused. Short: these drive an unread badge. */
    protected const CACHE_SECONDS = 60;

    /** Most alerts kept per source, so one bad day can't produce 400 rows. */
    protected const PER_SOURCE = 5;

    /**
     * Alerts for one admin, newest first, each flagged read/unread.
     *
     * @return Collection<int,array>
     */
    public function for(User $user): Collection
    {
        $read = AdminAlertRead::where('user_id', $user->getKey())
            ->pluck('alert_key')->flip();

        return $this->all()
            ->map(fn ($a) => $a + ['read' => $read->has($a['key'])])
            ->sortBy([
                ['read', false],                                  // unread first
                fn ($a, $b) => $this->weight($b) <=> $this->weight($a),
            ])
            ->values();
    }

    public function unreadCountFor(User $user): int
    {
        return $this->for($user)->reject(fn ($a) => $a['read'])->count();
    }

    /** Urgent before warning before info; newer before older within a level. */
    protected function weight(array $alert): array
    {
        $rank = ['urgent' => 3, 'warning' => 2, 'info' => 1][$alert['level']] ?? 0;

        return [$rank, $alert['at']?->timestamp ?? 0];
    }

    /**
     * The whole list, cached briefly and shared by every admin — the alerts are
     * identical for all of them; only the read state differs.
     *
     * What goes into the cache is a plain array with `at` as a Unix timestamp,
     * never a Collection and never a Carbon: config/cache.php sets
     * serializable_classes = false, so any object stored here comes back as
     * __PHP_Incomplete_Class and blows up at the point of use. Timestamps are
     * rehydrated on the way out.
     */
    public function all(): Collection
    {
        $cached = Cache::remember('admin.alerts.v1', self::CACHE_SECONDS, fn () => [
            ...$this->outOfStock(),
            ...$this->lowStock(),
            ...$this->pendingReviews(),
            ...$this->abandonedCarts(),
            ...$this->newOrders(),
            ...$this->stuckOrders(),
            ...$this->failedDeliveries(),
            ...$this->sellingBelowCost(),
            ...$this->smsCredit(),
            ...$this->metaTokenExpiring(),
            ...$this->viewedNotSold(),
            ...$this->returningCustomers(),
            ...$this->couponIssues(),
        ]);

        // An entry written before this contract was enforced would come back
        // mangled; recompute rather than hand it on.
        if (! is_array($cached)) {
            Cache::forget('admin.alerts.v1');

            return $this->all();
        }

        return collect($cached)->map(fn ($a) => [
            ...$a,
            'at' => $a['at'] ? Carbon::createFromTimestamp($a['at']) : null,
        ]);
    }

    /** Run one source, and let it fail without taking the rest down. */
    protected function guard(callable $fn): array
    {
        try {
            return $fn();
        } catch (\Throwable $e) {
            report($e);

            return [];
        }
    }

    /** `at` is stored as a Unix timestamp — see all() for why nothing here may be an object. */
    protected function alert(string $key, string $type, string $level, string $title, string $body, ?string $url, $at = null): array
    {
        return [
            'key' => $key,
            'type' => $type,
            'level' => $level,
            'title' => $title,
            'body' => $body,
            'url' => $url,
            'at' => $at ? Carbon::parse($at)->getTimestamp() : null,
        ];
    }

    // ── Stock ────────────────────────────────────────────────────────────────

    protected function outOfStock(): array
    {
        return $this->guard(fn () => Product::where('status', 'published')
            ->where('manage_stock', true)->where('stock_quantity', '<=', 0)
            ->orderByDesc('updated_at')->limit(self::PER_SOURCE)
            ->get(['id', 'name', 'slug', 'updated_at'])
            ->map(fn ($p) => $this->alert(
                "stock.out.{$p->id}", 'stock', 'urgent',
                "{$p->name} is out of stock",
                'Published and unbuyable — restock it or set it to draft.',
                route('admin.products.edit', $p), $p->updated_at,
            ))->all());
    }

    protected function lowStock(): array
    {
        $threshold = max(1, (int) (theme('low_stock_threshold') ?: 3));

        return $this->guard(fn () => Product::where('status', 'published')
            ->where('manage_stock', true)
            ->whereBetween('stock_quantity', [1, $threshold])
            ->orderBy('stock_quantity')->limit(self::PER_SOURCE)
            ->get(['id', 'name', 'slug', 'stock_quantity', 'updated_at'])
            ->map(fn ($p) => $this->alert(
                "stock.low.{$p->id}.{$p->stock_quantity}", 'stock', 'warning',
                "{$p->name} is down to {$p->stock_quantity}",
                "At or below your low-stock threshold of {$threshold}.",
                route('admin.products.edit', $p), $p->updated_at,
            ))->all());
    }

    // ── Reviews & carts ──────────────────────────────────────────────────────

    protected function pendingReviews(): array
    {
        return $this->guard(fn () => Review::where('status', 'pending')
            ->with('product:id,name')->latest()->limit(self::PER_SOURCE)
            ->get()
            ->map(fn ($r) => $this->alert(
                "review.{$r->id}", 'review', 'info',
                'New review awaiting approval',
                trim(($r->product?->name ? $r->product->name.' — ' : '').($r->rating ? "{$r->rating}★ " : '').Str::limit((string) $r->comment, 70)),
                route('admin.reviews.index'), $r->created_at,
            ))->all());
    }

    protected function abandonedCarts(): array
    {
        return $this->guard(fn () => AbandonedCart::where('recovered', false)
            ->where('contacted', false)
            ->where('created_at', '>=', now()->subDays(7))
            ->orderByDesc('subtotal')->limit(self::PER_SOURCE)
            ->get()
            ->map(fn ($c) => $this->alert(
                "cart.{$c->id}", 'cart', 'warning',
                'Abandoned cart · '.money($c->subtotal),
                trim(($c->name ?: 'A visitor').' left '.$c->item_count.' item(s) at '.$c->last_step.'. Nobody has followed up.'),
                route('admin.abandoned.index'), $c->created_at,
            ))->all());
    }

    // ── Orders ───────────────────────────────────────────────────────────────

    protected function newOrders(): array
    {
        return $this->guard(fn () => Order::where('status', 'pending')
            ->latest()->limit(self::PER_SOURCE)
            ->get(['id', 'order_number', 'customer_name', 'total', 'created_at'])
            ->map(fn ($o) => $this->alert(
                "order.new.{$o->order_number}", 'order', 'urgent',
                "New order {$o->order_number} · ".money($o->total),
                "{$o->customer_name} is waiting for this to be confirmed.",
                route('admin.orders.show', $o->id), $o->created_at,
            ))->all());
    }

    /**
     * Orders that stopped moving. A forgotten order is the failure that costs
     * real money — a refund, a chargeback, or a review that never washes off.
     */
    protected function stuckOrders(): array
    {
        return $this->guard(fn () => Order::whereIn('status', ['confirmed', 'processing'])
            ->where('updated_at', '<', now()->subDays(3))
            ->orderBy('updated_at')->limit(self::PER_SOURCE)
            ->get(['id', 'order_number', 'status', 'customer_name', 'updated_at'])
            ->map(fn ($o) => $this->alert(
                "order.stuck.{$o->order_number}", 'order', 'urgent',
                "{$o->order_number} stuck in {$o->status}",
                'No change for '.(int) $o->updated_at->diffInDays(now()).' days — '.$o->customer_name.' is still waiting.',
                route('admin.orders.show', $o->id), $o->updated_at,
            ))->all());
    }

    protected function failedDeliveries(): array
    {
        return $this->guard(fn () => Order::whereIn('status', ['returned', 'cancelled'])
            ->where('updated_at', '>=', now()->subDays(7))
            ->orderByDesc('updated_at')->limit(self::PER_SOURCE)
            ->get(['id', 'order_number', 'status', 'total', 'customer_name', 'updated_at'])
            ->map(fn ($o) => $this->alert(
                "delivery.{$o->order_number}.{$o->status}", 'delivery', 'warning',
                ucfirst($o->status)." · {$o->order_number} · ".money($o->total),
                "{$o->customer_name}. Cash on delivery that didn't land — worth a call before writing it off.",
                route('admin.orders.show', $o->id), $o->updated_at,
            ))->all());
    }

    // ── Money ────────────────────────────────────────────────────────────────

    /**
     * A published product priced under what it costs to buy and ship. Usually a
     * typo in a quick price edit, and every sale loses money until it's caught.
     */
    protected function sellingBelowCost(): array
    {
        return $this->guard(fn () => Product::where('status', 'published')
            ->whereNotNull('cost_price')->where('cost_price', '>', 0)
            ->whereRaw('price < (COALESCE(cost_price, 0) + COALESCE(transport_cost, 0))')
            ->limit(self::PER_SOURCE)
            ->get(['id', 'name', 'slug', 'price', 'cost_price', 'transport_cost', 'updated_at'])
            ->map(fn ($p) => $this->alert(
                "margin.{$p->id}", 'money', 'urgent',
                "{$p->name} sells below cost",
                money($p->price).' each against '.money((float) $p->cost_price + (float) $p->transport_cost).' landed cost.',
                route('admin.products.edit', $p), $p->updated_at,
            ))->all());
    }

    /**
     * SMS credit. This one talks to the gateway, so it is cached far longer
     * than the rest — the balance moves slowly, and an admin page load must
     * never wait on a third-party API.
     */
    protected function smsCredit(): array
    {
        return $this->guard(function () {
            $sms = app(SmsService::class);
            if (! $sms->isEnabled()) {
                return [];
            }

            $balance = Cache::remember('admin.alerts.sms_balance', 1800, fn () => $sms->getBalance());
            $amount = $balance['statusInfo']['availablebalance'] ?? $balance['availablebalance'] ?? null;

            if (! is_numeric($amount) || (float) $amount > 100) {
                return [];
            }

            return [$this->alert(
                'sms.low', 'money', 'urgent',
                'SMS credit is low ('.$amount.')',
                'Order confirmations and login OTPs stop silently when this runs out.',
                route('admin.sms.index'), now(),
            )];
        });
    }

    // ── Integrations ─────────────────────────────────────────────────────────

    /**
     * The Facebook connection is expiring or already expired. Derived from
     * live state (MetaCredentials), not a "sent once" flag: the scheduled
     * RefreshMetaToken job renews it automatically in the last 14 days before
     * expiry, so under normal operation this never fires at all — it only
     * appears once renewal has actually been failing, and disappears again
     * the moment it succeeds or the merchant reconnects.
     */
    protected function metaTokenExpiring(): array
    {
        return $this->guard(function () {
            $creds = app(MetaCredentialResolver::class)->resolve();

            if (! $creds->hasConnection() || ! $creds->isOauth()) {
                return [];
            }

            $health = $creds->connectionHealth();
            if (! in_array($health, ['expiring', 'expired'], true)) {
                return [];
            }

            $days = $creds->connectionDaysLeft();
            $body = $health === 'expired'
                ? 'Product sync to Facebook has stopped. Reconnect to resume it.'
                : 'Automatic renewal has not succeeded yet ('.($days !== null ? $days.' day'.($days === 1 ? '' : 's').' left' : 'a few days left').'). It will keep retrying daily — reconnect now if you would rather not wait.';

            return [$this->alert(
                'meta.token_expiring', 'integration', $health === 'expired' ? 'urgent' : 'warning',
                $health === 'expired' ? 'Facebook connection has expired' : 'Facebook connection expires soon',
                $body,
                route('admin.meta.index'), now(),
            )];
        });
    }

    // ── Growth ───────────────────────────────────────────────────────────────

    protected function viewedNotSold(): array
    {
        return $this->guard(fn () => collect(
            app(DashboardAnalytics::class)->viewedNotSold(DateRange::preset('30d'), 3)
        )->map(fn ($r) => $this->alert(
            "growth.viewed.{$r['id']}", 'growth', 'info',
            "{$r['name']}: {$r['views']} views, no sales",
            'People are looking and not buying — usually the photos, the price, or the description.',
            route('admin.products.edit', Product::find($r['id']) ?? $r['id']), now(),
        ))->all());
    }

    /** Repeat buyers who came back this week — the cheapest people to sell to again. */
    protected function returningCustomers(): array
    {
        return $this->guard(fn () => Customer::where('total_orders', '>=', 3)
            ->where('last_order_at', '>=', now()->subDays(7))
            ->orderByDesc('total_spent')->limit(3)
            ->get(['id', 'name', 'phone', 'total_orders', 'total_spent', 'last_order_at'])
            ->map(fn ($c) => $this->alert(
                'growth.repeat.'.$c->id.'.'.optional($c->last_order_at)->toDateString(), 'growth', 'info',
                ($c->name ?: $c->phone).' ordered again ('.$c->total_orders.' orders)',
                money($c->total_spent).' lifetime. Worth a thank-you or an offer.',
                route('admin.customers.show', $c->id), $c->last_order_at,
            ))->all());
    }

    protected function couponIssues(): array
    {
        return $this->guard(function () {
            $out = [];

            $expiring = Coupon::where('is_active', true)
                ->whereNotNull('expires_at')
                ->whereBetween('expires_at', [now(), now()->addDays(3)])
                ->limit(3)->get();

            foreach ($expiring as $c) {
                $out[] = $this->alert(
                    "coupon.expiring.{$c->id}", 'growth', 'info',
                    "Coupon {$c->code} expires ".$c->expires_at->diffForHumans(),
                    'Extend it or let it lapse — customers with the code will hit a dead end.',
                    route('admin.coupons.index'), $c->expires_at,
                );
            }

            $exhausted = Coupon::where('is_active', true)
                ->whereNotNull('usage_limit')
                ->whereColumn('used_count', '>=', 'usage_limit')
                ->limit(3)->get();

            foreach ($exhausted as $c) {
                $out[] = $this->alert(
                    "coupon.used.{$c->id}", 'growth', 'warning',
                    "Coupon {$c->code} is fully used",
                    "All {$c->usage_limit} uses are gone, but the code is still advertised as active.",
                    route('admin.coupons.index'), $c->updated_at,
                );
            }

            return $out;
        });
    }

    /** Drop the cached list — used after marking things read so the badge is honest. */
    public static function flush(): void
    {
        Cache::forget('admin.alerts.v1');
    }
}

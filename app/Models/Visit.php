<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

class Visit extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'visitor_token', 'event', 'path', 'product_id', 'referrer_host',
        'source', 'campaign', 'medium', 'content', 'ad_id',
    ];

    /** Columns added after the table shipped, keyed by the flag that gates them. */
    public const AD_COLUMNS = ['medium', 'content', 'ad_id'];

    /** Record one traffic event; never throws into the request. */
    public static function record(string $event, array $attrs = []): void
    {
        try {
            // The middleware passes the token explicitly on a visitor's first
            // request, before the cookie exists on the request object.
            $token = $attrs['visitor_token'] ?? request()->cookie('visitor_token');
            if (blank($token)) {
                return;
            }
            unset($attrs['visitor_token']);

            $src = \App\Support\TrafficSource::fromRequest(request());

            $row = [
                'visitor_token' => substr((string) $token, 0, 40),
                'event' => $event,
                'path' => substr((string) request()->path(), 0, 255),
                'referrer_host' => $src['referrer'],
                'source' => $src['channel'],
                'campaign' => $src['campaign'],
                'medium' => $src['medium'],
                'content' => $src['content'],
                'ad_id' => $src['ad_id'],
            ];

            // The ad columns arrived in a later migration. On a server that
            // hasn't run it yet, drop them rather than lose the whole visit —
            // a coarser row beats no traffic data at all.
            if (! static::adColumnsReady()) {
                $row = array_diff_key($row, array_flip(self::AD_COLUMNS));
            }

            static::create(array_merge($row, $attrs));
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * What we know about where a visitor came from, for stamping onto an order.
     *
     * First touch is their very first pageview; last touch is the most recent
     * visit that actually came from somewhere (a direct hit mid-session
     * shouldn't wipe out the Facebook click that started it).
     *
     * @return array{source_channel:string, source_campaign:?string, source_referrer:?string, first_touch_channel:?string, landing_path:?string}
     */
    public static function attributionFor(?string $token): array
    {
        $blank = [
            'source_channel' => 'direct', 'source_campaign' => null, 'source_referrer' => null,
            'first_touch_channel' => null, 'landing_path' => null,
        ];

        // Attribution is a nice-to-have; an order is not. If the attribution
        // columns aren't on `orders` yet — a migration that hasn't run, or ran
        // half-way — return nothing rather than handing the caller keys that
        // would make the INSERT fail and cost a sale.
        if (! static::attributionColumnsReady()) {
            return [];
        }

        if (blank($token)) {
            return $blank;
        }

        try {
            $first = static::where('visitor_token', $token)->oldest('id')->first();
            $last = static::where('visitor_token', $token)
                ->where('source', '!=', 'direct')->latest('id')->first() ?: $first;

            if (! $first) {
                return $blank;
            }

            $out = [
                'source_channel' => $last->source ?: 'direct',
                'source_campaign' => $last->campaign,
                'source_referrer' => $last->referrer_host,
                'first_touch_channel' => $first->source ?: 'direct',
                'landing_path' => $first->path,
            ];

            // Which *ad* closed the sale, when both sides of the schema are ready.
            if (static::orderAdColumnReady() && static::adColumnsReady()) {
                $out['source_content'] = $last->content;
            }

            return $out;
        } catch (\Throwable $e) {
            report($e);

            return $blank;
        }
    }

    /**
     * Does `orders` actually have the attribution columns? Cached for the
     * request — a schema lookup per order would be wasteful, and this can only
     * change on deploy.
     */
    public const READY_KEY = 'visits.attribution_ready';

    public const AD_READY_KEY = 'visits.ad_columns_ready';

    public const ORDER_AD_READY_KEY = 'orders.source_content_ready';

    protected static function attributionColumnsReady(): bool
    {
        return static::schemaFlag(self::READY_KEY, 'orders', 'source_channel');
    }

    protected static function adColumnsReady(): bool
    {
        return static::schemaFlag(self::AD_READY_KEY, 'visits', 'medium');
    }

    protected static function orderAdColumnReady(): bool
    {
        return static::schemaFlag(self::ORDER_AD_READY_KEY, 'orders', 'source_content');
    }

    /**
     * Cached on the container rather than a static, so it lasts exactly one
     * request (this can only change on deploy) and tests can set it.
     */
    protected static function schemaFlag(string $key, string $table, string $column): bool
    {
        if (! app()->bound($key)) {
            try {
                $ready = Schema::hasColumn($table, $column);
            } catch (\Throwable $e) {
                $ready = false;
            }
            app()->instance($key, $ready);
        }

        return (bool) app($key);
    }
}

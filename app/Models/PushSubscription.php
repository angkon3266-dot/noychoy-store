<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PushSubscription extends Model
{
    protected $fillable = [
        'customer_id', 'audience', 'user_id', 'endpoint', 'endpoint_hash',
        'p256dh', 'auth', 'ua', 'label', 'last_used_at',
    ];

    protected $casts = ['last_used_at' => 'datetime'];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Shopper devices — the only ones marketing sends may ever reach.
     *
     * Guarded on the column existing: before the audience migration runs there
     * are no staff devices to exclude, and a hard failure here would abort the
     * scheduler tick that also drains the queue.
     */
    public function scopeCustomers($query)
    {
        return static::hasAudienceColumn() ? $query->where('audience', '!=', 'admin') : $query;
    }

    /**
     * Staff devices opted in to operational alerts (new orders). With no
     * audience column there are no staff devices, so match nothing — never
     * fall back to "everyone", which would push order alerts to shoppers.
     */
    public function scopeAdmins($query)
    {
        return static::hasAudienceColumn() ? $query->where('audience', 'admin') : $query->whereRaw('1 = 0');
    }

    public const AUDIENCE_KEY = 'push.audience_column';

    /** Cached per request; this can only change on deploy. */
    protected static function hasAudienceColumn(): bool
    {
        if (! app()->bound(self::AUDIENCE_KEY)) {
            try {
                $has = \Illuminate\Support\Facades\Schema::hasColumn('push_subscriptions', 'audience');
            } catch (\Throwable $e) {
                $has = false;
            }
            app()->instance(self::AUDIENCE_KEY, $has);
        }

        return (bool) app(self::AUDIENCE_KEY);
    }

    public static function hashFor(string $endpoint): string
    {
        return hash('sha256', $endpoint);
    }
}

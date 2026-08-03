<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One BDCourier lookup result, kept per phone number.
 *
 * Stored in the database rather than the cache because each row cost a plan
 * credit to obtain, and `optimize:clear` on every deploy wipes the cache.
 */
class CourierCheck extends Model
{
    protected $fillable = [
        'phone', 'payload', 'success_ratio', 'total_parcel', 'reports_count', 'checked_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'success_ratio' => 'decimal:2',
        'total_parcel' => 'integer',
        'reports_count' => 'integer',
        'checked_at' => 'datetime',
    ];

    /** Same canonical 01XXXXXXXXX form as Order::customer_phone, so the two join. */
    public function setPhoneAttribute($value): void
    {
        $this->attributes['phone'] = blank($value) ? null : bd_phone((string) $value);
    }

    public function isFresh(int $hours): bool
    {
        return $this->checked_at !== null && $this->checked_at->gt(now()->subHours($hours));
    }
}

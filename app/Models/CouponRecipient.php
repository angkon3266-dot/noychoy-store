<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One phone number a coupon is waiting for.
 *
 * Deliberately a phone and not a customer_id: the point is to be able to hold a
 * coupon for someone who has never ordered — a number from a leaflet, a
 * wedding-party list, a friend of the shop — and for that person to be
 * recognised the moment they type it at checkout, without ever registering.
 *
 * Usage is not tracked here. An applied coupon is written to
 * `orders.coupon_code`, so `Coupon::customerLimitReached()` already counts
 * redemptions per phone; a second counter would only be a second thing to keep
 * in step.
 */
class CouponRecipient extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['coupon_id', 'phone', 'name'];

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }
}

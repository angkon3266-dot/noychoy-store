<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;

class Customer extends Authenticatable
{
    protected $fillable = [
        'name', 'phone', 'email', 'password', 'total_orders',
        'total_spent', 'points', 'points_lifetime', 'last_order_at', 'blacklisted', 'notes',
        'google_id', 'avatar', 'referral_code', 'referred_by', 'referral_rewarded', 'gender',
        'birthday_day', 'birthday_month', 'anniversary_day', 'anniversary_month', 'locale', 'gift_profile',
    ];

    public const GENDERS = ['male' => 'Male', 'female' => 'Female', 'other' => 'Other'];

    public const LANGUAGES = ['en' => 'English', 'bn' => 'বাংলা'];

    /** Special dates the store can remember and celebrate. */
    public const OCCASIONS = ['birthday' => 'Birthday', 'anniversary' => 'Anniversary'];

    protected $hidden = ['password', 'remember_token'];

    protected $casts = [
        'last_order_at' => 'datetime',
        'notifications_read_at' => 'datetime',
        'total_spent' => 'decimal:2',
        'points' => 'integer',
        'points_lifetime' => 'integer',
        'blacklisted' => 'boolean',
        'referral_rewarded' => 'boolean',
        'password' => 'hashed',
        'birthday_day' => 'integer',
        'birthday_month' => 'integer',
        'anniversary_day' => 'integer',
        'anniversary_month' => 'integer',
        'birthday_reminded_at' => 'datetime',
        'birthday_wished_at' => 'datetime',
        'anniversary_reminded_at' => 'datetime',
        'anniversary_wished_at' => 'datetime',
        'gift_profile' => 'array',
    ];

    /**
     * Every phone is stored canonically as 01XXXXXXXXX, whatever was typed —
     * "+880 1711-195772", "8801711195772", "1711195772" and "01711-195772" are
     * one customer. Done as a mutator so every write path is covered: checkout,
     * registration, profile edit, admin, CSV import, seeds, tinker.
     *
     * Storage stays local-format; SmsService converts to 880… at the gateway.
     */
    public function setPhoneAttribute($value): void
    {
        $this->attributes['phone'] = blank($value) ? null : bd_phone((string) $value);
    }

    public function addresses(): HasMany
    {
        return $this->hasMany(Address::class);
    }

    public function defaultAddress()
    {
        return $this->hasOne(Address::class)->where('is_default', true);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /**
     * Rebuild total_orders and total_spent from the orders that are sales.
     *
     * A cancelled or returned order brought no money in (owner, 2026-09-19:
     * cancellations must not count), and until then it stayed in both figures
     * for good — ranking Top customers, marking people "repeat" and filling
     * spend-based segments on money that never arrived. Every place that
     * recomputes the rollups (a status change into or out of those states,
     * trash, restore, merge) goes through here, so they cannot disagree.
     */
    public function recountOrders(): void
    {
        $sales = $this->orders()->whereNotIn('status', Order::NOT_SALES);

        $this->update([
            'total_orders' => (clone $sales)->count(),
            'total_spent' => (float) (clone $sales)->sum('total'),
        ]);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    public function loves(): HasMany
    {
        return $this->hasMany(ProductLove::class);
    }

    public function pointTransactions(): HasMany
    {
        return $this->hasMany(PointTransaction::class)->latest();
    }

    public function offers(): HasMany
    {
        return $this->hasMany(CustomerOffer::class)->latest();
    }

    public function segments(): BelongsToMany
    {
        return $this->belongsToMany(CustomerSegment::class, 'customer_segment_members');
    }

    /**
     * The BDCourier result stored for this customer's number, if one was ever
     * paid for — from the order page, the customer page or a "Check next 50"
     * batch. Matched on the phone itself: both tables keep the canonical
     * 01XXXXXXXXX form, and a lookup belongs to the number, not the order.
     * Reading it never spends a credit.
     */
    public function courierCheck(): HasOne
    {
        return $this->hasOne(CourierCheck::class, 'phone', 'phone');
    }

    public function genderLabel(): string
    {
        return self::GENDERS[$this->gender] ?? '—';
    }

    /** Currently usable per-customer offers. */
    public function liveOffers()
    {
        return $this->hasMany(CustomerOffer::class)->live()->latest();
    }

    /** Products this customer has loved (newest first). */
    public function lovedProducts()
    {
        return $this->belongsToMany(Product::class, 'product_loves')
            ->withTimestamps()
            ->orderByPivot('created_at', 'desc');
    }

    /** The member whose invite link this customer arrived on. */
    public function referrer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'referred_by');
    }

    /** Customers who joined on this member's invite link. */
    public function referrals(): HasMany
    {
        return $this->hasMany(Customer::class, 'referred_by');
    }

    public function firstName(): string
    {
        return str($this->name)->trim()->explode(' ')->first() ?: 'there';
    }

    /** True when a day AND month are on file for the occasion. */
    public function hasOccasion(string $occasion): bool
    {
        return (int) $this->{$occasion.'_day'} > 0 && (int) $this->{$occasion.'_month'} > 0;
    }

    /** A registered member, as opposed to a checkout-only guest row. */
    public function isMember(): bool
    {
        return filled($this->password);
    }
}

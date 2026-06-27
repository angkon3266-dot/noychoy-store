<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Str;

class Customer extends Authenticatable
{
    protected $fillable = [
        'name', 'phone', 'email', 'password', 'total_orders',
        'total_spent', 'points', 'points_lifetime', 'last_order_at', 'blacklisted', 'notes', 'woo_id',
        'google_id', 'avatar', 'referral_code', 'referred_by', 'referral_rewarded',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected $casts = [
        'last_order_at' => 'datetime',
        'total_spent' => 'decimal:2',
        'points' => 'integer',
        'points_lifetime' => 'integer',
        'blacklisted' => 'boolean',
        'referral_rewarded' => 'boolean',
        'password' => 'hashed',
    ];

    /** The customer who referred this one. */
    public function referrer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'referred_by');
    }

    /** Customers this customer has referred. */
    public function referrals(): HasMany
    {
        return $this->hasMany(Customer::class, 'referred_by');
    }

    /** Return the referral code, generating & saving a unique one if missing. */
    public function ensureReferralCode(): string
    {
        if (blank($this->referral_code)) {
            do {
                $code = strtoupper(Str::random(7));
            } while (static::where('referral_code', $code)->exists());
            $this->forceFill(['referral_code' => $code])->saveQuietly();
        }

        return $this->referral_code;
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
}

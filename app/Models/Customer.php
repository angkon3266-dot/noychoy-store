<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;

class Customer extends Authenticatable
{
    protected $fillable = [
        'name', 'phone', 'email', 'password', 'total_orders',
        'total_spent', 'points', 'points_lifetime', 'last_order_at', 'blacklisted', 'notes', 'woo_id',
        'google_id', 'avatar', 'referral_code', 'referred_by', 'referral_rewarded', 'gender',
    ];

    public const GENDERS = ['male' => 'Male', 'female' => 'Female', 'other' => 'Other'];

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
    ];

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

    public function segments(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(CustomerSegment::class, 'customer_segment_members');
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
}

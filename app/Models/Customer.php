<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;

class Customer extends Authenticatable
{
    protected $fillable = [
        'name', 'phone', 'email', 'password', 'total_orders',
        'total_spent', 'last_order_at', 'blacklisted', 'notes', 'woo_id',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected $casts = [
        'last_order_at' => 'datetime',
        'total_spent' => 'decimal:2',
        'blacklisted' => 'boolean',
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
}

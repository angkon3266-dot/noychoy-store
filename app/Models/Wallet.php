<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Store credit held by one mobile number (owner, 2026-11-03).
 *
 * Keyed on bd_phone() like BlockedPhone, so credit added for
 * "+880 1712-345678" is found by a checkout typed as "01712345678". Only
 * App\Services\WalletService moves the balance — it writes the ledger row
 * and the balance together, under a row lock.
 */
class Wallet extends Model
{
    protected $fillable = ['phone', 'balance'];

    protected $casts = [
        'balance' => 'decimal:2',
    ];

    /** Stored canonically whatever was typed. */
    public function setPhoneAttribute($value): void
    {
        $this->attributes['phone'] = bd_phone((string) $value);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(WalletTransaction::class)->latest('id');
    }

    public function customer(): ?Customer
    {
        return Customer::where('phone', $this->phone)->first();
    }
}

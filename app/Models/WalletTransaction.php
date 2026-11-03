<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WalletTransaction extends Model
{
    /** What each type means on the ledger, as the admin reads it. */
    public const TYPES = [
        'credit' => 'Added',
        'debit' => 'Taken off',
        'spend' => 'Used on order',
        'refund' => 'Returned from order',
    ];

    protected $fillable = [
        'wallet_id', 'amount', 'balance_after', 'type', 'note', 'order_id', 'user_id',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'balance_after' => 'decimal:2',
    ];

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type] ?? ucfirst($this->type);
    }
}

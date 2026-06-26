<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerOffer extends Model
{
    public const TYPES = [
        'percent' => 'Percentage discount',
        'fixed' => 'Fixed amount off',
        'free_shipping' => 'Free shipping',
        'points' => 'Bonus points',
    ];

    protected $fillable = [
        'customer_id', 'title', 'description', 'type', 'value', 'code',
        'min_subtotal', 'starts_at', 'expires_at', 'is_active', 'redeemed_at',
    ];

    protected $casts = [
        'value' => 'decimal:2',
        'min_subtotal' => 'decimal:2',
        'starts_at' => 'datetime',
        'expires_at' => 'datetime',
        'redeemed_at' => 'datetime',
        'is_active' => 'boolean',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** Live = active, started, not expired, not yet redeemed. */
    public function scopeLive(Builder $q): Builder
    {
        $now = now();

        return $q->where('is_active', true)
            ->whereNull('redeemed_at')
            ->where(fn ($w) => $w->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
            ->where(fn ($w) => $w->whereNull('expires_at')->orWhere('expires_at', '>=', $now));
    }

    public function isLive(): bool
    {
        $now = now();

        return $this->is_active
            && $this->redeemed_at === null
            && ($this->starts_at === null || $this->starts_at <= $now)
            && ($this->expires_at === null || $this->expires_at >= $now);
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }

    /** Short human value, e.g. "10% off", "৳200 off", "Free shipping", "500 points". */
    public function rewardText(): string
    {
        return match ($this->type) {
            'percent' => rtrim(rtrim(number_format((float) $this->value, 2), '0'), '.').'% off',
            'fixed' => money($this->value).' off',
            'free_shipping' => 'Free shipping',
            'points' => (int) $this->value.' bonus points',
            default => $this->title,
        };
    }
}

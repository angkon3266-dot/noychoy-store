<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class AbandonedCart extends Model
{
    protected $fillable = [
        'session_id', 'visitor_token', 'phone', 'name', 'email',
        'address', 'area', 'is_inside_dhaka', 'items',
        'subtotal', 'item_count', 'last_step', 'recovered', 'contacted',
        'last_contacted_at', 'push_reminded_at', 'sms_reminded_at',
    ];

    protected $casts = [
        'items' => 'array',
        'subtotal' => 'decimal:2',
        'item_count' => 'integer',
        'recovered' => 'boolean',
        'contacted' => 'boolean',
        'is_inside_dhaka' => 'boolean',
        'last_contacted_at' => 'datetime',
        'push_reminded_at' => 'datetime',
        'sms_reminded_at' => 'datetime',
    ];

    public function contacts(): HasMany
    {
        return $this->hasMany(AbandonedCartContact::class)->latest('id');
    }

    /**
     * The order this lead was converted into, if it was converted by hand.
     *
     * Only set by the admin "Convert to order" path. A cart that recovered on
     * its own — the customer came back and checked out — is flagged recovered
     * with nothing to point at, because only the phone number ties them.
     */
    public function recoveredOrder(): HasOne
    {
        return $this->hasOne(Order::class);
    }

    /** Still worth chasing: no order came, and nobody has tried yet. */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('recovered', false)->where('contacted', false);
    }

    /**
     * Write an admin-side status without touching `updated_at`.
     *
     * The abandoned-cart SMS runner measures its whole due window from
     * `updated_at` (`>= now()-max_hours` and `<= now()-delay`). A plain
     * save() when someone marks a lead contacted would therefore make a
     * three-week-old cart look like it was abandoned this minute, and text
     * the shopper about a cart she has long forgotten.
     */
    public function stampQuietly(array $attributes): void
    {
        $this->timestamps = false;
        $this->forceFill($attributes)->saveQuietly();
        $this->timestamps = true;
    }

    /** The customer's first name, or a form of address that is not blank. */
    public function firstName(): string
    {
        return \Illuminate\Support\Str::before(trim((string) $this->name), ' ') ?: 'there';
    }

    /** "Ring ×2, Bracelet ×1" — the snapshot, not a live catalogue read. */
    public function itemSummary(int $limit = 3): string
    {
        $lines = collect($this->items ?? []);

        $shown = $lines->take($limit)
            ->map(fn ($i) => trim(($i['name'] ?? 'Item').' ×'.($i['qty'] ?? 1)))
            ->implode(', ');

        if ($shown === '') {
            return $this->item_count.' item(s)';
        }

        return $lines->count() > $limit
            ? $shown.' and '.($lines->count() - $limit).' more'
            : $shown;
    }

    /**
     * Where this shopper was when they walked away. Only ever 'checkout'
     * today, but the column has always allowed more.
     */
    public function stepLabel(): string
    {
        return ucfirst(str_replace('_', ' ', (string) $this->last_step));
    }

    public function zoneLabel(): ?string
    {
        return $this->is_inside_dhaka === null
            ? null
            : ($this->is_inside_dhaka ? 'Inside Dhaka' : 'Outside Dhaka');
    }
}

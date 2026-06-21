<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Offer extends Model
{
    public const TYPES = [
        'order_percent' => 'Percentage discount',
        'free_shipping' => 'Free shipping',
    ];

    protected $fillable = [
        'title', 'description', 'type', 'percent', 'min_subtotal', 'min_qty',
        'members_only', 'badge_label', 'show_on_pdp', 'is_active', 'sort',
    ];

    protected $casts = [
        'percent' => 'decimal:2',
        'min_subtotal' => 'decimal:2',
        'min_qty' => 'integer',
        'members_only' => 'boolean',
        'show_on_pdp' => 'boolean',
        'is_active' => 'boolean',
        'sort' => 'integer',
    ];

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_active', true)->orderBy('sort');
    }

    /** Does this offer's conditions match the given cart state? */
    public function matches(float $subtotal, int $qty, bool $isMember): bool
    {
        if ($this->members_only && ! $isMember) {
            return false;
        }
        if ($this->min_subtotal !== null && $subtotal < (float) $this->min_subtotal) {
            return false;
        }
        if ($this->min_qty !== null && $qty < (int) $this->min_qty) {
            return false;
        }

        return true;
    }

    /** How much more the customer must spend to unlock this offer (0 if met). */
    public function remainingToUnlock(float $subtotal): float
    {
        if ($this->min_subtotal === null) {
            return 0;
        }

        return max(0, (float) $this->min_subtotal - $subtotal);
    }
}

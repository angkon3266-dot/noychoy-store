<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Order extends Model
{
    public const STATUSES = [
        'pending' => 'Pending',
        'confirmed' => 'Confirmed',
        'processing' => 'Processing',
        'shipped' => 'Shipped',
        'delivered' => 'Delivered',
        'cancelled' => 'Cancelled',
        'returned' => 'Returned',
    ];

    protected $fillable = [
        'order_number', 'customer_id', 'customer_name', 'customer_phone', 'customer_email',
        'shipping_address', 'area', 'city', 'district', 'is_inside_dhaka',
        'subtotal', 'shipping_cost', 'discount', 'total',
        'payment_method', 'payment_status', 'status', 'coupon_code',
        'notes', 'admin_notes', 'source', 'woo_id',
    ];

    protected $casts = [
        'is_inside_dhaka' => 'boolean',
        'subtotal' => 'decimal:2',
        'shipping_cost' => 'decimal:2',
        'discount' => 'decimal:2',
        'total' => 'decimal:2',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function history(): HasMany
    {
        return $this->hasMany(OrderStatusHistory::class)->latest();
    }

    public function shipment(): HasOne
    {
        return $this->hasOne(Shipment::class)->latestOfMany();
    }

    public function shipments(): HasMany
    {
        return $this->hasMany(Shipment::class);
    }

    public function scopeStatus($query, ?string $status)
    {
        return $status ? $query->where('status', $status) : $query;
    }

    public function getTotalQuantityAttribute(): int
    {
        return (int) $this->items->sum('quantity');
    }

    // ── Profitability ───────────────────────────────────────────────────────

    /** Total landed cost of goods for this order (sum of line costs). */
    public function getCostOfGoodsAttribute(): float
    {
        return round($this->items->sum(fn ($i) => $i->line_cost), 2);
    }

    /** Gross profit = revenue (subtotal − discount) − cost of goods. Shipping excluded. */
    public function getGrossProfitAttribute(): float
    {
        return round((float) $this->subtotal - (float) $this->discount - $this->cost_of_goods, 2);
    }

    public function getMarginPercentAttribute(): ?float
    {
        $revenue = (float) $this->subtotal - (float) $this->discount;
        if ($revenue <= 0) {
            return null;
        }

        return round($this->gross_profit / $revenue * 100, 1);
    }

    /** True if any item is missing cost data (margin is then an underestimate). */
    public function getHasFullCostDataAttribute(): bool
    {
        return $this->items->every(fn ($i) => $i->has_cost);
    }

    // ── Repeat-customer detection ──────────────────────────────────────────

    /** Count of this customer's OTHER orders placed before this one (by phone). */
    public function priorOrderCount(): int
    {
        return static::where('customer_phone', $this->customer_phone)
            ->where('id', '!=', $this->id)
            ->where('created_at', '<=', $this->created_at)
            ->count();
    }

    public function isRepeatCustomer(): bool
    {
        return $this->priorOrderCount() > 0;
    }

    public static function generateNumber(): string
    {
        // NOY-YYMMDD-XXXX  (sequential per day)
        $prefix = 'NOY-'.now()->format('ymd').'-';
        $last = static::where('order_number', 'like', $prefix.'%')
            ->orderByDesc('order_number')->value('order_number');
        $seq = $last ? ((int) substr($last, -4)) + 1 : 1;
        return $prefix.str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Order extends Model
{
    use SoftDeletes;

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
        'subtotal', 'shipping_cost', 'discount', 'adjustments', 'member_discount', 'total',
        'points_redeemed', 'points_discount', 'points_earned',
        'payment_method', 'payment_status', 'status', 'coupon_code',
        'notes', 'admin_notes', 'card_message', 'source', 'woo_id', 'stock_restored',
        'source_channel', 'source_campaign', 'source_referrer', 'first_touch_channel', 'landing_path',
    ];

    protected $casts = [
        'is_inside_dhaka' => 'boolean',
        'stock_restored' => 'boolean',
        'adjustments' => 'array',
        'subtotal' => 'decimal:2',
        'shipping_cost' => 'decimal:2',
        'discount' => 'decimal:2',
        'total' => 'decimal:2',
        'points_redeemed' => 'integer',
        'points_discount' => 'decimal:2',
        'member_discount' => 'decimal:2',
        'points_earned' => 'integer',
    ];

    /** Same canonical 01XXXXXXXXX form as Customer::phone, so the two always join. */
    public function setCustomerPhoneAttribute($value): void
    {
        $this->attributes['customer_phone'] = blank($value) ? null : bd_phone((string) $value);
    }

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

    /**
     * Next plain sequential order number (10001, 10002…). Legacy "NOY-…"
     * numbers are ignored by only considering numeric ones.
     *
     * withTrashed() is essential: order_number is UNIQUE, and a deleted order
     * keeps its number in that index. Reading only live rows meant a deleted
     * order's number was handed out again and every checkout died on
     * "Duplicate entry … for key orders_order_number_unique".
     *
     * $attempt lets a caller step past a number that lost a race, so a retry
     * tries a different one instead of the same one again.
     */
    public static function generateNumber(int $attempt = 0): string
    {
        $last = static::withTrashed()
            ->where('order_number', 'not like', '%-%')
            // Longest first, then highest: with zero-padded numbers this is the
            // true maximum, and it works the same on MySQL and SQLite (CAST
            // syntax does not).
            ->orderByRaw('LENGTH(order_number) DESC')
            ->orderByDesc('order_number')
            ->value('order_number');

        $next = is_numeric($last) ? ((int) $last) + 1 : 10001;
        $next = max(10001, $next) + max(0, $attempt);

        return str_pad((string) $next, 5, '0', STR_PAD_LEFT);
    }
}

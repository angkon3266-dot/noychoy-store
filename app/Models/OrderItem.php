<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderItem extends Model
{
    protected $fillable = [
        'order_id', 'product_id', 'variant_id', 'name', 'sku',
        'attributes', 'price', 'cost_price', 'transport_cost', 'quantity', 'subtotal',
    ];

    protected $casts = [
        'attributes' => 'array',
        'price' => 'decimal:2',
        'cost_price' => 'decimal:2',
        'transport_cost' => 'decimal:2',
        'subtotal' => 'decimal:2',
        'quantity' => 'integer',
    ];

    /** Total landed cost for this line (cost + transport) × quantity. */
    public function getLineCostAttribute(): float
    {
        return ((float) $this->cost_price + (float) $this->transport_cost) * $this->quantity;
    }

    /** Line profit = revenue (subtotal) − line cost. */
    public function getLineProfitAttribute(): float
    {
        return (float) $this->subtotal - $this->line_cost;
    }

    /** Whether we have any cost data recorded for this line. */
    public function getHasCostAttribute(): bool
    {
        return $this->cost_price !== null || $this->transport_cost !== null;
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }
}

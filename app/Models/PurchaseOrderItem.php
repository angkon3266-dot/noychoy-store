<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseOrderItem extends Model
{
    protected $fillable = [
        'purchase_order_id', 'product_id', 'product_name', 'sku', 'qty', 'unit_cost', 'target_price',
        'received_qty', 'product_link', 'image_url', 'color', 'size', 'attribute_names', 'variants',
    ];

    protected $casts = [
        'qty' => 'integer',
        'received_qty' => 'integer',
        'unit_cost' => 'decimal:2',
        'target_price' => 'decimal:2',
        'attribute_names' => 'array',
        'variants' => 'array',
    ];

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function lineTotal(): float
    {
        return $this->qty * (float) $this->unit_cost;
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductVariant extends Model
{
    protected $fillable = [
        'product_id', 'sku', 'attributes', 'price', 'stock_quantity',
        'image_id', 'woo_variation_id', 'is_active',
    ];

    protected $casts = [
        'attributes' => 'array',
        'price' => 'decimal:2',
        'stock_quantity' => 'integer',
        'is_active' => 'boolean',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function image(): BelongsTo
    {
        return $this->belongsTo(ProductImage::class, 'image_id');
    }

    /** Effective price: variant override, else parent product price. */
    public function getEffectivePriceAttribute(): float
    {
        return (float) ($this->price ?? $this->product?->price ?? 0);
    }

    public function getLabelAttribute(): string
    {
        return collect($this->attributes ?? [])->map(fn ($v, $k) => "$k: $v")->implode(', ');
    }
}

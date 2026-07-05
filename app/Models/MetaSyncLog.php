<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One immutable record of a Meta catalog sync attempt.
 */
class MetaSyncLog extends Model
{
    protected $fillable = [
        'product_id', 'variant_id', 'retailer_id', 'product_name',
        'action', 'status', 'retry_count', 'execution_ms',
        'meta_response', 'api_error',
    ];

    protected $casts = [
        'meta_response' => 'array',
        'retry_count' => 'integer',
        'execution_ms' => 'integer',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function scopeStatus(Builder $q, ?string $status): Builder
    {
        return $q->when($status, fn ($w) => $w->where('status', $status));
    }

    public function scopeAction(Builder $q, ?string $action): Builder
    {
        return $q->when($action, fn ($w) => $w->where('action', $action));
    }

    public function scopeSearch(Builder $q, ?string $term): Builder
    {
        return $q->when($term, fn ($w) => $w->where(fn ($x) => $x
            ->where('product_name', 'like', "%{$term}%")
            ->orWhere('retailer_id', 'like', "%{$term}%")
            ->orWhere('api_error', 'like', "%{$term}%")));
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tracks the Meta-catalog sync status of a single product or variant.
 *
 * @property string $status  never|pending|synced|failed|removed
 */
class MetaSyncState extends Model
{
    public const STATUS_NEVER = 'never';
    public const STATUS_PENDING = 'pending';
    public const STATUS_SYNCED = 'synced';
    public const STATUS_FAILED = 'failed';
    public const STATUS_REMOVED = 'removed';

    protected $fillable = [
        'product_id', 'variant_id', 'retailer_id', 'status',
        'last_synced_at', 'payload_hash', 'last_error',
    ];

    protected $casts = [
        'last_synced_at' => 'datetime',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    /** Human label for the UI. */
    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_SYNCED => 'Synced',
            self::STATUS_PENDING => 'Pending',
            self::STATUS_FAILED => 'Failed',
            self::STATUS_REMOVED => 'Removed',
            default => 'Never synced',
        };
    }
}

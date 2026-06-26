<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class PurchaseOrder extends Model
{
    public const STATUSES = [
        'pending' => 'Pending',
        'ordered' => 'Ordered',
        'shipped' => 'Shipped',
        'received' => 'Received',
        'cancelled' => 'Cancelled',
    ];

    protected $fillable = [
        'po_number', 'supplier_id', 'status', 'ordered_at', 'expected_at', 'arrived_at',
        'notes', 'courier_name', 'courier_tracking', 'courier_cost', 'processing_pct',
        'total_cost', 'currency', 'exchange_rate',
    ];

    protected $casts = [
        'ordered_at' => 'date',
        'expected_at' => 'date',
        'arrived_at' => 'date',
        'courier_cost' => 'decimal:2',
        'processing_pct' => 'decimal:2',
        'total_cost' => 'decimal:2',
        'exchange_rate' => 'decimal:4',
    ];

    protected static function booted(): void
    {
        static::creating(function (PurchaseOrder $po) {
            if (blank($po->po_number)) {
                $po->po_number = 'PO-'.now()->format('Ym').'-'.strtoupper(Str::random(4));
            }
            if (blank($po->ordered_at)) {
                $po->ordered_at = now();
            }
        });
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class);
    }

    /** Subtotal of line items (qty × unit cost), in the PO currency. */
    public function itemsSubtotal(): float
    {
        return (float) $this->items->sum(fn ($i) => $i->qty * (float) $i->unit_cost);
    }

    /** Recompute total = items + courier + processing fee. */
    public function recalculateTotal(): void
    {
        $items = $this->itemsSubtotal();
        $courier = (float) $this->courier_cost;
        $processing = ($items + $courier) * ((float) $this->processing_pct / 100);
        $this->total_cost = round($items + $courier + $processing, 2);
        $this->saveQuietly();
    }

    public function totalInBdt(): ?float
    {
        return $this->exchange_rate ? round((float) $this->total_cost * (float) $this->exchange_rate, 2) : null;
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? ucfirst($this->status);
    }
}

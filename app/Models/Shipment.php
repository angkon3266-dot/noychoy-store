<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One consignment booked with the courier for an order.
 *
 * An order can have more than one since the owner asked (2026-09-17) to be
 * able to book an order again after it changed. Only the newest is CURRENT:
 * it is what Order::shipment() returns, and the only one allowed to move the
 * order's status. Older ones are marked superseded — kept, still updated by
 * the courier's webhook so the owner can see what became of them, but never
 * allowed to flip the order (the old parcel being cancelled at Steadfast must
 * not cancel an order that has just been re-booked).
 *
 * The one exception is a DELIVERY on a replaced consignment. Nothing stops the
 * rider taking the old parcel to the door when nobody cancelled it, and then
 * that is the parcel the customer has — so it does move the order, and the row
 * is stamped delivered_after_superseded_at, which stops a later cancellation of
 * the unused newer consignment from cancelling a delivered order.
 */
class Shipment extends Model
{
    protected $fillable = [
        'order_id', 'courier', 'consignment_id', 'tracking_code', 'invoice',
        'cod_amount', 'status', 'response', 'request', 'superseded_at', 'superseded_by',
        'delivered_after_superseded_at',
    ];

    protected $casts = [
        'cod_amount' => 'decimal:2',
        'response' => 'array',
        'request' => 'array',
        'superseded_at' => 'datetime',
        'delivered_after_superseded_at' => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** The consignment that replaced this one, when it has been replaced. */
    public function supersededBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'superseded_by');
    }

    public function isSuperseded(): bool
    {
        return $this->superseded_at !== null;
    }

    /** Consignments that have not been replaced by a newer booking. */
    public function scopeCurrent(Builder $query): Builder
    {
        return $query->whereNull('superseded_at');
    }

    /**
     * The invoice this consignment is known by at Steadfast.
     *
     * Shipments booked before 2026-09-17 did not record it, and every one of
     * them went under the plain order number — the only invoice that existed.
     */
    public function bookedInvoice(): ?string
    {
        $invoice = $this->invoice ?: data_get($this->request, 'invoice');

        return filled($invoice) ? (string) $invoice : $this->order?->order_number;
    }

    /** True once the courier has reported an outcome that will not change by itself. */
    public function isSettled(): bool
    {
        $s = strtolower((string) $this->status);

        // The rider has proposed an outcome the courier has not approved yet.
        if (str_ends_with($s, 'approval_pending')) {
            return false;
        }

        return str_contains($s, 'deliver') || str_contains($s, 'cancel') || str_contains($s, 'return');
    }
}

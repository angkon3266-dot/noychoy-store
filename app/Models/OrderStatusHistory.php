<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderStatusHistory extends Model
{
    protected $table = 'order_status_history';

    protected $fillable = ['order_id', 'status', 'note', 'created_by'];

    /**
     * Human label for the timeline. "booked" used to be special-cased here
     * because it was a history-only marker; it is a real order status now, so
     * the shared list covers it and the two screens can't word it differently.
     */
    public function getLabelAttribute(): string
    {
        return Order::STATUSES[$this->status]
            ?? ucwords(str_replace('_', ' ', (string) $this->status));
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}

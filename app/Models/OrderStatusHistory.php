<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderStatusHistory extends Model
{
    protected $table = 'order_status_history';

    protected $fillable = ['order_id', 'status', 'note', 'created_by'];

    /** Human label for the timeline. Custom states map to friendlier text. */
    public function getLabelAttribute(): string
    {
        $custom = [
            'booked' => 'Booked With Courier',
        ];

        return $custom[$this->status]
            ?? Order::STATUSES[$this->status]
            ?? ucwords(str_replace('_', ' ', (string) $this->status));
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}

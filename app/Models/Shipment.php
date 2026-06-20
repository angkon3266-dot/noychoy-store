<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Shipment extends Model
{
    protected $fillable = [
        'order_id', 'courier', 'consignment_id', 'tracking_code',
        'cod_amount', 'status', 'response',
    ];

    protected $casts = [
        'cod_amount' => 'decimal:2',
        'response' => 'array',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}

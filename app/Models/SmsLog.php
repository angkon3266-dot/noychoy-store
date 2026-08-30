<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SmsLog extends Model
{
    protected $fillable = [
        'phone', 'recipients', 'message', 'direction', 'status', 'provider_status',
        'message_id', 'order_id', 'response',
    ];

    protected $casts = [
        'response' => 'array',
        'recipients' => 'integer',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AbandonedCart extends Model
{
    protected $fillable = [
        'session_id', 'phone', 'name', 'email', 'items',
        'subtotal', 'item_count', 'last_step', 'recovered', 'contacted',
    ];

    protected $casts = [
        'items' => 'array',
        'subtotal' => 'decimal:2',
        'item_count' => 'integer',
        'recovered' => 'boolean',
        'contacted' => 'boolean',
    ];
}

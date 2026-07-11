<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A cached courier fraud-check result for a phone number (aggregated across
 * Steadfast, Pathao and RedX by the azmolla/fraud-checker-bd-courier package).
 */
class FraudReport extends Model
{
    protected $fillable = [
        'phone', 'payload',
        'total_deliveries', 'total_success', 'total_cancel',
        'success_ratio', 'cancel_ratio', 'is_risky', 'checked_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'is_risky' => 'boolean',
        'success_ratio' => 'float',
        'cancel_ratio' => 'float',
        'checked_at' => 'datetime',
    ];
}

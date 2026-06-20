<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Address extends Model
{
    protected $fillable = [
        'customer_id', 'label', 'name', 'phone', 'address',
        'area', 'city', 'district', 'is_inside_dhaka', 'is_default',
    ];

    protected $casts = [
        'is_inside_dhaka' => 'boolean',
        'is_default' => 'boolean',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}

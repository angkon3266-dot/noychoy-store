<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MetaAsset extends Model
{
    protected $fillable = [
        'meta_connection_id', 'type', 'external_id', 'name', 'asset_token', 'meta', 'is_selected',
    ];

    protected $casts = [
        'asset_token' => 'encrypted',
        'meta' => 'array',
        'is_selected' => 'boolean',
    ];

    public function connection(): BelongsTo
    {
        return $this->belongsTo(MetaConnection::class, 'meta_connection_id');
    }
}

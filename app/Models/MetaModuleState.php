<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MetaModuleState extends Model
{
    protected $fillable = [
        'meta_connection_id', 'module', 'enabled', 'installed_at', 'settings',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'installed_at' => 'datetime',
        'settings' => 'array',
    ];
}

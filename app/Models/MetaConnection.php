<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A provider connection (Token Manager record). Tokens use Laravel's `encrypted`
 * cast so they are never stored in plaintext.
 *
 * @property array|null $granted_scopes
 */
class MetaConnection extends Model
{
    protected $fillable = [
        'provider', 'access_token', 'refresh_token', 'token_expires_at',
        'granted_scopes', 'business_id', 'business_name', 'health_status', 'last_health_at',
    ];

    protected $casts = [
        'access_token' => 'encrypted',
        'refresh_token' => 'encrypted',
        'granted_scopes' => 'array',
        'token_expires_at' => 'datetime',
        'last_health_at' => 'datetime',
    ];

    public function assets(): HasMany
    {
        return $this->hasMany(MetaAsset::class);
    }

    public function moduleStates(): HasMany
    {
        return $this->hasMany(MetaModuleState::class);
    }
}

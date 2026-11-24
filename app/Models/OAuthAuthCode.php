<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A one-time authorization code. Only its SHA-256 is stored. */
class OAuthAuthCode extends Model
{
    protected $table = 'oauth_auth_codes';

    protected $fillable = [
        'code_hash', 'client_id', 'user_id', 'redirect_uri', 'code_challenge', 'resource', 'expires_at', 'used_at',
    ];

    protected $casts = ['expires_at' => 'datetime', 'used_at' => 'datetime'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

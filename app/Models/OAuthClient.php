<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A client that registered itself through Dynamic Client Registration
 * (RFC 7591) — in practice claude.ai, once per connector added. Public
 * clients only: there is no secret, PKCE is what proves the caller.
 */
class OAuthClient extends Model
{
    protected $table = 'oauth_clients';

    protected $fillable = ['client_id', 'name', 'redirect_uris', 'secret_hash'];

    protected $casts = ['redirect_uris' => 'array'];

    public function allowsRedirect(string $uri): bool
    {
        return in_array($uri, (array) $this->redirect_uris, true);
    }
}

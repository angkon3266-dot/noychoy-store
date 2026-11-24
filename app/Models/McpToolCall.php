<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One tool call made by Claude through the connector — the owner's audit
 * trail (Admin → Claude connector). Pruned by `logs:prune`
 * (config/retention.php).
 */
class McpToolCall extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'api_token_id', 'user_id', 'token_name', 'tool', 'arguments', 'ok', 'error', 'duration_ms', 'ip',
    ];

    protected $casts = ['arguments' => 'array', 'ok' => 'boolean'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function token(): BelongsTo
    {
        return $this->belongsTo(ApiToken::class, 'api_token_id');
    }
}

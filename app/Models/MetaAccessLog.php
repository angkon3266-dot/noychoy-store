<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Audit record for the Meta module's secondary security gate.
 */
class MetaAccessLog extends Model
{
    protected $fillable = [
        'user_id', 'email', 'ip', 'event', 'user_agent',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Convenience recorder used by the security controller. */
    public static function record(string $event, ?\App\Models\User $user = null): void
    {
        $request = request();

        static::create([
            'user_id' => $user?->id ?? auth()->id(),
            'email' => $user?->email ?? auth()->user()?->email,
            'ip' => $request?->ip(),
            'event' => $event,
            'user_agent' => substr((string) $request?->userAgent(), 0, 255),
        ]);
    }
}

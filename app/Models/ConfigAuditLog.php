<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Fine-grained audit record for a configuration action.
 */
class ConfigAuditLog extends Model
{
    protected $fillable = [
        'user_id', 'user_name', 'ip', 'user_agent', 'action', 'section', 'detail', 'success', 'message',
    ];

    protected $casts = [
        'detail' => 'array',
        'success' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Convenience recorder — captures the current request actor automatically.
     */
    public static function record(string $action, array $data = []): self
    {
        $request = request();
        $user = auth()->user();

        return static::create(array_merge([
            'user_id' => $user?->id,
            'user_name' => $user?->name,
            'ip' => $request?->ip(),
            'user_agent' => substr((string) $request?->userAgent(), 0, 255),
            'action' => $action,
            'success' => true,
        ], $data));
    }

    public function scopeFilter(Builder $q, array $filters): Builder
    {
        return $q
            ->when($filters['user'] ?? null, fn ($w, $u) => $w->where('user_id', $u))
            ->when($filters['action'] ?? null, fn ($w, $a) => $w->where('action', $a))
            ->when($filters['section'] ?? null, fn ($w, $s) => $w->where('section', $s))
            ->when($filters['date'] ?? null, fn ($w, $d) => $w->whereDate('created_at', $d));
    }
}

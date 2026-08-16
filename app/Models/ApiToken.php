<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A bearer token for the admin API / MCP endpoint.
 *
 * The plaintext exists only in the response that creates it. Everything after
 * that compares SHA-256 digests, so the table is useless to anyone who reads
 * it — which matters on shared hosting, where "has the database" and "has the
 * filesystem" tend to be the same person.
 */
class ApiToken extends Model
{
    protected $fillable = ['name', 'token_hash', 'prefix', 'abilities', 'last_used_at', 'expires_at'];

    protected $casts = [
        'abilities' => 'array',
        'last_used_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    /** Distinguishes our tokens from anything else pasted into a Bearer header. */
    public const PREFIX = 'mec_';

    /**
     * Mint a token. Returns [model, plaintext] — the plaintext is never
     * retrievable again.
     *
     * @return array{0: static, 1: string}
     */
    public static function issue(string $name, ?array $abilities = null, ?\DateTimeInterface $expiresAt = null): array
    {
        $plain = self::PREFIX.Str::random(48);

        $token = static::create([
            'name' => $name,
            'token_hash' => hash('sha256', $plain),
            'prefix' => substr($plain, 0, 12),
            'abilities' => $abilities,
            'expires_at' => $expiresAt,
        ]);

        return [$token, $plain];
    }

    /** Resolve a presented token, or null when it is unknown or expired. */
    public static function findValid(?string $plain): ?static
    {
        if (blank($plain) || ! str_starts_with($plain, self::PREFIX)) {
            return null;
        }

        $token = static::where('token_hash', hash('sha256', $plain))->first();

        if (! $token || ($token->expires_at && $token->expires_at->isPast())) {
            return null;
        }

        return $token;
    }

    /** null abilities = full access; otherwise the ability must be listed. */
    public function can(string $ability): bool
    {
        return $this->abilities === null || in_array($ability, $this->abilities, true);
    }

    /**
     * Stamp usage, but at most once a minute: this runs on every API call and
     * a write per request would make the token table the busiest one we have.
     */
    public function touchUsage(): void
    {
        if ($this->last_used_at?->gt(now()->subMinute())) {
            return;
        }

        $this->forceFill(['last_used_at' => now()])->saveQuietly();
    }
}

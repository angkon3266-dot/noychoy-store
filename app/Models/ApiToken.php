<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
    protected $fillable = [
        'user_id', 'name', 'kind', 'client_id', 'token_hash', 'refresh_hash', 'prefix', 'abilities',
        'last_used_at', 'expires_at', 'refresh_expires_at',
    ];

    protected $casts = [
        'abilities' => 'array',
        'last_used_at' => 'datetime',
        'expires_at' => 'datetime',
        'refresh_expires_at' => 'datetime',
    ];

    /** Pasted into Claude Code / Desktop by hand. */
    public const KIND_MANUAL = 'manual';

    /** Minted by the OAuth flow (claude.ai custom connector). */
    public const KIND_OAUTH = 'oauth';

    /** The admin user this token acts as. Its role decides what it can reach. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Distinguishes our tokens from anything else pasted into a Bearer header. */
    public const PREFIX = 'mec_';

    /**
     * Mint a token. Returns [model, plaintext] — the plaintext is never
     * retrievable again.
     *
     * @return array{0: static, 1: string}
     */
    public static function issue(string $name, ?array $abilities = null, ?\DateTimeInterface $expiresAt = null, ?User $user = null, array $extra = []): array
    {
        $plain = self::newPlain();

        $token = static::create([
            'user_id' => $user?->id,
            'name' => $name,
            'token_hash' => hash('sha256', $plain),
            'prefix' => substr($plain, 0, 12),
            'abilities' => $abilities,
            'expires_at' => $expiresAt,
            ...$extra,
        ]);

        return [$token, $plain];
    }

    public static function newPlain(): string
    {
        return self::PREFIX.Str::random(48);
    }

    /**
     * Swap in a fresh access token (and refresh token) on this same row — an
     * OAuth refresh. The old pair stops working the moment this returns.
     *
     * @return array{0: string, 1: string} [access, refresh]
     */
    public function rotate(\DateTimeInterface $expiresAt, \DateTimeInterface $refreshExpiresAt): array
    {
        $access = self::newPlain();
        $refresh = 'mcr_'.Str::random(48);

        $this->forceFill([
            'token_hash' => hash('sha256', $access),
            'prefix' => substr($access, 0, 12),
            'refresh_hash' => hash('sha256', $refresh),
            'expires_at' => $expiresAt,
            'refresh_expires_at' => $refreshExpiresAt,
        ])->save();

        return [$access, $refresh];
    }

    /** Resolve a presented refresh token, or null when unknown or expired. */
    public static function findByRefresh(?string $plain): ?static
    {
        if (blank($plain)) {
            return null;
        }

        $token = static::where('refresh_hash', hash('sha256', $plain))->first();

        if (! $token || ($token->refresh_expires_at && $token->refresh_expires_at->isPast())) {
            return null;
        }

        return $token;
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

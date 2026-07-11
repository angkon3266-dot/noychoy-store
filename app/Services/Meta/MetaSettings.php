<?php

namespace App\Services\Meta;

use App\Models\Setting;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;

/**
 * Typed, cached accessor for the Meta integration configuration. All per-store
 * values live in a single encrypted-where-sensitive settings row so each client
 * enters their own credentials via the admin UI — nothing is hardcoded.
 *
 * The access token is stored via Laravel Crypt (AES-256) and never returned in
 * plaintext except to the Graph client. The secondary security password is
 * stored as a bcrypt Hash and is never reversible.
 */
class MetaSettings
{
    private const KEY = 'meta_integration';

    public const MODE_DEVELOPMENT = 'development';
    public const MODE_PRODUCTION = 'production';

    /** Default shape — keeps reads null-safe and documents every option. */
    private const DEFAULTS = [
        'enabled' => false,
        'mode' => self::MODE_DEVELOPMENT,

        // Credentials (token is stored encrypted, under `token_encrypted`).
        'business_id' => null,
        'catalog_id' => null,
        'token_encrypted' => null,
        'pixel_id' => null,

        // Conversions API (server-side). Enabled per store; the token is optional
        // — when blank the system-user token above is reused (no duplicate cred).
        'capi_enabled' => false,
        'capi_token_encrypted' => null,

        // Connection metadata (populated after a successful test / OAuth).
        'connected_business_name' => null,
        'connected_catalog_name' => null,
        'connected_since' => null,       // ISO string — first successful connect
        'token_expires_at' => null,      // ISO string or null (never = long-lived)
        'last_connection_ok' => null,    // bool
        'last_connection_at' => null,
        'last_sync_at' => null,

        // Queue + webhook operational state.
        'queue_paused' => false,
        'webhook_verified_at' => null,   // ISO string of last successful handshake
        'last_webhook_event' => null,    // ['at' => ISO, 'summary' => string]
        'sync_batch_id' => null,

        // Sync behaviour toggles.
        'auto_sync' => true,
        'sync_draft' => false,
        'sync_out_of_stock' => true,
        'sync_hidden' => false,
        'sync_images' => true,
        'sync_variations' => true,
        'sync_inventory' => true,
        'sync_price' => true,
        'sync_categories' => true,

        // Secondary security gate.
        'security_password' => null,     // bcrypt hash
        'lock_until' => null,            // ISO string while locked out
        'failed_attempts' => 0,
    ];

    private ?array $cache = null;

    /** Full settings array merged over defaults. */
    public function all(): array
    {
        if ($this->cache === null) {
            $stored = Setting::get(self::KEY, []);
            $this->cache = array_merge(self::DEFAULTS, is_array($stored) ? $stored : []);
        }

        return $this->cache;
    }

    public function get(string $key, $default = null)
    {
        return $this->all()[$key] ?? $default;
    }

    /** Persist a partial set of changes (merged over current values). */
    public function update(array $changes): void
    {
        $current = $this->all();
        Setting::put(self::KEY, array_merge($current, $changes));
        $this->cache = null;
    }

    // ── Feature flags ──────────────────────────────────────────────────────

    public function isEnabled(): bool
    {
        return (bool) $this->get('enabled');
    }

    public function mode(): string
    {
        return $this->get('mode', self::MODE_DEVELOPMENT);
    }

    public function isProduction(): bool
    {
        return $this->mode() === self::MODE_PRODUCTION;
    }

    public function autoSyncEnabled(): bool
    {
        return $this->isEnabled() && $this->isConfigured() && (bool) $this->get('auto_sync');
    }

    public function toggle(string $name): bool
    {
        return (bool) $this->get($name);
    }

    // ── Credentials ────────────────────────────────────────────────────────

    public function businessId(): ?string
    {
        return $this->get('business_id') ?: null;
    }

    public function catalogId(): ?string
    {
        return $this->get('catalog_id') ?: null;
    }

    public function pixelId(): ?string
    {
        return $this->get('pixel_id') ?: null;
    }

    /** Whether server-side Conversions API sending is turned on and usable. */
    public function capiEnabled(): bool
    {
        return (bool) $this->get('capi_enabled')
            && filled($this->pixelId())
            && filled($this->capiToken());
    }

    /**
     * The Conversions API access token, read from the database. Prefers a
     * dedicated CAPI token (Events Manager) when the merchant set one, else
     * reuses the system-user token — so no duplicate credential is required and
     * nothing lives in .env.
     */
    public function capiToken(): ?string
    {
        $enc = $this->get('capi_token_encrypted');
        if ($enc) {
            try {
                return Crypt::decryptString($enc);
            } catch (DecryptException) {
                // Fall through to the system-user token.
            }
        }

        return $this->token();
    }

    /** Store (encrypt) an optional dedicated CAPI token. Pass null/'' to clear. */
    public function setCapiToken(?string $token): void
    {
        $this->update([
            'capi_token_encrypted' => filled($token) ? Crypt::encryptString($token) : null,
        ]);
    }

    public function hasCapiToken(): bool
    {
        return filled($this->get('capi_token_encrypted'));
    }

    /** Decrypted access token, or null if none stored. */
    public function token(): ?string
    {
        $enc = $this->get('token_encrypted');
        if (! $enc) {
            return null;
        }

        try {
            return Crypt::decryptString($enc);
        } catch (DecryptException) {
            return null;
        }
    }

    /** Store (encrypt) the access token. Pass null/'' to clear it. */
    public function setToken(?string $token): void
    {
        $this->update([
            'token_encrypted' => filled($token) ? Crypt::encryptString($token) : null,
        ]);
    }

    public function hasToken(): bool
    {
        return filled($this->get('token_encrypted'));
    }

    /** All three required credentials present. */
    public function isConfigured(): bool
    {
        return filled($this->businessId())
            && filled($this->catalogId())
            && $this->hasToken();
    }

    // ── Secondary security gate ────────────────────────────────────────────

    public function hasSecurityPassword(): bool
    {
        return filled($this->get('security_password'));
    }

    public function setSecurityPassword(string $plain): void
    {
        $this->update(['security_password' => Hash::make($plain)]);
    }

    public function checkSecurityPassword(string $plain): bool
    {
        $hash = $this->get('security_password');

        return filled($hash) && Hash::check($plain, $hash);
    }

    public function isLockedOut(): bool
    {
        $until = $this->get('lock_until');

        return $until && now()->lt($until);
    }

    public function lockedUntil(): ?\Illuminate\Support\Carbon
    {
        $until = $this->get('lock_until');

        return $until ? \Illuminate\Support\Carbon::parse($until) : null;
    }

    public function registerFailedAttempt(): void
    {
        $attempts = (int) $this->get('failed_attempts') + 1;
        $max = (int) config('meta.security.max_attempts', 5);

        $changes = ['failed_attempts' => $attempts];
        if ($attempts >= $max) {
            $changes['lock_until'] = now()
                ->addMinutes((int) config('meta.security.lockout_minutes', 15))
                ->toIso8601String();
            $changes['failed_attempts'] = 0;
        }

        $this->update($changes);
    }

    public function clearFailedAttempts(): void
    {
        $this->update(['failed_attempts' => 0, 'lock_until' => null]);
    }

    public function markSyncedNow(): void
    {
        $this->update(['last_sync_at' => now()->toIso8601String()]);
    }

    /** Redacted view for logs / debugging — never leaks token or password. */
    public function safeSnapshot(): array
    {
        $all = $this->all();
        unset($all['token_encrypted'], $all['security_password'], $all['capi_token_encrypted']);
        $all['has_token'] = $this->hasToken();
        $all['has_capi_token'] = $this->hasCapiToken();

        return $all;
    }
}

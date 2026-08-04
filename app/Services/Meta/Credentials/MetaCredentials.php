<?php

namespace App\Services\Meta\Credentials;

use Illuminate\Support\Carbon;

/**
 * One store's Meta credentials, as a value object.
 *
 * The module has always had **two independent credentials** and the code kept
 * blurring them, which produced advice that sent merchants to the wrong screen:
 *
 *  1. **The connection token** — obtained by "Connect with Facebook" (OAuth) or
 *     pasted as a System User token. Drives catalog sync. Expires (~60 days) if
 *     it came from OAuth; a System User token typically reports no expiry.
 *  2. **The CAPI token** — optional. When the merchant has not set one, the
 *     Conversions API *falls back to the connection token*.
 *
 * That fallback is exactly why the two must be modelled explicitly: if CAPI is
 * running on the connection token and it fails, telling the merchant to
 * "replace the System User token" is wrong — there is no System User token to
 * replace, and the real fix is to reconnect Facebook.
 *
 * Immutable and free of I/O, so it is trivially testable and can be constructed
 * per-merchant later without touching anything that consumes it.
 */
final class MetaCredentials
{
    /** Where the connection token came from. */
    public const SOURCE_OAUTH = 'oauth';

    public const SOURCE_SYSTEM_USER = 'system_user';

    /** Where the CAPI token comes from. */
    public const CAPI_DEDICATED = 'dedicated';

    public const CAPI_INHERITED = 'inherited';

    public const CAPI_NONE = 'none';

    /** Days before expiry at which the connection is called "expiring". */
    public const EXPIRY_WARNING_DAYS = 7;

    public function __construct(
        public readonly ?string $connectionToken = null,
        public readonly ?Carbon $connectionExpiresAt = null,
        public readonly string $connectionSource = self::SOURCE_SYSTEM_USER,
        public readonly ?string $dedicatedCapiToken = null,
        public readonly ?string $pixelId = null,
        public readonly ?string $catalogId = null,
        public readonly ?string $businessId = null,
        public readonly ?Carbon $connectedAt = null,
    ) {}

    /** An empty set — nothing connected. */
    public static function none(): self
    {
        return new self;
    }

    // ── Connection (catalog sync) ───────────────────────────────────────────

    public function hasConnection(): bool
    {
        return filled($this->connectionToken);
    }

    public function isOauth(): bool
    {
        return $this->connectionSource === self::SOURCE_OAUTH;
    }

    /** missing · expired · expiring · ok */
    public function connectionHealth(int $warnDays = self::EXPIRY_WARNING_DAYS): string
    {
        if (! $this->hasConnection()) {
            return 'missing';
        }
        if ($this->connectionExpiresAt === null) {
            return 'ok';                      // no expiry reported (system user)
        }
        if ($this->connectionExpiresAt->isPast()) {
            return 'expired';
        }

        // Threshold comparison, never a diff: Carbon 3's diffInDays() is signed,
        // and comparing it against a positive number was always true for a
        // future date — which is how "expires soon" ran permanently.
        return $this->connectionExpiresAt->lte(now()->addDays($warnDays)) ? 'expiring' : 'ok';
    }

    /** Whole days until the connection expires; null when it does not expire. */
    public function connectionDaysLeft(): ?int
    {
        if ($this->connectionExpiresAt === null) {
            return null;
        }

        // round(), not floor(): a token issued for exactly 60 days is 59.9999…
        // days away the instant after it is stored, and floor() would report 59.
        // Negative means already expired.
        return (int) round(now()->floatDiffInDays($this->connectionExpiresAt, false));
    }

    // ── Conversions API ─────────────────────────────────────────────────────

    /** The token CAPI will actually send with. */
    public function capiToken(): ?string
    {
        return $this->dedicatedCapiToken ?: $this->connectionToken;
    }

    /** dedicated · inherited · none — which credential CAPI is really using. */
    public function capiSource(): string
    {
        if (filled($this->dedicatedCapiToken)) {
            return self::CAPI_DEDICATED;
        }

        return $this->hasConnection() ? self::CAPI_INHERITED : self::CAPI_NONE;
    }

    public function usesDedicatedCapiToken(): bool
    {
        return $this->capiSource() === self::CAPI_DEDICATED;
    }

    /** Plain-English description of the CAPI credential, for the dashboard. */
    public function capiSourceLabel(): string
    {
        return match ($this->capiSource()) {
            self::CAPI_DEDICATED => 'Using a dedicated System User token',
            self::CAPI_INHERITED => 'Using the '.($this->isOauth() ? 'Facebook connection' : 'System User').' token',
            default => 'No token available',
        };
    }

    // ── Advice ──────────────────────────────────────────────────────────────

    /**
     * What to tell the merchant when the CONNECTION credential fails.
     * Never mentions the CAPI token — a different credential with a different fix.
     */
    public function connectionAdvice(): string
    {
        return $this->isOauth()
            ? 'Reconnect with Facebook to renew the connection.'
            : 'Generate a new System User token and paste it under Settings.';
    }

    /**
     * What to tell the merchant when the CAPI credential fails.
     *
     * The inherited case is the one that used to give wrong advice: there is no
     * separate token to replace, so the fix is whatever fixes the connection.
     */
    public function capiAdvice(): string
    {
        return match ($this->capiSource()) {
            self::CAPI_DEDICATED => 'Replace the Conversions API token under Marketing → Meta → Tracking.',
            self::CAPI_INHERITED => 'The Conversions API is using your '
                .($this->isOauth() ? 'Facebook connection' : 'System User')
                .' token. '.$this->connectionAdvice()
                .' Or set a dedicated Conversions API token under Tracking.',
            default => 'Connect with Facebook, or set a Conversions API token under Tracking.',
        };
    }
}

<?php

namespace App\Services\Meta\Credentials;

use App\Services\Meta\MetaSettings;
use Illuminate\Support\Carbon;

/**
 * The one-store-per-install resolver — today's behaviour, made explicit.
 *
 * Reads the same `MetaSettings` row the module has always used, and hands it
 * back as a {@see MetaCredentials} value object. Behaviour is unchanged; the
 * point is that consumers now ask a *contract* rather than reaching for the
 * settings singleton themselves.
 *
 * When multi-tenancy lands, this class is not modified — a second implementation
 * is written and bound instead. That is the whole reason it is small and dumb.
 */
class SingleStoreCredentialResolver implements MetaCredentialResolver
{
    /** Per-request memo: several panels on one page ask for this. */
    private ?MetaCredentials $resolved = null;

    public function __construct(private readonly MetaSettings $settings) {}

    public function resolve(): MetaCredentials
    {
        return $this->resolved ??= new MetaCredentials(
            connectionToken: $this->settings->token(),
            connectionExpiresAt: $this->parse($this->settings->get('token_expires_at')),
            connectionSource: $this->settings->get('mode') === MetaSettings::MODE_PRODUCTION
                ? MetaCredentials::SOURCE_OAUTH
                : MetaCredentials::SOURCE_SYSTEM_USER,
            // Read the DEDICATED token only. MetaSettings::capiToken() silently
            // falls back to the connection token, which is right for sending but
            // wrong here — the whole point of this object is to keep the two
            // credentials distinguishable.
            dedicatedCapiToken: $this->settings->hasCapiToken() ? $this->settings->capiToken() : null,
            pixelId: $this->settings->pixelId(),
            catalogId: $this->settings->catalogId(),
            businessId: $this->settings->get('business_id'),
            connectedAt: $this->parse($this->settings->get('connected_since')),
        );
    }

    public function currentKey(): string
    {
        return 'default';
    }

    /** Drop the memo — for tests, and for anything that mutates settings mid-request. */
    public function forget(): void
    {
        $this->resolved = null;
    }

    private function parse(mixed $value): ?Carbon
    {
        if (blank($value)) {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}

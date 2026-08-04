<?php

namespace App\Services\Meta;

use App\Http\Controllers\Admin\MetaOAuthController;
use App\Models\MetaSyncLog;
use App\Services\Meta\Credentials\MetaCredentialResolver;
use App\Services\Meta\Credentials\MetaCredentials;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Keeps the OAuth ("Connect with Facebook") connection token alive without a
 * merchant ever having to reconnect.
 *
 * Only the OAuth-obtained long-lived USER token is in scope. A System User
 * token (Development Mode) is generated directly in the merchant's own
 * Business Settings, outside any OAuth session this app holds — there is no
 * `fb_exchange_token` equivalent for it from here, and this codebase's own
 * setup guide already tells merchants to pick "Never" expiry for it. That
 * token needs no refresh, and {@see MetaCredentials::connectionHealth()}
 * already reports it as permanently 'ok'.
 *
 * The mechanism is the same call {@see MetaOAuthController}
 * makes for the initial short-lived→long-lived exchange
 * (`grant_type=fb_exchange_token`), just re-run on the CURRENT still-valid
 * long-lived token before it expires — Meta returns a fresh token with a new
 * ~60-day clock. An already-expired token cannot be exchanged this way at
 * all ("you can not use an expired token to request a long-lived token"), so
 * that case is never attempted — it needs the merchant to reconnect, and the
 * existing 'expired' health state already surfaces that.
 */
class MetaTokenRefresher
{
    /**
     * Start attempting refresh this many days before expiry, not at the last
     * day — a token expiring in 60 days is checked daily but ignored until it
     * enters this window, then retried daily until it succeeds or expires.
     * Deliberately earlier than MetaCredentials::EXPIRY_WARNING_DAYS (7): under
     * normal operation the refresh should succeed well before the UI's
     * "expiring soon" badge would ever need to appear at all.
     */
    public const REFRESH_WINDOW_DAYS = 14;

    public function __construct(
        private readonly MetaCredentialResolver $resolver,
        private readonly MetaSettings $settings,
    ) {}

    /** Whether the current connection is close enough to expiry to attempt a refresh. */
    public function dueForRefresh(): bool
    {
        $creds = $this->resolver->resolve();

        if (! $creds->hasConnection() || ! $creds->isOauth()) {
            return false;
        }

        $days = $creds->connectionDaysLeft();
        if ($days === null) {
            return false; // no expiry reported — nothing to renew
        }

        // Strictly between "not yet due" and "already expired" — an expired
        // token cannot be exchanged, only reconnected by the merchant.
        return $days > 0 && $days <= self::REFRESH_WINDOW_DAYS;
    }

    /**
     * Attempt the refresh. Safe to call even when not due — it will simply
     * report failure without making a request, rather than assume the caller
     * already checked.
     */
    public function refresh(): bool
    {
        $creds = $this->resolver->resolve();

        if (! $creds->hasConnection() || ! $creds->isOauth()) {
            return $this->fail('Refresh attempted on a non-OAuth or disconnected store — nothing to renew.');
        }

        if ($creds->connectionHealth() === 'expired') {
            // Already past expiry: Meta will not exchange this token at all.
            // Don't burn a request finding that out again tomorrow — the
            // 'expired' health state already tells the merchant to reconnect.
            return $this->fail('Connection token has already expired; cannot be refreshed automatically. The merchant must reconnect with Facebook.');
        }

        if (blank(config('meta.oauth.app_id')) || blank(config('meta.oauth.app_secret'))) {
            return $this->fail('META_APP_ID / META_APP_SECRET are not configured — cannot refresh an OAuth token without them.');
        }

        try {
            $response = Http::acceptJson()->get($this->graph('oauth/access_token'), [
                'grant_type' => 'fb_exchange_token',
                'client_id' => config('meta.oauth.app_id'),
                'client_secret' => config('meta.oauth.app_secret'),
                'fb_exchange_token' => $creds->connectionToken,
            ]);
        } catch (\Throwable $e) {
            return $this->fail('Network error contacting Meta: '.$e->getMessage());
        }

        $newToken = $response->json('access_token');
        if ($response->failed() || blank($newToken)) {
            return $this->fail($response->json('error.message', 'Meta rejected the refresh with no error message.'));
        }

        $expiresIn = (int) $response->json('expires_in', 0);
        $this->settings->setToken($newToken);
        $this->settings->update([
            'token_expires_at' => $expiresIn > 0 ? now()->addSeconds($expiresIn)->toIso8601String() : null,
        ]);

        $this->log('success', null);
        Log::info('Meta OAuth token refreshed', ['expires_in_days' => $expiresIn > 0 ? intdiv($expiresIn, 86400) : null]);

        return true;
    }

    private function fail(string $reason): bool
    {
        $this->log('failed', $reason);
        Log::warning('Meta OAuth token refresh failed', ['reason' => $reason]);

        return false;
    }

    /** One row per attempt in the existing sync log — 'refresh' is already a listed action there. */
    private function log(string $status, ?string $error): void
    {
        try {
            MetaSyncLog::create([
                'action' => 'refresh',
                'status' => $status,
                'api_error' => $error,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Failed to write Meta token refresh log', ['error' => $e->getMessage()]);
        }
    }

    private function graph(string $path): string
    {
        return rtrim((string) config('meta.graph_url'), '/').'/'.config('meta.graph_version').'/'.ltrim($path, '/');
    }
}

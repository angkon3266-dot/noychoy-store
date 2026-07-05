<?php

namespace App\Support\Social\Contracts;

/**
 * Provider-agnostic connection/token manager. Meta implements this today;
 * Google, TikTok, LinkedIn, etc. implement the same contract later, so modules
 * never depend on a specific platform's connection class.
 */
interface SocialConnectionManager
{
    public function provider(): string;

    /** Whether a usable connection exists. */
    public function isConnected(): bool;

    /** Decrypted access token, or null. */
    public function token(): ?string;

    /** All granted permission scopes. */
    public function scopes(): array;

    public function hasScope(string $scope): bool;

    public function hasScopes(array $scopes): bool;

    /** Merge newly granted scopes into the connection. */
    public function grantScopes(array $scopes): void;

    /**
     * Assets of a given type (page|instagram|catalog|pixel|ad_account) as
     * [['id'=>..,'name'=>..], ...].
     */
    public function assets(string $type): array;

    /** Current health status (ok|expiring|expired|needs_reconnect|disconnected). */
    public function health(): string;

    /** Clear the connection (disconnect). */
    public function disconnect(): void;
}

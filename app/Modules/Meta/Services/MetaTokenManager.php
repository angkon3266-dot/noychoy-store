<?php

namespace App\Modules\Meta\Services;

use App\Models\MetaAsset;
use App\Models\MetaConnection;
use App\Support\Social\Contracts\SocialConnectionManager;

/**
 * Meta driver for the platform's Token Manager. Owns the Meta connection row +
 * its assets (catalogs, pages, IG accounts, pixels, ad accounts), granted scopes
 * and health. Implements the provider-agnostic {@see SocialConnectionManager} so
 * modules depend on the contract, not on Meta specifics.
 *
 * **Scoping.** Every lookup goes through {@see scope()}, which describes *which*
 * connection this manager speaks for. Today that is `['provider' => 'meta']` —
 * one row, the current behaviour exactly. It is a method rather than a literal
 * so multi-tenancy is a one-line change here (`+ ['merchant_id' => …]`) instead
 * of a hunt for `firstOrCreate` calls scattered through the class.
 *
 * `forStore()` already lets a caller aim this manager at a specific connection,
 * which is what a per-merchant resolver will use. Nothing calls it yet.
 */
class MetaTokenManager implements SocialConnectionManager
{
    private ?MetaConnection $connection = null;

    /** Extra scope narrowing which connection this manager speaks for. */
    private array $scopeOverride = [];

    public function provider(): string
    {
        return 'meta';
    }

    /**
     * The attributes identifying this manager's connection row.
     *
     * MULTI-TENANCY: add the merchant key here and every read/write below
     * follows automatically.
     */
    protected function scope(): array
    {
        return ['provider' => 'meta'] + $this->scopeOverride;
    }

    /**
     * Point this manager at a specific store's connection.
     *
     * Returns a fresh instance rather than mutating, so one request can safely
     * touch several stores without the memo leaking between them.
     */
    public function forStore(array $scope): static
    {
        $clone = clone $this;
        $clone->scopeOverride = $scope;
        $clone->connection = null;          // never carry another store's row over

        return $clone;
    }

    /** The Meta connection for the current scope, created on first write. */
    public function connection(): MetaConnection
    {
        return $this->connection ??= MetaConnection::firstOrCreate($this->scope());
    }

    /** Existing connection or null (read-only, no row creation). */
    public function existing(): ?MetaConnection
    {
        return $this->connection ??= MetaConnection::where($this->scope())->first();
    }

    public function isConnected(): bool
    {
        return filled($this->token());
    }

    public function token(): ?string
    {
        return $this->existing()?->access_token;
    }

    public function setToken(?string $token, ?\DateTimeInterface $expiresAt = null): void
    {
        $c = $this->connection();
        $c->access_token = $token;
        $c->token_expires_at = $expiresAt;
        $c->save();
    }

    public function scopes(): array
    {
        return $this->existing()?->granted_scopes ?? [];
    }

    public function hasScope(string $scope): bool
    {
        return in_array($scope, $this->scopes(), true);
    }

    public function hasScopes(array $scopes): bool
    {
        return empty(array_diff($scopes, $this->scopes()));
    }

    public function grantScopes(array $scopes): void
    {
        $c = $this->connection();
        $c->granted_scopes = collect($c->granted_scopes ?? [])->merge($scopes)->unique()->values()->all();
        $c->save();
    }

    // ── Assets ─────────────────────────────────────────────────────────────

    public function assets(string $type): array
    {
        return $this->existing()?->assets()->where('type', $type)->get()
            ->map(fn (MetaAsset $a) => ['id' => $a->external_id, 'name' => $a->name, 'selected' => $a->is_selected])
            ->all() ?? [];
    }

    public function selectedAsset(string $type): ?array
    {
        $asset = $this->existing()?->assets()->where('type', $type)->where('is_selected', true)->first();

        return $asset ? ['id' => $asset->external_id, 'name' => $asset->name] : null;
    }

    /** Upsert an asset; pass $selected to mark it the chosen one (unselects siblings). */
    public function putAsset(string $type, string $externalId, ?string $name = null, bool $selected = false, ?string $token = null): void
    {
        $connection = $this->connection();

        if ($selected) {
            $connection->assets()->where('type', $type)->update(['is_selected' => false]);
        }

        $connection->assets()->updateOrCreate(
            ['type' => $type, 'external_id' => $externalId],
            array_filter(['name' => $name, 'is_selected' => $selected, 'asset_token' => $token], fn ($v) => $v !== null),
        );
    }

    public function setBusiness(string $id, ?string $name = null): void
    {
        $c = $this->connection();
        $c->business_id = $id;
        $c->business_name = $name;
        $c->save();
    }

    public function businessId(): ?string
    {
        return $this->existing()?->business_id;
    }

    // ── Health ─────────────────────────────────────────────────────────────

    public function health(): string
    {
        $c = $this->existing();
        if (! $c || ! filled($c->access_token)) {
            return 'disconnected';
        }
        if ($c->token_expires_at) {
            if ($c->token_expires_at->isPast()) {
                return 'expired';
            }
            // Threshold comparison, not a diff: Carbon 3's diffInDays() is
            // signed, so a future expiry read as negative and every healthy
            // connection reported itself as "expiring".
            if ($c->token_expires_at->lte(now()->addDays(7))) {
                return 'expiring';
            }
        }

        return $c->health_status === 'needs_reconnect' ? 'needs_reconnect' : 'ok';
    }

    public function setHealth(string $status): void
    {
        $c = $this->connection();
        $c->health_status = $status;
        $c->last_health_at = now();
        $c->save();
    }

    public function disconnect(): void
    {
        $c = $this->existing();
        if (! $c) {
            return;
        }
        $c->assets()->delete();
        $c->update([
            'access_token' => null,
            'refresh_token' => null,
            'granted_scopes' => [],
            'business_id' => null,
            'business_name' => null,
            'health_status' => 'disconnected',
        ]);
    }
}

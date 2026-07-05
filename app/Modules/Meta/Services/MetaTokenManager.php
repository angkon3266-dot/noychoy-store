<?php

namespace App\Modules\Meta\Services;

use App\Models\MetaAsset;
use App\Models\MetaConnection;
use App\Support\Social\Contracts\SocialConnectionManager;

/**
 * Meta driver for the platform's Token Manager. Owns the single Meta connection
 * row + its assets (catalogs, pages, IG accounts, pixels, ad accounts), granted
 * scopes and health. Implements the provider-agnostic {@see SocialConnectionManager}
 * so modules depend on the contract, not on Meta specifics.
 */
class MetaTokenManager implements SocialConnectionManager
{
    private ?MetaConnection $connection = null;

    public function provider(): string
    {
        return 'meta';
    }

    /** The single Meta connection, created on first write. */
    public function connection(): MetaConnection
    {
        return $this->connection ??= MetaConnection::firstOrCreate(['provider' => 'meta']);
    }

    /** Existing connection or null (read-only, no row creation). */
    public function existing(): ?MetaConnection
    {
        return $this->connection ??= MetaConnection::where('provider', 'meta')->first();
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
            if ($c->token_expires_at->diffInDays(now()) <= 7) {
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

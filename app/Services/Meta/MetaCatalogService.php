<?php

namespace App\Services\Meta;

use App\Models\MetaSyncLog;
use App\Models\MetaSyncState;
use App\Models\Product;
use App\Services\Meta\Data\ConnectionStatus;
use App\Services\Meta\Exceptions\MetaApiException;
use Illuminate\Support\Facades\Log;

/**
 * Domain-level orchestration for Meta catalog sync. Uses {@see MetaGraphClient}
 * for transport and {@see MetaProductMapper} for field mapping, and owns the
 * bookkeeping (sync state + logs). Every public method is safe to call from a
 * queue job and never throws for expected API failures — it records them.
 */
class MetaCatalogService
{
    /** Attempt number of the current queue job, surfaced into the sync log. */
    private int $attempt = 0;

    public function __construct(
        private readonly MetaSettings $settings,
        private readonly MetaGraphClient $client,
        private readonly MetaProductMapper $mapper,
    ) {}

    /** Called by the queue job so logs record which retry produced the outcome. */
    public function withAttempt(int $attempt): self
    {
        $this->attempt = max(0, $attempt - 1);

        return $this;
    }

    // ── Test connection ────────────────────────────────────────────────────

    /**
     * Verify token validity, business access, catalog access, permissions and
     * Graph connectivity. Never throws — returns a structured verdict.
     */
    public function testConnection(): ConnectionStatus
    {
        $checks = [];

        if (! $this->settings->isConfigured()) {
            return ConnectionStatus::failed('connection_failed', 'Enter Business ID, Catalog ID and an access token first.');
        }

        try {
            // 1. Token validity + expiry + scopes.
            $debug = $this->client->debugToken();
            $checks[] = ['label' => 'Graph API connectivity', 'ok' => true, 'detail' => null];

            if (isset($debug['is_valid']) && $debug['is_valid'] === false) {
                $checks[] = ['label' => 'Token validity', 'ok' => false, 'detail' => 'Token is not valid.'];

                return ConnectionStatus::failed('invalid_token', '❌ Invalid Token', $checks);
            }
            $checks[] = ['label' => 'Token validity', 'ok' => true, 'detail' => null];

            $expiresAt = (int) ($debug['expires_at'] ?? 0);
            if ($expiresAt !== 0 && $expiresAt < time()) {
                $checks[] = ['label' => 'Token expiry', 'ok' => false, 'detail' => 'Token expired.'];

                return ConnectionStatus::failed('token_expired', '❌ Token Expired', $checks);
            }
            $checks[] = ['label' => 'Token expiry', 'ok' => true, 'detail' => $expiresAt === 0 ? 'Never expires (long-lived)' : 'Valid'];

            // 2. Required permissions.
            $scopes = $debug['scopes'] ?? $this->client->grantedScopes();
            $required = ['catalog_management'];
            $missing = array_diff($required, $scopes);
            if ($missing) {
                $checks[] = ['label' => 'Required permissions', 'ok' => false, 'detail' => 'Missing: '.implode(', ', $missing)];

                return ConnectionStatus::failed('missing_permission', '❌ Missing Permission ('.implode(', ', $missing).')', $checks);
            }
            $checks[] = ['label' => 'Required permissions', 'ok' => true, 'detail' => implode(', ', $scopes) ?: null];

            // 3. Business access.
            $business = null;
            try {
                $business = $this->client->business($this->settings->businessId());
                $checks[] = ['label' => 'Business access', 'ok' => true, 'detail' => $business['name'] ?? null];
            } catch (MetaApiException $e) {
                $checks[] = ['label' => 'Business access', 'ok' => false, 'detail' => $e->getMessage()];

                return ConnectionStatus::failed('missing_permission', '❌ Missing Permission (business)', $checks);
            }

            // 4. Catalog access.
            try {
                $catalog = $this->client->catalog($this->settings->catalogId());
                $checks[] = ['label' => 'Catalog access', 'ok' => true, 'detail' => $catalog['name'] ?? null];
            } catch (MetaApiException $e) {
                $checks[] = ['label' => 'Catalog access', 'ok' => false, 'detail' => $e->getMessage()];

                return ConnectionStatus::failed('catalog_not_found', '❌ Catalog Not Found', $checks);
            }

            // Persist connection metadata.
            $this->settings->update([
                'connected_business_name' => $business['name'] ?? null,
                'connected_catalog_name' => $catalog['name'] ?? null,
                'connected_since' => $this->settings->get('connected_since') ?? now()->toIso8601String(),
                'token_expires_at' => $expiresAt === 0 ? null : date('c', $expiresAt),
                'last_connection_ok' => true,
                'last_connection_at' => now()->toIso8601String(),
            ]);

            return ConnectionStatus::connected(
                '✅ Connected',
                $checks,
                $business['name'] ?? null,
                $catalog['name'] ?? null,
                isset($catalog['product_count']) ? (int) $catalog['product_count'] : null,
                $scopes,
            );
        } catch (MetaApiException $e) {
            $this->settings->update(['last_connection_ok' => false, 'last_connection_at' => now()->toIso8601String()]);

            return ConnectionStatus::failed(
                match ($e->category) {
                    MetaApiException::TOKEN_INVALID => 'invalid_token',
                    MetaApiException::TOKEN_EXPIRED => 'token_expired',
                    MetaApiException::PERMISSION => 'missing_permission',
                    MetaApiException::CATALOG => 'catalog_not_found',
                    default => 'connection_failed',
                },
                '❌ '.$e->getMessage(),
                $checks,
            );
        }
    }

    // ── Sync a single product ──────────────────────────────────────────────

    /** Whether a product is eligible to appear in the catalog given the toggles. */
    public function shouldSync(Product $product): bool
    {
        if (! $this->settings->isEnabled() || ! $this->settings->isConfigured()) {
            return false;
        }

        if ($product->trashed()) {
            return false;
        }

        if ($product->status !== 'published' && ! $this->settings->toggle('sync_draft')) {
            return false;
        }

        $outOfStock = $product->manage_stock ? $product->stock_quantity <= 0 : ! $product->in_stock;
        if ($outOfStock && ! $this->settings->toggle('sync_out_of_stock')) {
            return false;
        }

        return true;
    }

    /**
     * Push a product (and its variants) to the catalog, or remove it if it is
     * no longer eligible. Records state + a log row. Throws only on retryable
     * transport errors so the queue can retry; records terminal errors.
     *
     * @throws MetaApiException on retryable failures (rate limit / network)
     */
    public function syncProduct(Product $product, string $action = 'update', bool $force = false): void
    {
        $started = microtime(true);

        // Not eligible → ensure it is removed from the catalog.
        if (! $this->shouldSync($product)) {
            $this->removeProduct($product, 'update');

            return;
        }

        $items = $this->mapper->items($product);
        $hash = hash('sha256', json_encode($items));

        // Skip if nothing changed since the last successful sync.
        if (! $force && $this->isUpToDate($product, $hash)) {
            $this->log($product, $action, 'skipped', $started, null, null);

            return;
        }

        $requests = array_map(fn ($item) => [
            'method' => 'UPDATE',
            'retailer_id' => $item['retailer_id'],
            'data' => $item['data'],
        ], $items);

        try {
            $response = $this->client->itemsBatch($this->settings->catalogId(), $requests);
        } catch (MetaApiException $e) {
            $this->markFailed($product, $e->getMessage());
            $this->log($product, $action, 'failed', $started, null, $e->getMessage());

            if ($e->isRetryable()) {
                throw $e; // let the job retry
            }

            return; // terminal error — recorded, do not crash
        }

        $this->markSynced($product, $items, $hash);
        $this->settings->markSyncedNow();
        $this->log($product, $action, 'success', $started, $response, null);
    }

    /**
     * Sync a whole chunk of products in ONE items_batch call — create/update the
     * eligible ones and delete the no-longer-eligible ones together. This is the
     * bulk path ("Sync all" / "Full refresh"): far fewer Graph round-trips than
     * one call per product, while preserving the exact same per-product state,
     * hashing/skip and logging behaviour as {@see syncProduct()}.
     *
     * Throws only when the single batch call hits a *retryable* transport error,
     * so the queue can retry the whole chunk. Terminal errors are recorded.
     *
     * @param  array<int,int>  $productIds
     * @throws MetaApiException on retryable failures (rate limit / network)
     */
    public function syncChunk(array $productIds, bool $force = false): void
    {
        if (empty($productIds) || ! $this->settings->isConfigured()) {
            return;
        }

        $started = microtime(true);

        $products = Product::withTrashed()
            ->with(['images', 'variants', 'category'])
            ->whereIn('id', $productIds)
            ->get();

        $requests = [];          // combined UPDATE + DELETE requests for one call
        $toMarkSynced = [];      // [product_id => ['product'=>P, 'items'=>[], 'hash'=>h]]
        $toMarkRemoved = [];     // [product_id => P]

        // Pre-load existing sync states for the whole chunk (avoids N queries).
        $statesByProduct = MetaSyncState::whereIn('product_id', $products->pluck('id'))
            ->where('status', '!=', MetaSyncState::STATUS_REMOVED)
            ->get()
            ->groupBy('product_id');

        foreach ($products as $product) {
            if ($this->shouldSync($product)) {
                $items = $this->mapper->items($product);
                $hash = hash('sha256', json_encode($items));

                if (! $force && $this->isUpToDate($product, $hash)) {
                    $this->log($product, 'sync_all', 'skipped', $started, null, null);

                    continue;
                }

                foreach ($items as $item) {
                    $requests[] = [
                        'method' => 'UPDATE',
                        'retailer_id' => $item['retailer_id'],
                        'data' => $item['data'],
                    ];
                }

                $toMarkSynced[$product->id] = ['product' => $product, 'items' => $items, 'hash' => $hash];
            } else {
                // No longer eligible → delete whatever we previously synced.
                $states = $statesByProduct->get($product->id, collect());
                if ($states->isEmpty()) {
                    continue;
                }

                foreach ($states as $state) {
                    $requests[] = ['method' => 'DELETE', 'retailer_id' => $state->retailer_id];
                }

                $toMarkRemoved[$product->id] = $product;
            }
        }

        if (empty($requests)) {
            return; // everything was already up to date / nothing to remove
        }

        try {
            $response = $this->client->itemsBatch($this->settings->catalogId(), $requests);
        } catch (MetaApiException $e) {
            // Record the failure against every product in the chunk.
            foreach ($toMarkSynced as $entry) {
                $this->markFailed($entry['product'], $e->getMessage());
                $this->log($entry['product'], 'sync_all', 'failed', $started, null, $e->getMessage());
            }
            foreach ($toMarkRemoved as $product) {
                $this->log($product, 'delete', 'failed', $started, null, $e->getMessage());
            }

            if ($e->isRetryable()) {
                throw $e; // let the chunk job retry the whole batch
            }

            return; // terminal error — recorded, do not crash the worker
        }

        // Success — persist per-product state + logs.
        foreach ($toMarkSynced as $entry) {
            $this->markSynced($entry['product'], $entry['items'], $entry['hash']);
            $this->log($entry['product'], 'sync_all', 'success', $started, $response, null);
        }

        foreach ($toMarkRemoved as $product) {
            MetaSyncState::where('product_id', $product->id)
                ->where('status', '!=', MetaSyncState::STATUS_REMOVED)
                ->update(['status' => MetaSyncState::STATUS_REMOVED, 'last_error' => null]);
            $this->log($product, 'delete', 'success', $started, $response, null);
        }

        if (! empty($toMarkSynced)) {
            $this->settings->markSyncedNow();
        }
    }

    /** Remove a product's items from the catalog. */
    public function removeProduct(Product $product, string $action = 'delete'): void
    {
        $started = microtime(true);

        $states = MetaSyncState::where('product_id', $product->id)
            ->where('status', '!=', MetaSyncState::STATUS_REMOVED)
            ->get();

        if ($states->isEmpty()) {
            return; // nothing was ever synced
        }

        if (! $this->settings->isConfigured()) {
            return;
        }

        $requests = $states->map(fn ($s) => [
            'method' => 'DELETE',
            'retailer_id' => $s->retailer_id,
        ])->all();

        try {
            $response = $this->client->itemsBatch($this->settings->catalogId(), $requests);
        } catch (MetaApiException $e) {
            $this->log($product, $action, 'failed', $started, null, $e->getMessage());
            if ($e->isRetryable()) {
                throw $e;
            }

            return;
        }

        $states->each->update(['status' => MetaSyncState::STATUS_REMOVED, 'last_error' => null]);
        $this->log($product, $action, 'success', $started, $response, null);
    }

    // ── Bulk / verification ────────────────────────────────────────────────

    /** Query builder for every product eligible for the catalog under current toggles. */
    public function eligibleQuery()
    {
        return Product::query()
            ->when(! $this->settings->toggle('sync_draft'), fn ($q) => $q->where('status', 'published'))
            ->orderBy('id');
    }

    /** Remote product count reported by Meta, or null if unavailable. */
    public function remoteProductCount(): ?int
    {
        try {
            $catalog = $this->client->catalog($this->settings->catalogId());

            return isset($catalog['product_count']) ? (int) $catalog['product_count'] : null;
        } catch (MetaApiException) {
            return null;
        }
    }

    // ── State + logging helpers ────────────────────────────────────────────

    private function isUpToDate(Product $product, string $hash): bool
    {
        $states = MetaSyncState::where('product_id', $product->id)->get();
        if ($states->isEmpty()) {
            return false;
        }

        return $states->every(fn ($s) => $s->status === MetaSyncState::STATUS_SYNCED && $s->payload_hash === $hash);
    }

    /** @param array<int,array{retailer_id:string,data:array}> $items */
    private function markSynced(Product $product, array $items, string $hash): void
    {
        $keep = [];
        foreach ($items as $item) {
            $variantId = $this->variantIdFromRetailer($item['retailer_id']);
            $keep[] = $item['retailer_id'];

            MetaSyncState::updateOrCreate(
                ['product_id' => $product->id, 'variant_id' => $variantId],
                [
                    'retailer_id' => $item['retailer_id'],
                    'status' => MetaSyncState::STATUS_SYNCED,
                    'last_synced_at' => now(),
                    'payload_hash' => $hash,
                    'last_error' => null,
                ],
            );
        }

        // Any stale rows (e.g. a variant that was removed) → mark removed.
        MetaSyncState::where('product_id', $product->id)
            ->whereNotIn('retailer_id', $keep)
            ->update(['status' => MetaSyncState::STATUS_REMOVED]);
    }

    private function markFailed(Product $product, string $error): void
    {
        MetaSyncState::updateOrCreate(
            ['product_id' => $product->id, 'variant_id' => null],
            [
                'retailer_id' => $this->mapper->retailerId($product),
                'status' => MetaSyncState::STATUS_FAILED,
                'last_error' => \Illuminate\Support\Str::limit($error, 500),
            ],
        );
    }

    private function variantIdFromRetailer(string $retailerId): ?int
    {
        return preg_match('/-var-(\d+)$/', $retailerId, $m) ? (int) $m[1] : null;
    }

    private function log(Product $product, string $action, string $status, float $started, ?array $response, ?string $error): void
    {
        try {
            MetaSyncLog::create([
                'product_id' => $product->exists ? $product->id : null,
                'retailer_id' => $this->mapper->retailerId($product),
                'product_name' => $product->name,
                'action' => $action,
                'status' => $status,
                'retry_count' => $this->attempt,
                'execution_ms' => (int) round((microtime(true) - $started) * 1000),
                'meta_response' => $response,
                'api_error' => $error,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Failed to write Meta sync log', ['error' => $e->getMessage()]);
        }
    }
}

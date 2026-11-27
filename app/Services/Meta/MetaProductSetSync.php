<?php

namespace App\Services\Meta;

use App\Models\Collection;
use App\Models\Product;
use App\Services\CollectionService;
use App\Services\Meta\Exceptions\MetaApiException;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Mirrors each collection into the Meta catalogue as a product set of the same
 * name, so it can be picked under "Product set" in a carousel or catalogue ad.
 * Meta's ads only take product sets; Shopify's Facebook channel creates one per
 * collection quietly, which is why Shopify collections "just appear" there.
 *
 * The set filters on retailer_id: the exact items this collection shows today,
 * using the same ids the CSV feed writes (meta_content_id — variant rows for a
 * product with variants, since the feed has no parent row for those). A smart
 * collection's matches move as products change, so the hourly
 * `meta:sync-collections` re-sends any set whose list changed; an unchanged set
 * never reaches the API (meta_set_hash).
 *
 * A collection that is inactive, switched off for Meta, or empty has no set:
 * one it had is deleted, so a stale set never sits in the ad's dropdown. Sets
 * the owner built by hand in Commerce Manager are never touched — only ids
 * stored on a collection are ever updated or deleted.
 */
class MetaProductSetSync
{
    public function __construct(
        private readonly MetaSettings $settings,
        private readonly MetaGraphClient $client,
        private readonly CollectionService $collections,
    ) {}

    /** The integration is on and has a catalogue and token to write with. */
    public function available(): bool
    {
        return $this->settings->isEnabled() && $this->settings->isConfigured();
    }

    /**
     * Bring one collection's set in line with the collection.
     *
     * @return string created|updated|unchanged|removed|absent|skipped|failed
     */
    public function sync(Collection $collection, bool $force = false): string
    {
        if (! $this->available()) {
            return 'skipped';
        }

        // The save's after-response sync and the hourly run could otherwise
        // both see "no set yet" and create two.
        try {
            return Cache::lock('meta-product-set-'.$collection->id, 60)->block(20, fn () => $this->locked($collection, $force));
        } catch (LockTimeoutException) {
            return 'skipped'; // the other run has it; the hourly pass catches up
        }
    }

    private function locked(Collection $collection, bool $force): string
    {
        $collection->refresh();

        try {
            return $this->apply($collection, $force);
        } catch (MetaApiException $e) {
            $collection->forceFill(['meta_sync_error' => Str::limit($e->getMessage(), 480)])->saveQuietly();
            // Production logs at error only (LOG_LEVEL=error), so a warning
            // here would vanish.
            Log::error('Meta product set sync failed', [
                'collection' => $collection->id,
                'set' => $collection->meta_product_set_id,
                'error' => $e->getMessage(),
            ]);

            return 'failed';
        }
    }

    /** @return array<string,int> outcome => how many collections */
    public function syncAll(bool $force = false): array
    {
        $tally = [];
        foreach (Collection::orderBy('id')->get() as $collection) {
            $outcome = $this->sync($collection, $force);
            $tally[$outcome] = ($tally[$outcome] ?? 0) + 1;
        }

        return $tally;
    }

    /** Delete a set by id — for a collection that no longer exists. */
    public function deleteSet(string $setId): void
    {
        if (! $this->available()) {
            return;
        }

        try {
            $this->delete($setId);
        } catch (MetaApiException $e) {
            Log::error('Meta product set delete failed', ['set' => $setId, 'error' => $e->getMessage()]);
        }
    }

    /**
     * The catalogue ids this collection shows right now, sorted so the same
     * list always hashes the same.
     *
     * @return list<string>
     */
    public function retailerIds(Collection $collection): array
    {
        $ids = [];
        $this->collections->query($collection)->with('variants')->get()
            ->each(function (Product $p) use (&$ids) {
                if ($p->has_variants && $p->variants->isNotEmpty()) {
                    foreach ($p->variants as $v) {
                        $ids[] = meta_content_id($p, $v);
                    }
                } else {
                    $ids[] = meta_content_id($p);
                }
            });

        $ids = array_values(array_unique($ids));
        sort($ids);

        return $ids;
    }

    private function apply(Collection $collection, bool $force): string
    {
        $ids = ($collection->meta_sync && $collection->is_active) ? $this->retailerIds($collection) : [];

        if ($ids === []) {
            if (! $collection->meta_product_set_id) {
                if ($collection->meta_sync_error) {
                    $this->record($collection, null, null, null);
                }

                return 'absent';
            }

            $this->delete($collection->meta_product_set_id);
            $this->record($collection, null, null, null);

            return 'removed';
        }

        $name = Str::limit(trim($collection->name), 120, '');
        $filter = json_encode(['retailer_id' => ['is_any' => $ids]], JSON_UNESCAPED_SLASHES);
        $hash = hash('sha256', $name."\n".$filter);

        if (! $force && $collection->meta_product_set_id && $collection->meta_set_hash === $hash) {
            return 'unchanged';
        }

        if ($collection->meta_product_set_id) {
            try {
                $this->client->request('POST', $collection->meta_product_set_id, ['name' => $name, 'filter' => $filter]);
                $this->record($collection, $collection->meta_product_set_id, $hash, count($ids));

                return 'updated';
            } catch (MetaApiException $e) {
                // Deleted in Commerce Manager: make a fresh one below.
                if (! $this->isMissing($e)) {
                    throw $e;
                }
            }
        }

        $created = $this->client->request('POST', $this->settings->catalogId().'/product_sets', [
            'name' => $name,
            'filter' => $filter,
        ]);
        $setId = (string) ($created['id'] ?? '');
        if ($setId === '') {
            throw new MetaApiException('Meta created the product set but returned no id.', MetaApiException::UNKNOWN, $created);
        }

        $this->record($collection, $setId, $hash, count($ids));

        return 'created';
    }

    private function delete(string $setId): void
    {
        try {
            $this->client->request('DELETE', $setId);
        } catch (MetaApiException $e) {
            // Already gone is what we wanted.
            if (! $this->isMissing($e)) {
                throw $e;
            }
        }
    }

    /** Graph's "object does not exist" (code 100, subcode 33). */
    private function isMissing(MetaApiException $e): bool
    {
        return (int) ($e->meta['code'] ?? 0) === 100 && (int) ($e->meta['error_subcode'] ?? 0) === 33;
    }

    private function record(Collection $collection, ?string $setId, ?string $hash, ?int $count): void
    {
        $collection->forceFill([
            'meta_product_set_id' => $setId,
            'meta_set_hash' => $hash,
            'meta_set_count' => $count,
            'meta_synced_at' => now(),
            'meta_sync_error' => null,
        ])->saveQuietly();
    }
}

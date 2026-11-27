<?php

namespace App\Jobs\Meta;

use App\Models\Collection;
use App\Services\Meta\MetaProductSetSync;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * Mirror one collection to its Meta product set right after the admin's save
 * (dispatchAfterResponse: no queue worker needed, the page is not held up).
 * Given a set id for a collection that has just been deleted, removes that set.
 * Anything this misses, the hourly meta:sync-collections catches.
 */
class SyncCollectionToMeta
{
    use Dispatchable;

    public function __construct(
        public ?int $collectionId,
        public ?string $deletedSetId = null,
    ) {}

    public function handle(MetaProductSetSync $sync): void
    {
        if ($this->deletedSetId) {
            $sync->deleteSet($this->deletedSetId);

            return;
        }

        if ($collection = Collection::find($this->collectionId)) {
            $sync->sync($collection);
        }
    }
}

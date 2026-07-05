<?php

namespace App\Listeners;

use App\Events\ConfigurationRestored;
use App\Jobs\RebuildConfigCache;

/**
 * On restore/import, queue a config-cache rebuild so the restored values take
 * effect on subsequent requests.
 */
class RebuildConfigurationCache
{
    public function handle(ConfigurationRestored $event): void
    {
        RebuildConfigCache::dispatch();
    }
}

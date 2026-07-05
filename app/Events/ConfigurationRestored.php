<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired after a backup/import is restored, so caches can be rebuilt.
 */
class ConfigurationRestored
{
    use Dispatchable;

    public function __construct(
        public string $source, // backup|import
        public int $count = 0,
    ) {}
}

<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired after a configuration section is successfully saved.
 */
class ConfigurationChanged
{
    use Dispatchable;

    /** @param array<int,string> $keys changed field keys */
    public function __construct(
        public string $section,
        public array $keys = [],
    ) {}
}

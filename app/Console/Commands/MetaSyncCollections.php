<?php

namespace App\Console\Commands;

use App\Services\Meta\MetaProductSetSync;
use Illuminate\Console\Command;

/**
 * Mirror every collection into the Meta catalogue as a product set. Runs
 * hourly, because a smart collection's products move as products change
 * without the collection itself being saved; sets whose list did not change
 * never reach the API.
 */
class MetaSyncCollections extends Command
{
    protected $signature = 'meta:sync-collections {--force : Re-send every set even if nothing changed}';

    protected $description = 'Create, update or remove the Meta product set for each collection';

    public function handle(MetaProductSetSync $sync): int
    {
        if (! $sync->available()) {
            $this->warn('Meta is not connected (needs the integration on, a catalogue and a token).');

            return self::SUCCESS;
        }

        $tally = $sync->syncAll((bool) $this->option('force'));
        $this->info(collect($tally)->map(fn ($n, $k) => "{$k}: {$n}")->implode(', ') ?: 'No collections.');

        return ($tally['failed'] ?? 0) > 0 ? self::FAILURE : self::SUCCESS;
    }
}

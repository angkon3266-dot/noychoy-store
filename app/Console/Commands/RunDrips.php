<?php

namespace App\Console\Commands;

use App\Services\DripService;
use Illuminate\Console\Command;

class RunDrips extends Command
{
    protected $signature = 'push:drip';

    protected $description = 'Send any due steps of scheduled drip campaigns.';

    public function handle(DripService $drips): int
    {
        $sent = $drips->sendDue();
        $this->info("Drip campaigns: {$sent} step(s) sent.");

        return self::SUCCESS;
    }
}

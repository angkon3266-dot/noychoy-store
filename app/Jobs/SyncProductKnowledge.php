<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;

/**
 * Refresh one product's knowledge markdown after a save (queued so admin
 * saves stay instant). Fired from the Product model's saved hook.
 */
class SyncProductKnowledge implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(public int $productId) {}

    public function handle(): void
    {
        try {
            Artisan::call('knowledge:sync', ['--product' => $this->productId]);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}

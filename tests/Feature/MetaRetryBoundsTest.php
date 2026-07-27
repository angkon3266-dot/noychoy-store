<?php

namespace Tests\Feature;

use App\Jobs\Meta\RetryFailedMetaSyncs;
use App\Jobs\Meta\SyncProductToMeta;
use App\Models\MetaSyncState;
use App\Models\Product;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The hourly retry used to re-queue every failed product forever. When Meta
 * rate-limits the catalogue (#80014) the retry fails again and is retried next
 * hour — one meta_sync_logs row per product per hour, indefinitely. Retrying
 * has to give up on failures that clearly aren't transient.
 */
class MetaRetryBoundsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // autoSyncEnabled() requires the module on and configured.
        Setting::put('meta', ['enabled' => true]);
        config(['meta.enabled' => true]);
    }

    protected function failedState(int $productId, string $updatedAt): void
    {
        $product = Product::create([
            'name' => 'P'.$productId, 'slug' => 'p-'.$productId, 'status' => 'published', 'price' => 100,
        ]);

        MetaSyncState::create([
            'product_id' => $product->id,
            'retailer_id' => 'prod-'.$product->id,
            'status' => MetaSyncState::STATUS_FAILED,
            'last_error' => '(#80014) too many calls for the batch uploads to this catalog account',
        ]);

        // created_at/updated_at are managed, so set the age explicitly.
        MetaSyncState::where('product_id', $product->id)->update(['updated_at' => $updatedAt]);
    }

    public function test_a_stale_failure_is_not_retried_forever(): void
    {
        Queue::fake();

        $this->failedState(1, now()->subHours(2)->toDateTimeString());     // recent
        $this->failedState(2, now()->subDays(5)->toDateTimeString());      // long dead

        app(RetryFailedMetaSyncs::class)->handle(app(\App\Services\Meta\MetaSettings::class));

        // Only the recent one is worth another attempt (or none at all when the
        // module is off — either way the stale one must never be queued).
        Queue::assertNotPushed(SyncProductToMeta::class, function ($job) {
            return $job->productId === 2;
        });
    }

    public function test_the_batch_is_capped(): void
    {
        $this->assertSame(100, RetryFailedMetaSyncs::MAX_PER_RUN);
        $this->assertSame(24, RetryFailedMetaSyncs::GIVE_UP_AFTER_HOURS);
    }

    public function test_nothing_is_queued_when_auto_sync_is_off(): void
    {
        Queue::fake();
        Setting::put('meta', ['enabled' => false]);
        config(['meta.enabled' => false]);

        $this->failedState(3, now()->subHour()->toDateTimeString());

        app(RetryFailedMetaSyncs::class)->handle(app(\App\Services\Meta\MetaSettings::class));

        Queue::assertNothingPushed();
    }
}

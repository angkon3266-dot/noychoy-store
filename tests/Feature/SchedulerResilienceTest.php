<?php

namespace Tests\Feature;

use App\Models\CustomerNotification;
use App\Models\PushSubscription;
use App\Models\Setting;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * schedule:run executes closure tasks in its own process, so an exception in
 * one aborts the whole tick — including the every-minute queue drain that
 * delivers order SMS, invoices and staff alerts. Nothing scheduled may throw.
 */
class SchedulerResilienceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Setting::put('webpush_enabled', true);
        Setting::put('webpush_public_key', str_repeat('a', 87));
        Setting::put('webpush_private_key', str_repeat('b', 43));
    }

    protected function dueNotification(): CustomerNotification
    {
        return CustomerNotification::create([
            'type' => 'update', 'audience' => 'all', 'title' => 'Scheduled', 'body' => 'Hello',
            'scheduled_at' => now()->subMinute(),
        ]);
    }

    public function test_delivery_survives_a_database_without_the_audience_column(): void
    {
        Queue::fake();
        $this->dueNotification();

        // A server whose audience migration hasn't run yet.
        app()->instance(PushSubscription::AUDIENCE_KEY, false);

        // Must not throw — this is what took the scheduler down.
        app(NotificationService::class)->deliverDue();

        $this->assertNotNull(CustomerNotification::first()->sent_at);
    }

    public function test_order_alerts_reach_nobody_rather_than_everybody_without_the_column(): void
    {
        Queue::fake();
        app()->instance(PushSubscription::AUDIENCE_KEY, false);

        $endpoint = 'https://push.example/shopper';
        PushSubscription::create([
            'endpoint' => $endpoint, 'endpoint_hash' => PushSubscription::hashFor($endpoint),
            'p256dh' => 'k', 'auth' => 'a',
        ]);

        // Without the column we cannot tell staff from shoppers, so an order
        // alert must go nowhere — never to every customer's phone.
        $this->assertSame(0, PushSubscription::admins()->count());
    }

    public function test_one_broken_notification_does_not_stop_the_rest(): void
    {
        Queue::fake();
        $this->dueNotification();
        $this->dueNotification();

        $sent = app(NotificationService::class)->deliverDue();

        $this->assertSame(2, $sent);
        $this->assertSame(0, CustomerNotification::whereNull('sent_at')->count());
    }
}

<?php

namespace Tests\Feature;

use App\Jobs\SendWebPush;
use App\Models\Order;
use App\Models\PushSubscription;
use App\Models\Setting;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * New-order push alerts for staff devices. Two things must hold: the alert
 * actually reaches every opted-in staff device, and those devices never appear
 * in a customer marketing send.
 */
class AdminOrderAlertTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Web push must look "ready" for any delivery path to run.
        Setting::put('webpush_enabled', true);
        Setting::put('webpush_public_key', str_repeat('a', 87));
        Setting::put('webpush_private_key', str_repeat('b', 43));
    }

    protected function subscription(string $audience, ?int $customerId = null): PushSubscription
    {
        $endpoint = 'https://push.example/'.$audience.'/'.uniqid();

        return PushSubscription::create([
            'audience' => $audience,
            'customer_id' => $customerId,
            'endpoint' => $endpoint,
            'endpoint_hash' => PushSubscription::hashFor($endpoint),
            'p256dh' => 'k', 'auth' => 'a',
        ]);
    }

    protected function order(): Order
    {
        $order = Order::create([
            'order_number' => '20001', 'customer_name' => 'Rahim', 'customer_phone' => '01712345678',
            'shipping_address' => 'X', 'subtotal' => 2500, 'shipping_cost' => 0, 'discount' => 0,
            'member_discount' => 0, 'total' => 2500, 'payment_method' => 'cod',
            'payment_status' => 'unpaid', 'status' => 'processing', 'source' => 'web',
        ]);
        $order->items()->create([
            'name' => 'Pearl Drop Earrings', 'price' => 2500, 'quantity' => 1, 'subtotal' => 2500,
        ]);

        return $order->fresh('items');
    }

    public function test_new_order_alerts_every_staff_device(): void
    {
        Queue::fake();
        $admin = $this->subscription('admin');
        $this->subscription('customer');

        $queued = app(NotificationService::class)->alertAdminsNewOrder($this->order());

        $this->assertSame(1, $queued);
        Queue::assertPushed(SendWebPush::class, function (SendWebPush $job) use ($admin) {
            return $job->subscriptionIds === [$admin->id]
                && str_contains($job->payload['title'], '20001')
                && str_contains($job->payload['body'], 'Rahim');
        });
    }

    public function test_alert_is_silent_when_paused(): void
    {
        Queue::fake();
        $this->subscription('admin');
        Setting::put('admin_order_alerts', false);

        $this->assertSame(0, app(NotificationService::class)->alertAdminsNewOrder($this->order()));
        Queue::assertNothingPushed();
    }

    public function test_staff_devices_are_excluded_from_customer_broadcasts(): void
    {
        $this->subscription('admin');
        $shopper = $this->subscription('customer');

        $ids = PushSubscription::customers()->pluck('id')->all();

        $this->assertSame([$shopper->id], $ids);
    }
}

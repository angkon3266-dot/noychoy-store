<?php

namespace Tests\Feature;

use App\Jobs\SendOrderPlacedEffects;
use App\Models\Order;
use App\Services\Meta\MetaTrackingService;
use App\Services\NotificationService;
use App\Services\SmsService;
use App\Support\QueueFallback;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * What happens when the cron that runs the scheduler stops (3 Oct 2026):
 * the queue drains itself from web requests, and order confirmations that
 * run hours late skip the customer messages but still send the Meta event.
 */
class QueueStallTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['queue.default' => 'database']);
    }

    private function queueProbe(): void
    {
        dispatch((new QueueStallProbeJob)->onConnection('database'));
    }

    private function waiting(): int
    {
        return DB::table('jobs')->count();
    }

    public function test_without_a_heartbeat_a_web_request_drains_the_queue(): void
    {
        $this->queueProbe();
        $this->queueProbe();

        app(QueueFallback::class)->afterResponse();

        $this->assertSame(0, $this->waiting());
        $this->assertSame(2, Cache::get('queue-stall-probe'));
    }

    public function test_while_the_cron_is_running_requests_leave_the_queue_to_it(): void
    {
        QueueFallback::beat();
        $this->queueProbe();

        app(QueueFallback::class)->afterResponse();

        $this->assertTrue(QueueFallback::schedulerRunning());
        $this->assertSame(1, $this->waiting());
    }

    public function test_only_one_request_a_minute_drains(): void
    {
        $this->queueProbe();
        app(QueueFallback::class)->afterResponse();

        $this->queueProbe();
        app(QueueFallback::class)->afterResponse();

        $this->assertSame(1, $this->waiting());
    }

    public function test_a_stale_heartbeat_counts_as_a_missing_cron(): void
    {
        Cache::put(QueueFallback::HEARTBEAT, now()->subMinutes(10)->getTimestamp());

        $this->assertFalse(QueueFallback::schedulerRunning());
        $this->assertGreaterThanOrEqual(600, QueueFallback::schedulerAge());
    }

    public function test_super_admins_see_the_missing_cron_banner(): void
    {
        $owner = \App\Models\User::create(['name' => 'Owner', 'email' => 'o@t.local', 'password' => bcrypt('x'), 'role' => 'admin']);
        if (! $owner->can('system-config.access')) {
            $this->markTestSkipped('This role cannot reach System Config.');
        }

        $this->actingAs($owner)->get('/admin')->assertSee('Scheduled tasks are not running');

        QueueFallback::beat();
        $this->actingAs($owner)->get('/admin')->assertDontSee('Scheduled tasks are not running');
    }

    // ── Late confirmations ──────────────────────────────────────────────────

    private function order(int $minutesAgo): Order
    {
        $order = Order::create([
            'order_number' => '9'.random_int(1000, 9999), 'customer_name' => 'Buyer', 'customer_phone' => '01711000001',
            'customer_email' => 'buyer@example.com', 'shipping_address' => 'X', 'subtotal' => 100, 'shipping_cost' => 0,
            'discount' => 0, 'total' => 100, 'payment_method' => 'cod', 'payment_status' => 'unpaid',
            'status' => 'processing', 'source' => 'web',
        ]);
        Order::whereKey($order->id)->update(['created_at' => now()->subMinutes($minutesAgo)]);

        return $order->fresh();
    }

    private function confirm(Order $order, bool $expectMessages): void
    {
        $sms = $this->mock(SmsService::class);
        $alerts = $this->mock(NotificationService::class);
        if ($expectMessages) {
            $sms->shouldReceive('sendTemplate')->once()->with('order_placed', \Mockery::any());
            $alerts->shouldReceive('alertAdminsNewOrder')->once();
        } else {
            $sms->shouldNotReceive('sendTemplate');
            $alerts->shouldNotReceive('alertAdminsNewOrder');
        }
        $capi = $this->mock(MetaTrackingService::class);
        $capi->shouldReceive('purchase')->once()->andReturn(['ok' => true]);

        (new SendOrderPlacedEffects($order, []))->handle($sms, $capi);
    }

    public function test_a_confirmation_hours_late_skips_the_messages_but_not_the_purchase_event(): void
    {
        Log::spy();

        $this->confirm($this->order(minutesAgo: 22 * 60), expectMessages: false);

        Log::shouldHaveReceived('error')->withArgs(fn ($msg) => str_contains($msg, 'ran late'))->once();
    }

    public function test_an_on_time_confirmation_still_messages_everyone(): void
    {
        $this->confirm($this->order(minutesAgo: 1), expectMessages: true);
    }
}

/** A queued job that only counts its runs. */
class QueueStallProbeJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function handle(): void
    {
        Cache::increment('queue-stall-probe');
    }
}

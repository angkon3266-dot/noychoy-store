<?php

namespace Tests\Feature;

use App\Jobs\SendOrderPlacedEffects;
use App\Models\Order;
use App\Services\Meta\MetaTrackingService;
use App\Services\NotificationService;
use App\Services\SmsService;
use App\Support\QueueFallback;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * What happens when the cron that runs the scheduler stops (3 Oct 2026):
 * web requests drain the queue and run the timed tasks themselves, and order
 * confirmations that run hours late skip the customer messages but still send
 * the Meta event.
 */
class QueueStallTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['queue.default' => 'database']);

        // The real schedule would start courier syncs and SMS passes from
        // here whenever a test happened to land on their minute.
        $this->app->instance(Schedule::class, new Schedule);
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

    public function test_links_made_while_standing_in_point_at_the_store_not_the_requested_host(): void
    {
        config(['app.url' => 'https://noychoy.com']);

        // Steadfast still posts its webhook to the old domain, and that
        // request is the one that drains the review request it queued.
        $this->app['url']->setRequest(Request::create('https://meridianeclat.shop/webhooks/steadfast', 'POST'));
        dispatch((new QueueStallLinkJob)->onConnection('database'));

        app(QueueFallback::class)->afterResponse();

        $this->assertStringStartsWith('https://noychoy.com/r/10151/', Cache::get('queue-stall-link'));
    }

    // ── The timed tasks ─────────────────────────────────────────────────────

    private function task(string $name): \Illuminate\Console\Scheduling\CallbackEvent
    {
        return app(Schedule::class)->call(fn () => Cache::increment('ran:'.$name))->name($name);
    }

    private function ran(string $name): int
    {
        return (int) Cache::get('ran:'.$name, 0);
    }

    private function requestAt(string $time): void
    {
        $this->travelTo(Carbon::parse($time));
        app(QueueFallback::class)->afterResponse();
    }

    public function test_without_a_cron_a_request_runs_the_tasks_that_are_due(): void
    {
        $this->task('minutely')->everyMinute();
        $this->task('hourly')->hourly();

        $this->requestAt('2026-10-05 10:17:20');

        $this->assertSame(1, $this->ran('minutely'));
        $this->assertSame(0, $this->ran('hourly'));
    }

    public function test_a_task_whose_minute_nobody_visited_runs_once_at_the_next_request(): void
    {
        $this->task('hourly')->hourly();
        $this->task('daily')->dailyAt('11:30');

        $this->requestAt('2026-10-05 10:58:10');
        $this->assertSame(0, $this->ran('hourly'));

        // Nobody visits at 11:00 or at 11:30.
        $this->requestAt('2026-10-05 11:42:05');
        $this->assertSame(1, $this->ran('hourly'));
        $this->assertSame(1, $this->ran('daily'));

        $this->requestAt('2026-10-05 11:43:05');
        $this->assertSame(1, $this->ran('hourly'));
        $this->assertSame(1, $this->ran('daily'));
    }

    public function test_tasks_due_after_the_crons_last_beat_still_run(): void
    {
        $this->task('hourly')->hourly();

        $this->travelTo(Carbon::parse('2026-10-05 10:55:00'));
        QueueFallback::beat();

        $this->requestAt('2026-10-05 11:09:30');

        $this->assertSame(1, $this->ran('hourly'));
    }

    public function test_a_long_silence_is_not_replayed(): void
    {
        $this->task('daily')->dailyAt('11:30');

        $this->requestAt('2026-10-01 09:00:00');
        $this->requestAt('2026-10-05 10:00:00');

        $this->assertSame(0, $this->ran('daily'));
    }

    public function test_a_minute_runs_once_however_many_requests_arrive(): void
    {
        $this->task('minutely')->everyMinute();

        $this->requestAt('2026-10-05 10:17:05');
        $this->requestAt('2026-10-05 10:17:40');
        $this->assertSame(1, $this->ran('minutely'));

        $this->requestAt('2026-10-05 10:18:01');
        $this->assertSame(2, $this->ran('minutely'));
    }

    public function test_while_the_cron_is_running_requests_leave_the_tasks_to_it(): void
    {
        $this->task('minutely')->everyMinute();
        QueueFallback::beat();

        app(QueueFallback::class)->afterResponse();

        $this->assertSame(0, $this->ran('minutely'));
    }

    public function test_the_web_never_beats_the_heartbeat_or_runs_the_crons_own_drain(): void
    {
        app(Schedule::class)->call(fn () => QueueFallback::beat())->everyMinute()->name('scheduler-heartbeat');
        $this->task('meta-queue-drain')->everyMinute();

        app(QueueFallback::class)->afterResponse();

        $this->assertNull(QueueFallback::schedulerAge());
        $this->assertSame(0, $this->ran('meta-queue-drain'));
    }

    public function test_a_failing_task_does_not_stop_the_rest(): void
    {
        app(Schedule::class)->call(fn () => throw new \RuntimeException('gateway down'))->everyMinute()->name('broken');
        $this->task('after')->everyMinute();

        app(QueueFallback::class)->afterResponse();

        $this->assertSame(1, $this->ran('after'));
        $report = Cache::get(QueueFallback::SCHEDULE_REPORT)['ran'];
        $this->assertStringStartsWith('failed', $report['broken']);
        $this->assertSame('ok', $report['after']);
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

/** A queued job that builds the review short link a delivery texts out. */
class QueueStallLinkJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function handle(): void
    {
        Cache::put('queue-stall-link', route('order.review.short', ['orderNumber' => '10151', 'token' => 'f2cab04754be820f']));
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

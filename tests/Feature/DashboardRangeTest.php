<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use App\Support\DateRange;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * The dashboard reporting window.
 *
 * The distinction that matters is which figures follow it: sales and orders
 * do, but the "to process" queue and the stock counts describe the shop right
 * now — showing 0 pending because someone picked "Today" would be a lie.
 */
class DashboardRangeTest extends TestCase
{
    use RefreshDatabase;

    protected function admin(): User
    {
        return User::firstOrCreate(
            ['email' => 'a@b.test'],
            ['name' => 'Admin', 'password' => bcrypt('secret'), 'role' => 'admin'],
        );
    }

    protected function order(string $when, float $total, string $status = 'delivered'): Order
    {
        $order = Order::create([
            'order_number' => (string) random_int(10000, 99999),
            'customer_name' => 'Buyer',
            'customer_phone' => '01712345678',
            'shipping_address' => 'x',
            'subtotal' => $total,
            'total' => $total,
            'status' => $status,
        ]);

        $order->forceFill(['created_at' => $when, 'updated_at' => $when])->save();

        return $order;
    }

    // ── The range itself ─────────────────────────────────────────────────────

    public function test_today_is_bounded_at_both_ends(): void
    {
        $range = DateRange::preset('today');

        $this->assertTrue($range->start->isToday());
        $this->assertTrue($range->end->isToday());
        $this->assertSame(1, $range->days());
    }

    public function test_maximum_has_no_bounds_and_nothing_to_compare_against(): void
    {
        $range = DateRange::preset('all');

        $this->assertTrue($range->isAllTime());
        $this->assertNull($range->days());
        $this->assertNull($range->previous(), 'all time has no earlier period');
    }

    public function test_the_previous_period_is_the_same_length_immediately_before(): void
    {
        $range = DateRange::preset('7d');
        $previous = $range->previous();

        $this->assertSame(7, $range->days());
        $this->assertTrue($previous->end->lt($range->start));
        $this->assertEqualsWithDelta(
            $range->start->diffInSeconds($range->end),
            $previous->start->diffInSeconds($previous->end),
            2,
        );
    }

    public function test_a_backwards_custom_range_is_swapped_rather_than_returning_nothing(): void
    {
        $range = DateRange::custom('2026-03-31', '2026-03-01');

        $this->assertSame('2026-03-01', $range->start->toDateString());
        $this->assertSame('2026-03-31', $range->end->toDateString());
    }

    public function test_an_unusable_period_falls_back_instead_of_erroring(): void
    {
        // A stale bookmark or hand-edited URL should still render a dashboard.
        foreach (['nonsense', '', 'custom'] as $period) {
            $range = DateRange::fromRequest(Request::create('/admin', 'GET', ['period' => $period]));
            $this->assertSame(DateRange::DEFAULT, $range->key, "failed for '{$period}'");
        }
    }

    public function test_a_closed_past_window_is_cached_longer_than_a_live_one(): void
    {
        // Yesterday's numbers can't change; today's keep moving.
        $this->assertGreaterThan(
            DateRange::preset('today')->cacheSeconds(),
            DateRange::preset('yesterday')->cacheSeconds(),
        );
    }

    // ── What the dashboard actually reports ──────────────────────────────────

    public function test_sales_follow_the_selected_window(): void
    {
        // Pin the clock to midday. "2 hours ago" has to still be today for this
        // test to mean anything, and between midnight and 02:00 UTC it wasn't —
        // the suite went red on the clock rather than on a code change.
        $this->travelTo(now()->startOfDay()->addHours(12));

        $this->order(now()->subHours(2)->toDateTimeString(), 100);
        $this->order(now()->subDays(10)->toDateTimeString(), 500);

        $today = $this->dashboard('today');
        $this->assertSame(100.0, (float) $today['sales_period']);
        $this->assertSame(1, $today['orders_period']);

        $month = $this->dashboard('30d');
        $this->assertSame(600.0, (float) $month['sales_period']);
        $this->assertSame(2, $month['orders_period']);
    }

    public function test_maximum_includes_orders_older_than_every_preset(): void
    {
        $this->order(now()->subYears(2)->toDateTimeString(), 900);

        $this->assertSame(0.0, (float) $this->dashboard('30d')['sales_period']);
        $this->assertSame(900.0, (float) $this->dashboard('all')['sales_period']);
    }

    public function test_a_custom_range_reports_only_that_span(): void
    {
        $this->order(now()->subDays(20)->toDateTimeString(), 250);
        $this->order(now()->subDays(2)->toDateTimeString(), 999);

        $stats = $this->dashboard('custom', [
            'from' => now()->subDays(25)->toDateString(),
            'to' => now()->subDays(15)->toDateString(),
        ]);

        $this->assertSame(250.0, (float) $stats['sales_period']);
    }

    public function test_the_live_queue_ignores_the_window(): void
    {
        // An order placed a month ago that still needs processing must stay
        // visible on the "To process" card when the filter says "Today".
        $this->order(now()->subDays(30)->toDateTimeString(), 100, 'processing');

        $this->assertSame(1, $this->dashboard('today')['processing']);
        $this->assertSame(1, $this->dashboard('all')['processing']);
    }

    public function test_cancelled_orders_never_count_as_sales(): void
    {
        $this->order(now()->subHour()->toDateTimeString(), 400, 'cancelled');

        $this->assertSame(0.0, (float) $this->dashboard('today')['sales_period']);
    }

    public function test_every_preset_renders(): void
    {
        $this->order(now()->subDays(3)->toDateTimeString(), 120);

        foreach (array_keys(DateRange::PRESETS) as $preset) {
            $this->actingAs($this->admin())
                ->get('/admin?period='.$preset)
                ->assertOk();
        }
    }

    /** The stats array the dashboard rendered for a given period. */
    protected function dashboard(string $period, array $extra = []): array
    {
        $response = $this->actingAs($this->admin())
            ->get('/admin?'.http_build_query(['period' => $period] + $extra))
            ->assertOk();

        return $response->viewData('stats');
    }
}

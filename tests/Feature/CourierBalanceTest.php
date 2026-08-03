<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The Steadfast wallet balance on the ORDERS LIST.
 *
 * It lives on the list rather than one order deep: this is the screen parcels
 * are booked from, and a wallet running dry stops bookings for everyone, not
 * for one order. Cached, so rendering the busiest admin screen doesn't cost a
 * courier round-trip per page.
 */
class CourierBalanceTest extends TestCase
{
    use RefreshDatabase;

    protected function admin(): User
    {
        return User::firstOrCreate(
            ['email' => 'a@b.c'],
            ['name' => 'A', 'password' => bcrypt('x'), 'role' => 'admin'],
        );
    }

    protected function order(): Order
    {
        return Order::create([
            'order_number' => '70001', 'customer_name' => 'Buyer', 'customer_phone' => '01712345678',
            'shipping_address' => 'X', 'subtotal' => 100, 'shipping_cost' => 0, 'discount' => 0,
            'total' => 100, 'payment_method' => 'cod', 'payment_status' => 'unpaid',
            'status' => 'processing', 'source' => 'web',
        ]);
    }

    protected function configureSteadfast(): void
    {
        Setting::put('integrations', [
            'steadfast_base_url' => 'https://portal.steadfast.com.bd/api/v1',
            'steadfast_api_key' => 'k', 'steadfast_secret_key' => 's',
        ]);
    }

    protected function withBalance(float $balance): void
    {
        $this->configureSteadfast();
        Http::fake([
            '*/get_balance' => Http::response(['status' => 200, 'current_balance' => $balance]),
            '*' => Http::response([], 200),
        ]);
    }

    public function test_the_balance_shows_on_the_orders_list(): void
    {
        $this->withBalance(1930);
        $this->order();

        $html = $this->actingAs($this->admin())->get('/admin/orders')->assertOk()->getContent();

        $this->assertStringContainsString('Courier balance', $html);
        $this->assertStringContainsString('1,930', $html);

        // Above the table — visible before you start picking orders.
        $this->assertLessThan(strpos($html, '<table'), strpos($html, 'Courier balance'));
    }

    public function test_it_is_read_once_and_cached_not_fetched_per_page(): void
    {
        $this->withBalance(1930);
        $this->order();

        $this->actingAs($this->admin())->get('/admin/orders');
        $this->actingAs($this->admin())->get('/admin/orders');
        $this->actingAs($this->admin())->get('/admin/orders');

        // One courier round-trip, not one per page load.
        Http::assertSentCount(1);
    }

    public function test_a_low_balance_is_flagged(): void
    {
        $this->withBalance(120);

        $this->order();
        $html = $this->actingAs($this->admin())->get('/admin/orders')->getContent();

        $this->assertStringContainsString('Courier balance', $html);
        $this->assertStringContainsString('top up Steadfast', $html);
    }

    public function test_a_healthy_balance_is_not_flagged_as_low(): void
    {
        $this->withBalance(5000);

        $this->order();
        $html = $this->actingAs($this->admin())->get('/admin/orders')->getContent();

        $this->assertStringNotContainsString('top up Steadfast', $html);
    }

    public function test_the_pill_is_hidden_when_the_courier_is_not_configured(): void
    {
        Setting::put('integrations', []);

        $this->order();
        $html = $this->actingAs($this->admin())->get('/admin/orders')->assertOk()->getContent();

        $this->assertStringNotContainsString('Courier balance', $html);
    }

    public function test_an_unreachable_courier_api_does_not_break_the_page(): void
    {
        $this->configureSteadfast();
        Http::fake(fn () => throw new \RuntimeException('connection refused'));

        $this->order();
        $this->actingAs($this->admin())->get('/admin/orders')->assertOk();
    }
}

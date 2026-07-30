<?php

namespace Tests\Feature;

use App\Models\AbandonedCart;
use App\Models\AdminAlertRead;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\AdminAlerts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The notification bell.
 *
 * Alerts are derived from live data rather than stored, so the properties worth
 * pinning are that they appear when the condition is true, stop appearing when
 * it is fixed, and that marking one read is per-admin.
 */
class AdminAlertsTest extends TestCase
{
    use RefreshDatabase;

    protected function admin(string $email = 'a@b.test'): User
    {
        return User::firstOrCreate(
            ['email' => $email],
            ['name' => 'Admin', 'password' => bcrypt('secret'), 'role' => 'admin'],
        );
    }

    protected function alerts(): AdminAlerts
    {
        \Illuminate\Support\Facades\Cache::flush();

        return app(AdminAlerts::class);
    }

    protected function keys(?User $user = null): array
    {
        return $this->alerts()->for($user ?? $this->admin())->pluck('key')->all();
    }

    protected function product(array $attributes = []): Product
    {
        return Product::create($attributes + [
            'name' => 'Gold Ring',
            'slug' => 'gold-ring-'.\Illuminate\Support\Str::random(5),
            'status' => 'published',
            'price' => 550,
        ]);
    }

    // ── Sources ──────────────────────────────────────────────────────────────

    public function test_an_out_of_stock_product_raises_an_alert(): void
    {
        $p = $this->product(['manage_stock' => true, 'stock_quantity' => 0]);

        $this->assertContains("stock.out.{$p->id}", $this->keys());
    }

    public function test_the_alert_disappears_once_the_product_is_restocked(): void
    {
        // The point of deriving alerts rather than storing them: fixing the
        // problem is what clears it, with no backlog to tidy up afterwards.
        $p = $this->product(['manage_stock' => true, 'stock_quantity' => 0]);
        $this->assertContains("stock.out.{$p->id}", $this->keys());

        $p->update(['stock_quantity' => 25]);

        $this->assertNotContains("stock.out.{$p->id}", $this->keys());
    }

    public function test_a_draft_product_is_not_reported_as_out_of_stock(): void
    {
        // Nobody can buy it, so nobody is being disappointed.
        $p = $this->product(['status' => 'draft', 'manage_stock' => true, 'stock_quantity' => 0]);

        $this->assertNotContains("stock.out.{$p->id}", $this->keys());
    }

    public function test_a_product_priced_below_its_landed_cost_is_flagged(): void
    {
        $p = $this->product(['price' => 100, 'cost_price' => 90, 'transport_cost' => 30]);

        $this->assertContains("margin.{$p->id}", $this->keys());
    }

    public function test_a_healthy_margin_is_not_flagged(): void
    {
        $p = $this->product(['price' => 500, 'cost_price' => 90, 'transport_cost' => 30]);

        $this->assertNotContains("margin.{$p->id}", $this->keys());
    }

    public function test_a_new_order_and_a_stuck_one_are_both_reported(): void
    {
        $new = $this->order('10001', 'pending');

        $stuck = $this->order('10002', 'processing');
        $stuck->forceFill(['updated_at' => now()->subDays(5)])->save();

        $keys = $this->keys();

        $this->assertContains('order.new.10001', $keys);
        $this->assertContains('order.stuck.10002', $keys);
    }

    public function test_an_order_still_moving_is_not_called_stuck(): void
    {
        $this->order('10003', 'processing');

        $this->assertNotContains('order.stuck.10003', $this->keys());
    }

    public function test_an_uncontacted_abandoned_cart_is_reported(): void
    {
        $cart = AbandonedCart::create([
            'phone' => '01712345678', 'name' => 'Fatima',
            'subtotal' => 2400, 'item_count' => 2, 'last_step' => 'checkout',
        ]);

        $this->assertContains("cart.{$cart->id}", $this->keys());

        $cart->update(['contacted' => true]);

        $this->assertNotContains("cart.{$cart->id}", $this->keys());
    }

    // ── Read state ───────────────────────────────────────────────────────────

    public function test_marking_one_read_keeps_it_in_the_list(): void
    {
        $p = $this->product(['manage_stock' => true, 'stock_quantity' => 0]);
        $key = "stock.out.{$p->id}";

        $this->actingAs($this->admin())
            ->post(route('admin.alerts.read'), ['key' => $key])
            ->assertRedirect();

        $alert = $this->alerts()->for($this->admin())->firstWhere('key', $key);

        $this->assertNotNull($alert, 'a read alert should still be listed, not deleted');
        $this->assertTrue($alert['read']);
    }

    public function test_reading_is_per_admin(): void
    {
        $p = $this->product(['manage_stock' => true, 'stock_quantity' => 0]);
        $key = "stock.out.{$p->id}";

        $this->actingAs($this->admin('one@b.test'))->post(route('admin.alerts.read'), ['key' => $key]);

        // One admin clearing their bell must not hide the problem from another.
        $other = $this->alerts()->for($this->admin('two@b.test'))->firstWhere('key', $key);

        $this->assertFalse($other['read']);
        $this->assertSame(0, $this->alerts()->unreadCountFor($this->admin('one@b.test')));
        $this->assertSame(1, $this->alerts()->unreadCountFor($this->admin('two@b.test')));
    }

    public function test_reading_the_same_alert_twice_is_not_an_error(): void
    {
        $p = $this->product(['manage_stock' => true, 'stock_quantity' => 0]);
        $admin = $this->admin();

        foreach ([1, 2] as $_) {
            $this->actingAs($admin)
                ->post(route('admin.alerts.read'), ['key' => "stock.out.{$p->id}"])
                ->assertRedirect();
        }

        $this->assertSame(1, AdminAlertRead::where('alert_key', "stock.out.{$p->id}")->count());
    }

    public function test_mark_all_read_empties_the_badge(): void
    {
        $this->product(['manage_stock' => true, 'stock_quantity' => 0]);
        $this->order('10004', 'pending');

        $admin = $this->admin();
        $this->assertGreaterThan(0, $this->alerts()->unreadCountFor($admin));

        $this->actingAs($admin)->post(route('admin.alerts.read-all'))->assertRedirect();

        $this->assertSame(0, $this->alerts()->unreadCountFor($admin));
    }

    public function test_an_offsite_redirect_is_refused(): void
    {
        // `url` comes from the browser; following it blindly would turn the
        // admin panel into an open redirect.
        $this->actingAs($this->admin())
            ->from('/admin')
            ->post(route('admin.alerts.read'), ['key' => 'x', 'url' => 'https://evil.example/steal'])
            ->assertRedirect('/admin');
    }

    public function test_a_broken_source_does_not_take_down_the_bell(): void
    {
        // Drop a table one source reads; the rest must still be listed.
        $p = $this->product(['manage_stock' => true, 'stock_quantity' => 0]);
        \Illuminate\Support\Facades\Schema::drop('abandoned_carts');

        $this->assertContains("stock.out.{$p->id}", $this->keys());
    }

    public function test_nothing_object_shaped_is_written_to_the_cache(): void
    {
        // config/cache.php sets serializable_classes = false, so a cached
        // Collection or Carbon returns as __PHP_Incomplete_Class and 500s the
        // whole admin layout on the second page load — which is exactly what
        // happened the first time this was built.
        $this->product(['manage_stock' => true, 'stock_quantity' => 0]);
        $this->alerts()->all();

        $payload = \Illuminate\Support\Facades\Cache::get('admin.alerts.v1');

        $this->assertIsArray($payload);
        foreach ($payload as $alert) {
            foreach ($alert as $field => $value) {
                $this->assertFalse(is_object($value), "alert field '{$field}' is an object");
            }
        }
    }

    public function test_the_second_read_of_a_cached_list_still_works(): void
    {
        $this->product(['manage_stock' => true, 'stock_quantity' => 0]);

        $service = app(AdminAlerts::class);
        $service->all();                      // writes the cache
        $again = $service->all();             // reads it back

        $this->assertNotEmpty($again);
        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $again->first()['at']);
    }

    public function test_a_poisoned_cache_entry_is_discarded(): void
    {
        \Illuminate\Support\Facades\Cache::put('admin.alerts.v1', new \stdClass, 60);
        $this->product(['manage_stock' => true, 'stock_quantity' => 0]);

        $keys = app(AdminAlerts::class)->all()->pluck('key')->all();

        $this->assertNotEmpty($keys);
    }

    public function test_the_bell_renders_in_the_admin_layout(): void
    {
        $this->product(['manage_stock' => true, 'stock_quantity' => 0]);

        $html = $this->actingAs($this->admin())->get('/admin')->assertOk()->getContent();

        $this->assertStringContainsString('aria-label="Notifications"', $html);
        $this->assertStringContainsString('is out of stock', $html);
    }

    protected function order(string $number, string $status): Order
    {
        return Order::create([
            'order_number' => $number,
            'customer_name' => 'Buyer',
            'customer_phone' => '01712345678',
            'shipping_address' => 'x',
            'subtotal' => 500, 'total' => 500,
            'status' => $status,
        ]);
    }
}

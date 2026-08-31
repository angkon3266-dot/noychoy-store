<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\Visit;
use App\Services\DashboardAnalytics;
use App\Support\DateRange;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * What each funnel step was worth.
 *
 * The funnel could say "one person reached checkout" but never "…carrying
 * ৳4,500" — which is the half that decides whether chasing an abandoned
 * checkout is worth anyone's afternoon.
 *
 * The counts and the values deliberately measure different things: a count is
 * people (distinct visitors), a value is money (summed over every event). One
 * shopper adding three pieces is one person carrying three items' worth.
 */
class FunnelValueTest extends TestCase
{
    use RefreshDatabase;

    protected function product(array $extra = []): Product
    {
        return Product::create(array_merge([
            'name' => 'Ring', 'slug' => 'ring', 'status' => 'published', 'price' => 1500,
            'manage_stock' => true, 'stock_quantity' => 20, 'in_stock' => true,
        ], $extra));
    }

    protected function funnel(): array
    {
        return app(DashboardAnalytics::class)->funnel(DateRange::preset('30d'));
    }

    public function test_adding_to_cart_records_what_the_line_was_worth(): void
    {
        $product = $this->product(['price' => 1500]);

        $this->post('/cart/add/'.$product->slug, ['qty' => 3]);

        $visit = Visit::where('event', 'cart_add')->sole();

        $this->assertSame('4500.00', (string) $visit->value);
    }

    public function test_starting_checkout_records_what_the_cart_was_worth(): void
    {
        $product = $this->product(['price' => 1200]);

        $this->post('/cart/add/'.$product->slug, ['qty' => 2]);
        $this->get('/checkout')->assertOk();

        $visit = Visit::where('event', 'checkout_start')->sole();

        $this->assertSame('2400.00', (string) $visit->value);
    }

    public function test_the_funnel_reports_the_money_beside_the_people(): void
    {
        $product = $this->product(['price' => 1000]);

        // One shopper, two items — one person, two items' worth.
        $this->post('/cart/add/'.$product->slug, ['qty' => 2]);
        $this->get('/checkout');

        $steps = collect($this->funnel()['steps'])->keyBy('label');

        $this->assertSame(1, $steps['Added to cart']['count']);
        $this->assertSame(2000.0, $steps['Added to cart']['money']);
        $this->assertSame(1, $steps['Started checkout']['count']);
        $this->assertSame(2000.0, $steps['Started checkout']['money']);
    }

    public function test_the_ordered_step_carries_real_revenue(): void
    {
        Order::create([
            'order_number' => '10001', 'customer_name' => 'B', 'customer_phone' => '01712345678',
            'shipping_address' => 'Dhaka', 'subtotal' => 3000, 'total' => 3200,
            'status' => 'delivered', 'payment_method' => 'cod',
        ]);
        // A cancelled order is not revenue.
        Order::create([
            'order_number' => '10002', 'customer_name' => 'C', 'customer_phone' => '01712345679',
            'shipping_address' => 'Dhaka', 'subtotal' => 9000, 'total' => 9000,
            'status' => 'cancelled', 'payment_method' => 'cod',
        ]);

        $f = $this->funnel();
        $ordered = collect($f['steps'])->firstWhere('label', 'Ordered');

        $this->assertSame(3200.0, $ordered['money']);
        $this->assertSame(3200.0, $f['revenue']);
    }

    public function test_it_reports_what_was_left_at_checkout(): void
    {
        $product = $this->product(['price' => 5000]);

        $this->post('/cart/add/'.$product->slug, ['qty' => 1]);
        $this->get('/checkout');

        // Reached checkout carrying ৳5,000, ordered nothing.
        $this->assertSame(5000.0, $this->funnel()['abandoned']);
    }

    public function test_abandoned_never_goes_negative(): void
    {
        // Orders can outrun measured checkouts — an order placed by phone, or a
        // checkout that started before value tracking. "Minus ৳3,000 abandoned"
        // is not a thing.
        $product = $this->product(['price' => 100]);
        $this->post('/cart/add/'.$product->slug, ['qty' => 1]);
        $this->get('/checkout');

        Order::create([
            'order_number' => '10003', 'customer_name' => 'B', 'customer_phone' => '01712345678',
            'shipping_address' => 'Dhaka', 'subtotal' => 9000, 'total' => 9000,
            'status' => 'delivered', 'payment_method' => 'cod',
        ]);

        $this->assertSame(0.0, $this->funnel()['abandoned']);
    }

    public function test_an_unmeasured_event_is_not_counted_as_zero(): void
    {
        // A row from before the column existed. Summing it as 0 would report a
        // real cart as worthless; the panel has to say it does not know.
        Visit::create(['visitor_token' => str_repeat('a', 40), 'event' => 'cart_add', 'path' => 'cart']);

        $f = $this->funnel();
        $carted = collect($f['steps'])->firstWhere('label', 'Added to cart');

        $this->assertNull($carted['money'], 'nothing measured — the value must be unknown, not zero');
        $this->assertSame(1, $carted['unmeasured']);
        $this->assertSame(1, $f['unmeasured']);
    }

    public function test_measured_and_unmeasured_events_report_the_measured_total_and_flag_the_rest(): void
    {
        $product = $this->product(['price' => 700]);
        $this->post('/cart/add/'.$product->slug, ['qty' => 1]);

        Visit::create(['visitor_token' => str_repeat('b', 40), 'event' => 'cart_add', 'path' => 'cart']);

        $carted = collect($this->funnel()['steps'])->firstWhere('label', 'Added to cart');

        $this->assertSame(700.0, $carted['money']);
        $this->assertSame(1, $carted['unmeasured']);
    }

    public function test_the_funnel_still_works_before_the_value_column_exists(): void
    {
        Schema::table('visits', fn ($t) => $t->dropColumn('value'));
        app()->instance(Visit::VALUE_READY_KEY, false);

        $product = $this->product();
        $this->post('/cart/add/'.$product->slug, ['qty' => 1]);

        $carted = collect($this->funnel()['steps'])->firstWhere('label', 'Added to cart');

        $this->assertSame(1, $carted['count'], 'the count must survive a missing money column');
        $this->assertNull($carted['money']);
    }

    public function test_the_dashboard_renders_the_values(): void
    {
        $admin = \App\Models\User::create([
            'name' => 'Admin', 'email' => 'a@b.test', 'password' => bcrypt('secret'), 'role' => 'admin',
        ]);

        $product = $this->product(['price' => 2500]);
        $this->post('/cart/add/'.$product->slug, ['qty' => 1]);
        $this->get('/checkout');

        $this->actingAs($admin)->get('/admin?period=30d')
            ->assertOk()
            ->assertSee('Order value', false)
            ->assertSee('Left at checkout', false);
    }

    public function test_buy_now_counts_as_an_add_to_cart(): void
    {
        // Buy now puts a piece in the cart exactly like Add to cart does. It was
        // recorded only in add(), so the strongest intent on the page was
        // invisible: the shopper reappeared at checkout having apparently never
        // added anything. On production that read as 508 product viewers and one
        // single add-to-cart.
        $product = $this->product(['price' => 2200]);

        $this->post('/cart/buy-now/'.$product->slug, ['qty' => 2])->assertRedirect();

        $visit = Visit::where('event', 'cart_add')->sole();
        $this->assertSame('4400.00', (string) $visit->value);
    }

    public function test_a_bundle_is_one_add_to_cart_carrying_the_whole_bundle(): void
    {
        // One decision, one event — counting each piece separately would flatter
        // the add-to-cart step.
        $a = $this->product(['price' => 1000, 'slug' => 'a', 'name' => 'A']);
        $b = $this->product(['price' => 1500, 'slug' => 'b', 'name' => 'B']);

        $this->post('/cart/add-many', ['product_ids' => [$a->id, $b->id]]);

        $visit = Visit::where('event', 'cart_add')->sole();
        $this->assertSame('2500.00', (string) $visit->value);
    }

    public function test_a_bundle_that_adds_nothing_records_nothing(): void
    {
        $variable = $this->product(['has_variants' => true]);

        $this->post('/cart/add-many', ['product_ids' => [$variable->id]]);

        $this->assertSame(0, Visit::where('event', 'cart_add')->count());
    }
}

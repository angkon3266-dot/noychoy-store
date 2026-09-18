<?php

namespace Tests\Feature;

use App\Models\ContactMessage;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Models\Visit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The dashboard compacted for a phone (owner's call, 17 Sep 2026).
 *
 * On a narrow screen long lists show their first few rows behind "Show all"
 * and the secondary panels fold shut. The promise that made that acceptable is
 * that nothing left the page: a row hidden on a phone is still in the markup,
 * one tap from view, and every panel still renders its figures. These tests
 * fill every list past its phone cut-off and hold the page to that promise.
 */
class DashboardMobileLayoutTest extends TestCase
{
    use RefreshDatabase;

    protected function admin(): User
    {
        return User::firstOrCreate(
            ['email' => 'a@b.test'],
            ['name' => 'Admin', 'password' => bcrypt('secret'), 'role' => 'admin'],
        );
    }

    protected function product(string $slug, array $extra = []): Product
    {
        return Product::create(array_merge([
            'name' => ucwords(str_replace('-', ' ', $slug)), 'slug' => $slug, 'status' => 'published',
            'price' => 1500, 'manage_stock' => true, 'stock_quantity' => 10, 'in_stock' => true,
        ], $extra));
    }

    protected function order(string $number, float $total = 1500): Order
    {
        return Order::create([
            'order_number' => $number, 'customer_name' => 'Buyer '.$number, 'customer_phone' => '01712345678',
            'shipping_address' => 'Dhaka', 'subtotal' => $total, 'total' => $total,
            'status' => 'processing', 'payment_method' => 'cod',
        ]);
    }

    /** A dashboard with more of everything than a phone shows at once. */
    protected function busyStore(): void
    {
        // Twelve orders: the recent-orders card lists ten, a phone shows five.
        foreach (range(1, 12) as $i) {
            $this->order((string) (20000 + $i));
        }

        // Four products selling: "Running out soon" shows three on a phone.
        foreach (['amber-drop-earring', 'bridal-kundan-set', 'pearl-halo-ring', 'rose-gold-bangle'] as $slug) {
            $product = $this->product($slug, ['loves_count' => 5]);
            OrderItem::create([
                'order_id' => Order::first()->id, 'product_id' => $product->id, 'name' => $product->name,
                'price' => 1500, 'quantity' => 2, 'subtotal' => 3000,
            ]);
        }

        // Three unread messages: a phone shows two.
        foreach (['Nusrat', 'Farhana', 'Tahmina'] as $name) {
            ContactMessage::create([
                'name' => $name, 'phone' => '01812345678', 'subject' => 'Sizing', 'message' => 'Does this ring come in size 7?',
                'is_read' => false,
            ]);
        }

        Customer::create(['name' => 'Sharmin Akter', 'phone' => '01912345678', 'total_orders' => 3, 'total_spent' => 9000]);

        Visit::create([
            'visitor_token' => str_repeat('a', 40), 'event' => 'page', 'path' => '/',
            'source' => 'facebook_ads', 'campaign' => 'puja-festive', 'content' => 'reel-v3',
        ]);
    }

    public function test_rows_a_phone_hides_behind_show_all_are_still_on_the_page(): void
    {
        $this->busyStore();

        $response = $this->actingAs($this->admin())->get('/admin?period=30d')->assertOk();

        // Ten most recent orders — all of them in the markup, not just the five
        // a phone shows before "Show all".
        foreach (range(3, 12) as $i) {
            $response->assertSee((string) (20000 + $i));
        }
        $response->assertSee('Show all 10');

        $response->assertSee('Tahmina');
        $response->assertSee('Show all 3');

        $response->assertSee('Rose Gold Bangle');
        $response->assertSee('Show all 4');
    }

    public function test_panels_that_fold_on_a_phone_still_render_their_contents(): void
    {
        $this->busyStore();

        $this->actingAs($this->admin())->get('/admin?period=30d')
            ->assertOk()
            // Top customers, with the points liability kept in its heading.
            ->assertSee('Sharmin Akter')
            ->assertSee('Points liability')
            // Most loved, where visitors come from, and the ads table.
            ->assertSee('Most loved products')
            ->assertSee('Where visitors come from')
            ->assertSee('puja-festive')
            ->assertSee('reel-v3')
            ->assertSee('Dead stock');
    }

    public function test_the_arrange_button_keeps_its_name_when_it_shrinks_to_a_gear_on_a_phone(): void
    {
        // Since 18 Sep 2026 the ⚙ is "Arrange" (the whole dashboard moves,
        // not just four panels) — the name still has to survive the shrink.
        $this->actingAs($this->admin())->get('/admin')
            ->assertOk()
            ->assertSee('Arrange dashboard')
            // The chosen preset is marked, so the sideways strip can scroll to it.
            ->assertSee('aria-current="page"', false);
    }

    public function test_a_sales_bar_past_a_week_is_labelled_with_its_date_not_just_its_weekday(): void
    {
        // The chart reads a tapped bar out by its label; thirty bars of bare
        // weekdays would leave "Wed" meaning any of four days.
        $month = $this->actingAs($this->admin())->get('/admin?period=30d')->assertOk()->viewData('daily');
        $this->assertCount(30, $month);
        $this->assertMatchesRegularExpression('/^[A-Z][a-z]{2} \d{1,2}$/', $month->last()['label']);

        // A week or less still reads as plain weekdays, as it always did.
        $week = $this->actingAs($this->admin())->get('/admin?period=7d')->assertOk()->viewData('daily');
        $this->assertMatchesRegularExpression('/^[A-Z][a-z]{2}$/', $week->last()['label']);
    }
}

<?php

namespace Tests\Feature;

use App\Models\AbandonedCart;
use App\Models\Address;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Starting a manual order from a customer the shop already knows.
 *
 * Owner, 2026-09-17: "add option so I can create new order from customer list".
 * A repeat buyer rings to order again, and until now the only way was to open
 * the blank order form and re-type a name, a number and an address sitting on
 * the customer page in the next tab.
 *
 * The customers table holds no address, so the form borrows the best guess —
 * the default saved address, else the last order — and says which it used.
 */
class ManualOrderFromCustomerTest extends TestCase
{
    use RefreshDatabase;

    protected function admin(): User
    {
        return User::firstOrCreate(
            ['email' => 'owner@test.local'],
            ['name' => 'Owner', 'password' => bcrypt('x'), 'role' => 'admin'],
        );
    }

    protected function customer(array $attrs = []): Customer
    {
        return Customer::create(array_merge([
            'name' => 'Nusrat Jahan',
            'phone' => '01712345678',
            'email' => 'nusrat@example.com',
            'total_orders' => 2,
            'total_spent' => 5200,
        ], $attrs));
    }

    protected function pastOrder(Customer $customer, array $attrs = [], ?\DateTimeInterface $placedAt = null): Order
    {
        $order = Order::create(array_merge([
            'order_number' => 'NOY-'.random_int(100000, 999999),
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'customer_phone' => $customer->phone,
            'shipping_address' => 'House 12, Road 7, Dhanmondi',
            'area' => 'Dhanmondi',
            'district' => 'Dhaka',
            'is_inside_dhaka' => true,
            'status' => 'delivered',
            'subtotal' => 2600, 'total' => 2670,
        ], $attrs));

        // created_at is not mass assignable.
        if ($placedAt) {
            $order->forceFill(['created_at' => $placedAt])->save();
        }

        return $order;
    }

    // ── Where the button is ──────────────────────────────────────────────────

    public function test_every_row_on_the_customer_list_offers_a_new_order_for_that_customer(): void
    {
        $nusrat = $this->customer();
        $other = $this->customer(['name' => 'Rafi Ahmed', 'phone' => '01812345678', 'email' => null]);

        $this->actingAs($this->admin())
            ->get(route('admin.customers.index'))
            ->assertOk()
            ->assertSee(route('admin.orders.create', ['customer' => $nusrat->id]))
            ->assertSee(route('admin.orders.create', ['customer' => $other->id]))
            // The row itself opens the customer page, so the button must stop
            // the click before it gets there.
            ->assertSee('href="'.route('admin.orders.create', ['customer' => $nusrat->id]).'" onclick="event.stopPropagation()"', false);
    }

    public function test_a_blacklisted_customer_still_gets_the_button(): void
    {
        $flagged = $this->customer(['blacklisted' => true]);

        $this->actingAs($this->admin())
            ->get(route('admin.customers.index'))
            ->assertOk()
            ->assertSee(route('admin.orders.create', ['customer' => $flagged->id]));
    }

    public function test_the_customer_page_offers_a_new_order_too(): void
    {
        $customer = $this->customer();

        $this->actingAs($this->admin())
            ->get(route('admin.customers.show', $customer))
            ->assertOk()
            ->assertSee(route('admin.orders.create', ['customer' => $customer->id]))
            ->assertSee('New order');
    }

    // ── The form arrives filled in ───────────────────────────────────────────

    public function test_the_form_opens_with_their_details_and_the_address_of_their_last_order(): void
    {
        $customer = $this->customer();
        $latest = $this->pastOrder($customer, [
            'shipping_address' => 'Flat 3B, Uttara Sector 7', 'area' => 'Uttara', 'district' => 'Dhaka North',
            'is_inside_dhaka' => true,
        ], now()->subDays(5));
        // Written after it — an import of older history — but placed before.
        $this->pastOrder($customer, [
            'shipping_address' => 'Old flat, Mirpur 10', 'area' => 'Mirpur', 'district' => 'Dhaka',
        ], now()->subMonths(2));

        $res = $this->actingAs($this->admin())
            ->get(route('admin.orders.create', ['customer' => $customer->id]))
            ->assertOk()
            ->assertSee('Nusrat Jahan')
            ->assertSee('01712345678')
            ->assertSee('nusrat@example.com')
            ->assertSee('Flat 3B, Uttara Sector 7')
            ->assertDontSee('Old flat, Mirpur 10')
            // The district box used to ignore the prefill entirely.
            ->assertSee('value="Dhaka North"', false)
            ->assertSee('Delivery details from their last order on '.store_time($latest->created_at)->format('j M'));

        $prefill = $res->viewData('prefill');
        $this->assertSame('Uttara', $prefill['customer']['area']);
        $this->assertSame('Dhaka North', $prefill['customer']['district']);
        $this->assertTrue($prefill['customer']['is_inside_dhaka']);
        $this->assertSame([], $prefill['lines'], 'a customer brings no basket — the form opens on one blank line');
    }

    public function test_an_order_outside_dhaka_carries_its_zone_over(): void
    {
        $customer = $this->customer();
        $this->pastOrder($customer, [
            'shipping_address' => 'College Road', 'area' => 'Sadar', 'district' => 'Cumilla', 'is_inside_dhaka' => false,
        ]);

        $prefill = $this->actingAs($this->admin())
            ->get(route('admin.orders.create', ['customer' => $customer->id]))
            ->assertOk()
            ->viewData('prefill');

        $this->assertFalse($prefill['customer']['is_inside_dhaka']);
        $this->assertSame('Cumilla', $prefill['customer']['district']);
    }

    public function test_a_default_saved_address_is_preferred_over_the_last_order(): void
    {
        $customer = $this->customer();
        $this->pastOrder($customer, ['shipping_address' => 'Where the last parcel went']);

        Address::create([
            'customer_id' => $customer->id, 'label' => 'Office', 'name' => 'Nusrat Jahan', 'phone' => '01712345678',
            'address' => 'Not the default, Gulshan 1', 'area' => 'Gulshan', 'is_default' => false,
        ]);
        Address::create([
            'customer_id' => $customer->id, 'label' => 'Home', 'name' => 'Nusrat Jahan', 'phone' => '01712345678',
            'address' => 'Road 4, Banani', 'area' => 'Banani', 'district' => 'Dhaka',
            'is_inside_dhaka' => true, 'is_default' => true,
        ]);

        $res = $this->actingAs($this->admin())
            ->get(route('admin.orders.create', ['customer' => $customer->id]))
            ->assertOk()
            ->assertSee('Road 4, Banani')
            ->assertDontSee('Where the last parcel went')
            ->assertDontSee('Not the default, Gulshan 1')
            ->assertSee('Delivery details from their default saved address');

        $this->assertSame('Banani', $res->viewData('prefill')['customer']['area']);
    }

    public function test_the_name_and_number_are_the_customers_own_even_when_the_saved_address_is_for_someone_else(): void
    {
        // A gift address in her book must not file the sale under the friend.
        $customer = $this->customer();
        Address::create([
            'customer_id' => $customer->id, 'name' => 'Her Sister', 'phone' => '01999999999',
            'address' => 'Sister’s house, Rajshahi', 'is_default' => true,
        ]);

        $prefill = $this->actingAs($this->admin())
            ->get(route('admin.orders.create', ['customer' => $customer->id]))
            ->assertOk()
            ->viewData('prefill');

        $this->assertSame('Nusrat Jahan', $prefill['customer']['name']);
        $this->assertSame('01712345678', $prefill['customer']['phone']);
        $this->assertSame('Sister’s house, Rajshahi', $prefill['customer']['address']);
    }

    public function test_a_customer_with_no_address_anywhere_opens_with_a_note_to_type_one(): void
    {
        $customer = $this->customer(['total_orders' => 0, 'total_spent' => 0]);

        $this->actingAs($this->admin())
            ->get(route('admin.orders.create', ['customer' => $customer->id]))
            ->assertOk()
            ->assertSee('Nusrat Jahan')
            ->assertSee('No saved address or past order to copy delivery details from');
    }

    public function test_an_unknown_or_malformed_customer_is_ignored(): void
    {
        $this->customer();

        foreach (['999999', 'abc', '1 OR 1=1', '-1'] as $id) {
            $res = $this->actingAs($this->admin())
                ->get('/admin/orders/create?customer='.urlencode($id))
                ->assertOk()
                ->assertSee('New order')
                ->assertDontSee('Nusrat Jahan');

            $this->assertNull($res->viewData('prefill'), "customer={$id} should open the blank form");
            $this->assertNull($res->viewData('customer'));
        }

        $this->actingAs($this->admin())
            ->get('/admin/orders/create?customer[]=1')
            ->assertOk()
            ->assertDontSee('Nusrat Jahan');
    }

    public function test_a_blacklisted_customer_opens_with_a_warning(): void
    {
        $customer = $this->customer(['blacklisted' => true]);

        $this->actingAs($this->admin())
            ->get(route('admin.orders.create', ['customer' => $customer->id]))
            ->assertOk()
            ->assertSee('Nusrat Jahan is blacklisted')
            ->assertSee('high-risk for cash on delivery');
    }

    public function test_a_customer_in_good_standing_gets_no_warning(): void
    {
        $customer = $this->customer();

        $res = $this->actingAs($this->admin())
            ->get(route('admin.orders.create', ['customer' => $customer->id]))
            ->assertOk()
            ->assertDontSee('Nusrat Jahan is blacklisted');

        $this->assertFalse($res->viewData('prefill')['picked']['blacklisted']);
    }

    public function test_a_lead_wins_when_both_a_lead_and_a_customer_are_given(): void
    {
        $customer = $this->customer();
        $this->pastOrder($customer);

        $product = Product::create([
            'name' => 'Pearl Drop Necklace', 'slug' => 'pearl-drop', 'status' => 'published',
            'price' => 1850, 'manage_stock' => false, 'in_stock' => true,
        ]);
        $cart = AbandonedCart::create([
            'session_id' => 'sess-1', 'name' => 'Farida Bari', 'phone' => '01912345678',
            'address' => 'House 8, Road 3, Lalmatia', 'area' => 'Lalmatia', 'is_inside_dhaka' => true,
            'items' => [['product_id' => $product->id, 'name' => $product->name, 'qty' => 1, 'price' => 1850]],
            'subtotal' => 1850, 'item_count' => 1,
        ]);

        $res = $this->actingAs($this->admin())
            ->get('/admin/orders/create?from_cart='.$cart->id.'&customer='.$customer->id)
            ->assertOk()
            ->assertSee('Converting')
            ->assertSee('Farida Bari')
            ->assertDontSee('Nusrat Jahan');

        $this->assertCount(1, $res->viewData('prefill')['lines']);
        $this->assertNull($res->viewData('customer'));
    }

    public function test_the_blank_form_is_unchanged(): void
    {
        $res = $this->actingAs($this->admin())
            ->get(route('admin.orders.create'))
            ->assertOk()
            ->assertSee('New order')
            ->assertDontSee('Delivery details from');

        $this->assertNull($res->viewData('prefill'));
    }

    // ── Picking a customer on the form itself ────────────────────────────────
    //
    // Owner, 2026-09-17: "when creating a new order, I need to be able to
    // select existing customer data from name".

    protected function search(string $term, ?User $as = null): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($as ?? $this->admin())
            ->getJson(route('admin.orders.customer-search', ['q' => $term]));
    }

    public function test_the_order_form_offers_a_customer_search_box(): void
    {
        $this->actingAs($this->admin())
            ->get(route('admin.orders.create'))
            ->assertOk()
            ->assertSee('Find an existing customer — name or phone')
            ->assertSee('id="customer-search"', false);
    }

    public function test_the_search_finds_a_customer_by_part_of_their_name(): void
    {
        $this->customer();
        $this->customer(['name' => 'Rafi Ahmed', 'phone' => '01812345678', 'email' => null]);

        $this->search('usrat')
            ->assertOk()
            ->assertJsonCount(1, 'customers')
            ->assertJsonPath('customers.0.name', 'Nusrat Jahan')
            ->assertJsonPath('customers.0.phone', '01712345678')
            ->assertJsonPath('customers.0.email', 'nusrat@example.com');
    }

    public function test_the_search_finds_a_customer_by_a_pasted_number_in_any_shape(): void
    {
        $nusrat = $this->customer();
        $this->customer(['name' => 'Rafi Ahmed', 'phone' => '01812345678', 'email' => null]);

        foreach (['+880 1712-345678', '8801712345678', '01712 345', '1712345678'] as $typed) {
            $this->search($typed)
                ->assertOk()
                ->assertJsonCount(1, 'customers')
                ->assertJsonPath('customers.0.id', $nusrat->id);
        }
    }

    public function test_a_name_with_digits_in_it_is_not_matched_as_a_number(): void
    {
        $this->customer();

        // "017" alone would find her by number; beside a name it is a name.
        $this->search('Rafi 017')->assertOk()->assertJsonCount(0, 'customers');
    }

    public function test_the_search_returns_at_most_eight(): void
    {
        foreach (range(1, 12) as $n) {
            $this->customer(['name' => 'Repeat Buyer '.$n, 'phone' => '0171'.str_pad((string) $n, 7, '0', STR_PAD_LEFT), 'email' => null]);
        }

        $this->search('Repeat Buyer')->assertOk()->assertJsonCount(8, 'customers');
    }

    public function test_the_search_waits_for_two_characters(): void
    {
        $this->customer();

        $this->search('N')->assertOk()->assertExactJson(['customers' => []]);
        $this->search('Nu')->assertOk()->assertJsonCount(1, 'customers');
    }

    public function test_each_result_carries_what_the_form_fills_in_and_warns_about(): void
    {
        $saved = $this->customer(['name' => 'Saved Address', 'phone' => '01711111111']);
        Address::create([
            'customer_id' => $saved->id, 'label' => 'Home', 'name' => 'Saved Address', 'phone' => '01711111111',
            'address' => 'Road 4, Banani', 'area' => 'Banani', 'district' => 'Dhaka', 'is_inside_dhaka' => true, 'is_default' => true,
        ]);

        $ordered = $this->customer(['name' => 'Past Order', 'phone' => '01722222222', 'total_orders' => 3]);
        $this->pastOrder($ordered, [
            'shipping_address' => 'College Road', 'area' => 'Sadar', 'district' => 'Cumilla', 'is_inside_dhaka' => false,
        ]);

        $this->customer(['name' => 'Past Flagged', 'phone' => '01733333333', 'blacklisted' => true, 'total_orders' => 0]);

        $cards = collect($this->search('Past')->assertOk()->json('customers'))
            ->merge($this->search('Saved')->json('customers'))
            ->keyBy('name');

        $this->assertSame('Road 4, Banani', $cards['Saved Address']['address']);
        $this->assertTrue($cards['Saved Address']['is_inside_dhaka']);
        $this->assertStringStartsWith('Delivery details from their default saved address “Home”', $cards['Saved Address']['source']);

        $this->assertSame('College Road', $cards['Past Order']['address']);
        $this->assertSame('Cumilla', $cards['Past Order']['district']);
        $this->assertFalse($cards['Past Order']['is_inside_dhaka']);
        $this->assertSame(3, $cards['Past Order']['total_orders']);
        $this->assertStringStartsWith('Delivery details from their last order on', $cards['Past Order']['source']);

        $this->assertTrue($cards['Past Flagged']['blacklisted']);
        $this->assertNull($cards['Past Flagged']['address']);
        $this->assertFalse($cards['Past Order']['blacklisted']);
    }

    public function test_the_search_costs_the_same_queries_for_eight_customers_as_for_two(): void
    {
        // It answers a box as she types, so it must not go back to the
        // database once per customer for their address or last order.
        $make = function (string $prefix, int $count) {
            foreach (range(1, $count) as $n) {
                $c = $this->customer([
                    'name' => $prefix.' '.$n, 'email' => null,
                    'phone' => ($prefix === 'Pair' ? '0181' : '0191').str_pad((string) $n, 7, '0', STR_PAD_LEFT),
                ]);
                $this->pastOrder($c);
                $this->pastOrder($c, ['shipping_address' => 'Newer address '.$n]);
                if ($n % 2) {
                    Address::create([
                        'customer_id' => $c->id, 'name' => $c->name, 'phone' => $c->phone,
                        'address' => 'Saved '.$n, 'is_default' => $n % 4 === 1,
                    ]);
                }
            }
        };
        $make('Pair', 2);
        $make('Crowd', 8);

        $admin = $this->admin();
        $count = function (string $term) use ($admin) {
            \Illuminate\Support\Facades\DB::flushQueryLog();
            \Illuminate\Support\Facades\DB::enableQueryLog();
            $this->search($term, $admin)->assertOk();
            $queries = count(\Illuminate\Support\Facades\DB::getQueryLog());
            \Illuminate\Support\Facades\DB::disableQueryLog();

            return $queries;
        };

        // Anything the first request of a test warms up is not the search's cost.
        $count('warm up');

        $forTwo = $count('Pair');
        $forEight = $count('Crowd');

        $this->assertSame(8, count($this->search('Crowd', $admin)->json('customers')));
        $this->assertSame($forTwo, $forEight, "the search ran {$forTwo} queries for two customers and {$forEight} for eight");
    }

    public function test_staff_who_cannot_open_the_customer_list_can_still_search_from_the_order_form(): void
    {
        $staff = User::create([
            'name' => 'Packer', 'email' => 'staff@test.local', 'password' => bcrypt('x'), 'role' => 'staff',
        ]);
        $this->customer();

        $this->actingAs($staff)->get(route('admin.customers.index'))->assertForbidden();

        $this->search('Nusrat', $staff)->assertOk()->assertJsonPath('customers.0.name', 'Nusrat Jahan');

        $this->actingAs($staff)
            ->get(route('admin.orders.create'))
            ->assertOk()
            ->assertSee('Find an existing customer — name or phone');
    }

    public function test_a_bounced_save_still_knows_which_customer_was_picked(): void
    {
        \Illuminate\Support\Facades\Queue::fake();

        $flagged = $this->customer(['blacklisted' => true]);
        $product = Product::create([
            'name' => 'Opal Ring', 'slug' => 'opal-ring', 'status' => 'published',
            'price' => 2000, 'manage_stock' => false, 'in_stock' => true,
        ]);

        // Picked on the blank form, then refused on save.
        $res = $this->actingAs($this->admin())
            ->from(route('admin.orders.create'))
            ->followingRedirects()
            ->post(route('admin.orders.store-manual'), [
                'picked_customer' => (string) $flagged->id,
                'name' => 'Nusrat Jahan', 'phone' => '01712345678', 'address' => 'Road 4, Banani',
                'coupon_code' => 'NOSUCHCODE',
                'lines' => [['product_id' => $product->id, 'qty' => 1, 'price' => null]],
            ])
            ->assertOk()
            ->assertSee('There is no coupon with the code NOSUCHCODE.')
            ->assertSee('Nusrat Jahan is blacklisted');

        $this->assertSame($flagged->id, $res->viewData('restored')['picked']['id']);
        $this->assertSame(0, Order::count());
    }
}

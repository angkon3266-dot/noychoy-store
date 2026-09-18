<?php

namespace Tests\Feature;

use App\Models\AbandonedCart;
use App\Models\BlockedPhone;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Numbers that may not order (owner, 2026-09-18).
 *
 * The point of the feature is that one blocked number is one person however
 * she types it, so most of these tests are the same number in a different
 * shape: "+880 1712-345678", "8801712345678", "1712345678", "01712 345678".
 */
class BlockedPhoneTest extends TestCase
{
    use RefreshDatabase;

    /** Every spelling of the same number, as customers and the owner type it. */
    public static function spellings(): array
    {
        return [
            'plain' => ['01712345678'],
            'international' => ['+8801712345678'],
            'international spaced' => ['+880 1712-345678'],
            'no plus' => ['8801712345678'],
            'no leading zero' => ['1712345678'],
            'spaced' => ['01712 345678'],
            'dashed' => ['017-1234-5678'],
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        BlockedPhone::forgetMemo();
    }

    protected function admin(): User
    {
        return User::firstOrCreate(['email' => 'a@b.test'], ['name' => 'Admin', 'password' => bcrypt('secret'), 'role' => 'admin']);
    }

    protected function product(): Product
    {
        // firstOrCreate: a test that checks out twice would otherwise trip the
        // unique slug the second time round.
        return Product::firstOrCreate(['slug' => 'ring'], [
            'name' => 'Ring', 'status' => 'published', 'price' => 1500,
            'manage_stock' => true, 'stock_quantity' => 10, 'in_stock' => true,
        ]);
    }

    protected function checkout(string $phone)
    {
        $this->post('/cart/add/'.$this->product()->slug, ['qty' => 1]);

        return $this->post('/checkout', [
            'name' => 'Buyer', 'phone' => $phone,
            'address' => 'House 4, Road 2, Dhanmondi', 'is_inside_dhaka' => 1,
        ]);
    }

    // ── However it is typed, it is the same block ───────────────────────────

    #[DataProvider('spellings')]
    public function test_a_blocked_number_cannot_check_out_however_it_is_typed(string $typed): void
    {
        BlockedPhone::block('01712345678', 'refused three parcels');

        $this->checkout($typed)->assertSessionHasErrors('phone');

        $this->assertSame(0, Order::count());
    }

    #[DataProvider('spellings')]
    public function test_blocking_accepts_the_number_in_any_shape(string $typed): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.customers.blocked.store'), ['phones' => $typed])
            ->assertRedirect();

        // Stored once, canonically, whatever was pasted.
        $this->assertSame(['01712345678'], BlockedPhone::pluck('phone')->all());
        $this->assertTrue(BlockedPhone::blocks('+880 1712-345678'));
    }

    public function test_a_number_that_is_not_blocked_still_orders(): void
    {
        BlockedPhone::block('01712345678');

        $this->checkout('01812345678')->assertRedirect();

        $this->assertSame(1, Order::count());
    }

    public function test_unblocking_lets_the_order_through_again(): void
    {
        BlockedPhone::block('01712345678');
        $this->checkout('01712345678')->assertSessionHasErrors('phone');

        BlockedPhone::unblock('+880 1712-345678');

        $this->checkout('01712345678')->assertRedirect();
        $this->assertSame(1, Order::count());
    }

    // ── The other doors into an order ──────────────────────────────────────

    public function test_an_order_typed_in_the_admin_is_refused_with_the_reason(): void
    {
        BlockedPhone::block('01712345678');
        $product = $this->product();

        $this->actingAs($this->admin())
            ->from(route('admin.orders.create'))
            ->post(route('admin.orders.store-manual'), [
                'name' => 'Buyer', 'phone' => '+880 1712-345678',
                'address' => 'Road 4, Banani', 'is_inside_dhaka' => '1',
                'shipping_cost' => 70, 'discount' => 0, 'status' => 'confirmed',
                'lines' => [['product_id' => $product->id, 'qty' => 1, 'price' => null]],
            ])
            ->assertSessionHasErrors('phone');

        $this->assertSame(0, Order::count());
    }

    public function test_a_blocked_number_leaves_no_lead_behind(): void
    {
        BlockedPhone::block('01712345678');
        $this->post('/cart/add/'.$this->product()->slug, ['qty' => 1]);

        $this->postJson('/checkout/lead', ['phone' => '8801712345678', 'name' => 'Buyer'])
            ->assertOk()
            ->assertJson(['ok' => false]);

        $this->assertSame(0, AbandonedCart::count());
    }

    public function test_place_order_refuses_a_blocked_number_even_when_called_directly(): void
    {
        // The assistant places orders through this action, so the guard has to
        // live where every door passes rather than only on the web form.
        BlockedPhone::block('01712345678');
        app(\App\Services\CartService::class)->add($this->product(), null, 1);

        $this->expectException(\App\Exceptions\CheckoutException::class);

        app(\App\Actions\PlaceOrder::class)->handle([
            'name' => 'Buyer', 'phone' => '01712 345678',
            'address' => 'House 4', 'is_inside_dhaka' => true,
        ]);
    }

    // ── The screen ─────────────────────────────────────────────────────────

    public function test_a_pasted_list_blocks_every_number_and_names_what_it_could_not_read(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.customers.blocked.store'), [
                'phones' => "Nadia 01712345678\n+880 1812-345678, 01912345678\nnot-a-number",
                'reason' => 'kept refusing parcels',
            ])
            ->assertRedirect()
            ->assertSessionHas('warning');

        $this->assertEqualsCanonicalizing(
            ['01712345678', '01812345678', '01912345678'],
            BlockedPhone::pluck('phone')->all()
        );
        $this->assertSame('kept refusing parcels', BlockedPhone::first()->reason);
    }

    public function test_a_box_with_nothing_readable_blocks_nobody_and_says_so(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.customers.blocked.store'), ['phones' => 'nobody, 12345'])
            ->assertSessionHasErrors('phones');

        $this->assertSame(0, BlockedPhone::count());
    }

    public function test_the_same_number_pasted_twice_is_one_row(): void
    {
        $admin = $this->actingAs($this->admin());
        $admin->post(route('admin.customers.blocked.store'), ['phones' => '01712345678'])->assertRedirect();
        $admin->post(route('admin.customers.blocked.store'), ['phones' => '+8801712345678'])->assertRedirect();

        $this->assertSame(1, BlockedPhone::count());
    }

    public function test_the_list_shows_what_the_number_cost_before_it_was_blocked(): void
    {
        BlockedPhone::block('01712345678', 'three returns');
        Order::create([
            'order_number' => '50001', 'customer_name' => 'Buyer', 'customer_phone' => '01712345678',
            'shipping_address' => 'Dhaka', 'subtotal' => 1500, 'shipping_cost' => 70, 'discount' => 0,
            'total' => 1570, 'payment_method' => 'cod', 'payment_status' => 'unpaid', 'status' => 'returned',
        ]);

        $this->actingAs($this->admin())->get(route('admin.customers.blocked'))
            ->assertOk()
            ->assertSee('01712345678')
            ->assertSee('three returns')
            ->assertSee('1 order');
    }

    public function test_the_customer_page_blocks_and_unblocks_in_one_tap(): void
    {
        $customer = Customer::create(['name' => 'Buyer', 'phone' => '01712345678']);
        $admin = $this->actingAs($this->admin());

        $admin->post(route('admin.customers.blocked.quick'), ['phone' => '01712345678'])->assertRedirect();
        $this->assertTrue(BlockedPhone::blocks('01712345678'));

        $admin->get(route('admin.customers.show', $customer))->assertOk()->assertSee('Blocked — unblock');

        $admin->delete(route('admin.customers.blocked.destroy', BlockedPhone::first()))->assertRedirect();
        $this->assertFalse(BlockedPhone::blocks('01712345678'));
    }

    public function test_staff_cannot_reach_the_blocked_list(): void
    {
        $staff = User::create(['name' => 'Staff', 'email' => 's@b.test', 'password' => bcrypt('secret'), 'role' => 'staff']);

        $this->actingAs($staff)->get(route('admin.customers.blocked'))->assertForbidden();
    }
}

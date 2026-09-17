<?php

namespace Tests\Feature;

use App\Models\Coupon;
use App\Models\CouponRecipient;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\CartService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The "Customer phone numbers" box on the coupon form.
 *
 * Owner, 2026-09-17: "add option to add phone number in the Coupon — so
 * whenever that phone number is used, the customer will get the coupon applied
 * against that number." The phone list behind it already existed, but it took
 * three steps and a second save to reach, and no coupon in production had ever
 * used it. These pin the box itself, and then the promise it makes on the
 * storefront: the listed number gets the discount without typing anything, and
 * nobody else can spend the code.
 */
class CouponPhoneListTest extends TestCase
{
    use RefreshDatabase;

    protected function admin(): User
    {
        return User::create([
            'name' => 'Admin', 'email' => 'a@b.test', 'password' => bcrypt('secret'), 'role' => 'admin',
        ]);
    }

    /** The form as the browser posts it, with the phone box filled in. */
    protected function form(array $extra = []): array
    {
        return array_merge([
            'code' => 'VIP10', 'type' => 'percent', 'value' => 10, 'applies_to' => 'all',
            'is_active' => 1, 'recipient_phones' => '', 'recipient_phones_mode' => 'sync',
        ], $extra);
    }

    protected function phoneListCoupon(array $phones, array $extra = []): Coupon
    {
        $coupon = Coupon::create(array_merge([
            'code' => 'VIP10', 'type' => 'percent', 'value' => 10,
            'applies_to' => 'all', 'is_active' => true, 'auto_apply' => true, 'audience' => 'phones',
        ], $extra));

        foreach ($phones as $phone => $name) {
            CouponRecipient::create(['coupon_id' => $coupon->id, 'phone' => $phone, 'name' => $name]);
        }

        return $coupon;
    }

    protected function product(): Product
    {
        return Product::create([
            'name' => 'Ring', 'slug' => 'ring', 'status' => 'published', 'price' => 2000,
            'manage_stock' => true, 'stock_quantity' => 50, 'in_stock' => true,
        ]);
    }

    protected function checkout(string $phone): \Illuminate\Testing\TestResponse
    {
        return $this->post(route('checkout.store'), [
            'name' => 'Test Buyer', 'phone' => $phone, 'address' => '123 Road, Dhaka', 'is_inside_dhaka' => 1,
        ]);
    }

    // ── The box on the form ─────────────────────────────────────────────────

    public function test_creating_a_coupon_with_numbers_makes_it_a_phone_list_that_applies_itself(): void
    {
        // Neither "Apply automatically" nor the audience is sent — the page
        // disables both while the box has numbers. The box alone decides.
        $this->actingAs($this->admin())
            ->post(route('admin.coupons.store'), $this->form(['recipient_phones' => '01712345678, +8801812345678']))
            ->assertRedirect(route('admin.coupons.index'))
            ->assertSessionHas('success');

        $coupon = Coupon::where('code', 'VIP10')->sole();

        $this->assertSame('phones', $coupon->audience);
        $this->assertTrue($coupon->auto_apply);
        $this->assertEqualsCanonicalizing(['01712345678', '01812345678'], $coupon->recipients()->pluck('phone')->all());
    }

    public function test_an_entry_that_is_not_a_mobile_number_is_named_and_nothing_is_created(): void
    {
        $this->actingAs($this->admin())
            ->from(route('admin.coupons.index'))
            ->post(route('admin.coupons.store'), $this->form(['recipient_phones' => "01712345678\n01999"]))
            ->assertRedirect(route('admin.coupons.index'))
            ->assertSessionHasErrors('recipient_phones');

        $this->assertStringContainsString('01999', session('errors')->first('recipient_phones'));
        $this->assertSame(0, Coupon::count());
        $this->assertSame(0, CouponRecipient::count());
    }

    public function test_numbers_written_in_groups_are_read_as_whole_numbers(): void
    {
        // How numbers are actually written here. Read digit group by digit
        // group, each would have been refused as a fragment.
        $this->actingAs($this->admin())->post(route('admin.coupons.store'), $this->form([
            'recipient_phones' => "01712 345678\n+880 1812-345678",
        ]))->assertSessionHasNoErrors();

        $this->assertEqualsCanonicalizing(['01712345678', '01812345678'], CouponRecipient::pluck('phone')->all());
    }

    public function test_editing_the_list_removes_a_dropped_number_and_keeps_a_kept_numbers_name(): void
    {
        $coupon = $this->phoneListCoupon(['01712345678' => 'Nadia', '01812345678' => 'Rumana']);

        $this->actingAs($this->admin())->put(route('admin.coupons.update', $coupon), $this->form([
            'recipient_phones' => "01712345678\n01912345678",
        ]))->assertSessionHasNoErrors();

        $rows = CouponRecipient::pluck('name', 'phone');

        $this->assertEqualsCanonicalizing(['01712345678', '01912345678'], $rows->keys()->all());
        // The box never showed the name, and saving must not have erased it.
        $this->assertSame('Nadia', $rows['01712345678']);
    }

    public function test_clearing_the_box_turns_a_phone_list_back_into_a_normal_code(): void
    {
        $coupon = $this->phoneListCoupon(['01712345678' => null, '01812345678' => null]);

        // The dangerous shape: the tick and "Every order" are still there from
        // before. Honouring them would hand a personal discount to every order.
        $this->actingAs($this->admin())->put(route('admin.coupons.update', $coupon), $this->form([
            'recipient_phones' => '', 'auto_apply' => 1, 'audience' => 'all',
        ]))->assertSessionHasNoErrors();

        $coupon->refresh();

        $this->assertSame(0, $coupon->recipients()->count());
        $this->assertFalse($coupon->auto_apply);
        $this->assertSame('all', $coupon->audience);
    }

    public function test_a_list_longer_than_the_box_shows_is_only_ever_added_to(): void
    {
        $coupon = $this->phoneListCoupon([]);
        CouponRecipient::insert(collect(range(1, 301))->map(fn ($i) => [
            'coupon_id' => $coupon->id, 'phone' => '0155'.str_pad((string) $i, 7, '0', STR_PAD_LEFT),
        ])->all());
        $admin = $this->admin();

        // The page shows the count, not the numbers, and says so.
        $this->actingAs($admin)->get(route('admin.coupons.index', ['edit' => $coupon->id]))
            ->assertOk()
            ->assertSee('301 numbers on the list — manage them in the list below')
            ->assertSee('name="recipient_phones_mode" value="append"', false);

        // A number typed into the box is added; none of the 301 goes.
        $this->put(route('admin.coupons.update', $coupon), $this->form([
            'recipient_phones' => '01712345678', 'recipient_phones_mode' => 'append',
        ]))->assertSessionHasNoErrors();

        $this->assertSame(302, $coupon->recipients()->count());

        // Saved as it looks — an empty box — and from a stale page that still
        // said "sync": the count is checked on the server, so nothing goes, and
        // it stays a list that applies itself.
        $this->put(route('admin.coupons.update', $coupon), $this->form([
            'recipient_phones' => '', 'recipient_phones_mode' => 'sync',
        ]))->assertSessionHasNoErrors();

        $coupon->refresh();
        $this->assertSame(302, $coupon->recipients()->count());
        $this->assertSame('phones', $coupon->audience);
        $this->assertTrue($coupon->auto_apply);
    }

    public function test_a_short_list_is_written_into_the_box_for_editing(): void
    {
        $coupon = $this->phoneListCoupon(['01712345678' => 'Nadia', '01812345678' => null]);

        $this->actingAs($this->admin())->get(route('admin.coupons.index', ['edit' => $coupon->id]))
            ->assertOk()
            // One per line, in the textarea — the chips further down are not
            // newline-joined, so this can only match the box.
            ->assertSee("01712345678\n01812345678", false)
            ->assertSee('name="recipient_phones_mode" value="sync"', false);
    }

    public function test_the_coupon_list_says_how_many_numbers_a_phone_list_has(): void
    {
        $this->phoneListCoupon(['01712345678' => null, '01812345678' => null, '01912345678' => null]);

        $this->actingAs($this->admin())->get(route('admin.coupons.index'))
            ->assertOk()
            ->assertSee('📱 3 numbers');
    }

    public function test_a_coupon_that_does_not_apply_itself_is_never_saved_waiting_for_a_phone_list(): void
    {
        // The audience select stays in the page while hidden, so ticking
        // "Apply automatically", choosing the phone list and unticking again
        // posted exactly this. Now that a list also decides who may TYPE the
        // code, saving it would make a code nobody can spend.
        $this->actingAs($this->admin())->post(route('admin.coupons.store'), $this->form([
            'audience' => 'phones',
        ]))->assertSessionHasNoErrors();

        $coupon = Coupon::where('code', 'VIP10')->sole();

        $this->assertFalse($coupon->auto_apply);
        $this->assertSame('all', $coupon->audience);
    }

    public function test_choosing_the_phone_list_without_numbers_opens_the_coupon_to_add_them(): void
    {
        // How a coupon for a saved customer group starts: the group is added
        // from the list tools, which only exist once the coupon does.
        $response = $this->actingAs($this->admin())->post(route('admin.coupons.store'), $this->form([
            'auto_apply' => 1, 'audience' => 'phones',
        ]));

        $coupon = Coupon::where('code', 'VIP10')->sole();

        $response->assertRedirect(route('admin.coupons.index', ['edit' => $coupon->id]))
            ->assertSessionHas('warning');
        $this->assertSame('phones', $coupon->audience);
    }

    // ── What the box promises on the storefront ─────────────────────────────

    public function test_a_listed_phone_gets_the_coupon_as_soon_as_it_is_typed_at_checkout(): void
    {
        // End to end: made in the admin exactly as the owner would make it.
        $this->actingAs($this->admin())->post(route('admin.coupons.store'), $this->form([
            'code' => 'VIP15', 'value' => 15, 'recipient_phones' => "01712345678\n01812345678",
        ]))->assertSessionHasNoErrors();

        $this->post(route('cart.add', $this->product()), ['qty' => 1]);

        $this->postJson(route('checkout.lead'), ['phone' => '+8801812345678', 'name' => 'Rumana'])
            ->assertOk()
            ->assertJsonPath('summary.discountText', '৳300');

        $cart = app(CartService::class);
        $this->assertSame('VIP15', $cart->coupon()?->code);
        $this->assertSame(300.0, $cart->couponDiscount());
    }

    public function test_a_phone_that_is_not_on_the_list_does_not_get_it(): void
    {
        $this->phoneListCoupon(['01712345678' => null]);
        $this->post(route('cart.add', $this->product()), ['qty' => 1]);

        $this->postJson(route('checkout.lead'), ['phone' => '01999999999'])->assertOk();

        $cart = app(CartService::class);
        $this->assertNull($cart->coupon());
        $this->assertSame(0.0, $cart->couponDiscount());
    }

    public function test_an_unlisted_phone_that_types_the_code_is_refused_at_checkout(): void
    {
        $coupon = $this->phoneListCoupon(['01712345678' => null]);
        $this->post(route('cart.add', $this->product()), ['qty' => 1]);

        // Nobody has said who they are yet, so the cart takes the code...
        $this->post(route('cart.coupon'), ['code' => 'VIP10'])->assertSessionHas('success');

        // ...and the order is where the number is known and the code refused.
        $this->checkout('01999999999')
            ->assertRedirect(route('cart'))
            ->assertSessionHas('error', fn (string $message) => str_contains($message, 'different phone number'));

        $this->assertSame(0, Order::count());
        $this->assertSame(0, $coupon->fresh()->used_count);
    }

    public function test_a_listed_phone_that_types_the_code_gets_it_on_the_order(): void
    {
        $coupon = $this->phoneListCoupon(['01712345678' => null]);
        $this->post(route('cart.add', $this->product()), ['qty' => 1]);
        $this->post(route('cart.coupon'), ['code' => 'VIP10'])->assertSessionHas('success');

        $this->checkout('01712345678')->assertRedirect();

        $order = Order::sole();
        $this->assertSame('VIP10', $order->coupon_code);
        $this->assertSame('200.00', (string) $order->discount);
        $this->assertSame(1, $coupon->fresh()->used_count);
    }
}

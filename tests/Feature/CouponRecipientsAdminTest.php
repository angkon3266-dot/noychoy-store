<?php

namespace Tests\Feature;

use App\Models\Coupon;
use App\Models\CouponRecipient;
use App\Models\Customer;
use App\Models\CustomerSegment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Building the list an auto-applying coupon is waiting for. */
class CouponRecipientsAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function admin(): User
    {
        return User::create([
            'name' => 'Admin', 'email' => 'a@b.test', 'password' => bcrypt('secret'), 'role' => 'admin',
        ]);
    }

    protected function coupon(array $extra = []): Coupon
    {
        return Coupon::create(array_merge([
            'code' => 'VIP10', 'type' => 'percent', 'value' => 10,
            'applies_to' => 'all', 'is_active' => true, 'auto_apply' => true, 'audience' => 'phones',
        ], $extra));
    }

    public function test_pasted_numbers_are_added_in_whatever_shape_they_arrive(): void
    {
        $coupon = $this->coupon();

        $this->actingAs($this->admin())->post("/admin/coupons/{$coupon->id}/recipients", [
            'source' => 'paste',
            'phones' => "01712345678\nNadia 01812345678\n+8801912345678, 01612345678\n\nnot a phone\n0171234567",
        ])->assertRedirect();

        $rows = CouponRecipient::pluck('name', 'phone');

        // Canonicalised, de-duplicated, junk and short numbers dropped.
        $this->assertEqualsCanonicalizing(
            ['01712345678', '01812345678', '01912345678', '01612345678'],
            $rows->keys()->all(),
        );
        $this->assertSame('Nadia', $rows['01812345678']);
    }

    public function test_the_same_list_pasted_twice_does_not_duplicate(): void
    {
        $coupon = $this->coupon();
        $admin = $this->admin();
        $payload = ['source' => 'paste', 'phones' => "01712345678\n01812345678"];

        $this->actingAs($admin)->post("/admin/coupons/{$coupon->id}/recipients", $payload);
        $this->actingAs($admin)->post("/admin/coupons/{$coupon->id}/recipients", $payload);

        $this->assertSame(2, CouponRecipient::count());
    }

    public function test_every_past_buyer_can_be_added_at_once(): void
    {
        Customer::create(['name' => 'Bought', 'phone' => '01712345678', 'total_orders' => 2]);
        Customer::create(['name' => 'Also bought', 'phone' => '01812345678', 'total_orders' => 1]);
        Customer::create(['name' => 'Never bought', 'phone' => '01912345678', 'total_orders' => 0]);
        Customer::create(['name' => 'Opted out', 'phone' => '01612345678', 'total_orders' => 5, 'blacklisted' => true]);

        $coupon = $this->coupon();

        $this->actingAs($this->admin())
            ->post("/admin/coupons/{$coupon->id}/recipients", ['source' => 'buyers'])
            ->assertRedirect();

        $this->assertEqualsCanonicalizing(
            ['01712345678', '01812345678'],
            CouponRecipient::pluck('phone')->all(),
        );
    }

    public function test_a_saved_group_can_be_added_at_once(): void
    {
        Customer::create(['name' => 'Big spender', 'phone' => '01712345678', 'total_orders' => 3, 'total_spent' => 20000]);
        Customer::create(['name' => 'Small spender', 'phone' => '01812345678', 'total_orders' => 1, 'total_spent' => 500]);

        $segment = CustomerSegment::create([
            'name' => 'VIPs', 'type' => 'dynamic', 'rules' => ['min_spend' => 10000],
        ]);
        $coupon = $this->coupon();

        $this->actingAs($this->admin())->post("/admin/coupons/{$coupon->id}/recipients", [
            'source' => 'segment', 'segment_id' => $segment->id,
        ])->assertRedirect();

        $this->assertSame(['01712345678'], CouponRecipient::pluck('phone')->all());
    }

    public function test_adding_a_list_switches_the_coupon_on(): void
    {
        // A list on a coupon that never applies itself does nothing, and being
        // silently ignored is worse than being corrected.
        $coupon = $this->coupon(['auto_apply' => false, 'audience' => 'all']);

        $this->actingAs($this->admin())->post("/admin/coupons/{$coupon->id}/recipients", [
            'source' => 'paste', 'phones' => '01712345678',
        ]);

        $coupon->refresh();
        $this->assertTrue($coupon->auto_apply);
        $this->assertSame('phones', $coupon->audience);
    }

    public function test_text_with_no_usable_number_is_rejected_not_silently_accepted(): void
    {
        $coupon = $this->coupon();

        $this->actingAs($this->admin())->post("/admin/coupons/{$coupon->id}/recipients", [
            'source' => 'paste', 'phones' => "hello\n12345",
        ])->assertSessionHas('error');

        $this->assertSame(0, CouponRecipient::count());
    }

    public function test_a_recipient_can_be_removed(): void
    {
        $coupon = $this->coupon();
        $r = CouponRecipient::create(['coupon_id' => $coupon->id, 'phone' => '01712345678']);

        $this->actingAs($this->admin())
            ->delete("/admin/coupons/{$coupon->id}/recipients/{$r->id}")
            ->assertRedirect();

        $this->assertSame(0, CouponRecipient::count());
    }

    public function test_a_recipient_cannot_be_removed_through_another_coupon(): void
    {
        $mine = $this->coupon();
        $other = $this->coupon(['code' => 'OTHER']);
        $r = CouponRecipient::create(['coupon_id' => $mine->id, 'phone' => '01712345678']);

        $this->actingAs($this->admin())
            ->delete("/admin/coupons/{$other->id}/recipients/{$r->id}")
            ->assertNotFound();

        $this->assertSame(1, CouponRecipient::count());
    }

    public function test_the_form_saves_a_standing_rule(): void
    {
        $this->actingAs($this->admin())->post('/admin/coupons', [
            'code' => 'FIRST10', 'type' => 'percent', 'value' => 10, 'applies_to' => 'all',
            'is_active' => 1, 'auto_apply' => 1, 'audience' => 'rule',
            'audience_rules' => ['first_order_only' => 1, 'min_orders' => '', 'min_spend' => '', 'lapsed_days' => ''],
        ])->assertRedirect();

        $coupon = Coupon::where('code', 'FIRST10')->sole();

        $this->assertTrue($coupon->auto_apply);
        // Blank boxes are dropped: "at least 0 orders" would match everybody.
        $this->assertSame(['first_order_only' => true], $coupon->audience_rules);
    }

    public function test_rules_are_discarded_when_the_audience_is_not_a_rule(): void
    {
        $this->actingAs($this->admin())->post('/admin/coupons', [
            'code' => 'ALL5', 'type' => 'percent', 'value' => 5, 'applies_to' => 'all',
            'is_active' => 1, 'auto_apply' => 1, 'audience' => 'all',
            'audience_rules' => ['min_orders' => 3],
        ])->assertRedirect();

        $this->assertNull(Coupon::where('code', 'ALL5')->sole()->audience_rules);
    }

    public function test_the_recipient_list_is_admin_only(): void
    {
        $coupon = $this->coupon();

        $this->post("/admin/coupons/{$coupon->id}/recipients", ['source' => 'buyers'])
            ->assertRedirect(route('admin.login'));

        $this->assertSame(0, CouponRecipient::count());
    }
}

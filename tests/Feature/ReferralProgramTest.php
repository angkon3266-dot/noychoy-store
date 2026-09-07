<?php

namespace Tests\Feature;

use App\Actions\TransitionOrderStatus;
use App\Models\Customer;
use App\Models\CustomerNotification;
use App\Models\Order;
use App\Models\PointTransaction;
use App\Models\Product;
use App\Models\Setting;
use App\Support\Referral;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The referral program hands out points to two people on the strength of a
 * cookie, so these pin the places it could leak: a link must attribute once
 * and only at the moment someone becomes a member, nobody can refer
 * themselves, and the first delivered order pays each side exactly once —
 * through a delivered → returned → delivered flip-flop included.
 */
class ReferralProgramTest extends TestCase
{
    use RefreshDatabase;

    protected function member(string $name = 'Areeba Khan', string $phone = '01711100001'): Customer
    {
        return Customer::create(['name' => $name, 'phone' => $phone, 'password' => 'secret123']);
    }

    protected function product(float $price = 1000): Product
    {
        return Product::create([
            'name' => 'Ring', 'slug' => 'ring-'.uniqid(), 'status' => 'published',
            'price' => $price, 'manage_stock' => false, 'in_stock' => true,
        ]);
    }

    public function test_a_member_gets_a_stable_invite_code_and_link(): void
    {
        $areeba = $this->member();

        $code = Referral::codeFor($areeba);
        $this->assertMatchesRegularExpression('/^AREE[A-Z0-9]{5}$/', $code);
        $this->assertSame($code, Referral::codeFor($areeba->fresh()));
        $this->assertSame(route('invite', $code), Referral::inviteUrl($areeba));
    }

    public function test_the_invite_link_sends_a_guest_to_register_and_names_the_inviter(): void
    {
        $areeba = $this->member();
        Setting::put('loyalty_referral_points', 300);

        $response = $this->get(Referral::inviteUrl($areeba));
        $response->assertRedirect(route('customer.register'))
            ->assertCookie(Referral::COOKIE)
            ->assertSessionHas('success', fn ($m) => str_contains($m, 'Areeba invited you') && str_contains($m, '300 points'));

        // The register page reads the cookie and says who sent them.
        $this->withCookies([Referral::COOKIE => Referral::codeFor($areeba)])
            ->get(route('customer.register'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('invitedBy.name', 'Areeba')
                ->where('invitedBy.points', 300));

        // An unknown code never dead-ends a shared link.
        $this->get('/invite/NOPE12345')->assertRedirect(route('home'));
    }

    public function test_registering_with_the_cookie_attributes_the_new_member_once(): void
    {
        $areeba = $this->member();
        $code = Referral::codeFor($areeba);

        $this->withCookies([Referral::COOKIE => $code])->post(route('customer.register'), [
            'name' => 'Rima Sultana', 'phone' => '01711100002', 'password' => 'secret123', 'password_confirmation' => 'secret123',
        ])->assertRedirect(route('account'));

        $rima = Customer::where('phone', '01711100002')->firstOrFail();
        $this->assertSame($areeba->id, $rima->referred_by);
        $this->assertFalse($rima->referral_rewarded);

        // Areeba hears about it in her bell.
        $this->assertTrue(CustomerNotification::where('type', 'referral')->where('title', 'Rima joined with your invite')->exists());

        // A later attribution never overwrites the first, and nobody self-refers.
        $other = $this->member('Other One', '01711100003');
        $request = \Illuminate\Http\Request::create('/', 'GET', cookies: [Referral::COOKIE => Referral::codeFor($other)]);
        $this->assertNull(Referral::attach($rima->fresh(), $request));
        $this->assertSame($areeba->id, $rima->fresh()->referred_by);

        $self = \Illuminate\Http\Request::create('/', 'GET', cookies: [Referral::COOKIE => $code]);
        $this->assertNull(Referral::attach($areeba->fresh(), $self));
        $this->assertNull($areeba->fresh()->referred_by);
    }

    public function test_the_first_delivered_order_pays_both_sides_exactly_once(): void
    {
        Setting::put('loyalty_enabled', 1);
        Setting::put('loyalty_referral_points', 300);
        $areeba = $this->member();
        $rima = Customer::create(['name' => 'Rima Sultana', 'phone' => '01711100002', 'password' => 'secret123', 'referred_by' => $areeba->id]);

        $this->actingAs($rima, 'customer');
        $this->post(route('cart.add', $this->product(2000)), ['qty' => 1]);
        $this->post(route('checkout.store'), ['name' => 'Rima Sultana', 'phone' => '01711100002', 'address' => 'Dhaka']);
        $order = Order::latest('id')->firstOrFail();
        $this->assertSame($rima->id, $order->customer_id);

        $transition = app(TransitionOrderStatus::class);
        $transition->handle($order->fresh(), 'delivered');

        $this->assertSame(300, $areeba->fresh()->points);
        // Rima: 300 referral + 200 earned on the ৳2,000 order itself.
        $this->assertSame(500, $rima->fresh()->points);
        $this->assertTrue($rima->fresh()->referral_rewarded);
        $this->assertSame(1, PointTransaction::where('type', 'referral_referrer')->count());
        $this->assertSame(1, PointTransaction::where('type', 'referral_referred')->count());

        // Re-saving "delivered" is a no-op; a return takes it back; a
        // re-delivery of the SAME order cannot mint it a second time.
        $transition->handle($order->fresh(), 'delivered');
        $this->assertSame(300, $areeba->fresh()->points);

        $transition->handle($order->fresh(), 'returned');
        $this->assertSame(0, $areeba->fresh()->points);
        $this->assertFalse($rima->fresh()->referral_rewarded);
        $this->assertSame(1, PointTransaction::where('type', 'referral_referrer_reverse')->count());

        $transition->handle($order->fresh(), 'delivered');
        $this->assertSame(0, $areeba->fresh()->points);
        $this->assertSame(1, PointTransaction::where('type', 'referral_referrer')->count());
    }

    public function test_the_invite_page_shows_the_link_and_the_tally(): void
    {
        Setting::put('loyalty_referral_points', 300);
        $areeba = $this->member();
        Customer::create(['name' => 'Rima', 'phone' => '01711100002', 'password' => 'x', 'referred_by' => $areeba->id, 'referral_rewarded' => true]);
        Customer::create(['name' => 'Tanha', 'phone' => '01711100004', 'password' => 'x', 'referred_by' => $areeba->id]);

        $this->actingAs($areeba, 'customer')->get(route('account.referrals'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Account/Referrals')
                ->where('invite.points', 300)
                ->where('invite.stats.joined', 2)
                ->where('invite.stats.rewarded', 1)
                ->where('invite.url', Referral::inviteUrl($areeba->fresh())));
    }
}

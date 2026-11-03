<?php

namespace Tests\Feature;

use App\Actions\TransitionOrderStatus;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Models\Wallet;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Wallet credit per mobile number (owner, 2026-11-03): added in the admin,
 * spent at checkout on products only, returned when the order unwinds.
 */
class WalletTest extends TestCase
{
    use RefreshDatabase;

    protected function admin(): User
    {
        return User::firstOrCreate(['email' => 'a@b.test'], ['name' => 'Admin', 'password' => bcrypt('secret'), 'role' => 'admin']);
    }

    protected function product(float $price = 1000): Product
    {
        static $n = 0;
        $n++;

        return Product::create([
            'name' => 'Ring '.$n,
            'slug' => 'wallet-ring-'.$n,
            'status' => 'published',
            'price' => $price,
            'manage_stock' => true,
            'stock_quantity' => 5,
            'in_stock' => true,
        ]);
    }

    protected function checkout(array $override = [])
    {
        $p = $this->product();
        $this->post('/cart/add/'.$p->slug, ['qty' => 1])->assertRedirect();

        return $this->post('/checkout', array_merge([
            'name' => 'Test Buyer',
            'phone' => '01712345678',
            'address' => '123 Road, Dhaka',
            'is_inside_dhaka' => 1,
            'use_wallet' => 1,
        ], $override));
    }

    public function test_admin_adds_money_to_a_number_typed_any_way(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.customers.wallets.store'), ['phone' => '+880 1712-345678', 'direction' => 'add', 'amount' => 500, 'note' => 'Refund'])
            ->assertSessionHas('success');

        $wallet = Wallet::sole();
        $this->assertSame('01712345678', $wallet->phone);
        $this->assertEquals(500, (float) $wallet->balance);
        $this->assertSame('credit', $wallet->transactions()->sole()->type);
    }

    public function test_taking_off_more_than_the_balance_is_refused(): void
    {
        app(WalletService::class)->adjust('01712345678', 300);

        $this->actingAs($this->admin())
            ->post(route('admin.customers.wallets.store'), ['phone' => '01712345678', 'direction' => 'take', 'amount' => 400])
            ->assertSessionHasErrors('amount');
        $this->assertEquals(300, app(WalletService::class)->balanceFor('01712345678'));

        $this->actingAs($this->admin())
            ->post(route('admin.customers.wallets.store'), ['phone' => '01712345678', 'direction' => 'take', 'amount' => 100])
            ->assertSessionHas('success');
        $this->assertEquals(200, app(WalletService::class)->balanceFor('01712345678'));
    }

    public function test_checkout_spends_credit_on_products_but_not_delivery(): void
    {
        app(WalletService::class)->adjust('01712345678', 1500);

        $this->checkout()->assertRedirect();

        $order = Order::sole();
        $this->assertEquals(1000, (float) $order->wallet_amount);
        $this->assertEquals(1000, (float) $order->discount);
        // The rider collects the delivery charge and nothing else.
        $this->assertEquals((float) $order->shipping_cost, (float) $order->total);
        $this->assertEquals(500, app(WalletService::class)->balanceFor('01712345678'));
        $this->assertDatabaseHas('wallet_transactions', ['order_id' => $order->id, 'type' => 'spend', 'amount' => -1000]);
    }

    public function test_a_smaller_balance_is_used_up_and_the_rest_is_cash(): void
    {
        app(WalletService::class)->adjust('01712345678', 250);

        $this->checkout(['phone' => '+8801712345678'])->assertRedirect();

        $order = Order::sole();
        $this->assertEquals(250, (float) $order->wallet_amount);
        $this->assertEquals(750 + (float) $order->shipping_cost, (float) $order->total);
        $this->assertEquals(0, app(WalletService::class)->balanceFor('01712345678'));
    }

    public function test_credit_stays_put_when_she_unticks_it_or_orders_on_another_number(): void
    {
        app(WalletService::class)->adjust('01712345678', 500);

        $this->checkout(['use_wallet' => 0])->assertRedirect();
        $this->checkout(['phone' => '01812345678'])->assertRedirect();

        $this->assertEquals(0, (float) Order::sum('wallet_amount'));
        $this->assertEquals(500, app(WalletService::class)->balanceFor('01712345678'));
    }

    public function test_a_cancelled_order_returns_the_credit_once(): void
    {
        app(WalletService::class)->adjust('01712345678', 400);
        $this->checkout()->assertRedirect();
        $order = Order::sole();

        app(TransitionOrderStatus::class)->handle($order, 'cancelled');
        app(TransitionOrderStatus::class)->handle($order->fresh(), 'processing');
        app(TransitionOrderStatus::class)->handle($order->fresh(), 'cancelled');

        $this->assertEquals(400, app(WalletService::class)->balanceFor('01712345678'));
        $this->assertNotNull($order->fresh()->wallet_refunded_at);
        $this->assertDatabaseCount('wallet_transactions', 3); // credit, spend, one refund
    }

    public function test_the_checkout_learns_the_balance_when_the_number_is_typed(): void
    {
        app(WalletService::class)->adjust('01712345678', 350);
        $p = $this->product();
        $this->post('/cart/add/'.$p->slug, ['qty' => 1]);

        $this->postJson('/checkout/lead', ['phone' => '01712 345678'])
            ->assertOk()
            ->assertJsonPath('wallet.phone', '01712345678')
            ->assertJsonPath('wallet.balance', 350);

        $this->postJson('/checkout/lead', ['phone' => '01812345678'])
            ->assertOk()
            ->assertJsonPath('wallet', null);
    }

    public function test_the_admin_pages_render(): void
    {
        app(WalletService::class)->adjust('01712345678', 200, 'Goodwill');
        $wallet = Wallet::sole();

        $this->actingAs($this->admin())->get(route('admin.customers.wallets'))
            ->assertOk()->assertSee('01712345678');
        $this->actingAs($this->admin())->get(route('admin.customers.wallets.show', $wallet))
            ->assertOk()->assertSee('Goodwill');
    }

    public function test_adding_credit_offers_a_whatsapp_message_filled_in(): void
    {
        \App\Models\Customer::create(['name' => 'Rina', 'phone' => '01712345678']);

        $res = $this->actingAs($this->admin())
            ->post(route('admin.customers.wallets.store'), ['phone' => '01712345678', 'direction' => 'add', 'amount' => 500]);

        $url = session('wallet_whatsapp')['url'];
        $this->assertStringStartsWith('https://wa.me/8801712345678?text=', $url);
        $text = rawurldecode(substr($url, strpos($url, '=') + 1));
        $this->assertStringContainsString('Hello Rina!', $text);
        $this->assertStringContainsString(money(500), $text);

        $this->actingAs($this->admin())->get(route('admin.customers.wallets'))
            ->assertSee('Message on WhatsApp');
    }

    public function test_taking_money_off_offers_no_message(): void
    {
        app(WalletService::class)->adjust('01712345678', 300);

        $this->actingAs($this->admin())
            ->post(route('admin.customers.wallets.store'), ['phone' => '01712345678', 'direction' => 'take', 'amount' => 100]);

        $this->assertNull(session('wallet_whatsapp'));
    }

    public function test_the_owner_edits_the_message_and_blank_restores_the_default(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.customers.wallets.template'), ['template' => 'Hi {name}, {amount} for you. Balance {balance}.'])
            ->assertSessionHas('success');

        // No customer on the number: "Hi {name}," reads "Hi,".
        $text = rawurldecode(explode('?text=', app(WalletService::class)->whatsappLink('01712345678', 200, 350))[1]);
        $this->assertSame('Hi, '.money(200).' for you. Balance '.money(350).'.', $text);

        $this->actingAs($this->admin())->post(route('admin.customers.wallets.template'), ['template' => '']);
        $this->assertSame(WalletService::WHATSAPP_DEFAULT, app(WalletService::class)->whatsappTemplate());
    }
}

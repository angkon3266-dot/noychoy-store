<?php

namespace Tests\Feature;

use App\Jobs\SendReviewRequest;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The post-delivery review request — the one thing on the roadmap that moves
 * conversion most, because all 105 products currently have zero reviews.
 *
 * It spends the owner's SMS credit, so the tests care as much about what it
 * does NOT send as about what it does: off by default, once per order, never
 * for an ancient delivery, never past the per-run cap.
 */
class ReviewRequestTest extends TestCase
{
    use RefreshDatabase;

    protected function deliveredOrder(int $daysAgo = 5, string $phone = '01711111111'): Order
    {
        $product = Product::create([
            'name' => 'Review Ring '.uniqid(),
            'slug' => 'review-ring-'.uniqid(),
            'status' => 'published',
            'price' => 3000,
            'manage_stock' => false,
        ]);

        $order = Order::create([
            'order_number' => 'NOY-'.random_int(100000, 999999),
            'customer_name' => 'Buyer',
            'customer_phone' => $phone,
            'shipping_address' => 'Dhaka',
            'status' => 'delivered',
            'subtotal' => 3000,
            'shipping_cost' => 0,
            'total' => 3000,
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'name' => $product->name,
            'price' => 3000,
            'quantity' => 1,
            'subtotal' => 3000,
        ]);

        // The command reads the delivery time out of the status history, which
        // TransitionOrderStatus writes on every change — so no backfill is
        // needed for orders delivered before the feature shipped.
        $history = $order->history()->create(['status' => 'delivered']);
        // Eloquent stamps created_at on insert, so back-date it afterwards.
        $history->forceFill(['created_at' => now()->subDays($daysAgo)])->saveQuietly();

        return $order;
    }

    public function test_it_sends_nothing_while_the_automation_is_off(): void
    {
        Queue::fake();
        $this->deliveredOrder();

        $this->artisan('reviews:request')->assertExitCode(0);

        Queue::assertNothingPushed();
        $this->assertNull(Order::first()->review_request_sent_at);
    }

    public function test_it_queues_one_request_per_delivered_order(): void
    {
        Queue::fake();
        Setting::put('review_request_enabled', true);
        $order = $this->deliveredOrder(5);

        $this->artisan('reviews:request')->assertExitCode(0);

        Queue::assertPushed(SendReviewRequest::class, 1);
        $this->assertNotNull($order->fresh()->review_request_sent_at);
    }

    public function test_an_order_is_never_asked_twice(): void
    {
        Queue::fake();
        Setting::put('review_request_enabled', true);
        $this->deliveredOrder(5);

        $this->artisan('reviews:request');
        $this->artisan('reviews:request');

        // An admin toggling delivered → shipped → delivered must not make the
        // shop pay for a second SMS.
        Queue::assertPushed(SendReviewRequest::class, 1);
    }

    public function test_it_waits_for_the_delay_window(): void
    {
        Queue::fake();
        Setting::put('review_request_enabled', true);
        Setting::put('review_request_delay_days', 3);
        $this->deliveredOrder(1);

        $this->artisan('reviews:request');

        Queue::assertNothingPushed();
    }

    public function test_it_skips_deliveries_older_than_the_window(): void
    {
        Queue::fake();
        Setting::put('review_request_enabled', true);
        Setting::put('review_request_max_days', 30);

        // The deploy-day safety valve: without this the first run would text
        // every historical delivered order at once.
        $this->deliveredOrder(120);

        $this->artisan('reviews:request');

        Queue::assertNothingPushed();
    }

    public function test_it_honours_the_per_run_cap(): void
    {
        Queue::fake();
        Setting::put('review_request_enabled', true);
        Setting::put('review_request_per_run', 2);

        $this->deliveredOrder(5, '01711111111');
        $this->deliveredOrder(5, '01722222222');
        $this->deliveredOrder(5, '01733333333');

        $this->artisan('reviews:request');

        Queue::assertPushed(SendReviewRequest::class, 2);
    }

    public function test_a_dry_run_sends_nothing_and_stamps_nothing(): void
    {
        Queue::fake();
        Setting::put('review_request_enabled', true);
        $order = $this->deliveredOrder(5);

        $this->artisan('reviews:request --dry')->assertExitCode(0);

        Queue::assertNothingPushed();
        $this->assertNull($order->fresh()->review_request_sent_at);
    }

    public function test_the_signed_link_opens_the_review_page_for_a_guest(): void
    {
        $order = $this->deliveredOrder(5);

        // COD buyers mostly never register, so the link cannot demand a login.
        $link = URL::signedRoute('order.review', ['orderNumber' => $order->order_number]);

        $this->get($link)->assertOk()->assertInertia(
            fn (Assert $page) => $page
                ->component('ReviewInvite')
                ->where('order.number', $order->order_number)
                // The phone travels to the form: it is what earns the
                // "Verified buyer" badge on the way back in.
                ->where('order.phone', $order->customer_phone)
                ->has('items', 1)
                ->where('items.0.done', false)
        );
    }

    public function test_an_unsigned_link_is_turned_away(): void
    {
        $order = $this->deliveredOrder(5);

        // Order numbers are guessable, so the bare URL must not open.
        $this->get(route('order.review', $order->order_number))
            ->assertRedirect(route('track'));
    }

    public function test_a_tampered_signature_is_turned_away(): void
    {
        $order = $this->deliveredOrder(5);
        $link = URL::signedRoute('order.review', ['orderNumber' => $order->order_number]);

        $this->get($link.'x')->assertRedirect(route('track'));
    }

    public function test_a_review_left_earlier_shows_as_already_done(): void
    {
        $order = $this->deliveredOrder(5);
        $item = $order->items()->first();

        \App\Models\Review::create([
            'product_id' => $item->product_id,
            'author_name' => 'Buyer',
            'phone' => $order->customer_phone,
            'rating' => 5,
            'status' => 'pending',
        ]);

        $link = URL::signedRoute('order.review', ['orderNumber' => $order->order_number]);

        $this->get($link)->assertInertia(
            fn (Assert $page) => $page->where('items.0.done', true)
        );
    }

    public function test_the_owner_can_see_and_save_the_settings_in_the_admin(): void
    {
        $admin = \App\Models\User::create([
            'name' => 'Owner', 'email' => 'owner@review.test',
            'password' => 'secret-pass', 'role' => 'admin',
        ]);
        $this->deliveredOrder(5);

        // The card renders, with the live due count the command would use.
        $this->actingAs($admin)->get(route('admin.notifications.index'))
            ->assertOk()
            ->assertSee('Post-delivery review requests')
            ->assertSee('order(s) are due right now', false);

        $this->actingAs($admin)->post(route('admin.notifications.review-requests'), [
            'review_request_enabled' => '1',
            'review_request_delay_days' => 4,
            'review_request_max_days' => 21,
            'review_request_per_run' => 25,
        ])->assertRedirect();

        $this->assertTrue((bool) Setting::get('review_request_enabled'));
        $this->assertSame(4, (int) Setting::get('review_request_delay_days'));
        $this->assertSame(21, (int) Setting::get('review_request_max_days'));
        $this->assertSame(25, (int) Setting::get('review_request_per_run'));
    }

    public function test_the_sms_template_carries_the_link_placeholder(): void
    {
        // sendTemplate() falls back to config/sms.php when the admin has saved
        // nothing; without a default the send silently returns false.
        $this->assertStringContainsString('{link}', config('sms.templates.review_request'));
    }
}

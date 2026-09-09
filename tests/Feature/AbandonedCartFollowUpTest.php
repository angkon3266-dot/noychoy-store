<?php

namespace Tests\Feature;

use App\Jobs\SendAbandonedCartSms;
use App\Models\AbandonedCart;
use App\Models\AbandonedCartContact;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Support\AbandonedCartOutreach;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * An abandoned cart was a phone number and a list of product names, shown on a
 * screen whose only action was "mark contacted". Everything else the shopper
 * typed — where to deliver it, which zone, who she is — was discarded unless
 * the order completed, so recovering the sale meant asking for the address a
 * second time on the phone, and nobody could tell whether a lead had already
 * been called or what was said.
 *
 * The trap this pins hardest: `updated_at` is the abandoned-cart SMS runner's
 * entire due window, so an admin action that touches it would make a
 * three-week-old cart look freshly abandoned and text the shopper again.
 */
class AbandonedCartFollowUpTest extends TestCase
{
    use RefreshDatabase;

    protected function admin(): User
    {
        return User::firstOrCreate(
            ['email' => 'a@b.test'],
            ['name' => 'Admin', 'password' => bcrypt('secret'), 'role' => 'admin'],
        );
    }

    protected function enableSms(): void
    {
        Setting::put('integrations', [
            'sms_enabled' => true,
            'sms_base_url' => 'https://sms.test',
            'sms_api_key' => 'key',
            'sms_secret_key' => 'secret',
            'sms_caller_id' => 'NoyChoy',
        ]);
    }

    protected function lead(array $overrides = []): AbandonedCart
    {
        return AbandonedCart::create(array_merge([
            'session_id' => 'sess-1',
            'phone' => '01712345678',
            'name' => 'Fatima Rahman',
            'address' => '12/3 Green Road, Flat 5B',
            'area' => 'Dhanmondi',
            'is_inside_dhaka' => true,
            'items' => [['product_id' => null, 'variant_id' => null, 'name' => 'Pearl Ring', 'qty' => 2, 'price' => 1500]],
            'subtotal' => 3000,
            'item_count' => 2,
            'last_step' => 'checkout',
        ], $overrides));
    }

    // ── Capture ────────────────────────────────────────────────────────────

    public function test_the_checkout_keeps_the_address_she_typed_not_only_her_number(): void
    {
        $product = Product::create([
            'name' => 'Pearl Ring', 'slug' => 'pearl-ring', 'status' => 'published',
            'price' => 1500, 'manage_stock' => false,
        ]);

        $this->post(route('cart.add', $product), ['quantity' => 1]);

        $this->postJson(route('checkout.lead'), [
            'phone' => '+8801712345678',
            'name' => 'Fatima',
            'address' => '12/3 Green Road',
            'area' => 'Dhanmondi',
            'is_inside_dhaka' => true,
        ])->assertOk();

        $cart = AbandonedCart::latest('id')->first();

        $this->assertSame('01712345678', $cart->phone, 'the number must be canonicalised or it never matches an order');
        $this->assertSame('12/3 Green Road', $cart->address);
        $this->assertSame('Dhanmondi', $cart->area);
        $this->assertTrue($cart->is_inside_dhaka);
    }

    public function test_a_later_capture_without_an_address_does_not_wipe_the_one_already_stored(): void
    {
        // The name and phone blurs fire before the address is typed, and a page
        // reload blanks the form again for a guest, so a later POST of the same
        // session carries no address. Writing it would throw away what the
        // first capture had already learned.
        $product = Product::create([
            'name' => 'Pearl Ring', 'slug' => 'pearl-ring', 'status' => 'published',
            'price' => 1500, 'manage_stock' => false,
        ]);
        $this->post(route('cart.add', $product), ['quantity' => 1]);

        $this->withCredentials()->postJson(route('checkout.lead'), [
            'phone' => '01712345678',
            'name' => 'Fatima',
            'address' => '12/3 Green Road, Flat 5B',
            'area' => 'Dhanmondi',
            'is_inside_dhaka' => true,
        ])->assertOk();

        // The harness mints a fresh session id per request, so the cookie has
        // to be pinned — and postJson only sends cookies under
        // withCredentials(). Without both, updateOrCreate() inserts a SECOND
        // row and the merge guard is never reached, which is exactly how this
        // test used to pass while proving nothing.
        $session = AbandonedCart::sole()->session_id;

        $this->withCredentials()
            ->withCookie(config('session.cookie'), $session)
            ->postJson(route('checkout.lead'), ['phone' => '01712345678', 'name' => 'Fatima R'])
            ->assertOk();

        $cart = AbandonedCart::sole(); // one lead, not two
        $this->assertSame('Fatima R', $cart->name, 'the newly typed name must still land');
        $this->assertSame('12/3 Green Road, Flat 5B', $cart->address);
        $this->assertSame('Dhanmondi', $cart->area);
        $this->assertTrue($cart->is_inside_dhaka);
    }

    public function test_a_capture_with_nothing_typed_invents_nothing(): void
    {
        $product = Product::create([
            'name' => 'Ring', 'slug' => 'ring-2', 'status' => 'published',
            'price' => 1500, 'manage_stock' => false,
        ]);
        $this->post(route('cart.add', $product), ['quantity' => 1]);
        $this->postJson(route('checkout.lead'), ['phone' => '01799999999'])->assertOk();

        $captured = AbandonedCart::where('phone', '01799999999')->sole();
        $this->assertNull($captured->address);
        $this->assertNull($captured->area);
        $this->assertNull($captured->is_inside_dhaka, '"never chose a zone" is not "outside Dhaka"');
    }

    public function test_a_shopper_who_chooses_outside_dhaka_is_recorded_as_outside_dhaka(): void
    {
        // false is a real answer, not a blank one — the merge guard skips empty
        // values, so a naive filled() check would drop it and the caller would
        // be told the zone was never picked.
        $product = Product::create([
            'name' => 'Ring', 'slug' => 'ring-3', 'status' => 'published',
            'price' => 1500, 'manage_stock' => false,
        ]);
        $this->post(route('cart.add', $product), ['quantity' => 1]);

        $this->postJson(route('checkout.lead'), [
            'phone' => '01766554433',
            'address' => 'Zindabazar, Sylhet',
            'is_inside_dhaka' => false,
        ])->assertOk();

        $this->assertFalse(AbandonedCart::where('phone', '01766554433')->sole()->is_inside_dhaka);
    }

    // ── The lead page ──────────────────────────────────────────────────────

    public function test_the_lead_page_shows_the_cart_the_address_and_a_way_to_reach_her(): void
    {
        $cart = $this->lead();

        $response = $this->actingAs($this->admin())
            ->get(route('admin.abandoned.show', $cart))
            ->assertOk();

        $response->assertSee('Pearl Ring');
        $response->assertSee('12/3 Green Road, Flat 5B');
        $response->assertSee('Dhanmondi');
        $response->assertSee('Inside Dhaka');
        // Call and WhatsApp, both in the international form those schemes need.
        $response->assertSee('tel:+8801712345678', false);
        $response->assertSee('https://wa.me/8801712345678', false);
    }

    public function test_a_line_that_still_sells_links_to_it_and_one_that_is_gone_is_flagged(): void
    {
        // The caller needs to know before dialling: the restore link silently
        // drops a line whose product has been unpublished or deleted, so a
        // page that showed both alike would have her promising a piece the
        // link will not give back.
        $live = Product::create([
            'name' => 'Pearl Ring', 'slug' => 'pearl-ring', 'status' => 'published',
            'price' => 1500, 'manage_stock' => false,
        ]);
        $pulled = Product::create([
            'name' => 'Retired Cuff', 'slug' => 'retired-cuff', 'status' => 'draft',
            'price' => 2400, 'manage_stock' => false,
        ]);

        $cart = $this->lead(['items' => [
            ['product_id' => $live->id, 'variant_id' => null, 'name' => 'Pearl Ring', 'qty' => 2, 'price' => 1500],
            ['product_id' => $pulled->id, 'variant_id' => null, 'name' => 'Retired Cuff', 'qty' => 1, 'price' => 2400],
            ['product_id' => 999999, 'variant_id' => null, 'name' => 'Deleted Piece', 'qty' => 1, 'price' => 900],
        ]]);

        $response = $this->actingAs($this->admin())
            ->get(route('admin.abandoned.show', $cart))
            ->assertOk();

        // The live one is a link to the storefront; the other two are flagged.
        $response->assertSee(route('product.show', $live), false);
        $response->assertDontSee(route('product.show', $pulled), false);
        $response->assertSee('No longer available');
        $response->assertSee('Deleted Piece');
    }

    public function test_a_lead_whose_name_contains_markup_is_escaped(): void
    {
        // The name and address come straight from a public checkout form.
        $cart = $this->lead(['name' => '<script>alert(1)</script>']);

        $this->actingAs($this->admin())
            ->get(route('admin.abandoned.show', $cart))
            ->assertOk()
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertSee('&lt;script&gt;', false);
    }

    public function test_every_channel_carries_the_link_that_rebuilds_her_cart(): void
    {
        $cart = $this->lead();

        $link = AbandonedCartOutreach::restoreLink($cart);
        $this->assertStringContainsString('signature=', $link, 'an unsigned restore link is a 403');

        // The signature must actually validate, or the message sends her nowhere.
        $this->get($link)->assertRedirect();

        $message = AbandonedCartOutreach::whatsappMessage($cart);
        $this->assertStringContainsString($link, $message);

        $waLink = AbandonedCartOutreach::whatsappLink($cart);
        $this->assertStringStartsWith('https://wa.me/8801712345678?text=', $waLink);
        $this->assertStringContainsString(rawurlencode($link), $waLink);
    }

    public function test_a_template_edited_without_the_link_placeholder_still_carries_the_link(): void
    {
        // The link is the only part of the message that recovers the sale, so
        // an owner cannot drop it by rewording the template.
        Setting::put('whatsapp_abandoned_template', 'Hi {name}, are you still thinking about it?');

        $message = AbandonedCartOutreach::whatsappMessage($this->lead());

        $this->assertStringContainsString('Hi Fatima, are you still thinking about it?', $message);
        $this->assertStringContainsString('/cart/restore/', $message);
    }

    // ── Follow-up log ──────────────────────────────────────────────────────

    public function test_logging_a_call_records_who_what_and_when_and_marks_the_lead_contacted(): void
    {
        $cart = $this->lead();
        $admin = $this->admin();

        $this->actingAs($admin)
            ->post(route('admin.abandoned.log', $cart), [
                'channel' => 'call',
                'outcome' => 'will_order',
                'note' => 'Call back after 8pm.',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $contact = AbandonedCartContact::sole();
        $this->assertSame('call', $contact->channel);
        $this->assertSame('will_order', $contact->outcome);
        $this->assertSame('Call back after 8pm.', $contact->note);
        $this->assertSame($admin->id, $contact->user_id);

        $fresh = $cart->fresh();
        $this->assertTrue($fresh->contacted);
        $this->assertNotNull($fresh->last_contacted_at);
    }

    public function test_following_up_does_not_make_an_old_cart_look_freshly_abandoned(): void
    {
        // sms:abandoned-cart selects on `updated_at >= now()-max_hours`. If
        // marking a lead contacted bumped it, a cart from three weeks ago would
        // re-enter the queue and the shopper would be texted out of nowhere.
        $cart = $this->lead();
        $cart->forceFill(['updated_at' => now()->subDays(21)])->saveQuietly();
        $before = $cart->fresh()->updated_at;

        $this->actingAs($this->admin())
            ->post(route('admin.abandoned.log', $cart), ['channel' => 'call'])
            ->assertRedirect();

        $this->assertTrue(
            $before->equalTo($cart->fresh()->updated_at),
            'a follow-up must not touch updated_at — it is the SMS runner\'s due window',
        );
    }

    public function test_the_contact_form_rejects_a_channel_it_does_not_know(): void
    {
        $cart = $this->lead();

        $this->actingAs($this->admin())
            ->post(route('admin.abandoned.log', $cart), ['channel' => 'carrier-pigeon'])
            ->assertSessionHasErrors('channel');
    }

    // ── Sending ────────────────────────────────────────────────────────────

    public function test_send_sms_now_queues_the_recovery_message_and_logs_it(): void
    {
        Queue::fake();
        $this->enableSms();
        $cart = $this->lead();

        $this->actingAs($this->admin())
            ->post(route('admin.abandoned.sms', $cart))
            ->assertRedirect()
            ->assertSessionHas('success');

        Queue::assertPushed(SendAbandonedCartSms::class, 1);
        $this->assertNotNull($cart->fresh()->sms_reminded_at);
        $this->assertSame('sms', AbandonedCartContact::sole()->channel);
    }

    public function test_send_sms_refuses_loudly_when_the_gateway_is_not_configured(): void
    {
        Queue::fake();
        $cart = $this->lead();

        $this->actingAs($this->admin())
            ->post(route('admin.abandoned.sms', $cart))
            ->assertRedirect()
            ->assertSessionHas('error');

        Queue::assertNothingPushed();
        $this->assertNull($cart->fresh()->sms_reminded_at, 'a refused send must not look like a sent one');
    }

    public function test_a_recovered_cart_is_never_texted_again(): void
    {
        Queue::fake();
        $this->enableSms();
        $cart = $this->lead(['recovered' => true]);

        $this->actingAs($this->admin())
            ->post(route('admin.abandoned.sms', $cart))
            ->assertSessionHas('warning');

        Queue::assertNothingPushed();
    }

    // ── Bulk ───────────────────────────────────────────────────────────────

    public function test_a_bulk_text_reaches_every_selected_lead(): void
    {
        Queue::fake();
        $this->enableSms();

        $ids = collect(['01711111111', '01722222222', '01733333333'])
            ->map(fn ($phone, $i) => $this->lead(['phone' => $phone, 'session_id' => 'sess-'.$i])->id);

        $this->actingAs($this->admin())
            ->post(route('admin.abandoned.bulk'), ['bulk_action' => 'sms', 'ids' => $ids->all()])
            ->assertRedirect()
            ->assertSessionHas('success');

        Queue::assertPushed(SendAbandonedCartSms::class, 3);
    }

    public function test_a_second_bulk_text_does_not_buy_the_same_message_twice(): void
    {
        Queue::fake();
        $this->enableSms();
        $cart = $this->lead();

        $send = fn () => $this->actingAs($this->admin())
            ->post(route('admin.abandoned.bulk'), ['bulk_action' => 'sms', 'ids' => [$cart->id]]);

        $send();
        $second = $send();

        Queue::assertPushed(SendAbandonedCartSms::class, 1);
        $this->assertStringContainsString('Skipped', $second->getSession()->get('success'));
    }

    public function test_bulk_mark_contacted_and_bulk_delete_do_what_they_say(): void
    {
        $keep = $this->lead(['phone' => '01711111111', 'session_id' => 'sess-a']);
        $drop = $this->lead(['phone' => '01722222222', 'session_id' => 'sess-b']);

        $this->actingAs($this->admin())
            ->post(route('admin.abandoned.bulk'), ['bulk_action' => 'contacted', 'ids' => [$keep->id]])
            ->assertRedirect();
        $this->assertTrue($keep->fresh()->contacted);

        $this->actingAs($this->admin())
            ->post(route('admin.abandoned.bulk'), ['bulk_action' => 'delete', 'ids' => [$drop->id]])
            ->assertRedirect();
        $this->assertNull($drop->fresh());
        $this->assertNotNull($keep->fresh());
    }

    public function test_bulk_delete_does_not_bounce_the_owner_into_the_lead_it_just_deleted(): void
    {
        // Reported as a 404 on delete. The bulk bar is on the list, but back()
        // goes to the last page the browser actually ASKED the server for —
        // and leaving a lead page with the browser's own Back button never
        // asks. So deleting that lead redirected straight into its own 404.
        $cart = $this->lead();
        $showUrl = route('admin.abandoned.show', $cart);

        $this->actingAs($this->admin())->get($showUrl)->assertOk();

        $this->actingAs($this->admin())
            ->post(route('admin.abandoned.bulk'), ['bulk_action' => 'delete', 'ids' => [$cart->id]])
            ->assertRedirect(route('admin.abandoned.index'));
    }

    public function test_bulk_delete_still_returns_to_the_list_the_owner_was_filtering(): void
    {
        // The guard must only fire for the deleted rows — a filtered list has
        // to survive the round trip, or every delete loses the owner's place.
        $keep = $this->lead(['phone' => '01711111111', 'session_id' => 'sess-a']);
        $drop = $this->lead(['phone' => '01722222222', 'session_id' => 'sess-b']);

        $filtered = route('admin.abandoned.index', ['filter' => 'open', 'q' => '017']);
        $this->actingAs($this->admin())->get($filtered)->assertOk();

        $this->actingAs($this->admin())
            ->post(route('admin.abandoned.bulk'), ['bulk_action' => 'delete', 'ids' => [$drop->id]])
            ->assertRedirect($filtered);

        $this->assertNotNull($keep->fresh());
    }

    public function test_a_lead_that_is_already_gone_explains_itself_instead_of_404ing(): void
    {
        // Its URL outlives it: the notification bell caches an alert pointing
        // at the lead, the owner bookmarks it, a second person has it open.
        $cart = $this->lead();
        $showUrl = route('admin.abandoned.show', $cart);
        $cart->delete();

        foreach ([
            ['get', $showUrl],
            ['post', route('admin.abandoned.sms', $cart->id)],
            ['post', route('admin.abandoned.log', $cart->id)],
            ['delete', route('admin.abandoned.destroy', $cart->id)],
        ] as [$verb, $url]) {
            $this->actingAs($this->admin())
                ->{$verb}($url, $verb === 'get' ? [] : ['channel' => 'call'])
                ->assertRedirect(route('admin.abandoned.index'))
                ->assertSessionHas('warning');
        }
    }

    public function test_deleting_a_lead_takes_its_follow_up_history_with_it(): void
    {
        $cart = $this->lead();
        $this->actingAs($this->admin())->post(route('admin.abandoned.log', $cart), ['channel' => 'call']);
        $this->assertSame(1, AbandonedCartContact::count());

        $this->actingAs($this->admin())
            ->delete(route('admin.abandoned.destroy', $cart))
            // Not back() — the page it was deleted from no longer exists.
            ->assertRedirect(route('admin.abandoned.index'));

        $this->assertSame(0, AbandonedCartContact::count());
    }

    // ── The list ───────────────────────────────────────────────────────────

    public function test_the_list_searches_a_pasted_international_number(): void
    {
        $this->lead(['phone' => '01712345678', 'name' => 'Fatima']);
        $this->lead(['phone' => '01799999999', 'name' => 'Someone Else', 'session_id' => 'sess-2']);

        // Asserted on the numbers, not the names: the layout's notification
        // bell lists open leads by name on every admin page, so a name would
        // pass whether or not the table was actually filtered.
        $this->actingAs($this->admin())
            ->get(route('admin.abandoned.index', ['q' => '+880 1712-345678']))
            ->assertOk()
            ->assertSee('01712345678')
            ->assertDontSee('01799999999');
    }

    public function test_the_open_filter_hides_leads_that_are_done_with(): void
    {
        $waiting = $this->lead(['phone' => '01711111111', 'name' => 'Waiting Shopper']);
        $this->lead(['phone' => '01722222222', 'name' => 'Already Called', 'session_id' => 'sess-2', 'contacted' => true]);
        $this->lead(['phone' => '01733333333', 'name' => 'Came Back', 'session_id' => 'sess-3', 'recovered' => true]);

        $this->actingAs($this->admin())
            ->get(route('admin.abandoned.index', ['filter' => 'open']))
            ->assertOk()
            ->assertSee('01711111111')
            ->assertDontSee('01722222222')
            ->assertDontSee('01733333333');

        $this->assertSame(1, AbandonedCart::open()->count());
        $this->assertTrue($waiting->fresh()->exists);
    }
}

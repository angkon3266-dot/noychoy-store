<?php

namespace Tests\Feature;

use App\Models\AbandonedCart;
use App\Models\CallReminder;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\AdminAlerts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Calls to make later.
 *
 * The owner, 2026-09-17: "create a reminder option where I can add customer
 * lead phone number along with items to call later". She chose a due list, an
 * alert in the notification bell when a reminder comes due, and on each one a
 * Call, a WhatsApp and a "Create order" that opens the manual order form with
 * the number and the items already in it.
 *
 * The clock is pinned at 10:00 UTC on 17 September 2026 — 4 PM in Dhaka, a
 * Thursday — so "this evening", "tomorrow" and "due" mean one thing in every
 * test.
 */
class CallRemindersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-09-17 10:00:00', 'UTC'));
    }

    protected function admin(): User
    {
        return User::firstOrCreate(
            ['email' => 'owner@test.local'],
            ['name' => 'Owner', 'password' => bcrypt('x'), 'role' => 'admin'],
        );
    }

    protected function staff(): User
    {
        return User::firstOrCreate(
            ['email' => 'packer@test.local'],
            ['name' => 'Packer', 'password' => bcrypt('x'), 'role' => 'staff'],
        );
    }

    protected function product(array $attrs = []): Product
    {
        return Product::create(array_merge([
            'name' => 'Pearl Drop Necklace',
            'slug' => 'pearl-drop-'.uniqid(),
            'sku' => 'PDN-1',
            'price' => 1850,
            'status' => 'published',
            'in_stock' => true,
            'manage_stock' => true,
            'stock_quantity' => 10,
        ], $attrs));
    }

    protected function reminder(array $attrs = []): CallReminder
    {
        $reminder = new CallReminder;
        $reminder->forceFill(array_merge([
            'phone' => '01712345678',
            'name' => 'Farida Bari',
            'due_at' => now()->subHours(2),
        ], $attrs))->save();

        return $reminder;
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

    /** The form fields a save needs, due at 7 PM Dhaka today unless overridden. */
    protected function form(array $attrs = []): array
    {
        return array_merge([
            'phone' => '01712345678',
            'name' => 'Farida Bari',
            'due_date' => '2026-09-17',
            'due_time' => '19:00',
        ], $attrs);
    }

    /**
     * The page's own content. The bell in the header names the longest-waiting
     * call on every admin page, so "not on this tab" is asserted inside <main>.
     */
    protected function main(string $html): string
    {
        return (string) strstr((string) strstr($html, '<main'), '</main>', true);
    }

    protected function reminderAlert(User $user): ?array
    {
        Cache::flush();

        return app(AdminAlerts::class)->for($user)
            ->first(fn ($a) => str_starts_with($a['key'], 'reminder.due.'));
    }

    // ── Saving one ───────────────────────────────────────────────────────────

    public function test_a_reminder_is_saved_with_its_number_items_and_due_time(): void
    {
        $necklace = $this->product();
        $ring = $this->product(['name' => 'Opal Band', 'sku' => 'OB', 'price' => 2400, 'has_variants' => true]);
        $size8 = ProductVariant::create([
            'product_id' => $ring->id, 'sku' => 'OB-8', 'attributes' => ['Size' => '8'],
            'price' => 2600, 'stock_quantity' => 3, 'is_active' => true,
        ]);

        $this->actingAs($admin = $this->admin())
            ->post(route('admin.reminders.store'), $this->form([
                'notes' => 'Wants it before Eid',
                'items' => [
                    ['product_id' => $necklace->id, 'qty' => 1, 'price' => ''],
                    // A row added and never used is not a reason to refuse.
                    ['product_id' => '', 'qty' => 1, 'price' => ''],
                    ['product_id' => $ring->id, 'variant_id' => $size8->id, 'qty' => 2, 'price' => ''],
                ],
            ]))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.reminders.index', ['tab' => 'upcoming']));

        $reminder = CallReminder::sole();

        $this->assertSame('01712345678', $reminder->phone);
        $this->assertSame('Farida Bari', $reminder->name);
        $this->assertSame('Wants it before Eid', $reminder->notes);
        // 7 PM in Dhaka is 1 PM UTC — typed in the shop's clock, stored in UTC.
        $this->assertSame('2026-09-17 13:00:00', $reminder->due_at->copy()->utc()->format('Y-m-d H:i:s'));
        // The catalogue's name and price at the time, the option's own price
        // for an option. Equals, not Same: JSON hands a whole ৳1,850 back as 1850.
        $this->assertEquals([
            ['product_id' => $necklace->id, 'variant_id' => null, 'name' => 'Pearl Drop Necklace', 'qty' => 1, 'price' => 1850],
            ['product_id' => $ring->id, 'variant_id' => $size8->id, 'name' => 'Opal Band', 'qty' => 2, 'price' => 2600],
        ], $reminder->items);
        $this->assertSame($admin->id, $reminder->created_by);
        $this->assertNull($reminder->done_at);

        // Listed as a plain line of text, with the option they chose.
        $html = $this->main($this->actingAs($admin)->get(route('admin.reminders.index', ['tab' => 'upcoming']))->assertOk()->getContent());
        $this->assertStringContainsString('Pearl Drop Necklace × 1, Opal Band (Size: 8) × 2', $html);
        $this->assertStringContainsString('Wants it before Eid', $html);
        $this->assertStringContainsString('Today 7:00 PM', $html);
    }

    public function test_a_number_is_stored_the_way_customers_and_orders_store_it(): void
    {
        $customer = $this->customer();

        $this->actingAs($this->admin())
            ->post(route('admin.reminders.store'), $this->form(['phone' => '+880 1712-345678', 'name' => '']))
            ->assertSessionHasNoErrors();

        $reminder = CallReminder::sole();

        $this->assertSame('01712345678', $reminder->phone);
        $this->assertSame($customer->id, $reminder->customer_id, 'the reminder should find the customer the number belongs to');
        $this->assertNull($reminder->name);
        $this->assertSame('Nusrat Jahan', $reminder->displayName(), "a blank name reads as the customer's");
    }

    public function test_a_save_without_a_real_number_or_a_time_is_refused_in_plain_words(): void
    {
        $this->actingAs($this->admin())
            ->from(route('admin.reminders.create'))
            ->post(route('admin.reminders.store'), ['phone' => '12345', 'due_date' => '', 'due_time' => '25:99'])
            ->assertRedirect(route('admin.reminders.create'))
            ->assertSessionHasErrors([
                'phone' => 'Please enter a valid Bangladeshi mobile number (01XXXXXXXXX).',
                'due_date' => 'Choose the day to call.',
                'due_time' => 'Choose the time to call.',
            ]);

        $this->assertSame(0, CallReminder::count());

        $this->actingAs($this->admin())
            ->from(route('admin.reminders.create'))
            ->followingRedirects()
            ->post(route('admin.reminders.store'), ['phone' => '', 'due_date' => '2026-09-17', 'due_time' => '19:00'])
            ->assertOk()
            ->assertSee('The reminder was not saved')
            ->assertSee('Enter the number to call.');
    }

    // ── Starting from what the shop already holds ────────────────────────────

    public function test_the_form_opens_with_a_customers_name_and_number(): void
    {
        $customer = $this->customer();

        $res = $this->actingAs($this->admin())
            ->get(route('admin.reminders.create', ['customer' => $customer->id]))
            ->assertOk()
            ->assertSee('value="01712345678"', false)
            ->assertSee('value="Nusrat Jahan"', false)
            ->assertSee('This number belongs to');

        $this->assertSame($customer->id, $res->viewData('initial')['matched']['id']);
    }

    public function test_the_form_opens_with_a_leads_number_name_and_basket_and_remembers_the_lead(): void
    {
        $necklace = $this->product();
        $cart = AbandonedCart::create([
            'session_id' => 'sess-1', 'name' => 'Farida Bari', 'phone' => '01812345678',
            'items' => [['product_id' => $necklace->id, 'variant_id' => null, 'name' => $necklace->name, 'qty' => 2, 'price' => 1750]],
            'subtotal' => 3500, 'item_count' => 2,
        ]);

        $res = $this->actingAs($this->admin())
            ->get(route('admin.reminders.create', ['cart' => $cart->id]))
            ->assertOk()
            ->assertSee('Farida Bari&rsquo;s abandoned cart', false)
            ->assertSee('value="01812345678"', false)
            ->assertSee('name="abandoned_cart_id" value="'.$cart->id.'"', false);

        // The basket rides in with the price she was shown.
        $this->assertSame(
            [['product_id' => $necklace->id, 'variant_id' => null, 'qty' => 2, 'price' => 1750.0]],
            $res->viewData('initial')['items'],
        );

        $this->actingAs($this->admin())
            ->post(route('admin.reminders.store'), $this->form([
                'phone' => '01812345678',
                'abandoned_cart_id' => $cart->id,
                'items' => [['product_id' => $necklace->id, 'variant_id' => '', 'qty' => 2, 'price' => '1750']],
            ]))
            ->assertSessionHasNoErrors();

        $reminder = CallReminder::sole();
        $this->assertSame($cart->id, $reminder->abandoned_cart_id);
        $this->assertEquals(1750, $reminder->items[0]['price'], 'the price she was shown is kept, not the catalogue’s');
    }

    public function test_the_lead_page_offers_remind_me_to_call(): void
    {
        $cart = AbandonedCart::create([
            'session_id' => 'sess-1', 'name' => 'Farida Bari', 'phone' => '01712345678',
            'items' => [], 'subtotal' => 0, 'item_count' => 0,
        ]);

        $this->actingAs($this->admin())
            ->get(route('admin.abandoned.show', $cart))
            ->assertOk()
            ->assertSee('Remind me to call')
            ->assertSee(route('admin.reminders.create', ['cart' => $cart->id]));
    }

    // ── The list ─────────────────────────────────────────────────────────────

    public function test_due_now_upcoming_and_done_each_list_their_own(): void
    {
        $this->reminder(['name' => 'Overdue Olivia', 'due_at' => now()->subHours(3)]);
        $this->reminder(['name' => 'Later Lamia', 'phone' => '01812345678', 'due_at' => now()->addHours(2)]);
        $this->reminder([
            'name' => 'Done Dilruba', 'phone' => '01912345678', 'due_at' => now()->subDay(),
            'done_at' => now()->subHour(), 'done_by' => $this->admin()->id, 'outcome' => 'Ordering on Friday',
        ]);

        $due = $this->main($this->actingAs($this->admin())->get(route('admin.reminders.index'))->assertOk()->getContent());
        $this->assertStringContainsString('Overdue Olivia', $due);
        $this->assertStringNotContainsString('Later Lamia', $due);
        $this->assertStringNotContainsString('Done Dilruba', $due);

        $upcoming = $this->main($this->actingAs($this->admin())->get(route('admin.reminders.index', ['tab' => 'upcoming']))->assertOk()->getContent());
        $this->assertStringContainsString('Later Lamia', $upcoming);
        $this->assertStringNotContainsString('Overdue Olivia', $upcoming);
        $this->assertStringNotContainsString('Done Dilruba', $upcoming);

        $done = $this->main($this->actingAs($this->admin())->get(route('admin.reminders.index', ['tab' => 'done']))->assertOk()->getContent());
        $this->assertStringContainsString('Done Dilruba', $done);
        $this->assertStringContainsString('Ordering on Friday', $done);
        $this->assertStringContainsString('Owner', $done, 'the Done tab says who made the call');
        $this->assertStringNotContainsString('Overdue Olivia', $done);
    }

    public function test_due_now_puts_the_longest_waiting_first_and_says_how_late(): void
    {
        $this->reminder(['name' => 'Twenty Minutes', 'phone' => '01812345678', 'due_at' => now()->subMinutes(20)]);
        $this->reminder(['name' => 'Three Hours', 'due_at' => now()->subHours(3)]);

        $html = $this->main($this->actingAs($this->admin())->get(route('admin.reminders.index'))->assertOk()->getContent());

        $this->assertLessThan(strpos($html, 'Twenty Minutes'), strpos($html, 'Three Hours'), 'the call that has waited longest comes first');
        $this->assertStringContainsString('Overdue · 3 hours late', $html);
        // Twenty minutes past its time is still on time, not overdue.
        $this->assertStringNotContainsString('20 minutes late', $html);
        // 3 hours before 4 PM in Dhaka.
        $this->assertStringContainsString('Today 1:00 PM', $html);
    }

    public function test_due_times_read_in_dhaka_time(): void
    {
        // 19:30 UTC on the 17th is already 1:30 AM on the 18th in Dhaka.
        $this->assertSame('Tomorrow 1:30 AM', $this->reminder(['due_at' => Carbon::parse('2026-09-17 19:30:00', 'UTC')])->dueLabel());
        $this->assertSame('Today 4:30 PM', $this->reminder(['due_at' => Carbon::parse('2026-09-17 10:30:00', 'UTC')])->dueLabel());
        $this->assertSame('Tomorrow 11:00 AM', $this->reminder(['due_at' => Carbon::parse('2026-09-18 05:00:00', 'UTC')])->dueLabel());

        $later = $this->reminder(['due_at' => Carbon::parse('2026-09-20 05:00:00', 'UTC')]);
        $this->assertSame('In 3 days', $later->dueLabel());
        $this->assertSame('Sun 20 Sep, 11:00 AM', $later->dueExact());
    }

    public function test_the_list_finds_a_reminder_by_name_or_any_shape_of_number(): void
    {
        $this->reminder(['name' => 'Farida Bari', 'phone' => '01712345678']);
        $this->reminder(['name' => 'Rafi Ahmed', 'phone' => '01812345678']);

        foreach (['+880 1712-345', 'Farida'] as $term) {
            $html = $this->main($this->actingAs($this->admin())->get(route('admin.reminders.index', ['q' => $term]))->assertOk()->getContent());

            $this->assertStringContainsString('Farida Bari', $html, "searching {$term}");
            $this->assertStringNotContainsString('Rafi Ahmed', $html, "searching {$term}");
        }
    }

    public function test_each_row_offers_call_whatsapp_and_create_order(): void
    {
        $reminder = $this->reminder();

        $this->actingAs($this->admin())
            ->get(route('admin.reminders.index'))
            ->assertOk()
            ->assertSee('href="tel:+8801712345678"', false)
            ->assertSee('https://wa.me/8801712345678?text=', false)
            ->assertSee(route('admin.orders.create', ['reminder' => $reminder->id]))
            ->assertSee('Snooze')
            ->assertSee('Mark done')
            ->assertSee(route('admin.reminders.edit', $reminder))
            ->assertSee("onsubmit=\"return confirm('Delete this reminder? This cannot be undone.')\"", false);
    }

    public function test_whatsapp_greets_them_in_their_language_and_names_the_pieces(): void
    {
        $items = [['product_id' => 1, 'variant_id' => null, 'name' => 'Pearl Drop Necklace', 'qty' => 1, 'price' => 1850]];

        // A lead the shop knows nothing about gets both, Bangla first.
        $lead = $this->reminder(['items' => $items])->whatsappMessage();
        $this->assertStringStartsWith('হ্যালো Farida', $lead);
        $this->assertStringContainsString('Hello Farida', $lead);
        $this->assertStringContainsString('Pearl Drop Necklace', $lead);

        $english = $this->customer(['locale' => 'en', 'phone' => '01812345678']);
        $message = $this->reminder(['phone' => '01812345678', 'name' => null, 'customer_id' => $english->id, 'items' => $items])->whatsappMessage();
        $this->assertStringStartsWith('Hello Nusrat, this is ', $message);
        $this->assertStringNotContainsString('হ্যালো', $message);

        $bangla = $this->customer(['locale' => 'bn', 'phone' => '01912345678']);
        $message = $this->reminder(['phone' => '01912345678', 'customer_id' => $bangla->id])->whatsappMessage();
        $this->assertStringContainsString('কথামতো যোগাযোগ করছি', $message, 'no items: a follow-up as promised');
        $this->assertStringNotContainsString('Hello', $message);
    }

    // ── Working it ───────────────────────────────────────────────────────────

    public function test_snooze_moves_the_call_on_in_dhaka_time(): void
    {
        $reminder = $this->reminder();

        $snooze = fn (string $until) => $this->actingAs($this->admin())
            ->from(route('admin.reminders.index'))
            ->post(route('admin.reminders.snooze', $reminder), ['until' => $until])
            ->assertRedirect(route('admin.reminders.index'))
            ->assertSessionHas('success');

        $snooze('hour');
        $this->assertSame('2026-09-17 11:00:00', $reminder->refresh()->due_at->format('Y-m-d H:i:s'));

        // 7 PM Dhaka.
        $snooze('evening');
        $this->assertSame('2026-09-17 13:00:00', $reminder->refresh()->due_at->format('Y-m-d H:i:s'));

        // 11 AM Dhaka tomorrow.
        $snooze('tomorrow');
        $this->assertSame('2026-09-18 05:00:00', $reminder->refresh()->due_at->format('Y-m-d H:i:s'));

        $this->assertNull($reminder->done_at);
    }

    public function test_this_evening_is_not_offered_once_seven_has_gone_and_rolls_to_tomorrow(): void
    {
        $reminder = $this->reminder();

        $this->actingAs($this->admin())->get(route('admin.reminders.index'))->assertSee('This evening, 7 PM');

        // 8:30 PM in Dhaka.
        $this->travelTo(Carbon::parse('2026-09-17 14:30:00', 'UTC'));

        $this->actingAs($this->admin())->get(route('admin.reminders.index'))
            ->assertOk()
            ->assertDontSee('This evening, 7 PM')
            ->assertSee('Tomorrow, 11 AM');

        $this->actingAs($this->admin())
            ->post(route('admin.reminders.snooze', $reminder), ['until' => 'evening'])
            ->assertRedirect();

        $this->assertSame('2026-09-18 13:00:00', $reminder->refresh()->due_at->format('Y-m-d H:i:s'));
    }

    public function test_marking_a_call_done_keeps_what_came_of_it_and_who_made_it(): void
    {
        $reminder = $this->reminder();
        $admin = $this->admin();

        $this->actingAs($admin)
            ->post(route('admin.reminders.done', $reminder), ['outcome' => 'Will order on Friday'])
            ->assertRedirect()
            ->assertSessionHas('success');

        $reminder->refresh();
        $this->assertTrue($reminder->done_at->equalTo(now()));
        $this->assertSame($admin->id, $reminder->done_by);
        $this->assertSame('Will order on Friday', $reminder->outcome);

        // A second press is harmless and changes nothing.
        $this->actingAs($admin)
            ->post(route('admin.reminders.done', $reminder), ['outcome' => 'Something else'])
            ->assertSessionHas('warning');
        $this->assertSame('Will order on Friday', $reminder->refresh()->outcome);

        $this->assertSame(0, CallReminder::due()->count());
    }

    public function test_editing_changes_the_time_and_items_but_not_the_lead_it_came_from(): void
    {
        $necklace = $this->product();
        $cart = AbandonedCart::create(['session_id' => 's', 'phone' => '01712345678', 'items' => [], 'subtotal' => 0, 'item_count' => 0]);
        $reminder = $this->reminder(['abandoned_cart_id' => $cart->id]);

        $this->actingAs($this->admin())
            ->get(route('admin.reminders.edit', $reminder))
            ->assertOk()
            ->assertSee('Save changes');

        $this->actingAs($this->admin())
            ->put(route('admin.reminders.update', $reminder), $this->form([
                'due_date' => '2026-09-19', 'due_time' => '11:30',
                'items' => [['product_id' => $necklace->id, 'qty' => 3, 'price' => '']],
                'abandoned_cart_id' => '',
            ]))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.reminders.index', ['tab' => 'upcoming']));

        $reminder->refresh();
        $this->assertSame('2026-09-19 05:30:00', $reminder->due_at->format('Y-m-d H:i:s'));
        $this->assertSame(3, $reminder->items[0]['qty']);
        $this->assertSame($cart->id, $reminder->abandoned_cart_id);
    }

    public function test_a_reminder_can_be_deleted(): void
    {
        $reminder = $this->reminder();

        $this->actingAs($this->admin())
            ->from(route('admin.reminders.index'))
            ->delete(route('admin.reminders.destroy', $reminder))
            ->assertRedirect(route('admin.reminders.index'))
            ->assertSessionHas('success', 'Reminder deleted.');

        $this->assertSame(0, CallReminder::count());

        // Deleted from its own edit page, it goes back to the list, not to itself.
        $other = $this->reminder();
        $this->actingAs($this->admin())
            ->from(route('admin.reminders.edit', $other))
            ->delete(route('admin.reminders.destroy', $other))
            ->assertRedirect(route('admin.reminders.index', ['tab' => 'due']));

        // And a stale link to it lands on the list with a word, not a 404.
        $this->actingAs($this->admin())
            ->get(route('admin.reminders.edit', $other->id))
            ->assertRedirect(route('admin.reminders.index'))
            ->assertSessionHas('warning');
    }

    // ── Who can ──────────────────────────────────────────────────────────────

    public function test_staff_take_the_phone_orders_so_they_can_work_the_reminders(): void
    {
        $staff = $this->staff();
        $reminder = $this->reminder();

        $this->assertContains('reminders', User::sectionsFor('staff'));
        $this->assertContains('reminders', User::sectionsFor('manager'));

        $this->actingAs($staff)->get(route('admin.reminders.index'))->assertOk();
        $this->actingAs($staff)->get(route('admin.reminders.create'))->assertOk();
        $this->actingAs($staff)->post(route('admin.reminders.store'), $this->form(['phone' => '01812345678']))->assertSessionHasNoErrors();
        $this->actingAs($staff)->post(route('admin.reminders.done', $reminder), ['outcome' => 'Sorted'])->assertSessionHas('success');

        $this->actingAs($staff)->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('<span class="sb-label">Call reminders</span>', false);
    }

    public function test_a_role_without_the_reminders_section_is_refused(): void
    {
        // Every preset role has the section now, so the gate is checked with
        // an account whose role is refused exactly this one section.
        $user = new class extends User
        {
            protected $table = 'users';

            public function canAccess(string $section): bool
            {
                return $section !== 'reminders' && parent::canAccess($section);
            }
        };
        $user->forceFill(['name' => 'No Calls', 'email' => 'nocalls@test.local', 'password' => bcrypt('x'), 'role' => 'manager'])->save();

        $reminder = $this->reminder();

        foreach ([
            ['get', route('admin.reminders.index')],
            ['get', route('admin.reminders.create')],
            ['post', route('admin.reminders.store')],
            ['get', route('admin.reminders.edit', $reminder)],
            ['put', route('admin.reminders.update', $reminder)],
            ['post', route('admin.reminders.snooze', $reminder)],
            ['post', route('admin.reminders.done', $reminder)],
            ['delete', route('admin.reminders.destroy', $reminder)],
        ] as [$verb, $url]) {
            $this->actingAs($user)->{$verb}($url)->assertForbidden();
        }

        $this->assertNotNull($reminder->fresh());

        $this->actingAs($user)->get(route('admin.dashboard'))
            ->assertOk()
            ->assertDontSee('<span class="sb-label">Call reminders</span>', false);

        // And no one signed out, or signed in without an admin role, gets near it.
        auth()->logout();
        $this->get(route('admin.reminders.index'))->assertRedirect(route('admin.login'));
        $customerRole = User::create(['name' => 'Shopper', 'email' => 'shopper@test.local', 'password' => bcrypt('x'), 'role' => 'customer']);
        $this->actingAs($customerRole)->get(route('admin.reminders.index'))->assertForbidden();
    }

    // ── The badge and the bell ───────────────────────────────────────────────

    public function test_the_sidebar_badge_counts_the_calls_due_now(): void
    {
        $this->reminder(['due_at' => now()->subHours(3)]);
        $this->reminder(['phone' => '01812345678', 'due_at' => now()->subMinute()]);
        $this->reminder(['phone' => '01912345678', 'due_at' => now()->addHour()]);
        $this->reminder(['phone' => '01612345678', 'due_at' => now()->subDay(), 'done_at' => now()]);

        $html = $this->actingAs($this->admin())->get(route('admin.dashboard'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<span class="sb-badge[^"]*"\s+title="2 call reminder\(s\) due">2<\/span>/', $html);
        $this->assertStringContainsString('href="'.route('admin.reminders.index').'"', $html);
    }

    public function test_the_bell_rings_when_a_call_comes_due(): void
    {
        $this->reminder(['due_at' => now()->subMinutes(30)]);

        $alert = $this->reminderAlert($this->admin());

        $this->assertNotNull($alert);
        $this->assertSame('1 call reminder due', $alert['title']);
        $this->assertSame('warning', $alert['level']);
        $this->assertStringContainsString('Farida Bari', $alert['body']);
        $this->assertSame(route('admin.reminders.index', ['tab' => 'due']), $alert['url']);
        $this->assertFalse($alert['read']);
    }

    public function test_the_bell_is_quiet_when_every_call_is_upcoming_or_done(): void
    {
        $this->reminder(['due_at' => now()->addMinutes(5)]);
        $this->reminder(['phone' => '01812345678', 'due_at' => now()->subHour(), 'done_at' => now()]);

        $this->assertNull($this->reminderAlert($this->admin()));
    }

    public function test_the_bell_turns_urgent_once_a_call_has_waited_a_day(): void
    {
        $this->reminder(['name' => 'Yesterday Yasmin', 'due_at' => now()->subHours(26)]);
        $this->reminder(['name' => 'Recent Rumana', 'phone' => '01812345678', 'due_at' => now()->subHour()]);

        $alert = $this->reminderAlert($this->admin());

        $this->assertSame('2 call reminders due', $alert['title']);
        $this->assertSame('urgent', $alert['level']);
        $this->assertStringContainsString('Yesterday Yasmin', $alert['body'], 'the body names the call that has waited longest');
    }

    public function test_a_read_bell_alert_stays_read_while_the_list_is_worked_and_rings_again_for_the_next_call(): void
    {
        $admin = $this->admin();
        $oldest = $this->reminder(['name' => 'First Caller', 'due_at' => now()->subHours(2)]);
        $this->reminder(['name' => 'Second Caller', 'phone' => '01812345678', 'due_at' => now()->subHour()]);
        $this->reminder(['name' => 'Third Caller', 'phone' => '01912345678', 'due_at' => now()->addMinutes(30)]);

        $this->actingAs($admin)
            ->post(route('admin.alerts.read'), ['key' => $this->reminderAlert($admin)['key']])
            ->assertRedirect();
        $this->assertTrue($this->reminderAlert($admin)['read']);

        // Working the list, oldest first, does not ring it again.
        $this->actingAs($admin)->post(route('admin.reminders.done', $oldest), ['outcome' => 'No answer'])->assertRedirect();
        $this->assertTrue($this->reminderAlert($admin)['read']);

        // The next call coming due does.
        $this->travel(31)->minutes();
        $again = $this->reminderAlert($admin);
        $this->assertFalse($again['read']);
        $this->assertSame('2 call reminders due', $again['title']);
    }

    // ── Create order ─────────────────────────────────────────────────────────

    public function test_create_order_from_a_reminder_fills_in_the_number_and_the_items(): void
    {
        $necklace = $this->product();
        $reminder = $this->reminder([
            'items' => [['product_id' => $necklace->id, 'variant_id' => null, 'name' => $necklace->name, 'qty' => 2, 'price' => 1850]],
            'notes' => 'Wants the gift box',
        ]);

        $res = $this->actingAs($this->admin())
            ->get(route('admin.orders.create', ['reminder' => $reminder->id]))
            ->assertOk()
            ->assertSee('Order from the call reminder for Farida Bari')
            ->assertSee('Wants the gift box')
            ->assertSee('name="reminder_id" value="'.$reminder->id.'"', false)
            ->assertSee('value="01712345678"', false);

        $prefill = $res->viewData('prefill');
        $this->assertSame('Farida Bari', $prefill['customer']['name']);
        $this->assertSame('01712345678', $prefill['customer']['phone']);
        $this->assertCount(1, $prefill['lines']);
        $this->assertSame($necklace->id, $prefill['lines'][0]['product_id']);
        $this->assertSame(2, $prefill['lines'][0]['qty']);
        $this->assertSame(1850.0, $prefill['lines'][0]['price']);
        $this->assertSame([], $prefill['notices']);
    }

    public function test_a_known_customers_reminder_brings_her_own_name_and_delivery_details(): void
    {
        $customer = $this->customer();
        Order::create([
            'order_number' => 'NOY-100001', 'customer_id' => $customer->id, 'customer_name' => $customer->name,
            'customer_phone' => $customer->phone, 'shipping_address' => 'Flat 3B, Uttara Sector 7', 'area' => 'Uttara',
            'district' => 'Dhaka', 'is_inside_dhaka' => true, 'status' => 'delivered', 'subtotal' => 2600, 'total' => 2670,
        ]);
        $reminder = $this->reminder(['name' => 'Nusrat from Messenger', 'customer_id' => $customer->id]);

        $prefill = $this->actingAs($this->admin())
            ->get(route('admin.orders.create', ['reminder' => $reminder->id]))
            ->assertOk()
            ->viewData('prefill');

        $this->assertSame('Nusrat Jahan', $prefill['customer']['name']);
        $this->assertSame('Flat 3B, Uttara Sector 7', $prefill['customer']['address']);
        $this->assertTrue($prefill['customer']['is_inside_dhaka']);
        $this->assertSame($customer->id, $prefill['picked']['id']);
        $this->assertSame([], $prefill['lines'], 'a reminder without items opens on the usual blank line');
    }

    public function test_a_piece_that_can_no_longer_be_sold_is_left_off_and_said_plainly(): void
    {
        $gone = $this->product(['name' => 'Withdrawn Piece', 'status' => 'draft']);
        $reminder = $this->reminder([
            'items' => [['product_id' => $gone->id, 'variant_id' => null, 'name' => 'Withdrawn Piece', 'qty' => 1, 'price' => 900]],
        ]);

        $res = $this->actingAs($this->admin())
            ->get(route('admin.orders.create', ['reminder' => $reminder->id]))
            ->assertOk()
            ->assertSee('Withdrawn Piece is no longer on sale')
            ->assertSee('Nothing on this reminder can still be sold');

        $this->assertSame([], $res->viewData('prefill')['lines']);
    }

    public function test_a_reminder_wins_over_a_lead_and_a_customer_on_the_order_form(): void
    {
        $customer = $this->customer(['phone' => '01912345678']);
        $cart = AbandonedCart::create([
            'session_id' => 'sess-1', 'name' => 'Lead Lamia', 'phone' => '01812345678',
            'items' => [], 'subtotal' => 0, 'item_count' => 0,
        ]);
        $reminder = $this->reminder();

        $res = $this->actingAs($this->admin())
            ->get('/admin/orders/create?reminder='.$reminder->id.'&from_cart='.$cart->id.'&customer='.$customer->id)
            ->assertOk()
            ->assertSee('Order from the call reminder for Farida Bari')
            ->assertDontSee('Converting');

        $this->assertNull($res->viewData('cart'));
        $this->assertNull($res->viewData('customer'));
        $this->assertSame('01712345678', $res->viewData('prefill')['customer']['phone']);

        // Anything that is not a plain id is ignored, and the lead is used again.
        $this->actingAs($this->admin())
            ->get('/admin/orders/create?reminder=abc&from_cart='.$cart->id)
            ->assertOk()
            ->assertSee('Converting');
    }

    public function test_saving_the_order_ticks_the_reminder_off_with_the_order(): void
    {
        Queue::fake();

        $necklace = $this->product();
        $reminder = $this->reminder([
            'items' => [['product_id' => $necklace->id, 'variant_id' => null, 'name' => $necklace->name, 'qty' => 1, 'price' => 1850]],
        ]);
        $admin = $this->admin();
        $this->assertNotNull($this->reminderAlert($admin));

        $this->actingAs($admin)->post(route('admin.orders.store-manual'), [
            'reminder_id' => $reminder->id,
            'name' => 'Farida Bari',
            'phone' => '01712345678',
            'address' => 'House 8, Road 3, Lalmatia',
            'lines' => [['product_id' => $necklace->id, 'qty' => 1, 'price' => 1850]],
        ])->assertRedirect()->assertSessionHas('success');

        $order = Order::latest('id')->first();
        $reminder->refresh();

        $this->assertNotNull($order);
        $this->assertNotNull($reminder->done_at);
        $this->assertSame($order->id, $reminder->order_id);
        $this->assertSame($admin->id, $reminder->done_by);
        $this->assertSame('Order '.$order->order_number.' created', $reminder->outcome);
        $this->assertStringContainsString('The call reminder is marked done.', session('success'));
        $this->assertNull($this->reminderAlert($admin), 'the bell stops ringing for a call that became a sale');
    }

    public function test_a_reminder_made_from_a_lead_closes_the_lead_with_the_order(): void
    {
        Queue::fake();

        $necklace = $this->product();
        $cart = AbandonedCart::create([
            'session_id' => 'sess-1', 'name' => 'Farida Bari', 'phone' => '01712345678',
            'address' => 'House 8, Road 3, Lalmatia', 'area' => 'Lalmatia', 'is_inside_dhaka' => true,
            'items' => [['product_id' => $necklace->id, 'name' => $necklace->name, 'qty' => 1, 'price' => 1850]],
            'subtotal' => 1850, 'item_count' => 1,
        ]);
        $reminder = $this->reminder(['abandoned_cart_id' => $cart->id, 'items' => $cart->items]);

        $res = $this->actingAs($this->admin())
            ->get(route('admin.orders.create', ['reminder' => $reminder->id]))
            ->assertOk()
            ->assertSee('name="abandoned_cart_id" value="'.$cart->id.'"', false);

        // Nobody on file for the number, so the address she typed at checkout.
        $this->assertSame('House 8, Road 3, Lalmatia', $res->viewData('prefill')['customer']['address']);

        $this->actingAs($this->admin())->post(route('admin.orders.store-manual'), [
            'reminder_id' => $reminder->id,
            'abandoned_cart_id' => $cart->id,
            'name' => 'Farida Bari', 'phone' => '01712345678', 'address' => 'House 8, Road 3, Lalmatia',
            'lines' => [['product_id' => $necklace->id, 'qty' => 1, 'price' => 1850]],
        ])->assertRedirect();

        $order = Order::latest('id')->first();
        $this->assertSame($cart->id, $order->abandoned_cart_id);
        $this->assertTrue($cart->fresh()->recovered);
        $this->assertSame($order->id, $reminder->fresh()->order_id);
    }

    public function test_a_bounced_order_leaves_the_reminder_waiting(): void
    {
        Queue::fake();

        $necklace = $this->product();
        $reminder = $this->reminder();

        $this->actingAs($this->admin())
            ->from(route('admin.orders.create', ['reminder' => $reminder->id]))
            ->post(route('admin.orders.store-manual'), [
                'reminder_id' => $reminder->id,
                'name' => 'Farida Bari', 'phone' => '01712345678', 'address' => 'Lalmatia',
                'coupon_code' => 'NOSUCHCODE',
                'lines' => [['product_id' => $necklace->id, 'qty' => 1, 'price' => 1850]],
            ])
            ->assertRedirect(route('admin.orders.create', ['reminder' => $reminder->id]))
            ->assertSessionHasErrors('coupon_code');

        $this->assertSame(0, Order::count());
        $this->assertNull($reminder->fresh()->done_at);
        $this->assertNull($reminder->fresh()->order_id);
    }
}

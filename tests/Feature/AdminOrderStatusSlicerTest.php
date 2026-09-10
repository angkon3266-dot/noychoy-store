<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The status slicer on the ORDERS LIST.
 *
 * The dropdown could only ever say one thing at a time, which made the
 * owner's actual day — bouncing between Pending, Processing and Booked —
 * three round trips through a <select>. The pills are that same filter with
 * a live count, and, with "Multi" on, the ability to hold more than one
 * status at once, which the dropdown cannot express at all.
 */
class AdminOrderStatusSlicerTest extends TestCase
{
    use RefreshDatabase;

    protected function admin(): User
    {
        return User::firstOrCreate(
            ['email' => 'a@b.c'],
            ['name' => 'A', 'password' => bcrypt('x'), 'role' => 'admin'],
        );
    }

    private int $seq = 0;

    /**
     * The phone deliberately does NOT embed the order number: these tests
     * assert on raw HTML, and a phone of 01712347002 makes "this page does not
     * mention order 7002" impossible to state.
     */
    protected function order(string $number, string $status, string $name = 'Buyer', ?string $phone = null): Order
    {
        $phone ??= '01711'.str_pad((string) ++$this->seq, 6, '0', STR_PAD_LEFT);

        return Order::create([
            'order_number' => $number, 'customer_name' => $name, 'customer_phone' => $phone,
            'shipping_address' => 'X', 'subtotal' => 100, 'shipping_cost' => 0, 'discount' => 0,
            'total' => 100, 'payment_method' => 'cod', 'payment_status' => 'unpaid',
            'status' => $status, 'source' => 'web',
        ]);
    }

    /**
     * Just the rows.
     *
     * The page also carries a "new orders" alert panel that lists recent order
     * numbers whatever the filter says — so "the page does not mention 7002"
     * is not the same claim as "7002 was filtered out". Only the table can
     * answer the second one.
     */
    protected function rows(string $html): string
    {
        $start = strpos($html, '<tbody');
        $end = strpos($html, '</tbody>', $start ?: 0);

        $this->assertNotFalse($start, 'orders table not rendered');

        return substr($html, $start, $end - $start);
    }

    /** The list is a packing queue first, so an unfiltered visit still opens on Processing. */
    public function test_it_still_defaults_to_processing(): void
    {
        $this->order('7001', 'processing');
        $this->order('7002', 'pending');

        $html = $this->actingAs($this->admin())->get('/admin/orders')->assertOk()->getContent();

        $this->assertStringContainsString('7001', $this->rows($html));
        $this->assertStringNotContainsString('7002', $this->rows($html));
    }

    /** The whole point of the slicer: two statuses held open together. */
    public function test_it_filters_on_several_statuses_at_once(): void
    {
        $this->order('7001', 'processing');
        $this->order('7002', 'pending');
        $this->order('7003', 'delivered');

        $html = $this->actingAs($this->admin())
            ->get('/admin/orders?status=pending,processing')->assertOk()->getContent();

        $this->assertStringContainsString('7001', $this->rows($html));
        $this->assertStringContainsString('7002', $this->rows($html));
        $this->assertStringNotContainsString('7003', $this->rows($html));
    }

    /** ?status[]=a&status[]=b — the array form a form post would produce. */
    public function test_it_accepts_the_array_form_too(): void
    {
        $this->order('7001', 'processing');
        $this->order('7002', 'pending');
        $this->order('7003', 'delivered');

        $html = $this->actingAs($this->admin())
            ->get('/admin/orders?status[]=pending&status[]=processing')->assertOk()->getContent();

        $this->assertStringContainsString('7002', $this->rows($html));
        $this->assertStringNotContainsString('7003', $this->rows($html));
    }

    public function test_all_clears_the_filter(): void
    {
        $this->order('7001', 'processing');
        $this->order('7002', 'delivered');

        $html = $this->actingAs($this->admin())->get('/admin/orders?status=all')->assertOk()->getContent();

        $this->assertStringContainsString('7001', $this->rows($html));
        $this->assertStringContainsString('7002', $this->rows($html));
    }

    /**
     * A status that no longer exists must not reach the query. Passing it
     * through produced an empty table with no hint why; dropping it falls back
     * to the default, and a good key alongside a junk one still works.
     */
    public function test_unknown_statuses_are_dropped(): void
    {
        $this->order('7001', 'processing');
        $this->order('7002', 'pending');

        $html = $this->actingAs($this->admin())->get('/admin/orders?status=bogus')->assertOk()->getContent();
        $this->assertStringContainsString('7001', $this->rows($html));
        $this->assertStringNotContainsString('7002', $this->rows($html));

        $html = $this->actingAs($this->admin())->get('/admin/orders?status=bogus,pending')->assertOk()->getContent();
        $this->assertStringContainsString('7002', $this->rows($html));
        $this->assertStringNotContainsString('7001', $this->rows($html));
    }

    /** A pill has to keep its own count while a different pill is the active one. */
    public function test_counts_ignore_the_active_status_filter(): void
    {
        $this->order('7001', 'processing');
        $this->order('7002', 'processing');
        $this->order('7003', 'pending');

        $html = $this->actingAs($this->admin())->get('/admin/orders?status=pending')->assertOk()->getContent();

        // Processing is filtered out of the table but its pill still reads 2.
        $this->assertStringNotContainsString('7001', $this->rows($html));
        $this->assertMatchesRegularExpression('/Processing\s*<span[^>]*>\s*2\s*<\/span>/', $html);
    }

    /** Counts follow the search box, or they would contradict the table beside them. */
    public function test_counts_respect_the_search_term(): void
    {
        $this->order('7001', 'processing', 'Rahim');
        $this->order('7002', 'processing', 'Karim');

        $html = $this->actingAs($this->admin())->get('/admin/orders?q=Rahim&status=all')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/Processing\s*<span[^>]*>\s*1\s*<\/span>/', $html);
    }

    /** Trashed orders are their own world; the pills must count that world, not the live one. */
    public function test_counts_follow_the_trash_view(): void
    {
        $this->order('7001', 'processing');
        $this->order('7002', 'processing')->delete();

        $html = $this->actingAs($this->admin())->get('/admin/orders?trashed=1&status=all')->assertOk()->getContent();

        $this->assertStringContainsString('7002', $this->rows($html));
        $this->assertMatchesRegularExpression('/Processing\s*<span[^>]*>\s*1\s*<\/span>/', $html);
    }

    /** A nested array in the query string is a 500 waiting to happen if it is cast, not filtered. */
    public function test_a_malformed_status_query_does_not_blow_up(): void
    {
        $this->order('7001', 'processing');

        $html = $this->actingAs($this->admin())
            ->get('/admin/orders?status[][]=pending')->assertOk()->getContent();

        // Nothing usable was asked for, so it falls back to the packing queue.
        $this->assertStringContainsString('7001', $this->rows($html));
    }

    /** Likewise a stored pin list that is not a list of strings. */
    public function test_a_malformed_pin_setting_does_not_blow_up(): void
    {
        Setting::put('admin_order_quick_filters', ['pending', ['nested']]);

        $html = $this->actingAs($this->admin())->get('/admin/orders')->assertOk()->getContent();

        $this->assertStringContainsString('status=pending', $html);
        $this->assertStringNotContainsString('status=booked', $html);
    }

    /** A pill click must not throw away the search you already typed. */
    public function test_pill_links_carry_the_search_term(): void
    {
        $this->order('7001', 'processing', 'Rahim');

        $html = $this->actingAs($this->admin())->get('/admin/orders?q=Rahim&status=all')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/href="[^"]*q=Rahim[^"]*status=pending"/', $html);
    }

    /** Nor bounce you out of Trash. */
    public function test_pill_links_stay_inside_the_trash_view(): void
    {
        $this->order('7001', 'processing')->delete();

        $html = $this->actingAs($this->admin())->get('/admin/orders?trashed=1&status=all')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/href="[^"]*trashed=1[^"]*status=pending"/', $html);
        // The search form has to hold the view too, or submitting it escapes Trash.
        $this->assertStringContainsString('<input type="hidden" name="trashed" value="1">', $html);
    }

    /**
     * The multi href arithmetic is the feature. With Processing active:
     * Pending ADDS itself, and Processing itself untoggles to "all" rather
     * than to an empty status, which would snap back to the default.
     */
    public function test_the_multi_hrefs_add_and_remove(): void
    {
        $html = $this->actingAs($this->admin())->get('/admin/orders')->assertOk()->getContent();

        $this->assertStringContainsString('status=processing%2Cpending', $html);
        $this->assertStringContainsString('status=processing%2Cbooked', $html);
        $this->assertMatchesRegularExpression('/:href="multi \? \'[^\']*status=all\'/', $html);
    }

    /** A status filtered on but not pinned still gets a pill, or the row lights up nothing. */
    public function test_an_active_but_unpinned_status_still_gets_a_pill(): void
    {
        Setting::put('admin_order_quick_filters', ['delivered']);
        $this->order('7001', 'returned');

        $html = $this->actingAs($this->admin())->get('/admin/orders?status=returned')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/Returned\s*<span/', $html);
    }

    /** Page 2 of a multi-status filter has to stay a multi-status filter. */
    public function test_pagination_keeps_the_whole_status_set(): void
    {
        for ($i = 0; $i < 25; $i++) {
            $this->order('80'.str_pad((string) $i, 2, '0', STR_PAD_LEFT), $i % 2 ? 'pending' : 'processing');
        }

        $html = $this->actingAs($this->admin())
            ->get('/admin/orders?status=pending,processing')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/href="[^"]*status=pending%2Cprocessing[^"]*page=2"/', $html);
    }

    /** ?q[]=x used to be a 500: the array reached a LIKE binding as a string cast. */
    public function test_a_malformed_search_term_does_not_blow_up(): void
    {
        $this->order('7001', 'processing');

        $html = $this->actingAs($this->admin())->get('/admin/orders?q[]=x')->assertOk()->getContent();

        $this->assertStringContainsString('7001', $this->rows($html));
    }

    public function test_the_default_pills_are_pending_processing_and_booked(): void
    {
        $html = $this->actingAs($this->admin())->get('/admin/orders')->assertOk()->getContent();

        $this->assertStringContainsString('status=pending', $html);
        $this->assertStringContainsString('status=processing', $html);
        $this->assertStringContainsString('status=booked', $html);
    }

    public function test_the_owner_can_choose_which_statuses_are_pinned(): void
    {
        $this->actingAs($this->admin())
            ->post('/admin/orders/quick-filters', ['statuses' => ['delivered', 'returned']])
            ->assertRedirect();

        $this->assertSame(['delivered', 'returned'], Setting::get('admin_order_quick_filters'));

        $html = $this->actingAs($this->admin())->get('/admin/orders')->assertOk()->getContent();
        $this->assertStringContainsString('status=delivered', $html);
        $this->assertStringContainsString('status=returned', $html);
    }

    /** Pinning nothing is a real answer — dropdown only — not a reason to restore the defaults. */
    public function test_pinning_nothing_leaves_no_pills(): void
    {
        $this->actingAs($this->admin())->post('/admin/orders/quick-filters', [])->assertRedirect();

        $this->assertSame([], Setting::get('admin_order_quick_filters'));

        $html = $this->actingAs($this->admin())->get('/admin/orders')->assertOk()->getContent();
        $this->assertStringNotContainsString('status=booked', $html);
    }

    public function test_it_refuses_more_pills_than_the_row_can_hold(): void
    {
        $this->actingAs($this->admin())
            ->post('/admin/orders/quick-filters', ['statuses' => ['pending', 'confirmed', 'processing', 'booked']])
            ->assertSessionHasErrors('statuses');

        $this->assertNull(Setting::get('admin_order_quick_filters'));
    }

    public function test_it_refuses_a_status_that_does_not_exist(): void
    {
        $this->actingAs($this->admin())
            ->post('/admin/orders/quick-filters', ['statuses' => ['pending', 'bogus']])
            ->assertSessionHasErrors('statuses.1');

        $this->assertNull(Setting::get('admin_order_quick_filters'));
    }

    /** A stored value that is no longer a real status must not render a dead pill. */
    public function test_pins_are_re_validated_on_the_way_out(): void
    {
        Setting::put('admin_order_quick_filters', ['pending', 'retired_status']);

        $html = $this->actingAs($this->admin())->get('/admin/orders')->assertOk()->getContent();

        $this->assertStringContainsString('status=pending', $html);
        $this->assertStringNotContainsString('retired_status', $html);
    }
}

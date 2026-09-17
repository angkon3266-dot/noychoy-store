<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Setting;
use App\Models\User;
use App\Support\UpcomingOccasions;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Admin → Customers → Birthdays & anniversaries.
 *
 * Owner, 2026-09-17: "Create a dashboard for birthdays where I will be able to
 * see the customer's name and details for upcoming birthdays, anniversaries —
 * show it in the customers section".
 *
 * The page is only as good as its dates, and only a day and a month are
 * stored, so most of this pins the arithmetic: a Dhaka day rather than the
 * server's UTC one, a window that runs over New Year, and 29 February in the
 * three years out of four that do not have it. The rest pins the page: grouped
 * by day, filtered, counted, closed to staff, and every way of reaching the
 * customer pointing at something that exists.
 */
class CustomerOccasionsTest extends TestCase
{
    use RefreshDatabase;

    /** Counts up, so no two customers ever share a phone by accident. */
    protected int $phoneSeq = 100;

    /** Freeze the clock at a wall-clock time in Dhaka, whatever zone the server runs in. */
    protected function atDhaka(string $wallTime): CarbonImmutable
    {
        $now = CarbonImmutable::parse($wallTime, 'Asia/Dhaka');
        Carbon::setTestNow($now);

        return $now;
    }

    protected function user(string $role = 'admin'): User
    {
        return User::firstOrCreate(
            ['email' => $role.'@occasions.test'],
            ['name' => ucfirst($role), 'password' => bcrypt('x'), 'role' => $role],
        );
    }

    protected function customer(array $attrs = []): Customer
    {
        return Customer::create(array_merge([
            'name' => 'Customer '.$this->phoneSeq,
            'phone' => '017111'.str_pad((string) $this->phoneSeq++, 5, '0', STR_PAD_LEFT),
        ], $attrs));
    }

    /** Each row as "type:name:date:days", in the order it is listed. */
    protected function listed(Collection $rows): array
    {
        return $rows->map(fn (array $row) => implode(':', [
            $row['type'], $row['customer']->name, $row['date']->toDateString(), $row['days_until'],
        ]))->all();
    }

    // ── The date maths ──────────────────────────────────────────────────────

    public function test_the_next_occurrence_is_a_dhaka_day_over_new_year_and_on_leap_days(): void
    {
        $dhaka = fn (string $day) => CarbonImmutable::parse($day, 'Asia/Dhaka');
        $next = fn (int $month, int $day, $from) => UpcomingOccasions::nextOccurrence($month, $day, $from)?->toDateString();

        $this->assertSame('2026-09-17', $next(9, 17, $dhaka('2026-09-17')), 'today counts as upcoming');
        $this->assertSame('2027-09-16', $next(9, 16, $dhaka('2026-09-17')), 'yesterday comes round next year');
        $this->assertSame('2027-01-03', $next(1, 3, $dhaka('2026-12-20')));

        // 29 February: the 28th outside a leap year, its own day inside one.
        $this->assertSame('2027-02-28', $next(2, 29, $dhaka('2027-02-01')));
        $this->assertSame('2028-02-29', $next(2, 29, $dhaka('2027-03-01')));
        $this->assertSame('2028-02-29', $next(2, 29, $dhaka('2028-02-29')));
        // Checkout accepts "31 April"; it stays in April instead of becoming 1 May.
        $this->assertSame('2027-04-30', $next(4, 31, $dhaka('2027-04-01')));

        $this->assertNull($next(13, 1, $dhaka('2027-04-01')));
        $this->assertNull($next(2, 0, $dhaka('2027-04-01')));

        // 20:30 UTC on the 17th is already 02:30 on the 18th in Dhaka, so a
        // 17 September birthday has been and gone for this year.
        $this->assertSame('2027-09-17', $next(9, 17, CarbonImmutable::parse('2026-09-17 20:30', 'UTC')));
        $this->assertSame('Asia/Dhaka', UpcomingOccasions::nextOccurrence(9, 17, $dhaka('2026-09-17'))->getTimezone()->getName());
    }

    public function test_occasions_inside_the_window_are_listed_and_the_ones_outside_are_not(): void
    {
        $this->atDhaka('2026-09-17 10:00');

        $this->customer(['name' => 'Today Birthday', 'birthday_day' => 17, 'birthday_month' => 9]);
        $this->customer(['name' => 'Soon Couple', 'anniversary_day' => 20, 'anniversary_month' => 9]);
        $this->customer(['name' => 'Both Days', 'birthday_day' => 25, 'birthday_month' => 9, 'anniversary_day' => 25, 'anniversary_month' => 9]);
        $this->customer(['name' => 'Last Day In', 'birthday_day' => 16, 'birthday_month' => 10]);
        $this->customer(['name' => 'One Day Out', 'birthday_day' => 17, 'birthday_month' => 10]);
        $this->customer(['name' => 'Yesterday', 'birthday_day' => 16, 'birthday_month' => 9]);
        $this->customer(['name' => 'Half A Date', 'birthday_day' => 18]);
        $this->customer(['name' => 'No Dates']);

        // Thirty days is today and the 29 after it: 16 October in, the 17th out.
        $this->assertSame([
            'birthday:Today Birthday:2026-09-17:0',
            'anniversary:Soon Couple:2026-09-20:3',
            'birthday:Both Days:2026-09-25:8',
            'anniversary:Both Days:2026-09-25:8',
            'birthday:Last Day In:2026-10-16:29',
        ], $this->listed(UpcomingOccasions::upcoming(30)));

        $this->assertSame([
            'birthday:Today Birthday:2026-09-17:0',
            'anniversary:Soon Couple:2026-09-20:3',
        ], $this->listed(UpcomingOccasions::upcoming(7)));

        $this->assertSame(['anniversary:Soon Couple:2026-09-20:3', 'anniversary:Both Days:2026-09-25:8'],
            $this->listed(UpcomingOccasions::upcoming(30, ['anniversary'])));
    }

    public function test_a_window_over_new_year_is_narrowed_by_the_query_itself(): void
    {
        $now = $this->atDhaka('2026-12-20 09:00');

        $january = $this->customer(['name' => 'Early January', 'birthday_day' => 3, 'birthday_month' => 1]);
        $eve = $this->customer(['name' => 'New Year Eve', 'birthday_day' => 31, 'birthday_month' => 12]);
        $this->customer(['name' => 'Gone By', 'birthday_day' => 19, 'birthday_month' => 12]);
        $this->customer(['name' => 'Midsummer', 'birthday_day' => 15, 'birthday_month' => 6]);
        $this->customer(['name' => 'Just Too Late', 'birthday_day' => 19, 'birthday_month' => 1]);

        // The SQL already leaves out the far side of the year — nothing is
        // loaded only to be thrown away.
        $this->assertSame(
            [$january->id, $eve->id],
            UpcomingOccasions::candidates('birthday', $now, $now->addDays(29))->orderBy('id')->pluck('id')->all(),
        );

        $this->assertSame([
            'birthday:New Year Eve:2026-12-31:11',
            'birthday:Early January:2027-01-03:14',
        ], $this->listed(UpcomingOccasions::upcoming(30)));
    }

    public function test_a_29_february_birthday_is_kept_on_28_february_outside_a_leap_year(): void
    {
        $this->atDhaka('2027-02-22 12:00');
        $this->customer(['name' => 'Leap Day', 'birthday_day' => 29, 'birthday_month' => 2]);
        $this->customer(['name' => 'First Of March', 'birthday_day' => 1, 'birthday_month' => 3]);

        // A week from the 22nd ends on the 28th — the day February has instead.
        $this->assertSame(['birthday:Leap Day:2027-02-28:6'], $this->listed(UpcomingOccasions::upcoming(7)));

        // In a leap year the day is its own, so the same week misses it by one.
        $this->atDhaka('2028-02-22 12:00');
        $this->assertSame([], $this->listed(UpcomingOccasions::upcoming(7)));
        $this->assertSame(['birthday:Leap Day:2028-02-29:7'], $this->listed(UpcomingOccasions::upcoming(8)));
    }

    public function test_only_this_years_stamps_count_as_already_sent(): void
    {
        $this->atDhaka('2026-09-17 10:00');
        $day = CarbonImmutable::parse('2026-09-20', 'Asia/Dhaka');

        $thisYear = $this->customer(['anniversary_day' => 20, 'anniversary_month' => 9]);
        $thisYear->forceFill(['anniversary_reminded_at' => now()->subDays(7)])->saveQuietly();

        $lastYear = $this->customer(['anniversary_day' => 20, 'anniversary_month' => 9]);
        $lastYear->forceFill(['anniversary_reminded_at' => now()->subDays(372), 'anniversary_wished_at' => now()->subDays(362)])->saveQuietly();

        $this->assertNotNull(UpcomingOccasions::sentAt($thisYear->fresh(), 'anniversary', 'reminder', $day));
        $this->assertNull(UpcomingOccasions::sentAt($thisYear->fresh(), 'anniversary', 'wish', $day));
        $this->assertNull(UpcomingOccasions::sentAt($lastYear->fresh(), 'anniversary', 'reminder', $day));
        $this->assertNull(UpcomingOccasions::sentAt($lastYear->fresh(), 'anniversary', 'wish', $day));
    }

    // ── The page ────────────────────────────────────────────────────────────

    public function test_todays_occasions_are_grouped_under_today_by_the_dhaka_clock(): void
    {
        // 20:30 UTC on the 17th: the server's date still says the 17th, but in
        // Dhaka it is 02:30 on the 18th, and that is the day being celebrated.
        Carbon::setTestNow(CarbonImmutable::parse('2026-09-17 20:30', 'UTC'));

        $this->customer(['name' => 'Rima Sultana', 'birthday_day' => 18, 'birthday_month' => 9]);
        $this->customer(['name' => 'Karim Ahmed', 'anniversary_day' => 19, 'anniversary_month' => 9]);
        $this->customer(['name' => 'Nadia Islam', 'birthday_day' => 21, 'birthday_month' => 9]);
        $this->customer(['name' => 'Rafi Hasan', 'birthday_day' => 17, 'birthday_month' => 9]);

        $this->actingAs($this->user())
            ->get(route('admin.customers.occasions'))
            ->assertOk()
            ->assertSeeInOrder([
                'id="occasions-2026-09-18"', 'Today', 'Fri 18 Sep', 'Rima Sultana', 'Today',
                'id="occasions-2026-09-19"', 'Tomorrow', 'Sat 19 Sep', 'Karim Ahmed', 'Tomorrow',
                'id="occasions-2026-09-21"', 'Mon 21 Sep', 'Nadia Islam', 'in 3 days',
            ], false)
            ->assertDontSee('Rafi Hasan');
    }

    public function test_the_tiles_count_today_the_week_and_the_month_whatever_the_filters(): void
    {
        $this->atDhaka('2026-09-17 10:00');
        $this->customer(['birthday_day' => 17, 'birthday_month' => 9]);
        $this->customer(['anniversary_day' => 22, 'anniversary_month' => 9]);
        $this->customer(['birthday_day' => 1, 'birthday_month' => 10, 'anniversary_day' => 2, 'anniversary_month' => 10]);
        $this->customer(['birthday_day' => 20, 'birthday_month' => 10]);

        $this->actingAs($this->user())
            ->get(route('admin.customers.occasions', ['range' => 'today', 'type' => 'anniversary', 'q' => 'nobody']))
            ->assertOk()
            ->assertViewHas('tiles', [
                'today' => ['total' => 1, 'birthday' => 1, 'anniversary' => 0],
                '7d' => ['total' => 2, 'birthday' => 1, 'anniversary' => 1],
                '30d' => ['total' => 4, 'birthday' => 2, 'anniversary' => 2],
            ])
            ->assertViewHas('total', 0);
    }

    public function test_the_coverage_tile_counts_whole_dates_only(): void
    {
        $this->customer(['birthday_day' => 1, 'birthday_month' => 1]);
        $this->customer(['birthday_day' => 2, 'birthday_month' => 2, 'anniversary_day' => 3, 'anniversary_month' => 3]);
        $this->customer(['birthday_day' => 5, 'anniversary_month' => 4]);
        $this->customer();
        $this->customer();

        $this->assertSame(['customers' => 5, 'birthday' => 2, 'anniversary' => 1], UpcomingOccasions::coverage());

        $this->actingAs($this->user())
            ->get(route('admin.customers.occasions'))
            ->assertOk()
            ->assertSeeText('2 of 5 customers have a birthday on file')
            ->assertSeeText('1 of 5 customers have an anniversary on file');
    }

    public function test_the_type_pills_narrow_the_list_and_nonsense_falls_back_to_the_defaults(): void
    {
        $this->atDhaka('2026-09-17 10:00');
        $this->customer(['name' => 'Birthday Person', 'birthday_day' => 20, 'birthday_month' => 9]);
        $this->customer(['name' => 'Anniversary Couple', 'anniversary_day' => 21, 'anniversary_month' => 9]);
        $admin = $this->user();

        $this->actingAs($admin)->get(route('admin.customers.occasions', ['type' => 'birthday']))
            ->assertOk()->assertSee('Birthday Person')->assertDontSee('Anniversary Couple');

        $this->actingAs($admin)->get(route('admin.customers.occasions', ['type' => 'anniversary']))
            ->assertOk()->assertSee('Anniversary Couple')->assertDontSee('Birthday Person');

        $this->actingAs($admin)->get('/admin/customers/occasions?type=wedding&range=forever&q[]=x')
            ->assertOk()->assertSee('Birthday Person')->assertSee('Anniversary Couple')
            ->assertViewHas('range', '30d')->assertViewHas('type', 'all')->assertViewHas('q', '');
    }

    public function test_this_month_runs_from_today_and_next_month_is_all_of_it(): void
    {
        $this->atDhaka('2026-09-17 10:00');
        $this->customer(['name' => 'Earlier In September', 'birthday_day' => 10, 'birthday_month' => 9]);
        $this->customer(['name' => 'Later In September', 'birthday_day' => 30, 'birthday_month' => 9]);
        $this->customer(['name' => 'First Of October', 'anniversary_day' => 1, 'anniversary_month' => 10]);
        $this->customer(['name' => 'Halloween Couple', 'anniversary_day' => 31, 'anniversary_month' => 10]);
        $this->customer(['name' => 'Into The Next Month', 'birthday_day' => 1, 'birthday_month' => 11]);
        $admin = $this->user();

        $this->actingAs($admin)->get(route('admin.customers.occasions', ['range' => 'this-month']))
            ->assertOk()
            ->assertSee('Later In September')
            ->assertDontSee('Earlier In September')
            ->assertDontSee('First Of October');

        $this->actingAs($admin)->get(route('admin.customers.occasions', ['range' => 'next-month']))
            ->assertOk()
            ->assertSee('First Of October')
            ->assertSee('Halloween Couple')
            ->assertDontSee('Later In September')
            ->assertDontSee('Into The Next Month')
            ->assertSeeText('2 anniversaries in October');
    }

    public function test_search_finds_a_customer_by_name_or_by_a_phone_typed_any_way(): void
    {
        $this->atDhaka('2026-09-17 10:00');
        $this->customer(['name' => 'Rima Sultana', 'phone' => '01711195772', 'birthday_day' => 20, 'birthday_month' => 9]);
        $this->customer(['name' => 'Karim Ahmed', 'phone' => '01822222222', 'birthday_day' => 22, 'birthday_month' => 9]);

        $rima = ['birthday:Rima Sultana:2026-09-20:3'];
        $this->assertSame($rima, $this->listed(UpcomingOccasions::upcoming(30, search: 'rima')));
        $this->assertSame($rima, $this->listed(UpcomingOccasions::upcoming(30, search: '+880 1711-195772')));
        $this->assertSame($rima, $this->listed(UpcomingOccasions::upcoming(30, search: '1711195772')));
        $this->assertSame(['birthday:Karim Ahmed:2026-09-22:5'], $this->listed(UpcomingOccasions::upcoming(30, search: '22222')));

        $this->actingAs($this->user())
            ->get(route('admin.customers.occasions', ['q' => 'Karim']))
            ->assertOk()
            ->assertSee('Karim Ahmed')
            ->assertDontSee('Rima Sultana');
    }

    public function test_each_filter_has_its_own_empty_state(): void
    {
        $this->atDhaka('2026-09-17 10:00');
        $admin = $this->user();

        // Nothing on file at all: that is the thing to know, not "a quiet month".
        $this->actingAs($admin)->get(route('admin.customers.occasions'))
            ->assertOk()
            ->assertSeeText('No birthdays or anniversaries in the next 30 days.')
            ->assertSeeText('No customer has a birthday or an anniversary on file yet.');

        $this->customer(['name' => 'Later This Month', 'birthday_day' => 28, 'birthday_month' => 9]);

        $this->actingAs($admin)->get(route('admin.customers.occasions', ['range' => 'today']))
            ->assertOk()
            ->assertSeeText('No birthdays or anniversaries today.')
            ->assertSee('See the next 30 days');

        $this->actingAs($admin)->get(route('admin.customers.occasions', ['range' => '7d', 'type' => 'birthday']))
            ->assertOk()
            ->assertSeeText('No birthdays in the next 7 days.');

        // Nothing to point at: the month has no anniversaries either.
        $this->actingAs($admin)->get(route('admin.customers.occasions', ['range' => '7d', 'type' => 'anniversary']))
            ->assertOk()
            ->assertSeeText('No anniversaries in the next 7 days.')
            ->assertDontSee('See the next 30 days');

        $this->actingAs($admin)->get(route('admin.customers.occasions', ['range' => 'next-month', 'type' => 'anniversary']))
            ->assertOk()
            ->assertSeeText('No anniversaries in October.');

        $this->actingAs($admin)->get(route('admin.customers.occasions', ['range' => 'this-month', 'q' => 'Nobody']))
            ->assertOk()
            ->assertSeeText('No birthdays or anniversaries for the rest of September matching “Nobody”.')
            ->assertSee('Clear the search');
    }

    public function test_staff_cannot_open_it_but_managers_and_admins_can(): void
    {
        // The section gate reads the route name: customers.occasions is the
        // customers section, which staff (orders only) do not have.
        $this->actingAs($this->user('staff'))->get(route('admin.customers.occasions'))->assertForbidden();
        $this->actingAs($this->user('manager'))->get(route('admin.customers.occasions'))->assertOk();
        $this->actingAs($this->user('admin'))->get(route('admin.customers.occasions'))->assertOk();
    }

    public function test_a_signed_out_visitor_is_sent_to_log_in(): void
    {
        $this->get(route('admin.customers.occasions'))->assertRedirect();
    }

    public function test_blacklisted_customers_stay_listed_but_flagged_and_sent_messages_show(): void
    {
        $this->atDhaka('2026-09-17 10:00');

        $member = $this->customer(['name' => 'Wished Today', 'password' => 'secret123', 'gender' => 'female', 'birthday_day' => 17, 'birthday_month' => 9]);
        $member->forceFill(['birthday_reminded_at' => now()->subDays(10), 'birthday_wished_at' => now()->subHour()])->saveQuietly();
        $this->customer(['name' => 'Risky Buyer', 'blacklisted' => true, 'birthday_day' => 19, 'birthday_month' => 9]);
        $this->customer(['name' => 'Not Yet Messaged', 'anniversary_day' => 20, 'anniversary_month' => 9, 'total_orders' => 3, 'total_spent' => 12500, 'points' => 240]);

        $this->actingAs($this->user())
            ->get(route('admin.customers.occasions'))
            ->assertOk()
            ->assertSeeInOrder(['Wished Today', 'Member', 'Female', '✓ Reminder sent 7 Sep', '✓ Wish sent 17 Sep'], false)
            ->assertSeeInOrder(['Risky Buyer', 'Guest', 'Blacklisted'], false)
            ->assertSeeInOrder(['Not Yet Messaged', '3 orders', money(12500).' spent', 'No orders yet', '240 pts', 'No automatic message sent for this one yet'], false);
    }

    public function test_every_row_links_to_call_whatsapp_a_new_order_and_the_customer(): void
    {
        $this->atDhaka('2026-09-17 10:00');
        $rafiq = $this->customer(['name' => 'Md. Rafiqul Islam', 'phone' => '01711195772', 'birthday_day' => 20, 'birthday_month' => 9]);
        $rima = $this->customer(['name' => 'Rima Sultana', 'phone' => '01822222222', 'anniversary_day' => 17, 'anniversary_month' => 9]);
        $noPhone = $this->customer(['name' => 'Email Only', 'phone' => null, 'email' => 'e@example.com', 'birthday_day' => 21, 'birthday_month' => 9]);

        // Warm, in Bangla, naming the day and the shop — "in advance" until the
        // day itself, and never "Md." as a first name.
        $early = UpcomingOccasions::wish($rafiq, 'birthday', 3);
        $this->assertStringStartsWith('অগ্রিম শুভ জন্মদিন, Rafiqul!', $early);
        $this->assertStringContainsString(store_name(), $early);
        $onTheDay = UpcomingOccasions::wish($rima, 'anniversary', 0);
        $this->assertStringStartsWith('শুভ বিবাহবার্ষিকী, Rima!', $onTheDay);
        $this->assertStringContainsString(store_name(), $onTheDay);

        $this->actingAs($this->user())
            ->get(route('admin.customers.occasions'))
            ->assertOk()
            ->assertSee('href="tel:+8801711195772"', false)
            ->assertSee('href="https://wa.me/8801711195772?text='.rawurlencode($early).'"', false)
            ->assertSee('href="https://wa.me/8801822222222?text='.rawurlencode($onTheDay).'"', false)
            ->assertSee('href="'.route('admin.orders.create', ['customer' => $rafiq->id]).'"', false)
            ->assertSee('href="'.route('admin.orders.create', ['customer' => $noPhone->id]).'"', false)
            ->assertSee('href="'.route('admin.customers.show', $rafiq).'"', false)
            // No number, nothing to dial or text — but the order button stays.
            ->assertDontSee('occasion-sms-birthday-'.$noPhone->id);
    }

    public function test_the_reminder_button_is_drawn_only_while_the_reminders_screen_exists(): void
    {
        $this->atDhaka('2026-09-17 10:00');
        $customer = $this->customer(['birthday_day' => 20, 'birthday_month' => 9]);
        $admin = $this->user();

        // The call-reminder screen was built in parallel with this page. Stand
        // one in if it is not there, so both halves are pinned either way.
        if (! Route::has('admin.reminders.create')) {
            Route::get('admin/reminders/create', fn () => 'stand-in')->name('admin.reminders.create');
            Route::getRoutes()->refreshNameLookups();
        }

        $this->actingAs($admin)->get(route('admin.customers.occasions'))
            ->assertOk()
            ->assertSee('href="'.route('admin.reminders.create', ['customer' => $customer->id]).'"', false);

        // Without it the page must still render — just without the button.
        $routes = new RouteCollection;
        foreach (Route::getRoutes() as $route) {
            if ($route->getName() !== 'admin.reminders.create') {
                $routes->add($route);
            }
        }
        Route::setRoutes($routes);
        app('url')->setRoutes($routes);

        $this->actingAs($admin)->get(route('admin.customers.occasions'))
            ->assertOk()
            ->assertDontSee('reminders/create?customer='.$customer->id, false)
            ->assertDontSee('Remind me to call');
    }

    public function test_the_sms_box_posts_the_one_field_the_customer_sms_action_reads(): void
    {
        $this->atDhaka('2026-09-17 10:00');
        Setting::put('integrations', [
            'sms_enabled' => true, 'sms_base_url' => 'http://sms.test', 'sms_api_key' => 'k', 'sms_secret_key' => 's', 'sms_caller_id' => 'NC',
        ]);
        Http::fake(['*' => Http::response(['Status' => '0', 'Text' => 'ACCEPTD'], 200)]);
        $rima = $this->customer(['name' => 'Rima Sultana', 'phone' => '01711195772', 'anniversary_day' => 17, 'anniversary_month' => 9]);
        $admin = $this->user();

        $html = $this->actingAs($admin)->get(route('admin.customers.occasions'))->assertOk()->getContent();

        // Read the row's form the way a browser would submit it.
        $dom = new \DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?>'.$html);
        libxml_clear_errors();
        $xpath = new \DOMXPath($dom);

        $form = $xpath->query('//form[@id="occasion-sms-anniversary-'.$rima->id.'"]')->item(0);
        $this->assertNotNull($form, 'the row has no SMS form');
        $this->assertSame(route('admin.customers.sms', $rima), $form->getAttribute('action'));
        $this->assertSame('post', strtolower($form->getAttribute('method')));

        $fields = [];
        foreach ($xpath->query('.//input[@name]|.//textarea[@name]|.//select[@name]', $form) as $field) {
            $fields[$field->getAttribute('name')] = $field->nodeName === 'textarea' ? $field->textContent : $field->getAttribute('value');
        }
        // CustomerController::sendSms validates `message` (required, max 500)
        // and nothing else; a field named action/method/target would break the
        // background submit (AdminFormActionClobberTest).
        $this->assertSame(['_token', 'message'], array_keys($fields));
        $this->assertSame(UpcomingOccasions::wish($rima, 'anniversary', 0, sms: true), $fields['message']);
        $this->assertSame('500', $xpath->query('.//textarea[@name="message"]', $form)->item(0)->getAttribute('maxlength'));
        $this->assertLessThanOrEqual(70, mb_strlen($fields['message']), 'the prefilled wish should fit one unicode SMS part');

        $this->actingAs($admin)
            ->from(route('admin.customers.occasions'))
            ->post($form->getAttribute('action'), ['message' => $fields['message']])
            ->assertRedirect(route('admin.customers.occasions'))
            ->assertSessionHas('success', 'SMS sent.');

        // Other calls (the admin chrome's own checks) share the fake, so read
        // the fields defensively rather than assume every request is an SMS.
        Http::assertSent(fn ($request) => ($request->data()['toUser'] ?? null) === '8801711195772'
            && str_contains((string) ($request->data()['messageContent'] ?? ''), 'শুভ বিবাহবার্ষিকী, Rima!'));
    }
}

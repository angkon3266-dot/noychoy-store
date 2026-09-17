<?php

namespace Tests\Feature;

use App\Jobs\CheckCustomersCourier;
use App\Models\CourierCheck;
use App\Models\Customer;
use App\Models\Setting;
use App\Models\User;
use App\Services\BdCourierService;
use App\Services\Meta\MetaQueueRunner;
use App\Support\CourierTier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Customer value tiers from courier data (owner, 2026-09-17).
 *
 * "From my customer data, using phone number find out the high value
 * customers using their courier data and categorise them so I can call and
 * follow them… categorise them by colour code too and add a slicer on the
 * customer section to quickly navigate."
 *
 * Her choices, pinned here: tiers by TOTAL parcels (Under 100 · 100–200 ·
 * 200–300 · 300–600 · 600–1,000 · 1,000+, lower bound inclusive), the
 * delivered rate beside the tier, and lookups only in batches she starts by
 * hand — every BDCourier lookup is a paid credit. No test here reaches the
 * real API: stray requests are refused outright.
 */
class CustomerCourierTiersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        // The batch kicks a background queue worker where exec() is allowed.
        // A test run must never leave one behind.
        $this->mock(MetaQueueRunner::class, fn ($mock) => $mock->shouldReceive('kick'));
    }

    protected function configure(array $extra = []): void
    {
        Setting::put('integrations', array_merge([
            'bdcourier_enabled' => true,
            'bdcourier_api_key' => 'test-key',
            'bdcourier_base_url' => 'https://api.bdcourier.com',
        ], $extra));
    }

    protected function admin(): User
    {
        return User::firstOrCreate(
            ['email' => 'owner@test.local'],
            ['name' => 'Owner', 'password' => bcrypt('x'), 'role' => 'admin'],
        );
    }

    protected function customer(string $name, ?string $phone, array $attrs = []): Customer
    {
        return Customer::create(array_merge([
            'name' => $name,
            'phone' => $phone,
            'total_orders' => 1,
            'total_spent' => 1000,
        ], $attrs));
    }

    /** A lookup already paid for, stored the way BdCourierService stores one. */
    protected function checked(string $phone, int $total, float $ratio = 90.0, ?\DateTimeInterface $at = null): CourierCheck
    {
        $delivered = (int) round($total * $ratio / 100);

        return CourierCheck::create([
            'phone' => $phone,
            'payload' => [
                'ok' => true,
                'summary' => [
                    'total_parcel' => $total, 'success_parcel' => $delivered,
                    'cancelled_parcel' => $total - $delivered, 'success_ratio' => $ratio,
                ],
                'couriers' => [
                    ['key' => 'steadfast', 'name' => 'SteadFast', 'logo' => '', 'total_parcel' => $total,
                        'success_parcel' => $delivered, 'cancelled_parcel' => $total - $delivered, 'success_ratio' => $ratio],
                ],
                'reports' => [],
            ],
            'success_ratio' => $ratio,
            'total_parcel' => $total,
            'reports_count' => 0,
            'checked_at' => $at ?? now(),
        ]);
    }

    /** BDCourier's documented success response. */
    protected function payload(int $total = 412, float $ratio = 92.5): array
    {
        $delivered = (int) round($total * $ratio / 100);

        return [
            'status' => 'success',
            'data' => [
                'steadfast' => ['name' => 'SteadFast', 'logo' => '', 'total_parcel' => $total,
                    'success_parcel' => $delivered, 'cancelled_parcel' => $total - $delivered, 'success_ratio' => $ratio],
                'summary' => ['total_parcel' => $total, 'success_parcel' => $delivered,
                    'cancelled_parcel' => $total - $delivered, 'success_ratio' => $ratio],
            ],
            'reports' => [],
        ];
    }

    /** 017xxxxxxxx numbers that pass BdPhone. */
    protected function phone(int $n): string
    {
        return '0171'.str_pad((string) $n, 7, '0', STR_PAD_LEFT);
    }

    /** @return array<int, string> the phones BDCourier was asked about, in order */
    protected function phonesSent(): array
    {
        return collect(Http::recorded())->map(fn ($pair) => $pair[0]['phone'])->all();
    }

    /** Run the job start() queued, the way the database queue would. */
    protected function pushedBatchJob(): CheckCustomersCourier
    {
        $jobs = Queue::pushed(CheckCustomersCourier::class);
        $this->assertNotEmpty($jobs, 'no batch job was queued');

        return $jobs->last();
    }

    // ── The brackets ──────────────────────────────────────────────────────

    public function test_a_tier_includes_its_lower_bound_and_stops_short_of_its_upper(): void
    {
        $expected = [
            0 => 'under-100', 99 => 'under-100',
            100 => '100-200', 199 => '100-200',
            200 => '200-300', 299 => '200-300',
            300 => '300-600', 599 => '300-600',
            600 => '600-1000', 999 => '600-1000',
            1000 => '1000-plus', 25000 => '1000-plus',
        ];

        foreach ($expected as $parcels => $key) {
            $this->assertSame($key, CourierTier::forTotal($parcels)['key'], "{$parcels} parcels");
        }

        $this->assertSame(
            ['Under 100', '100–200', '200–300', '300–600', '600–1,000', '1,000+'],
            array_values(array_column(CourierTier::all(), 'label')),
        );
    }

    public function test_every_tier_has_its_own_colour(): void
    {
        $badges = array_column(CourierTier::all(), 'badge');

        $this->assertCount(6, array_unique($badges));
    }

    /**
     * The tier colours are PHP strings, which Tailwind never scans — a class
     * that is not already in the committed CSS renders as no colour at all,
     * and a rebuild would not fix it.
     */
    public function test_every_tier_colour_exists_in_the_built_admin_css(): void
    {
        $manifest = public_path('build/manifest.json');

        if (! is_file($manifest)) {
            $this->markTestSkipped('No built assets in this checkout.');
        }

        $file = json_decode((string) file_get_contents($manifest), true)['resources/css/app.css']['file'] ?? null;
        $this->assertIsString($file);
        $css = (string) file_get_contents(public_path('build/'.$file));

        $classes = collect(CourierTier::all())->pluck('badge')
            ->merge(array_values(CourierTier::DELIVERY_TONES))
            ->flatMap(fn ($list) => explode(' ', $list))
            ->unique();

        $missing = $classes->reject(function (string $class) use ($css) {
            $selector = '.'.preg_replace('/([^a-zA-Z0-9_-])/', '\\\\$1', $class);

            return (bool) preg_match('/'.preg_quote($selector, '/').'(?=[\s{:,.\[)>~+])/', $css);
        });

        $this->assertSame([], $missing->values()->all(), 'these classes are not in public/build — pick ones the admin CSS already has');
    }

    public function test_the_delivered_badge_follows_the_safe_and_warning_thresholds(): void
    {
        $this->configure();

        $this->assertSame(['92% delivered', 'safe'], array_values(array_intersect_key(CourierTier::delivery(92.4, 300), ['label' => 1, 'level' => 1])));
        // Rounded down: "80%" in a Warning's amber would contradict the 80% line.
        $this->assertSame('79% delivered', CourierTier::delivery(79.6, 300)['label']);
        $this->assertSame('warning', CourierTier::delivery(79.6, 300)['level']);
        $this->assertSame('risky', CourierTier::delivery(40, 300)['level']);
        $this->assertSame('No parcels yet', CourierTier::delivery(0, 0)['label']);

        // The owner's own thresholds, not hard-coded ones.
        $this->configure(['bdcourier_safe_threshold' => 95]);
        $this->assertSame('warning', CourierTier::delivery(92.4, 300)['level']);
    }

    // ── The slicer ────────────────────────────────────────────────────────

    public function test_the_pills_count_every_tier_and_the_unchecked_in_one_query(): void
    {
        $this->configure();

        foreach (['A' => 0, 'B' => 99, 'C' => 100, 'D' => 450, 'E' => 1000] as $name => $parcels) {
            $c = $this->customer($name, $this->phone(ord($name)));
            $this->checked($c->phone, $parcels);
        }
        $this->customer('F', $this->phone(70));
        $this->customer('G', $this->phone(71));
        $this->customer('H', null);

        DB::enableQueryLog();
        $res = $this->actingAs($this->admin())->get(route('admin.customers.index'))->assertOk();
        $grouped = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'as bucket'));

        $this->assertCount(1, $grouped, 'the pill counts should come from one grouped query');
        $this->assertSame([
            'all' => 8, 'unchecked' => 2, 'no-phone' => 1,
            'under-100' => 2, '100-200' => 1, '200-300' => 0, '300-600' => 1, '600-1000' => 0, '1000-plus' => 1,
        ], $res->viewData('courier')['counts']);

        $res->assertSeeText('Not checked 2')
            ->assertSeeText('Under 100 2')
            ->assertSeeText('300–600 1')
            ->assertSeeText('1,000+ 1')
            ->assertSeeText('1 without a phone number');
    }

    public function test_the_pills_count_within_the_other_filters_but_ignore_the_tier(): void
    {
        $this->configure();

        $repeat = $this->customer('Nusrat', $this->phone(1), ['total_orders' => 3]);
        $this->checked($repeat->phone, 450);
        $oneOff = $this->customer('Rafi', $this->phone(2), ['total_orders' => 1]);
        $this->checked($oneOff->phone, 350);
        $repeatSmall = $this->customer('Mitu', $this->phone(3), ['total_orders' => 2]);
        $this->checked($repeatSmall->phone, 150);

        $counts = $this->actingAs($this->admin())
            ->get(route('admin.customers.index', ['repeat' => 1, 'tier' => '300-600']))
            ->assertOk()
            ->viewData('courier')['counts'];

        // Repeat buyers only — and the 100–200 pill still shows Mitu while
        // 300–600 is the active one.
        $this->assertSame(2, $counts['all']);
        $this->assertSame(1, $counts['300-600']);
        $this->assertSame(1, $counts['100-200']);
    }

    public function test_a_tier_pill_filters_the_list_and_keeps_everything_else(): void
    {
        $this->configure();

        $nusrat = $this->customer('Nusrat Jahan', $this->phone(1), ['total_orders' => 3]);
        $this->checked($nusrat->phone, 450);
        $rafi = $this->customer('Rafi Ahmed', $this->phone(2), ['total_orders' => 1]);
        $this->checked($rafi->phone, 350);
        $mitu = $this->customer('Mitu Akter', $this->phone(3), ['total_orders' => 2]);
        $this->checked($mitu->phone, 150);

        $res = $this->actingAs($this->admin())
            ->get(route('admin.customers.index', ['tier' => '300-600', 'repeat' => 1, 'sort' => 'orders']))
            ->assertOk()
            ->assertSee('Nusrat Jahan')
            ->assertDontSee('Rafi Ahmed')   // right tier, not a repeat buyer
            ->assertDontSee('Mitu Akter');  // repeat buyer, wrong tier

        $this->assertSame('300-600', $res->viewData('courier')['active']);

        // Every pill carries the search, the sort and the ticked boxes along.
        $res->assertSee(route('admin.customers.index', ['repeat' => 1, 'sort' => 'orders', 'tier' => '1000-plus']))
            ->assertSee(route('admin.customers.index', ['repeat' => 1, 'sort' => 'orders', 'tier' => 'unchecked']))
            ->assertSee(route('admin.customers.index', ['repeat' => 1, 'sort' => 'orders']));

        // Exactly one pill is lit, and it is this one.
        $html = $res->getContent();
        $this->assertSame(1, substr_count($html, 'aria-current="true"'));
        $this->assertMatchesRegularExpression('/tier=300-600"\s+aria-current="true"/', $html);

        // Searching again from the form stays inside the tier.
        $res->assertSee('<input type="hidden" name="tier" value="300-600">', false);
    }

    public function test_the_next_page_stays_in_the_tier(): void
    {
        $this->configure();

        foreach (range(1, 31) as $i) {
            $c = $this->customer('Tier customer '.$i, $this->phone($i));
            $this->checked($c->phone, 700);
        }

        $customers = $this->actingAs($this->admin())
            ->get(route('admin.customers.index', ['tier' => '600-1000', 'q' => 'Tier']))
            ->assertOk()
            ->viewData('customers');

        $this->assertSame(31, $customers->total());
        $this->assertStringContainsString('tier=600-1000', $customers->url(2));
        $this->assertStringContainsString('q=Tier', $customers->url(2));
    }

    public function test_not_checked_lists_numbers_nobody_has_looked_up(): void
    {
        $this->configure();

        $checked = $this->customer('Already Checked', $this->phone(1));
        $this->checked($checked->phone, 120);
        $this->customer('Waiting One', $this->phone(2));
        $this->customer('Waiting Two', $this->phone(3));
        $this->customer('Google Signup', null);

        $this->actingAs($this->admin())
            ->get(route('admin.customers.index', ['tier' => 'unchecked']))
            ->assertOk()
            ->assertSee('Waiting One')
            ->assertSee('Waiting Two')
            ->assertDontSee('Already Checked')
            // No number, nothing to check — so not waiting to be checked.
            ->assertDontSee('Google Signup');
    }

    public function test_an_unknown_tier_shows_everyone(): void
    {
        $this->customer('Somebody', $this->phone(1));

        $res = $this->actingAs($this->admin())
            ->get(route('admin.customers.index', ['tier' => 'gold']))
            ->assertOk()
            ->assertSee('Somebody');

        $this->assertNull($res->viewData('courier')['active']);
    }

    public function test_most_parcels_sorts_by_parcels_with_the_unchecked_last(): void
    {
        $this->configure();

        $few = $this->customer('Few', $this->phone(1), ['total_spent' => 9000]);
        $this->checked($few->phone, 50);
        $most = $this->customer('Most', $this->phone(2), ['total_spent' => 100]);
        $this->checked($most->phone, 1200);
        $middle = $this->customer('Middle', $this->phone(3), ['total_spent' => 500]);
        $this->checked($middle->phone, 450);
        $this->customer('Unchecked big spender', $this->phone(4), ['total_spent' => 99999]);

        $names = $this->actingAs($this->admin())
            ->get(route('admin.customers.index', ['sort' => 'parcels']))
            ->assertOk()
            ->assertSee('<option value="parcels" selected>Most parcels</option>', false)
            ->viewData('customers')
            ->pluck('name')
            ->all();

        $this->assertSame(['Most', 'Middle', 'Few', 'Unchecked big spender'], $names);
    }

    // ── The Courier column ────────────────────────────────────────────────

    public function test_the_column_shows_the_parcels_the_delivered_rate_and_an_old_date(): void
    {
        $this->configure();

        $old = $this->customer('Old Lookup', $this->phone(1));
        $this->checked($old->phone, 450, 92.5, now()->subDays(5));
        $fresh = $this->customer('Fresh Lookup', $this->phone(2));
        $this->checked($fresh->phone, 1250, 45.0, now()->subHours(2));
        $waiting = $this->customer('Waiting', $this->phone(3));

        $this->actingAs($this->admin())
            ->get(route('admin.customers.index'))
            ->assertOk()
            ->assertSee('450 parcels')
            ->assertSee('92% delivered')
            ->assertSee('1,250 parcels')
            ->assertSee('45% delivered')
            ->assertSee('checked 5 days ago')
            // Inside the 48-hour window the date is noise.
            ->assertDontSee('checked 2 hours ago')
            ->assertSee('Not checked')
            ->assertSee(route('admin.customers.courier-check', $waiting))
            ->assertDontSee(route('admin.customers.courier-check', $old));
    }

    public function test_opening_the_list_or_a_customer_never_calls_bdcourier(): void
    {
        $this->configure();
        Http::fake();

        $checked = $this->customer('Checked', $this->phone(1));
        $this->checked($checked->phone, 300);
        $waiting = $this->customer('Waiting', $this->phone(2));

        $this->actingAs($this->admin())->get(route('admin.customers.index'))->assertOk();
        $this->actingAs($this->admin())->get(route('admin.customers.index', ['tier' => 'unchecked', 'sort' => 'parcels']))->assertOk();
        $this->actingAs($this->admin())->get(route('admin.customers.show', $checked))->assertOk();
        $this->actingAs($this->admin())->get(route('admin.customers.show', $waiting))->assertOk();

        Http::assertNothingSent();
    }

    // ── One lookup ────────────────────────────────────────────────────────

    public function test_the_check_button_stores_the_lookup_and_says_what_it_found(): void
    {
        $this->configure();
        Http::fake(['api.bdcourier.com/*' => Http::response($this->payload(412, 92.5))]);

        $customer = $this->customer('Nusrat Jahan', '01712345678');

        $this->actingAs($this->admin())
            ->from(route('admin.customers.index'))
            ->post(route('admin.customers.courier-check', $customer))
            ->assertRedirect(route('admin.customers.index'))
            ->assertSessionHas('success', 'Courier history for Nusrat Jahan: 412 parcels (300–600), 92% delivered.');

        Http::assertSent(fn (HttpRequest $r) => $r->url() === 'https://api.bdcourier.com/courier-check' && $r['phone'] === '01712345678');
        $this->assertDatabaseHas('courier_checks', ['phone' => '01712345678', 'total_parcel' => 412]);

        $this->actingAs($this->admin())
            ->get(route('admin.customers.index', ['tier' => '300-600']))
            ->assertSee('Nusrat Jahan');
    }

    public function test_a_failed_lookup_says_why_and_stores_nothing(): void
    {
        $this->configure();
        Http::fake(['api.bdcourier.com/*' => Http::response(['message' => 'Unauthenticated'], 401)]);

        $customer = $this->customer('Nusrat Jahan', '01712345678');

        $this->actingAs($this->admin())
            ->post(route('admin.customers.courier-check', $customer))
            ->assertSessionHas('error', 'BDCourier rejected the API key. Check it under Admin → Integrations.');

        $this->assertDatabaseCount('courier_checks', 0);
    }

    public function test_a_customer_without_a_phone_is_not_looked_up(): void
    {
        $this->configure();
        Http::fake();

        $customer = $this->customer('Google Signup', null);

        $this->actingAs($this->admin())
            ->post(route('admin.customers.courier-check', $customer))
            ->assertSessionHas('error', 'This customer has no phone number to check.');

        Http::assertNothingSent();
    }

    // ── Check next 50 ─────────────────────────────────────────────────────

    public function test_the_batch_checks_the_fifty_biggest_spenders_nobody_has_checked(): void
    {
        $this->configure();
        Http::fake(['api.bdcourier.com/*' => Http::response($this->payload())]);

        foreach (range(1, 55) as $i) {
            $this->customer('Customer '.$i, $this->phone($i), [
                'total_spent' => $i * 100,
                'last_order_at' => now()->subDays($i),
            ]);
        }
        // Tied on spend for the fiftieth place: the more recent buyer goes first.
        Customer::where('phone', $this->phone(5))->update(['total_spent' => 600, 'last_order_at' => now()->subDay()]);
        Customer::where('phone', $this->phone(6))->update(['total_spent' => 600, 'last_order_at' => now()->subMonth()]);

        // Bigger spenders that must not cost a credit.
        $known = $this->customer('Already checked', $this->phone(900), ['total_spent' => 999999]);
        $this->checked($known->phone, 80);
        $this->customer('No phone', null, ['total_spent' => 999999]);
        $this->customer('Not a mobile number', '12345', ['total_spent' => 999999]);

        $this->actingAs($this->admin())
            ->from(route('admin.customers.index'))
            ->post(route('admin.customers.courier-batch'))
            ->assertRedirect(route('admin.customers.index'))
            ->assertSessionHas('success');

        $expected = collect(range(7, 55))->push(5)->map(fn ($i) => $this->phone($i))->all();
        Http::assertSentCount(50);
        $this->assertEqualsCanonicalizing($expected, $this->phonesSent());
        // Biggest spend first.
        $this->assertSame($this->phone(55), $this->phonesSent()[0]);

        $status = CheckCustomersCourier::status();
        $this->assertTrue($status['finished']);
        $this->assertSame([50, 50, 50, 0, 0], [$status['total'], $status['done'], $status['checked'], $status['failed'], $status['skipped']]);

        // Four ordinary customers and the malformed number are still waiting.
        $this->actingAs($this->admin())
            ->get(route('admin.customers.index'))
            ->assertSeeText('Courier check finished: 50 of 50 customers checked.')
            ->assertViewHas('courier', fn ($courier) => $courier['unchecked'] === 6);
    }

    public function test_the_batch_stops_at_a_rejected_api_key(): void
    {
        $this->configure();
        Http::fake(['api.bdcourier.com/*' => Http::response(['message' => 'Unauthenticated'], 401)]);

        foreach (range(1, 5) as $i) {
            $this->customer('Customer '.$i, $this->phone($i), ['total_spent' => $i * 100]);
        }

        $this->actingAs($this->admin())->post(route('admin.customers.courier-batch'));

        // One refusal that will repeat for every number is enough.
        Http::assertSentCount(1);

        $status = CheckCustomersCourier::status();
        $this->assertTrue($status['finished']);
        $this->assertTrue($status['stopped']);
        $this->assertSame([1, 0, 1], [$status['done'], $status['checked'], $status['failed']]);
        $this->assertStringContainsString('API key', $status['error']);

        $this->actingAs($this->admin())
            ->get(route('admin.customers.index'))
            ->assertSeeText('Courier check stopped after 1 of 5 customers: BDCourier rejected the API key.');
    }

    public function test_the_batch_stops_when_the_plan_quota_runs_out(): void
    {
        $this->configure();
        Http::fake(['api.bdcourier.com/*' => Http::sequence()
            ->push($this->payload())
            ->push($this->payload())
            ->push(['message' => 'Too Many Requests'], 429)
            ->push($this->payload())]);

        foreach (range(1, 6) as $i) {
            $this->customer('Customer '.$i, $this->phone($i), ['total_spent' => $i * 100]);
        }

        $this->actingAs($this->admin())->post(route('admin.customers.courier-batch'));

        Http::assertSentCount(3);

        $status = CheckCustomersCourier::status();
        $this->assertTrue($status['stopped']);
        $this->assertSame([3, 2, 1], [$status['done'], $status['checked'], $status['failed']]);
        $this->assertStringContainsString('quota', $status['error']);
        $this->assertDatabaseCount('courier_checks', 2);
    }

    public function test_one_unreachable_lookup_does_not_end_the_batch(): void
    {
        $this->configure();
        Http::fake(['api.bdcourier.com/*' => Http::sequence()
            ->push(['message' => 'Server Error'], 500)
            ->push($this->payload())
            ->push($this->payload())]);

        foreach (range(1, 3) as $i) {
            $this->customer('Customer '.$i, $this->phone($i), ['total_spent' => $i * 100]);
        }

        $this->actingAs($this->admin())->post(route('admin.customers.courier-batch'));

        Http::assertSentCount(3);

        $status = CheckCustomersCourier::status();
        $this->assertFalse($status['stopped']);
        $this->assertSame([3, 2, 1], [$status['done'], $status['checked'], $status['failed']]);
        $this->assertSame('warning', $this->getJson(route('admin.customers.courier-batch.status'))->json('tone'));
    }

    public function test_the_list_shows_the_progress_while_the_batch_runs_and_the_result_after(): void
    {
        $this->configure();
        Queue::fake();

        foreach (range(1, 3) as $i) {
            $this->customer('Customer '.$i, $this->phone($i));
        }

        $this->actingAs($this->admin())
            ->post(route('admin.customers.courier-batch'))
            ->assertSessionHas('success', 'Checking 3 customers in the background (up to 3 BDCourier credits). You can keep working — the progress shows above the customer list.');

        Queue::assertPushed(CheckCustomersCourier::class, 1);

        $this->actingAs($this->admin())
            ->get(route('admin.customers.index'))
            ->assertSeeText('Checking 3 customers… 0 done');

        $this->actingAs($this->admin())
            ->getJson(route('admin.customers.courier-batch.status'))
            ->assertOk()
            ->assertJson(['running' => true, 'finished' => false, 'total' => 3, 'done' => 0, 'tone' => 'info']);

        // The queue worker gets to it.
        Http::fake(['api.bdcourier.com/*' => Http::response($this->payload())]);
        $this->pushedBatchJob()->handle(app(BdCourierService::class));

        $this->actingAs($this->admin())
            ->getJson(route('admin.customers.courier-batch.status'))
            ->assertJson(['running' => false, 'finished' => true, 'done' => 3, 'checked' => 3, 'tone' => 'success']);

        $this->actingAs($this->admin())
            ->get(route('admin.customers.index'))
            ->assertSeeText('Courier check finished: 3 of 3 customers checked.')
            ->assertSee('Refresh the list');
    }

    public function test_a_second_batch_is_refused_while_one_is_running(): void
    {
        $this->configure();
        Queue::fake();

        foreach (range(1, 3) as $i) {
            $this->customer('Customer '.$i, $this->phone($i));
        }

        $this->actingAs($this->admin())->post(route('admin.customers.courier-batch'))->assertSessionHas('success');
        $this->actingAs($this->admin())
            ->post(route('admin.customers.courier-batch'))
            ->assertSessionHas('error', 'A courier check is already running. Wait for it to finish before starting the next batch.');

        Queue::assertPushed(CheckCustomersCourier::class, 1);

        // And the button says so rather than inviting the click.
        $this->assertMatchesRegularExpression(
            '/courier-batch" method="POST"[^>]*>\s*<input[^>]*>\s*<button[^>]*disabled/',
            $this->actingAs($this->admin())->get(route('admin.customers.index'))->getContent(),
        );

        // Nor can the slot be taken from underneath the running batch.
        $this->assertNull(CheckCustomersCourier::start([1]));
    }

    public function test_a_batch_that_stopped_moving_frees_the_button(): void
    {
        $this->configure();
        Queue::fake();

        foreach (range(1, 3) as $i) {
            $this->customer('Customer '.$i, $this->phone($i));
        }

        $this->actingAs($this->admin())->post(route('admin.customers.courier-batch'));
        $abandoned = $this->pushedBatchJob();

        // Its worker died: nothing has touched it for longer than the window.
        $this->travel(CheckCustomersCourier::STALE_MINUTES + 1)->minutes();

        $this->assertTrue(CheckCustomersCourier::status()['interrupted']);
        $this->actingAs($this->admin())
            ->get(route('admin.customers.index'))
            ->assertSeeText('The last courier check stopped after 0 of 3 customers.');

        $this->actingAs($this->admin())->post(route('admin.customers.courier-batch'))->assertSessionHas('success');
        Queue::assertPushed(CheckCustomersCourier::class, 2);

        // If the old job surfaces after all, it belongs to nobody and spends nothing.
        Http::fake();
        $abandoned->handle(app(BdCourierService::class));
        Http::assertNothingSent();
    }

    public function test_a_batch_whose_job_dies_says_where_it_got_to_and_lets_go(): void
    {
        $this->configure();
        Queue::fake();

        foreach (range(1, 3) as $i) {
            $this->customer('Customer '.$i, $this->phone($i));
        }

        $this->actingAs($this->admin())->post(route('admin.customers.courier-batch'));

        // A worker timeout: the queue calls failed() on the job it gave up on.
        $this->pushedBatchJob()->failed(new \RuntimeException('timed out'));

        $status = CheckCustomersCourier::status();
        $this->assertTrue($status['finished']);
        $this->assertTrue($status['stopped']);
        $this->assertFalse($status['running']);

        $this->actingAs($this->admin())
            ->get(route('admin.customers.index'))
            ->assertSeeText('Courier check stopped after 0 of 3 customers: the background job stopped unexpectedly.');

        $this->actingAs($this->admin())->post(route('admin.customers.courier-batch'))->assertSessionHas('success');
        Queue::assertPushed(CheckCustomersCourier::class, 2);
    }

    public function test_a_customer_checked_since_the_batch_began_costs_no_credit(): void
    {
        $this->configure();
        Queue::fake();

        foreach (range(1, 3) as $i) {
            $this->customer('Customer '.$i, $this->phone($i), ['total_spent' => $i * 100]);
        }

        $this->actingAs($this->admin())->post(route('admin.customers.courier-batch'));

        // Somebody pressed Check on an order page in the meantime.
        $this->checked($this->phone(2), 250);

        Http::fake(['api.bdcourier.com/*' => Http::response($this->payload())]);
        $this->pushedBatchJob()->handle(app(BdCourierService::class));

        Http::assertSentCount(2);
        $this->assertNotContains($this->phone(2), $this->phonesSent());

        $status = CheckCustomersCourier::status();
        $this->assertSame([3, 2, 1], [$status['done'], $status['checked'], $status['skipped']]);
    }

    public function test_a_long_batch_hands_what_is_left_to_a_fresh_job(): void
    {
        $this->configure();
        Queue::fake();

        foreach (range(1, 3) as $i) {
            $this->customer('Customer '.$i, $this->phone($i), ['total_spent' => $i * 100]);
        }

        $this->actingAs($this->admin())->post(route('admin.customers.courier-batch'));

        // A run out of time after its first lookup.
        $job = $this->pushedBatchJob();
        $job->budgetSeconds = 0;

        Http::fake(['api.bdcourier.com/*' => Http::response($this->payload())]);
        $job->handle(app(BdCourierService::class));

        Http::assertSentCount(1);
        $this->assertSame([$this->phone(3)], $this->phonesSent());

        Queue::assertPushed(CheckCustomersCourier::class, 2);
        $next = $this->pushedBatchJob();
        $this->assertSame($job->batchId, $next->batchId);
        $this->assertSame(
            Customer::whereIn('phone', [$this->phone(2), $this->phone(1)])->orderByDesc('total_spent')->pluck('id')->all(),
            $next->customerIds,
        );

        $status = CheckCustomersCourier::status();
        $this->assertTrue($status['running']);
        $this->assertSame(1, $status['done']);
    }

    public function test_the_batch_is_disabled_until_bdcourier_is_configured(): void
    {
        Setting::put('integrations', []);
        Queue::fake();
        Http::fake();

        $waiting = $this->customer('Waiting', $this->phone(1));

        $this->actingAs($this->admin())
            ->get(route('admin.customers.index'))
            ->assertOk()
            ->assertSee('BDCourier is not set up — add the API key under Admin → Integrations to check customers.')
            ->assertSee('<button type="button" class="btn-outline text-sm" disabled>🔍 Check next 50 customers</button>', false)
            ->assertDontSee(route('admin.customers.courier-check', $waiting));

        $this->actingAs($this->admin())
            ->post(route('admin.customers.courier-batch'))
            ->assertSessionHas('error', 'BDCourier is not configured. Add the API key under Admin → Integrations.');

        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_the_status_endpoint_answers_when_no_batch_has_run(): void
    {
        $this->actingAs($this->admin())
            ->getJson(route('admin.customers.courier-batch.status'))
            ->assertOk()
            ->assertExactJson(['running' => false]);
    }

    // ── The customer page ─────────────────────────────────────────────────

    public function test_the_customer_page_shows_the_courier_history_card(): void
    {
        $this->configure();

        $customer = $this->customer('Nusrat Jahan', '01712345678');
        $this->checked($customer->phone, 1250, 88.4, now()->subDays(3));

        $this->actingAs($this->admin())
            ->get(route('admin.customers.show', $customer))
            ->assertOk()
            ->assertSee('Courier history')
            ->assertSee('1,000+ parcels')
            ->assertSee('88% delivered')
            ->assertSee('1,250')
            ->assertSee('1,105')           // delivered
            ->assertSee('145')             // cancelled
            ->assertSee('88.4%')
            ->assertSee('SteadFast')
            ->assertSee('3 days ago')
            ->assertSee(route('admin.customers.courier-check', $customer))
            ->assertSee('Refresh courier history')
            ->assertSee("return confirm('Refresh the courier history for 01712345678? Uses one BDCourier credit.')", false);
    }

    public function test_an_unchecked_customer_page_offers_the_first_lookup(): void
    {
        $this->configure();

        $customer = $this->customer('Nusrat Jahan', '01712345678');

        $this->actingAs($this->admin())
            ->get(route('admin.customers.show', $customer))
            ->assertOk()
            ->assertSee('Not checked yet.')
            ->assertSee('Check courier history')
            ->assertSee("return confirm('Look up the courier history for 01712345678? Uses one BDCourier credit.')", false);
    }

    public function test_the_customer_page_offers_a_call_and_a_reminder_beside_new_order(): void
    {
        // The reminders screen is another piece of work; stand its route in
        // if it has not landed, so this pins the button either way.
        if (! Route::has('admin.reminders.create')) {
            Route::get('admin/reminders/create', fn () => 'reminders')->name('admin.reminders.create');
            Route::getRoutes()->refreshNameLookups();
        }

        $customer = $this->customer('Nusrat Jahan', '01712345678');

        $this->actingAs($this->admin())
            ->get(route('admin.customers.show', $customer))
            ->assertOk()
            ->assertSee(route('admin.reminders.create', ['customer' => $customer->id]))
            ->assertSee('Remind me to call')
            ->assertSee('href="tel:+8801712345678"', false)
            ->assertSee(route('admin.orders.create', ['customer' => $customer->id]))
            ->assertSee('+ New order');
    }

    public function test_the_list_links_the_birthdays_page(): void
    {
        if (! Route::has('admin.customers.occasions')) {
            Route::get('admin/customers/occasions', fn () => 'occasions')->name('admin.customers.occasions');
            Route::getRoutes()->refreshNameLookups();
        }

        $this->actingAs($this->admin())
            ->get(route('admin.customers.index'))
            ->assertOk()
            ->assertSee(route('admin.customers.occasions'))
            ->assertSee('Birthdays &amp; anniversaries', false);
    }

    // ── Export ────────────────────────────────────────────────────────────

    public function test_the_export_carries_the_tier_the_parcels_and_the_delivered_rate(): void
    {
        $this->configure();

        $checked = $this->customer('Nusrat Jahan', $this->phone(1), ['total_spent' => 5000]);
        $this->checked($checked->phone, 450, 92.5);
        $this->customer('Rafi Ahmed', $this->phone(2), ['total_spent' => 100]);

        $csv = $this->actingAs($this->admin())->get(route('admin.customers.export'))->assertOk()->streamedContent();

        $this->assertStringContainsString('"Last order",Registered,"Courier tier","Total parcels","Delivered %"', $csv);
        $this->assertMatchesRegularExpression('/"Nusrat Jahan",'.$this->phone(1).',.*,300–600,450,92\.50\n/u', $csv);
        $this->assertMatchesRegularExpression('/"Rafi Ahmed",'.$this->phone(2).',.*,"Not checked",,\n/u', $csv);

        // Taken with a tier pill on, the export is that tier's call list.
        $tierCsv = $this->actingAs($this->admin())->get(route('admin.customers.export', ['tier' => '300-600']))->streamedContent();
        $this->assertStringContainsString('Nusrat Jahan', $tierCsv);
        $this->assertStringNotContainsString('Rafi Ahmed', $tierCsv);
    }
}

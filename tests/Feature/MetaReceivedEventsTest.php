<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Services\Meta\MetaSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The Tracking page's "What Meta received" card: Meta's own counts of the
 * storefront's events, browser Pixel against Conversions API.
 *
 * It exists because on 2026-09-27 the owner was told three times that the
 * Pixel was dead — by Meta's browser helper, by this page's Test panel log,
 * and by neither of them being able to see the Pixel at all — while Meta was
 * receiving its events by the hundred.
 */
class MetaReceivedEventsTest extends TestCase
{
    use RefreshDatabase;

    /** How Meta answers the next /stats call, by the event_source asked for. */
    private \Closure $meta;

    protected function setUp(): void
    {
        parent::setUp();

        // Same shape as MetaCapiPayloadTest::configureMeta().
        Setting::put('meta_integration', [
            'enabled' => true,
            'pixel_id' => '1234567890',
            'pixel_enabled' => true,
            'capi_enabled' => true,
            'capi_token_encrypted' => Crypt::encryptString('test-token'),
            'security_password' => bcrypt('meta-secret'),
        ]);
        app()->forgetInstance(MetaSettings::class);

        $this->meta = $this->answering(
            browser: [
                '2026-09-28T19:00:00+0600' => ['PageView' => 121, 'ViewContent' => 89],
                '2026-09-28T20:00:00+0600' => ['PageView' => 105, 'ViewContent' => 72, 'AddToCart' => 1, 'Purchase' => 1],
            ],
            server: [
                '2026-09-28T19:00:00+0600' => ['ViewContent' => 116],
                '2026-09-28T20:00:00+0600' => ['ViewContent' => 102, 'AddToCart' => 1, 'Purchase' => 1, 'Search' => 2],
            ],
        );
        Http::fake(fn (ClientRequest $request) => ($this->meta)($request));
    }

    public function test_it_reports_what_meta_received_from_each_side(): void
    {
        $live = $this->live()->assertOk()->json();

        $this->assertTrue($live['ok']);
        $this->assertSame([
            ['event' => 'PageView', 'browser' => 226, 'server' => 0],
            ['event' => 'ViewContent', 'browser' => 161, 'server' => 218],
            ['event' => 'Search', 'browser' => 0, 'server' => 2],
            ['event' => 'AddToCart', 'browser' => 1, 'server' => 1],
            ['event' => 'InitiateCheckout', 'browser' => 0, 'server' => 0],
            ['event' => 'Purchase', 'browser' => 1, 'server' => 1],
        ], $live['events']);
        $this->assertSame(389, $live['browser_total']);
        $this->assertSame(222, $live['server_total']);

        // The latest hour that had any browser event, as a timestamp every
        // browser can parse (Meta's own "+0600" form is not one of them).
        $this->assertSame(
            Carbon::parse('2026-09-28T20:00:00+06:00')->getTimestamp(),
            Carbon::parse($live['last_browser_hour'])->getTimestamp(),
        );
        $this->assertMatchesRegularExpression('/[+-]\d\d:\d\d$|Z$/', $live['last_browser_hour']);

        // Only aggregation=event honours event_source; any other breakdown
        // returns every copy and would make the two columns identical.
        Http::assertSent(fn (ClientRequest $r) => str_contains($r->url(), '/1234567890/stats')
            && $r['aggregation'] === 'event' && $r['event_source'] === 'WEB_ONLY');
        Http::assertSent(fn (ClientRequest $r) => $r['aggregation'] === 'event' && $r['event_source'] === 'SERVER_ONLY');
    }

    public function test_a_pixel_that_reaches_nothing_reads_as_zero_browser_events(): void
    {
        $this->meta = $this->answering(
            browser: [],
            server: ['2026-09-26T23:00:00+0600' => ['ViewContent' => 47]],
        );

        $live = $this->live()->assertOk()->json();

        $this->assertTrue($live['ok']);
        $this->assertSame(0, $live['browser_total']);
        $this->assertSame(47, $live['server_total']);
        $this->assertNull($live['last_browser_hour']);
    }

    public function test_meta_is_asked_at_most_once_in_ten_minutes(): void
    {
        $this->live()->assertOk();
        $this->live()->assertOk();
        Http::assertSentCount(2);   // one call per side, once

        $this->travel(11)->minutes();
        $this->live()->assertOk();
        Http::assertSentCount(4);
    }

    public function test_a_timeout_is_shown_without_the_access_token_and_asked_again_next_time(): void
    {
        // A thrown timeout never reaches Http's recorder, so count the asks here.
        $asked = 0;
        $this->meta = function () use (&$asked) {
            $asked++;
            throw new ConnectionException(
                'cURL error 28: Operation timed out for https://graph.facebook.com/v21.0/1234567890/stats?aggregation=event&access_token=test-token&appsecret_proof=abc123'
            );
        };

        $live = $this->live()->assertOk()->json();

        $this->assertFalse($live['ok']);
        $this->assertStringStartsWith('Meta did not answer:', $live['error']);
        $this->assertStringNotContainsString('test-token', $live['error']);
        $this->assertStringNotContainsString('abc123', $live['error']);

        // A failure is not cached: the next load asks Meta again.
        $this->live();
        $this->assertSame(2, $asked);
    }

    public function test_a_refusal_from_meta_is_shown_as_meta_worded_it(): void
    {
        $this->meta = fn () => Http::response(['error' => [
            'message' => '(#100) Missing permissions',
            'type' => 'OAuthException',
            'code' => 100,
        ]], 400);

        $live = $this->live()->assertOk()->json();

        $this->assertFalse($live['ok']);
        $this->assertStringContainsString('Missing permissions', $live['error']);
    }

    public function test_without_a_pixel_id_meta_is_not_asked(): void
    {
        Setting::put('meta_integration', array_merge(Setting::get('meta_integration'), ['pixel_id' => null]));
        app()->forgetInstance(MetaSettings::class);

        $live = $this->live()->assertOk()->json();

        $this->assertFalse($live['ok']);
        $this->assertStringContainsString('No Pixel ID', $live['error']);
        Http::assertNothingSent();
    }

    public function test_the_test_log_no_longer_has_columns_that_cannot_tick(): void
    {
        $this->unlocked($this->admin())->get(route('admin.meta.tracking'))
            ->assertOk()
            ->assertSee('What Meta received from the store')
            ->assertSee(str_replace('/', '\/', route('admin.meta.tracking.live')), false)   // written by @js()
            ->assertSee('Test event log')
            ->assertSee('says nothing about the Pixel on your store')
            ->assertDontSee('Event debugger')
            ->assertDontSee('<th class="pr-3">Pixel</th>', false)
            ->assertDontSee('<th class="pr-3">Dedup</th>', false);
    }

    private function live(): \Illuminate\Testing\TestResponse
    {
        return $this->unlocked($this->admin())->getJson(route('admin.meta.tracking.live'));
    }

    /**
     * A fake /stats edge: hour => [event => count] for each side.
     *
     * @param  array<string, array<string, int>>  $browser
     * @param  array<string, array<string, int>>  $server
     */
    private function answering(array $browser, array $server): \Closure
    {
        $shape = fn (array $hours) => ['data' => array_map(
            fn (string $hour, array $counts) => [
                'start_time' => $hour,
                'data' => array_map(fn ($event, $count) => ['value' => $event, 'count' => $count], array_keys($counts), $counts),
            ],
            array_keys($hours),
            $hours,
        )];

        return fn (ClientRequest $request) => Http::response(
            $shape($request['event_source'] === 'WEB_ONLY' ? $browser : $server)
        );
    }

    private function admin(): User
    {
        return User::firstOrCreate(['email' => 'a@b.test'], ['name' => 'Admin', 'password' => bcrypt('x'), 'role' => 'admin']);
    }

    /** Signed in, and past the Meta area's second password. */
    private function unlocked(User $admin): static
    {
        return $this->actingAs($admin)->withSession(['meta_unlocked_at' => now()->toIso8601String()]);
    }
}

<?php

namespace Tests\Feature;

use App\Support\MetaIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * fbc/fbp handling and Meta's customer-information normalisation.
 *
 * These rules fail silently in production — a wrongly normalised value is
 * simply a hash that matches nobody, reported as a lower match rate weeks
 * later — so they are pinned here rather than eyeballed in Events Manager.
 */
class MetaIdentityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware('web')->get('/_test/meta', fn (Request $r) => response()->json([
            'fbc' => MetaIdentity::fbc($r),
            'fbp' => MetaIdentity::fbp($r),
        ]));
    }

    // ── fbc from fbclid ──────────────────────────────────────────────────────

    public function test_an_fbclid_in_the_url_becomes_a_meta_shaped_fbc(): void
    {
        $res = $this->get('/_test/meta?fbclid=IwAR0testclick');

        $fbc = $res->json('fbc');

        $this->assertMatchesRegularExpression('/^fb\.1\.\d{13}\.IwAR0testclick$/', $fbc);
    }

    public function test_the_click_is_stored_so_it_survives_to_later_events(): void
    {
        // Without persistence the ad click would be attached to the landing
        // page and lost by the time the visitor reaches Purchase.
        $cookie = $this->get('/_test/meta?fbclid=IwAR0testclick')
            ->getCookie(MetaIdentity::FBC_COOKIE, decrypt: false);

        $this->assertNotNull($cookie);
        $this->assertStringEndsWith('.IwAR0testclick', $cookie->getValue());
        $this->assertFalse($cookie->isHttpOnly(), 'the Pixel must be able to read _fbc');
    }

    public function test_an_existing_cookie_survives_a_repeat_of_the_same_click(): void
    {
        // Rewriting it would reset the creation timestamp and lose when the
        // click actually happened.
        $existing = 'fb.1.1700000000000.IwAR0testclick';

        $res = $this->withUnencryptedCookie(MetaIdentity::FBC_COOKIE, $existing)
            ->get('/_test/meta?fbclid=IwAR0testclick');

        $this->assertSame($existing, $res->json('fbc'));
        $this->assertNull($res->getCookie(MetaIdentity::FBC_COOKIE, decrypt: false));
    }

    public function test_a_different_click_replaces_the_stored_one(): void
    {
        $res = $this->withUnencryptedCookie(MetaIdentity::FBC_COOKIE, 'fb.1.1700000000000.OLDCLICK')
            ->get('/_test/meta?fbclid=NEWCLICK');

        $this->assertStringEndsWith('.NEWCLICK', $res->json('fbc'));
    }

    public function test_no_fbc_is_invented_for_a_visitor_who_never_clicked_an_ad(): void
    {
        $this->assertNull($this->get('/_test/meta')->json('fbc'));
    }

    public function test_a_malformed_cookie_is_ignored_rather_than_forwarded(): void
    {
        $res = $this->withUnencryptedCookie(MetaIdentity::FBC_COOKIE, 'garbage')->get('/_test/meta');

        $this->assertNull($res->json('fbc'));
    }

    public function test_fbp_is_read_but_never_minted(): void
    {
        // The Pixel owns _fbp; a competing value would leave the browser and
        // the server reporting two different identifiers for one visitor.
        $this->assertNull($this->get('/_test/meta')->json('fbp'));
        $this->assertNull($this->get('/_test/meta')->getCookie(MetaIdentity::FBP_COOKIE));

        $this->assertSame(
            'fb.1.1700000000000.1234567890',
            $this->withUnencryptedCookie(MetaIdentity::FBP_COOKIE, 'fb.1.1700000000000.1234567890')
                ->get('/_test/meta')->json('fbp'),
        );
    }

    public function test_an_fbclid_containing_dots_is_not_truncated(): void
    {
        $res = $this->get('/_test/meta?fbclid=abc.def.ghi');

        $this->assertStringEndsWith('.abc.def.ghi', $res->json('fbc'));
    }

    // ── Normalisation ────────────────────────────────────────────────────────

    /** @return array<string,array{0:string,1:string,2:?string}> */
    public static function fields(): array
    {
        return [
            'email is lowercased and trimmed' => ['em', '  Shopper@Example.COM ', 'shopper@example.com'],
            'name loses punctuation and case' => ['fn', "O'Brien", 'obrien'],
            'name loses digits' => ['fn', 'Anna2', 'anna'],
            'city loses spaces' => ['ct', 'Cox’s Bazar', 'coxsbazar'],
            'state loses spaces' => ['st', 'Dhaka Division', 'dhakadivision'],
            'postcode loses spaces' => ['zp', '1207 ', '1207'],
            'country is a two letter code' => ['country', 'BD', 'bd'],
            'a country name is rejected' => ['country', 'Bangladesh', null],
            'blank is dropped' => ['em', '   ', null],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('fields')]
    public function test_fields_are_normalised_the_way_meta_expects(string $key, string $input, ?string $expected): void
    {
        $this->assertSame($expected, MetaIdentity::normalize($key, $input));
    }

    public function test_bangladesh_phones_are_sent_in_international_form(): void
    {
        // Stored locally as 01…; Meta wants digits with the country code and no
        // plus sign. Whatever the customer typed must land on one value.
        foreach (['01712345678', '+8801712345678', '8801712345678', '01712-345678'] as $input) {
            $this->assertSame('8801712345678', MetaIdentity::normalize('ph', $input), "failed for {$input}");
        }
    }

    public function test_unhashable_parameters_are_named_correctly(): void
    {
        // Hashing any of these makes Meta discard the event's match data.
        foreach (['fbp', 'fbc', 'client_ip_address', 'client_user_agent', 'external_id'] as $key) {
            $this->assertFalse(MetaIdentity::mustHash($key), "{$key} must not be hashed");
        }

        foreach (['em', 'ph', 'fn', 'ln', 'ct', 'st', 'zp', 'country'] as $key) {
            $this->assertTrue(MetaIdentity::mustHash($key));
        }
    }

    public function test_an_already_hashed_value_is_recognised(): void
    {
        $this->assertTrue(MetaIdentity::isHashed(hash('sha256', 'x')));
        $this->assertFalse(MetaIdentity::isHashed('shopper@example.com'));
    }

    // ── external_id ──────────────────────────────────────────────────────────

    public function test_the_anonymous_external_id_is_stable_across_the_journey(): void
    {
        Route::middleware('web')->get('/_test/xid', fn () => response()->json(MetaIdentity::externalIds()));

        $first = $this->withCookie('visitor_token', 'tok-abc')->get('/_test/xid')->json();
        $again = $this->withCookie('visitor_token', 'tok-abc')->get('/_test/xid')->json();

        $this->assertSame($first, $again, 'a per-event id would provide no matching value at all');
        $this->assertCount(1, $first);
        $this->assertNotSame('tok-abc', $first[0], 'the raw token must not leave the server');
    }

    public function test_the_very_first_page_view_already_has_an_external_id(): void
    {
        // An ad click lands on a browser with no cookies yet. That first event
        // is the most attributable one in the journey, so it must not be the
        // one that goes out without an external_id.
        Route::middleware('web')->get('/_test/xid', fn () => response()->json(MetaIdentity::externalIds()));

        $this->assertCount(1, $this->get('/_test/xid')->json());
    }

    public function test_nothing_is_invented_when_there_is_no_token_at_all(): void
    {
        $this->assertSame([], MetaIdentity::externalIds(Request::create('/')));
    }
}

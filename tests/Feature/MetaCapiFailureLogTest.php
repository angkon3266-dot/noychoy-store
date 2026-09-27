<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Services\Meta\MetaSettings;
use App\Services\Meta\MetaTrackingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use Monolog\LogRecord;
use Tests\TestCase;

/**
 * A Conversions API send that fails has to leave something a person can find.
 *
 * Until 2026-09-27 a failure was a Log::warning on the default channel, and
 * production's .env says LOG_LEVEL=error — so an expired token, a refused
 * payload and a timeout were all thrown away, and the only sign of an outage
 * was the "sent" lines in meta-debug going quiet. Every test here runs with the
 * channels shaped as production has them: laravel.log (LOG_STACK=daily) keeps
 * `error` and above, meta-debug keeps everything.
 */
class MetaCapiFailureLogTest extends TestCase
{
    use RefreshDatabase;

    private const CHROME = 'Mozilla/5.0 (Linux; Android 13; SM-A546E) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Mobile Safari/537.36';

    /** Meta's answer once the token has expired: HTTP 400, code 190, subcode 463. */
    private const EXPIRED_TOKEN = ['error' => [
        'message' => 'Error validating access token: Session has expired on Friday, 26-Sep-26 10:00:00 PDT. The current time is Saturday, 27-Sep-26 03:04:05 PDT.',
        'type' => 'OAuthException',
        'code' => 190,
        'error_subcode' => 463,
        'fbtrace_id' => 'AbCdEf-expired',
    ]];

    private TestHandler $metaDebug;

    private TestHandler $laravelLog;

    /** How Meta answers the next send. */
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

        config([
            'logging.default' => 'stack',
            'logging.channels.stack.channels' => ['daily'],
            'logging.channels.daily' => ['driver' => 'monolog', 'handler' => TestHandler::class, 'level' => 'error'],
            'logging.channels.meta-debug' => ['driver' => 'monolog', 'handler' => TestHandler::class, 'level' => 'debug'],
        ]);
        foreach (['stack', 'daily', 'meta-debug'] as $channel) {
            Log::forgetChannel($channel);
        }

        // The stack writes through the daily channel's own handler, so this is
        // what laravel.log would receive.
        $this->metaDebug = Log::channel('meta-debug')->getLogger()->getHandlers()[0];
        $this->laravelLog = Log::channel('daily')->getLogger()->getHandlers()[0];

        $this->meta = $this->accepted();
        Http::fake(fn (ClientRequest $request) => str_contains($request->url(), 'graph.facebook.com')
            ? ($this->meta)()
            : Http::response('', 200));
    }

    // ── The Meta channel ─────────────────────────────────────────────────────

    public function test_a_refused_event_is_written_to_the_meta_channel_beside_the_successes(): void
    {
        $this->sendCheckout('IC.ok');
        $this->meta = $this->expiredToken();
        $this->sendCheckout('IC.refused');

        [$sent] = $this->lines($this->metaDebug, 'Meta CAPI event sent');
        [$failed] = $this->lines($this->metaDebug, 'Meta CAPI event failed');

        $this->assertSame(Level::Error, $failed->level);

        // The line a success gets, plus what went wrong.
        $this->assertSame([], array_diff(array_keys($sent->context), array_keys($failed->context)));
        $this->assertEqualsCanonicalizing(['error', 'failure'], array_values(array_diff(array_keys($failed->context), array_keys($sent->context))));

        $this->assertSame('InitiateCheckout', $failed->context['event']);
        $this->assertSame('IC.refused', $failed->context['event_id']);
        $this->assertSame(400, $failed->context['status']);
        $this->assertSame(self::EXPIRED_TOKEN['error']['message'], $failed->context['error']);
        $this->assertSame('AbCdEf-expired', $failed->context['fbtrace_id']);
        $this->assertSame('http-400-190-463', $failed->context['failure']);
    }

    // ── laravel.log ──────────────────────────────────────────────────────────

    public function test_production_s_laravel_log_hears_of_it_as_an_error(): void
    {
        $this->meta = $this->expiredToken();
        $this->sendCheckout();

        $errors = $this->lines($this->laravelLog, 'Meta CAPI event failed');

        $this->assertCount(1, $errors, 'LOG_LEVEL=error dropped every CAPI failure while it was a warning');
        $this->assertSame(Level::Error, $errors[0]->level);
        $this->assertSame('http-400-190-463', $errors[0]->context['failure']);
        $this->assertSame('AbCdEf-expired', $errors[0]->context['fbtrace_id']);
        $this->assertStringContainsString('Session has expired', $errors[0]->context['error']);
        $this->assertStringContainsString('meta-debug', $errors[0]->context['repeats']);
    }

    public function test_an_outage_reaches_laravel_log_once_per_kind_per_quarter_hour(): void
    {
        $this->meta = $this->expiredToken();
        foreach (range(1, 5) as $i) {
            $this->sendCheckout("IC.{$i}");
        }

        $this->assertCount(5, $this->lines($this->metaDebug, 'Meta CAPI event failed'), 'every failure is kept in the Meta channel');
        $this->assertCount(1, $this->lines($this->laravelLog, 'Meta CAPI event failed'), 'one line per page view would bury the file');

        // A different kind of failure is news straight away…
        $this->meta = $this->timeout();
        $this->sendCheckout('IC.timeout');

        // …the same one fourteen minutes on is not…
        $this->travel(14)->minutes();
        $this->meta = $this->expiredToken();
        $this->sendCheckout('IC.14');

        // …and once the window has passed it is said again.
        $this->travel(2)->minutes();
        $this->sendCheckout('IC.16');

        $this->assertSame(
            ['http-400-190-463', 'ConnectionException-curl-28', 'http-400-190-463'],
            array_map(fn (LogRecord $r) => $r->context['failure'], $this->lines($this->laravelLog, 'Meta CAPI event failed')),
        );
        $this->assertCount(8, $this->lines($this->metaDebug, 'Meta CAPI event failed'));
    }

    // ── What never reaches either log ────────────────────────────────────────

    public function test_a_timeout_is_logged_in_the_same_shape_and_without_the_token(): void
    {
        $this->meta = fn () => throw new ConnectionException(
            'cURL error 28: Operation timed out for https://graph.facebook.com/v21.0/1234567890/events?access_token=test-token'
        );
        $this->sendCheckout('IC.timeout');

        [$failed] = $this->lines($this->metaDebug, 'Meta CAPI event failed');

        $this->assertSame(0, $failed->context['status']);
        $this->assertSame('ConnectionException-curl-28', $failed->context['failure']);
        $this->assertStringContainsString('[redacted]', $failed->context['error']);
        // A timeout used to be logged with no user_data at all, so its line
        // claimed the visitor had no browser, no IP and no Pixel cookie.
        $this->assertTrue($failed->context['ua']);
        $this->assertTrue($failed->context['ip']);
        $this->assertTrue($failed->context['fbp']);

        $this->assertCount(1, $this->lines($this->laravelLog, 'Meta CAPI event failed'));

        $logged = $this->everythingLogged();
        foreach (['test-token', '203.0.113.9', 'fb.1.1700000000000.42', 'SM-A546E'] as $value) {
            $this->assertStringNotContainsString($value, $logged);
        }
    }

    public function test_no_customer_value_reaches_either_log(): void
    {
        $this->meta = $this->expiredToken();

        app(MetaTrackingService::class)->lead('01712345678', 'Fatima Rahman', 'Lead.pii');

        // Which fields were sent, by name…
        [$failed] = $this->lines($this->metaDebug, 'Meta CAPI event failed');
        $this->assertSame([], array_diff(['ph', 'fn', 'ln'], $failed->context['match_keys']));

        // …and not one of their values, raw or hashed.
        $logged = $this->everythingLogged();
        foreach ([
            '01712345678', '8801712345678', 'Fatima', 'fatima', 'Rahman', 'rahman',
            hash('sha256', '8801712345678'), hash('sha256', 'fatima'), hash('sha256', 'rahman'),
        ] as $value) {
            $this->assertStringNotContainsString($value, $logged);
        }
    }

    // ── Writing it down must not break the send ──────────────────────────────

    public function test_a_meta_log_that_cannot_be_written_changes_nothing_else(): void
    {
        config(['logging.channels.meta-debug' => ['driver' => 'custom', 'via' => fn () => new Logger('meta-debug', [
            new class extends AbstractProcessingHandler
            {
                protected function write(LogRecord $record): void
                {
                    throw new \UnexpectedValueException('The stream or file "storage/logs/meta-debug-2026-09-27.log" could not be opened in append mode: Failed to open stream: Permission denied');
                }
            },
        ])]]);
        Log::forgetChannel('meta-debug');

        // The Test panel's send reports what happened, and needs a code to run.
        Setting::put('meta_integration', array_merge(Setting::get('meta_integration'), ['test_event_code' => 'TEST123']));
        app()->forgetInstance(MetaSettings::class);

        $this->assertTrue(
            app(MetaTrackingService::class)->sendTest('ViewContent')['ok'],
            'Meta accepted it, and a log line that could not be written must not turn that into a failure',
        );

        $this->meta = $this->expiredToken();
        $refused = app(MetaTrackingService::class)->sendTest('ViewContent');

        $this->assertFalse($refused['ok']);
        $this->assertStringContainsString('Session has expired', $refused['error']);
        $this->assertCount(1, $this->lines($this->laravelLog, 'Meta CAPI event failed'), 'laravel.log still hears of it');
        $this->assertNotNull(app(MetaTrackingService::class)->lastFailure());
    }

    // ── The admin's Meta pages ───────────────────────────────────────────────

    public function test_the_last_failure_is_kept_until_something_goes_through(): void
    {
        $this->assertNull(app(MetaTrackingService::class)->lastFailure());

        $this->meta = $this->expiredToken();
        $this->sendCheckout();

        $failure = app(MetaTrackingService::class)->lastFailure();
        $this->assertTrue($failure['ongoing']);
        $this->assertSame('InitiateCheckout', $failure['event']);
        $this->assertSame(400, $failure['status']);
        $this->assertSame('AbCdEf-expired', $failure['fbtrace_id']);
        // A dead token is fixed by a new one — named for the token this shop uses.
        $this->assertSame('Replace the Conversions API token under Marketing → Meta → Tracking.', $failure['advice']);

        $this->travel(1)->minutes();
        $this->meta = $this->accepted();
        $this->sendCheckout('IC.after');

        $this->assertFalse(app(MetaTrackingService::class)->lastFailure()['ongoing'], 'an event has gone through since');
    }

    public function test_a_timeout_is_advised_as_a_connection_problem_not_a_token_one(): void
    {
        $this->meta = $this->timeout();
        $this->sendCheckout();

        $this->assertStringContainsString(
            'could not reach graph.facebook.com',
            app(MetaTrackingService::class)->lastFailure()['advice'],
        );
    }

    // One page request per test: the router keeps a controller, and the Meta
    // settings it read, for every later request in the same test.

    public function test_the_tracking_page_says_events_are_not_reaching_meta(): void
    {
        $this->meta = $this->expiredToken();
        $this->sendCheckout();

        $this->unlocked($this->admin())->get(route('admin.meta.tracking'))
            ->assertOk()
            ->assertSee('Server events are not reaching Meta.')
            ->assertSee('Session has expired on Friday')
            ->assertSee('Replace the Conversions API token under Marketing → Meta → Tracking.')
            ->assertSee('AbCdEf-expired');
    }

    public function test_once_events_go_through_again_the_tracking_page_only_notes_the_failure(): void
    {
        $this->meta = $this->expiredToken();
        $this->sendCheckout();

        $this->travel(1)->minutes();
        $this->meta = $this->accepted();
        $this->sendCheckout('IC.after');

        $this->unlocked($this->admin())->get(route('admin.meta.tracking'))
            ->assertOk()
            ->assertDontSee('Server events are not reaching Meta.')
            ->assertSee('Last failure 1 minute ago');
    }

    public function test_the_dashboard_s_conversions_api_card_shows_it(): void
    {
        $this->meta = $this->expiredToken();
        $this->sendCheckout();

        $this->unlocked($this->admin())->get(route('admin.meta.index'))
            ->assertOk()
            ->assertSee('Last failure')
            ->assertSee('Nothing has reached Meta since')
            ->assertSee('Session has expired on Friday');
    }

    public function test_diagnostics_turn_red_while_nothing_gets_through(): void
    {
        $admin = $this->admin();
        $delivery = fn () => collect($this->unlocked($admin)
            ->getJson(route('admin.meta.tracking.diagnostics'))->assertOk()->json('checks'))
            ->firstWhere('key', 'capi_delivery');

        $this->meta = $this->expiredToken();
        $this->sendCheckout();

        $red = $delivery();
        $this->assertFalse($red['ok']);
        $this->assertStringContainsString('InitiateCheckout failed', $red['detail']);
        $this->assertStringContainsString('HTTP 400', $red['detail']);
        $this->assertStringContainsString('Session has expired', $red['detail']);
        $this->assertSame('Replace the Conversions API token under Marketing → Meta → Tracking.', $red['fix']);

        $this->travel(1)->minutes();
        $this->meta = $this->accepted();
        $this->sendCheckout('IC.after');

        $green = $delivery();
        $this->assertTrue($green['ok']);
        $this->assertNull($green['fix']);
        $this->assertStringContainsString('last failure', $green['detail']);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function accepted(): \Closure
    {
        return fn () => Http::response(['events_received' => 1, 'fbtrace_id' => 'AbCdEf-ok'], 200);
    }

    private function expiredToken(): \Closure
    {
        return fn () => Http::response(self::EXPIRED_TOKEN, 400);
    }

    private function timeout(): \Closure
    {
        return fn () => throw new ConnectionException(
            'cURL error 28: Operation timed out after 5001 milliseconds with 0 bytes received for https://graph.facebook.com/v21.0/1234567890/events'
        );
    }

    /** A storefront InitiateCheckout, as the deferred sender hands it over. */
    private function sendCheckout(string $eventId = 'IC.1'): void
    {
        app(MetaTrackingService::class)->initiateCheckout(['prod-1'], 1500.0, 1, $eventId, [], [
            'ip' => '203.0.113.9', 'ua' => self::CHROME, 'fbc' => null, 'fbp' => 'fb.1.1700000000000.42',
            'url' => 'https://noychoy.com/checkout', 'time' => time(), 'external_id' => [], 'prefetch' => false,
        ]);
    }

    /** @return array<int,LogRecord> */
    private function lines(TestHandler $log, string $message): array
    {
        return array_values(array_filter($log->getRecords(), fn (LogRecord $r) => $r->message === $message));
    }

    /** Everything either log was handed, as one string to search. */
    private function everythingLogged(): string
    {
        return json_encode(array_map(
            fn (LogRecord $r) => [$r->message, $r->context],
            [...$this->metaDebug->getRecords(), ...$this->laravelLog->getRecords()],
        ));
    }

    private function admin(): User
    {
        return User::create(['name' => 'Admin', 'email' => 'a@b.test', 'password' => bcrypt('x'), 'role' => 'admin']);
    }

    /** Signed in, and past the Meta area's second password. */
    private function unlocked(User $admin): static
    {
        return $this->actingAs($admin)->withSession(['meta_unlocked_at' => now()->toIso8601String()]);
    }
}

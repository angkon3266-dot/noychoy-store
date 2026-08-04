<?php

namespace Tests\Feature;

use App\Jobs\Meta\RefreshMetaToken;
use App\Models\MetaSyncLog;
use App\Services\AdminAlerts;
use App\Services\Meta\MetaSettings;
use App\Services\Meta\MetaTokenRefresher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Automatic renewal of the OAuth connection token.
 *
 * The problem this closes: a long-lived OAuth token lasts ~60 days and
 * nothing renewed it, so every merchant on "Connect with Facebook" eventually
 * had to notice sync had silently stopped and manually reconnect. The fix
 * mirrors the exchange MetaOAuthController already performs on first connect
 * (fb_exchange_token), just re-run on the current still-valid token before it
 * expires — and only for OAuth connections; a System User (Development Mode)
 * token has no equivalent refresh call and is expected to have no expiry at
 * all.
 */
class MetaTokenRefreshTest extends TestCase
{
    use RefreshDatabase;

    protected function settings(): MetaSettings
    {
        return app(MetaSettings::class);
    }

    protected function refresher(): MetaTokenRefresher
    {
        return app(MetaTokenRefresher::class);
    }

    protected function configureApp(): void
    {
        config(['meta.oauth.app_id' => 'app-123', 'meta.oauth.app_secret' => 'secret-xyz']);
    }

    protected function connectOauth(?int $daysLeft): void
    {
        $s = $this->settings();
        $s->setToken('current-token');
        $s->update([
            'enabled' => true,
            'mode' => MetaSettings::MODE_PRODUCTION,
            'token_expires_at' => $daysLeft === null ? null : now()->addDays($daysLeft)->toIso8601String(),
        ]);
    }

    protected function connectSystemUser(?int $daysLeft = null): void
    {
        $s = $this->settings();
        $s->setToken('system-user-token');
        $s->update([
            'enabled' => true,
            'mode' => MetaSettings::MODE_DEVELOPMENT,
            'token_expires_at' => $daysLeft === null ? null : now()->addDays($daysLeft)->toIso8601String(),
        ]);
    }

    protected function fakeSuccess(int $expiresInDays = 60): void
    {
        Http::fake(['*/oauth/access_token*' => Http::response([
            'access_token' => 'refreshed-token',
            'token_type' => 'bearer',
            'expires_in' => $expiresInDays * 86400,
        ])]);
    }

    // ── dueForRefresh() ──────────────────────────────────────────────────────

    public static function dueCases(): array
    {
        return [
            'far from expiry (60d)' => [60, false],
            'just outside the window (15d)' => [15, false],
            'at the window edge (14d)' => [14, true],
            'well inside the window (5d)' => [5, true],
            'expires tomorrow (1d)' => [1, true],
            'already expired (-1d)' => [-1, false],
        ];
    }

    #[DataProvider('dueCases')]
    public function test_due_for_refresh_only_inside_the_window_and_before_expiry(int $daysLeft, bool $expected): void
    {
        $this->connectOauth($daysLeft);

        $this->assertSame($expected, $this->refresher()->dueForRefresh());
    }

    public function test_not_due_when_nothing_is_connected(): void
    {
        $this->assertFalse($this->refresher()->dueForRefresh());
    }

    public function test_not_due_for_a_system_user_connection_even_inside_the_window(): void
    {
        // A manually pasted token CAN have an expiry set (if the merchant did
        // not choose "Never"), but there is no refresh mechanism for it from
        // here — it must not be treated as due.
        $this->connectSystemUser(5);

        $this->assertFalse($this->refresher()->dueForRefresh());
    }

    public function test_not_due_for_a_system_user_connection_with_no_expiry(): void
    {
        $this->connectSystemUser(null);

        $this->assertFalse($this->refresher()->dueForRefresh());
    }

    // ── refresh(): success ───────────────────────────────────────────────────

    public function test_a_successful_refresh_stores_the_new_token_and_expiry(): void
    {
        $this->configureApp();
        $this->connectOauth(5);
        $this->fakeSuccess(60);

        $ok = $this->refresher()->refresh();

        $this->assertTrue($ok);
        $this->assertSame('refreshed-token', $this->settings()->token());
        $this->assertEqualsWithDelta(60, $this->settings()->get('token_expires_at')
            ? now()->diffInDays($this->settings()->get('token_expires_at'), false) : 0, 1);
    }

    public function test_a_successful_refresh_sends_the_current_token_and_app_credentials(): void
    {
        $this->configureApp();
        $this->connectOauth(5);
        $this->fakeSuccess();

        $this->refresher()->refresh();

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'oauth/access_token')
                && $request['grant_type'] === 'fb_exchange_token'
                && $request['client_id'] === 'app-123'
                && $request['client_secret'] === 'secret-xyz'
                && $request['fb_exchange_token'] === 'current-token';
        });
    }

    public function test_a_successful_refresh_is_logged(): void
    {
        $this->configureApp();
        $this->connectOauth(5);
        $this->fakeSuccess();

        $this->refresher()->refresh();

        $this->assertDatabaseHas('meta_sync_logs', ['action' => 'refresh', 'status' => 'success']);
    }

    // ── refresh(): failure ───────────────────────────────────────────────────

    public function test_a_rejected_refresh_leaves_the_existing_token_untouched(): void
    {
        $this->configureApp();
        $this->connectOauth(5);
        Http::fake(['*/oauth/access_token*' => Http::response(['error' => ['message' => 'Invalid token']], 400)]);

        $ok = $this->refresher()->refresh();

        $this->assertFalse($ok);
        // The old token is still what sync will use tomorrow's retry with —
        // never blank it out on a failed attempt.
        $this->assertSame('current-token', $this->settings()->token());
    }

    public function test_a_rejected_refresh_is_logged_with_the_reason(): void
    {
        $this->configureApp();
        $this->connectOauth(5);
        Http::fake(['*/oauth/access_token*' => Http::response(['error' => ['message' => 'Invalid token']], 400)]);

        $this->refresher()->refresh();

        $log = MetaSyncLog::where('action', 'refresh')->first();
        $this->assertSame('failed', $log->status);
        $this->assertStringContainsString('Invalid token', $log->api_error);
    }

    public function test_an_already_expired_token_is_never_sent_to_meta(): void
    {
        $this->configureApp();
        $this->connectOauth(-2);
        Http::fake();

        $ok = $this->refresher()->refresh();

        $this->assertFalse($ok);
        // An expired token cannot be exchanged at all — don't waste the call
        // finding that out; the merchant needs to reconnect, not wait longer.
        Http::assertNothingSent();
        $this->assertDatabaseHas('meta_sync_logs', ['action' => 'refresh', 'status' => 'failed']);
    }

    public function test_missing_app_credentials_fails_without_a_request(): void
    {
        config(['meta.oauth.app_id' => null, 'meta.oauth.app_secret' => null]);
        $this->connectOauth(5);
        Http::fake();

        $ok = $this->refresher()->refresh();

        $this->assertFalse($ok);
        Http::assertNothingSent();
    }

    public function test_a_network_exception_fails_cleanly_rather_than_throwing(): void
    {
        $this->configureApp();
        $this->connectOauth(5);
        Http::fake(fn () => throw new \RuntimeException('connection refused'));

        $ok = $this->refresher()->refresh();

        $this->assertFalse($ok);
        $this->assertDatabaseHas('meta_sync_logs', ['action' => 'refresh', 'status' => 'failed']);
    }

    // ── The scheduled job ────────────────────────────────────────────────────

    public function test_the_job_does_nothing_when_not_due(): void
    {
        $this->configureApp();
        $this->connectOauth(60);
        Http::fake();

        (new RefreshMetaToken)->handle($this->refresher());

        Http::assertNothingSent();
    }

    public function test_the_job_refreshes_when_due(): void
    {
        $this->configureApp();
        $this->connectOauth(5);
        $this->fakeSuccess();

        (new RefreshMetaToken)->handle($this->refresher());

        $this->assertSame('refreshed-token', $this->settings()->token());
    }

    // ── The dashboard alert ──────────────────────────────────────────────────

    protected function alerts(): AdminAlerts
    {
        Cache::flush();

        return app(AdminAlerts::class);
    }

    public function test_no_alert_while_the_connection_is_healthy(): void
    {
        $this->connectOauth(45);

        $keys = $this->alerts()->all()->pluck('key')->all();

        $this->assertNotContains('meta.token_expiring', $keys);
    }

    public function test_a_warning_alert_appears_once_renewal_has_not_kept_up(): void
    {
        // Inside the refresh window but not yet expired — i.e. the daily job
        // has been trying and failing, not "everything is fine, just early".
        $this->connectOauth(5);

        $alert = $this->alerts()->all()->firstWhere('key', 'meta.token_expiring');

        $this->assertNotNull($alert);
        $this->assertSame('warning', $alert['level']);
    }

    public function test_an_urgent_alert_appears_once_actually_expired(): void
    {
        $this->connectOauth(-1);

        $alert = $this->alerts()->all()->firstWhere('key', 'meta.token_expiring');

        $this->assertNotNull($alert);
        $this->assertSame('urgent', $alert['level']);
    }

    public function test_no_alert_for_a_system_user_connection(): void
    {
        // Structurally shouldn't have an expiry at all, but confirms the
        // isOauth() gate rather than relying on that alone.
        $this->connectSystemUser(3);

        $keys = $this->alerts()->all()->pluck('key')->all();

        $this->assertNotContains('meta.token_expiring', $keys);
    }

    public function test_no_alert_when_nothing_is_connected(): void
    {
        $keys = $this->alerts()->all()->pluck('key')->all();

        $this->assertNotContains('meta.token_expiring', $keys);
    }
}

<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Meta\Credentials\MetaCredentialResolver;
use App\Services\Meta\Credentials\MetaCredentials;
use App\Services\Meta\Credentials\SingleStoreCredentialResolver;
use App\Services\Meta\MetaSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The two Meta credentials, kept apart.
 *
 * The module has always had a **connection token** (catalog sync, from OAuth or
 * a pasted System User token) and an optional **Conversions API token** which
 * silently falls back to the connection token when unset. Blurring them
 * produced advice that sent merchants to a screen that did not apply to them —
 * notably "replace the System User token" when there was no System User token.
 *
 * These tests pin the distinction, and the advice each credential gives.
 */
class MetaCredentialsTest extends TestCase
{
    use RefreshDatabase;

    protected function settings(): MetaSettings
    {
        return app(MetaSettings::class);
    }

    protected function creds(): MetaCredentials
    {
        // Fresh resolver each time — the real one memoises per request.
        return (new SingleStoreCredentialResolver($this->settings()))->resolve();
    }

    protected function connectOauth(?string $expiresAt = null): void
    {
        $s = $this->settings();
        $s->setToken('conn-token');
        $s->update(['enabled' => true, 'mode' => MetaSettings::MODE_PRODUCTION, 'token_expires_at' => $expiresAt]);
    }

    protected function connectSystemUser(): void
    {
        $s = $this->settings();
        $s->setToken('sys-token');
        $s->update(['enabled' => true, 'mode' => MetaSettings::MODE_DEVELOPMENT, 'token_expires_at' => null]);
    }

    // ── Which credential is in play ─────────────────────────────────────────

    public function test_capi_falls_back_to_the_connection_token_when_none_is_set(): void
    {
        $this->connectOauth();

        $c = $this->creds();
        $this->assertSame(MetaCredentials::CAPI_INHERITED, $c->capiSource());
        $this->assertSame('conn-token', $c->capiToken());
        $this->assertFalse($c->usesDedicatedCapiToken());
    }

    public function test_a_dedicated_capi_token_takes_precedence(): void
    {
        $this->connectOauth();
        $this->settings()->setCapiToken('capi-token');

        $c = $this->creds();
        $this->assertSame(MetaCredentials::CAPI_DEDICATED, $c->capiSource());
        $this->assertSame('capi-token', $c->capiToken());
        // The connection token is untouched — they are separate credentials.
        $this->assertSame('conn-token', $c->connectionToken);
    }

    public function test_nothing_connected_means_no_capi_credential(): void
    {
        $this->assertSame(MetaCredentials::CAPI_NONE, $this->creds()->capiSource());
        $this->assertNull($this->creds()->capiToken());
    }

    // ── Advice never crosses credentials ────────────────────────────────────

    public function test_an_oauth_connection_is_told_to_reconnect_not_to_paste_a_token(): void
    {
        $this->connectOauth();

        $advice = $this->creds()->connectionAdvice();
        $this->assertStringContainsString('Reconnect with Facebook', $advice);
        $this->assertStringNotContainsString('System User', $advice);
    }

    public function test_a_manual_connection_is_told_to_replace_its_system_user_token(): void
    {
        $this->connectSystemUser();

        $advice = $this->creds()->connectionAdvice();
        $this->assertStringContainsString('System User token', $advice);
        $this->assertStringNotContainsString('Reconnect with Facebook', $advice);
    }

    public function test_inherited_capi_advice_points_at_the_connection_not_a_phantom_token(): void
    {
        // The case that used to give wrong advice: CAPI is running on the OAuth
        // token, so there is no System User token to "replace".
        $this->connectOauth();

        $advice = $this->creds()->capiAdvice();
        $this->assertStringContainsString('Reconnect with Facebook', $advice);
    }

    public function test_dedicated_capi_advice_points_at_the_capi_token_only(): void
    {
        $this->connectOauth();
        $this->settings()->setCapiToken('capi-token');

        $advice = $this->creds()->capiAdvice();
        $this->assertStringContainsString('Conversions API token', $advice);
        $this->assertStringNotContainsString('Reconnect with Facebook', $advice);
    }

    // ── Expiry, with the Carbon-3 trap pinned ───────────────────────────────

    public static function expiryCases(): array
    {
        return [
            'expires in 60 days' => [60, 'ok'],
            'expires in 30 days' => [30, 'ok'],
            'expires in 8 days' => [8, 'ok'],
            'expires in 6 days' => [6, 'expiring'],
            'expires tomorrow' => [1, 'expiring'],
            'expired yesterday' => [-1, 'expired'],
        ];
    }

    #[DataProvider('expiryCases')]
    public function test_connection_health_only_warns_inside_the_window(int $days, string $expected): void
    {
        $this->connectOauth(now()->addDays($days)->toIso8601String());

        $this->assertSame($expected, $this->creds()->connectionHealth());
    }

    public function test_a_token_without_an_expiry_never_reads_as_expiring(): void
    {
        $this->connectSystemUser();

        $this->assertSame('ok', $this->creds()->connectionHealth());
        $this->assertNull($this->creds()->connectionDaysLeft());
    }

    public function test_days_left_counts_down_rather_than_up(): void
    {
        // Signed the right way round: a future expiry is positive.
        $this->connectOauth(now()->addDays(30)->toIso8601String());
        $this->assertSame(30, $this->creds()->connectionDaysLeft());

        $this->connectOauth(now()->subDays(3)->toIso8601String());
        $this->assertLessThan(0, $this->creds()->connectionDaysLeft());
    }

    // ── The dashboard reflects it ───────────────────────────────────────────

    public function test_a_healthy_connection_produces_no_expiry_warning(): void
    {
        $this->connectOauth(now()->addDays(45)->toIso8601String());
        $admin = User::create([
            'name' => 'A', 'email' => 'a@b.c', 'password' => bcrypt('x'), 'role' => 'admin',
        ]);
        $this->settings()->setSecurityPassword('p');

        $html = $this->actingAs($admin)
            ->withSession(['meta_unlocked_at' => now()->toIso8601String()])
            ->get('/admin/meta')->assertOk()->getContent();

        // This is the regression: it used to say "expires soon" at 45 days out.
        $this->assertStringNotContainsString('expires in', $html);
        $this->assertStringContainsString('Facebook connection', $html);
        $this->assertStringContainsString('Conversions API', $html);
    }

    public function test_the_dashboard_names_which_credential_capi_is_using(): void
    {
        $this->connectOauth(now()->addDays(45)->toIso8601String());
        $this->settings()->update(['capi_enabled' => true, 'pixel_id' => '123']);
        $admin = User::create([
            'name' => 'A', 'email' => 'a@b.c', 'password' => bcrypt('x'), 'role' => 'admin',
        ]);
        $this->settings()->setSecurityPassword('p');

        $html = $this->actingAs($admin)
            ->withSession(['meta_unlocked_at' => now()->toIso8601String()])
            ->get('/admin/meta')->assertOk()->getContent();

        $this->assertStringContainsString('Facebook connection token', $html);
    }

    // ── The resolver contract ───────────────────────────────────────────────

    public function test_the_resolver_is_bound_to_the_single_store_implementation(): void
    {
        $this->assertInstanceOf(
            SingleStoreCredentialResolver::class,
            app(MetaCredentialResolver::class),
        );
        $this->assertSame('default', app(MetaCredentialResolver::class)->currentKey());
    }

    public function test_the_resolver_returns_an_empty_set_rather_than_null(): void
    {
        $creds = app(MetaCredentialResolver::class)->resolve();

        $this->assertInstanceOf(MetaCredentials::class, $creds);
        $this->assertFalse($creds->hasConnection());
    }
}

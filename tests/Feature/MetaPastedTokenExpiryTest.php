<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Meta\Credentials\MetaCredentialResolver;
use App\Services\Meta\MetaSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Pasting a System User token must replace the old login's expiry date.
 *
 * The store had been connected with "Connect with Facebook" (a 60-day login).
 * Switching to a pasted, never-expiring System User token kept that 60-day
 * date, so the admin went on saying "Expiring soon" and then "Expired" for a
 * token that would never lapse.
 */
class MetaPastedTokenExpiryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['meta.oauth.app_id' => '111', 'meta.oauth.app_secret' => 'shh']);

        // Connected by Facebook login, three days from expiry.
        $s = $this->settings();
        $s->setSecurityPassword('p'); // the Meta lock needs one before it honours an unlock
        $s->setToken('old-user-token');
        $s->update([
            'enabled' => true,
            'mode' => MetaSettings::MODE_PRODUCTION,
            'token_expires_at' => now()->addDays(3)->toIso8601String(),
            'connected_since' => now()->subMonths(4)->toIso8601String(),
        ]);
    }

    protected function settings(): MetaSettings
    {
        return app(MetaSettings::class);
    }

    protected function admin(): User
    {
        return User::firstOrCreate(
            ['email' => 'a@b.test'],
            ['name' => 'Admin', 'password' => bcrypt('secret'), 'role' => 'admin'],
        );
    }

    /** Past the Meta module's secondary lock, which otherwise bounces every request. */
    protected function unlocked(): static
    {
        return $this->actingAs($this->admin())->withSession(['meta_unlocked_at' => now()->toIso8601String()]);
    }

    protected function paste(?string $token): void
    {
        $this->unlocked()->post('/admin/meta/save', array_filter([
            'mode' => 'development',
            'business_id' => '123',
            'catalog_id' => '456',
            'access_token' => $token,
        ]))->assertSessionHasNoErrors()->assertSessionHas('success');

        app(MetaCredentialResolver::class)->forget();
    }

    public function test_a_never_expiring_system_user_token_clears_the_old_expiry(): void
    {
        Http::fake(['*/debug_token*' => Http::response(['data' => ['is_valid' => true, 'expires_at' => 0]])]);

        $this->paste('system-user-token');

        $this->assertSame('system-user-token', $this->settings()->token());
        $this->assertNull($this->settings()->get('token_expires_at'));

        $creds = app(MetaCredentialResolver::class)->resolve();
        $this->assertSame('ok', $creds->connectionHealth());
        $this->assertNull($creds->connectionDaysLeft());
        $this->assertTrue(Carbon::parse($this->settings()->get('connected_since'))->isToday());

        $this->unlocked()->get('/admin/meta')
            ->assertOk()
            ->assertSee('Does not expire')
            ->assertDontSee('Expiring soon');
    }

    public function test_a_token_that_does_expire_records_its_own_date(): void
    {
        $expires = now()->addDays(60)->startOfSecond();
        Http::fake(['*/debug_token*' => Http::response(['data' => ['is_valid' => true, 'expires_at' => $expires->timestamp]])]);

        $this->paste('sixty-day-token');

        $this->assertTrue($expires->equalTo(Carbon::parse($this->settings()->get('token_expires_at'))));
    }

    public function test_a_token_from_another_meta_app_is_read_by_itself(): void
    {
        // Our app token can't inspect a token issued under a different app;
        // the token can still inspect itself.
        Http::fake(function ($request) {
            return str_contains($request->url(), 'access_token=111%7Cshh')
                ? Http::response(['error' => ['message' => 'App_id in the input_token did not match the Viewing App', 'code' => 100]], 400)
                : Http::response(['data' => ['is_valid' => true, 'expires_at' => 0]]);
        });

        $this->paste('other-app-token');

        $this->assertNull($this->settings()->get('token_expires_at'));
        Http::assertSentCount(2);
    }

    public function test_when_meta_cannot_be_asked_the_old_date_still_goes(): void
    {
        Http::fake(['*' => Http::response(['error' => ['message' => 'down', 'code' => 2]], 500)]);

        $this->paste('system-user-token');

        $this->assertNull($this->settings()->get('token_expires_at'));
    }

    public function test_saving_without_a_new_token_keeps_the_expiry(): void
    {
        Http::fake();
        $before = $this->settings()->get('token_expires_at');

        $this->paste(null);

        $this->assertSame($before, $this->settings()->get('token_expires_at'));
        $this->assertSame('old-user-token', $this->settings()->token());
        Http::assertNothingSent();
    }
}

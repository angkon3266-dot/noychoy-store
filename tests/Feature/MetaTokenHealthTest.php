<?php

namespace Tests\Feature;

use App\Models\MetaConnection;
use App\Models\User;
use App\Modules\Meta\Services\MetaTokenManager;
use App\Services\Meta\MetaSettings;
use App\Services\Meta\MetaStats;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Token-expiry health, and the Meta module's auto-lock.
 *
 * All of these went wrong the same way. Carbon 3 made `$a->diffInX($b)` SIGNED
 * (it returns b − a); Carbon 2 returned an absolute value. Every comparison
 * written against the old behaviour silently inverted:
 *
 *   - a token expiring in 60 days reported "expires soon" forever, and
 *   - the security gate's idle timeout never fired at all.
 *
 * These tests assert on the thresholds themselves, so a future refactor back to
 * a diff would fail rather than quietly re-inverting.
 */
class MetaTokenHealthTest extends TestCase
{
    use RefreshDatabase;

    protected function settings(): MetaSettings
    {
        return app(MetaSettings::class);
    }

    protected function connect(?string $expiresAt, string $mode = MetaSettings::MODE_PRODUCTION): void
    {
        $s = $this->settings();
        $s->setToken('tok');
        $s->update(['enabled' => true, 'mode' => $mode, 'token_expires_at' => $expiresAt]);
    }

    // ── MetaStats health ────────────────────────────────────────────────────

    public static function expiryCases(): array
    {
        return [
            'expires in 60 days' => [60, 'ok'],
            'expires in 30 days' => [30, 'ok'],
            'expires in 8 days' => [8, 'ok'],
            'expires in 6 days' => [6, 'expiring'],
            'expires tomorrow' => [1, 'expiring'],
        ];
    }

    #[DataProvider('expiryCases')]
    public function test_token_health_only_warns_inside_the_window(int $daysAway, string $expected): void
    {
        $this->connect(now()->addDays($daysAway)->toIso8601String());

        $this->assertSame($expected, app(MetaStats::class)->health()['token']);
    }

    public function test_an_expired_token_reads_expired(): void
    {
        $this->connect(now()->subDay()->toIso8601String());

        $this->assertSame('expired', app(MetaStats::class)->health()['token']);
    }

    public function test_a_token_with_no_expiry_is_healthy(): void
    {
        // A System User token reports no expiry at all.
        $this->connect(null);

        $this->assertSame('ok', app(MetaStats::class)->health()['token']);
    }

    // ── MetaTokenManager health ─────────────────────────────────────────────

    #[DataProvider('expiryCases')]
    public function test_connection_health_only_warns_inside_the_window(int $daysAway, string $expected): void
    {
        MetaConnection::create([
            'provider' => 'meta',
            'access_token' => 'tok',
            'token_expires_at' => now()->addDays($daysAway),
        ]);

        $health = app(MetaTokenManager::class)->health();

        $this->assertSame($expected === 'ok' ? 'ok' : 'expiring', $health);
    }

    // ── The security gate's idle timeout ────────────────────────────────────

    public function test_the_module_locks_again_once_the_session_goes_idle(): void
    {
        $ttl = (int) config('meta.security.session_ttl', 120);

        // Within the window the unlock still counts…
        $fresh = Carbon::parse(now()->subMinutes($ttl - 5)->toIso8601String());
        $this->assertTrue($fresh->gt(now()->subMinutes($ttl)));

        // …and past it, it must not. Before the fix this was always "unlocked",
        // so the Meta module's password wall never re-armed.
        $stale = Carbon::parse(now()->subMinutes($ttl + 5)->toIso8601String());
        $this->assertFalse($stale->gt(now()->subMinutes($ttl)));
    }

    public function test_an_idle_session_is_sent_back_to_the_unlock_screen(): void
    {
        $admin = User::create([
            'name' => 'A', 'email' => 'a@b.c', 'password' => bcrypt('x'), 'role' => 'admin',
        ]);
        $this->settings()->setSecurityPassword('gate-pass');
        $ttl = (int) config('meta.security.session_ttl', 120);

        // Unlocked well beyond the TTL — must be treated as locked.
        $this->actingAs($admin)
            ->withSession(['meta_unlocked_at' => now()->subMinutes($ttl + 60)->toIso8601String()])
            ->get('/admin/meta')
            ->assertRedirect(route('admin.meta.unlock'));

        // A recent unlock still gets through.
        $this->actingAs($admin)
            ->withSession(['meta_unlocked_at' => now()->subMinutes(5)->toIso8601String()])
            ->get('/admin/meta')
            ->assertOk();
    }
}

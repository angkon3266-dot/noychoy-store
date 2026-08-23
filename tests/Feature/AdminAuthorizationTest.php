<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\SmsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Not one negative authorization test existed before this file — every role
 * test in the suite asserted that someone COULD reach something. That is how a
 * live bug survived: the notification bell every admin page polls was not in
 * the manager or staff section list, so it 403'd every 25 seconds and the
 * client silently swallowed it. Neither role ever saw a new order.
 */
class AdminAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function user(string $role): User
    {
        return User::create([
            'name' => ucfirst($role),
            'email' => $role.'@roles.test',
            'password' => bcrypt('secret'),
            'role' => $role,
        ]);
    }

    public static function sectionAccess(): array
    {
        // route name => [admin, manager, staff]
        return [
            'dashboard' => ['admin.dashboard', true, true, true],
            'orders' => ['admin.orders.index', true, true, true],
            'products' => ['admin.products.index', true, true, false],
            'customers' => ['admin.customers.index', true, true, false],
            'reviews' => ['admin.reviews.index', true, true, false],
            'settings' => ['admin.settings', true, false, false],
            'users' => ['admin.users.index', true, false, false],
        ];
    }

    #[DataProvider('sectionAccess')]
    public function test_each_role_reaches_only_its_own_sections(
        string $route, bool $admin, bool $manager, bool $staff
    ): void {
        foreach (['admin' => $admin, 'manager' => $manager, 'staff' => $staff] as $role => $allowed) {
            $response = $this->actingAs($this->user($role))->get(route($route));

            if ($allowed) {
                $this->assertTrue(
                    $response->isOk() || $response->isRedirect(),
                    "$role should reach $route but got ".$response->status()
                );
            } else {
                $response->assertForbidden();
            }
        }
    }

    public function test_a_signed_out_visitor_is_sent_to_the_login_page(): void
    {
        $this->get(route('admin.dashboard'))->assertRedirect();
        $this->get(route('admin.orders.index'))->assertRedirect();
    }

    public function test_every_role_can_poll_the_notification_bell(): void
    {
        // The bug this file exists for. The bell is chrome on every admin page,
        // not a section — a role that cannot poll it never learns about an
        // order, and the failure is invisible because the client drops it.
        foreach (['admin', 'manager', 'staff'] as $role) {
            $this->actingAs($this->user($role))
                ->get(route('admin.alerts.feed'))
                ->assertOk();
        }
    }

    // ── Secrets stay out of the log file ───────────────────────────────────

    public function test_recipient_numbers_are_masked_before_they_reach_a_log(): void
    {
        $masked = SmsService::maskRecipients('01711111111,01822222222,01860988859');

        $this->assertStringNotContainsString('01711111111', $masked);
        $this->assertStringNotContainsString('01822222222', $masked);
        $this->assertStringContainsString('3 recipient(s)', $masked);
        // Enough tail to identify a send in support, not enough to dial.
        $this->assertStringContainsString('8859', $masked);
    }

    public function test_the_meta_debug_redaction_covers_the_token_inspection_call(): void
    {
        // debug_token receives the live token as `input_token`, and that name
        // was the one missing from the redaction list.
        $redact = new \ReflectionMethod(\App\Modules\Meta\Services\MetaDebug::class, 'redact');
        $redact->setAccessible(true);

        $out = $redact->invoke(app(\App\Modules\Meta\Services\MetaDebug::class), [
            'input_token' => 'EAAG-a-real-looking-token',
            'access_token' => 'EAAG-another-one',
        ]);

        $this->assertSame('***redacted***', $out['input_token']);
        $this->assertSame('***redacted***', $out['access_token']);
    }

    public function test_the_oauth_service_no_longer_logs_a_response_body(): void
    {
        // The token-exchange body IS the access token.
        $source = file_get_contents(app_path('Modules/Meta/Services/MetaOAuthService.php'));

        $this->assertStringNotContainsString("'raw_body'", $source);
    }
}

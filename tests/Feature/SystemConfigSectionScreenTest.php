<?php

namespace Tests\Feature;

use App\Models\ConfigAuditLog;
use App\Models\User;
use App\Services\SystemConfig\ConfigBackupService;
use App\Services\SystemConfig\SystemConfigService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The System Configuration section screen: one form, a Save button and a Test
 * connection button that aims the same form at a different route.
 *
 * Two things were wrong with it. The form demanded the signed-in admin's own
 * password on every save — a step that gated nothing the `system-config.access`
 * policy had not already decided. And "Test connection" appeared to do nothing,
 * because the ajax layer ignored the button's formaction and posted to save,
 * where the missing password failed validation. Both are pinned here.
 */
class SystemConfigSectionScreenTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::firstOrCreate(
            ['email' => 'super@noychoy.test'],
            ['name' => 'Super Admin', 'password' => bcrypt('secret'), 'role' => 'admin'],
        );
    }

    public function test_the_section_screen_does_not_ask_for_a_password(): void
    {
        $this->actingAs($this->admin())
            ->get(route('admin.system-config.edit', 'google'))
            ->assertOk()
            ->assertDontSee('security_password')
            ->assertDontSee('Confirm with your admin password');
    }

    public function test_a_save_goes_through_without_one(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.system-config.save', 'google'), [
                'values' => ['google.analytics_id' => 'G-TESTID1234'],
                'notes' => 'checking the save path',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $this->assertSame(
            'G-TESTID1234',
            app(SystemConfigService::class)->sectionCurrent('google')['google.analytics_id'],
        );
    }

    /** The button the owner pressed, going where it is aimed. */
    public function test_test_connection_reports_a_result_and_saves_nothing(): void
    {
        $before = app(SystemConfigService::class)->sectionCurrent('google');

        $response = $this->actingAs($this->admin())
            ->post(route('admin.system-config.test', 'google'), [
                'values' => ['google.analytics_id' => 'not-a-measurement-id'],
            ])
            ->assertRedirect()
            ->assertSessionHas('meta_config_test');

        $result = $response->getSession()->get('meta_config_test');

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('G-XXXXXXXXXX', $result['message']);
        $this->assertSame($before, app(SystemConfigService::class)->sectionCurrent('google'),
            'a test must not write anything');
        $this->assertTrue(ConfigAuditLog::where('action', 'test')->exists());
    }

    /**
     * The button sits on the save form and is pressed before saving. If a test
     * emptied the fields, every check would cost the owner their typing.
     */
    public function test_a_test_hands_back_what_was_typed(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.system-config.test', 'google'), [
                'values' => ['google.analytics_id' => 'G-STILLHERE1'],
            ])
            ->assertRedirect();

        $this->actingAs($this->admin())
            ->get(route('admin.system-config.edit', 'google'))
            ->assertOk()
            ->assertSee('G-STILLHERE1', false);
    }

    /** — except a secret, which never goes back into the HTML. */
    public function test_a_tested_secret_is_not_echoed_back_and_the_owner_is_told(): void
    {
        $response = $this->actingAs($this->admin())
            ->post(route('admin.system-config.test', 'google'), [
                'values' => [
                    'google.analytics_id' => 'G-TESTID1234',
                    'google.ads_id' => 'AW-123456789',
                    'google.ads_purchase_label' => 'AbC-D_efGhIjKlM',
                    'google.oauth_client_secret' => 'super-secret-value',
                ],
            ])
            ->assertRedirect();

        $this->assertStringContainsString('enter it again to save it',
            $response->getSession()->get('meta_config_test')['message']);

        $this->actingAs($this->admin())
            ->get(route('admin.system-config.edit', 'google'))
            ->assertOk()
            ->assertDontSee('super-secret-value');
    }

    /** A rejected save keeps the form too, for the same reason. */
    public function test_a_rejected_save_hands_back_what_was_typed(): void
    {
        // "Most chat orders one visitor may place per day" is a number field,
        // which is one of the few the schema will actually turn away.
        $this->actingAs($this->admin())
            ->post(route('admin.system-config.save', 'ai'), [
                'values' => ['ai.orders_per_day' => 'as many as they like'],
            ])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->actingAs($this->admin())
            ->get(route('admin.system-config.edit', 'ai'))
            ->assertOk()
            ->assertSee('as many as they like', false);
    }

    /** Nothing above matters if the button stops pointing at the test route. */
    public function test_the_test_button_aims_the_form_at_the_test_route(): void
    {
        $this->actingAs($this->admin())
            ->get(route('admin.system-config.edit', 'google'))
            ->assertOk()
            ->assertSee('formaction="'.route('admin.system-config.test', 'google').'"', false);
    }

    public function test_a_non_admin_still_cannot_save(): void
    {
        $customer = User::factory()->create(['role' => 'customer']);

        $this->actingAs($customer)
            ->post(route('admin.system-config.save', 'google'), [
                'values' => ['google.analytics_id' => 'G-SNEAKY12345'],
            ])
            ->assertForbidden();
    }

    /**
     * Restore rewrites the whole store. Its policy check used to arrive on the
     * form request that demanded the password; removing that must not have
     * taken the check with it.
     */
    public function test_a_staff_admin_cannot_restore_a_backup(): void
    {
        $backup = app(ConfigBackupService::class)->create('for the test', false);

        $staff = User::factory()->create(['role' => 'staff']);

        $this->actingAs($staff)
            ->post(route('admin.system-config.backups.restore', $backup))
            ->assertForbidden();
    }
}

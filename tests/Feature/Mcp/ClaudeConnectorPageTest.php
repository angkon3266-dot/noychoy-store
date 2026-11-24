<?php

namespace Tests\Feature\Mcp;

use App\Models\ApiToken;
use App\Models\McpToolCall;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Admin → Claude connector: URL + setup, connections, audit log. */
class ClaudeConnectorPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_sees_url_connections_and_activity(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'name' => 'Owner']);
        [$token] = ApiToken::issue('Claude Code', null, null, $admin);
        McpToolCall::create(['api_token_id' => $token->id, 'user_id' => $admin->id, 'token_name' => 'Claude Code', 'tool' => 'update_product', 'arguments' => ['product' => 'pearl-drops'], 'ok' => true]);
        McpToolCall::create(['api_token_id' => $token->id, 'user_id' => $admin->id, 'token_name' => 'Claude Code', 'tool' => 'delete_product', 'ok' => false, 'error' => 'Pass confirm=true']);

        $this->actingAs($admin)->get(route('admin.api-tokens'))->assertOk()
            ->assertSee(url('/mcp'))
            ->assertSee('Add custom connector')
            ->assertSee('claude mcp add --transport http', false)
            ->assertSee('Claude Code')
            ->assertSee('update_product')
            ->assertSee('pearl-drops')
            ->assertSee('refused')
            ->assertSee('Claude connector');
    }

    public function test_a_new_token_acts_as_its_creator_and_can_be_revoked(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->post(route('admin.api-tokens.store'), ['name' => 'Desktop'])
            ->assertSessionHas('new_api_token');

        $token = ApiToken::sole();
        $this->assertSame($admin->id, $token->user_id);
        $this->assertSame('manual', $token->kind);

        $this->actingAs($admin)->delete(route('admin.api-tokens.destroy', $token))->assertRedirect();
        $this->assertSame(0, ApiToken::count());
    }

    public function test_managers_and_staff_cannot_open_it(): void
    {
        foreach (['manager', 'staff'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))
                ->get(route('admin.api-tokens'))->assertForbidden();
        }
    }

    public function test_the_audit_log_is_pruned_after_a_year(): void
    {
        McpToolCall::create(['tool' => 'store_info', 'ok' => true]);
        McpToolCall::query()->update(['created_at' => now()->subDays(400)]);
        McpToolCall::create(['tool' => 'store_info', 'ok' => true]);

        $this->artisan('logs:prune')->assertSuccessful();

        $this->assertSame(1, McpToolCall::count());
    }
}

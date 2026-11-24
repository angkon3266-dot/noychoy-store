<?php

namespace Tests\Feature\Mcp;

use App\Mcp\StoreServer;
use App\Mcp\Tools\StoreTool;
use App\Models\McpToolCall;
use App\Models\Product;
use App\Models\User;

/**
 * A token acts as its admin user, and can do exactly what that user's role
 * can do in the admin panel (User::sectionsFor) — no more.
 */
class McpRoleScopingTest extends McpTestCase
{
    /** Every registered tool, name => section. */
    protected function catalogue(): array
    {
        $server = new \ReflectionClass(StoreServer::class);
        $tools = $server->getDefaultProperties()['tools'];

        return collect($tools)->mapWithKeys(function (string $class) {
            /** @var StoreTool $tool */
            $tool = app($class);

            return [$tool->name() => $tool->section()];
        })->all();
    }

    public function test_every_tool_is_a_store_tool_with_a_unique_name(): void
    {
        $server = new \ReflectionClass(StoreServer::class);
        $tools = $server->getDefaultProperties()['tools'];

        foreach ($tools as $class) {
            $this->assertTrue(is_subclass_of($class, StoreTool::class), "{$class} must extend StoreTool (role check + audit)");
        }
        $this->assertCount(count($tools), array_unique(array_map(fn ($c) => app($c)->name(), $tools)));
    }

    public function test_an_admin_sees_every_tool(): void
    {
        $this->assertEqualsCanonicalizing(array_keys($this->catalogue()), $this->toolNames());
    }

    public function test_each_role_sees_exactly_the_tools_of_its_sections(): void
    {
        foreach (['manager', 'staff'] as $role) {
            $user = $this->actAs($role);
            $expected = collect($this->catalogue())
                ->filter(fn ($section) => $section === null || $user->canAccess($section))
                ->keys()->all();

            $this->assertEqualsCanonicalizing($expected, $this->toolNames(), "tool list for {$role}");
        }
    }

    public function test_staff_get_orders_but_not_products_customers_or_menu(): void
    {
        $this->actAs('staff');
        $names = $this->toolNames();

        $this->assertContains('list_orders', $names);
        $this->assertContains('update_order_status', $names);
        $this->assertContains('sales_summary', $names);
        foreach (['search_products', 'update_product', 'find_customers', 'send_customer_sms', 'update_menu', 'create_coupon'] as $hidden) {
            $this->assertNotContains($hidden, $names);
        }
    }

    public function test_a_hidden_tool_called_by_name_is_refused(): void
    {
        $p = $this->product();
        $this->actAs('staff');

        $res = $this->rpc('tools/call', ['name' => 'update_product', 'arguments' => ['product' => $p->slug, 'price' => 1]]);

        // The server does not even find it for this user ("Tool not found").
        $this->assertNotNull($res->json('error'));
        $this->assertStringContainsString('not found', $res->json('error.message'));
        $this->assertEquals(1450, Product::find($p->id)->price);
    }

    public function test_the_tool_itself_also_refuses_a_user_outside_its_section(): void
    {
        // Belt and braces: even if a tool were reachable, its own check holds.
        $this->actAs('staff');
        $tool = app(\App\Mcp\Tools\Products\UpdateProduct::class);
        $this->assertFalse($tool->allows($this->user));
        $this->assertTrue($tool->allows(User::factory()->create(['role' => 'manager'])));
    }

    public function test_managers_cannot_reach_settings_level_tools_but_can_run_the_shop(): void
    {
        $this->actAs('manager');
        $names = $this->toolNames();

        foreach (['update_product', 'update_menu', 'send_customer_sms', 'create_offer', 'moderate_review'] as $tool) {
            $this->assertContains($tool, $names);
        }
    }

    public function test_role_downgrade_takes_effect_on_the_next_call(): void
    {
        $this->assertContains('update_product', $this->toolNames());

        User::whereKey($this->user->id)->update(['role' => 'staff']);

        $this->assertNotContains('update_product', $this->toolNames());
    }

    public function test_store_info_is_available_to_every_role(): void
    {
        $this->actAs('staff');
        $info = $this->ok('store_info');

        $this->assertSame('staff', $info['acting_as']['role']);
        $this->assertNotContains('products', $info['can_reach']);
        $this->assertSame(1, McpToolCall::where('tool', 'store_info')->count());
    }
}

<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Services\DashboardAnalytics;
use App\Support\DashboardBlocks;
use App\Support\DashboardLayout;
use App\Support\DateRange;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The dashboard the admin arranges (owner, 2026-09-18: "I need to be able to
 * move around the analytics, top, bottom as per my needs").
 *
 * Every card is a block from the registry, rendered in the admin's own saved
 * order inside one grid. What these pin: the page renders the registry's
 * order until told otherwise, a saved order is the order, a hidden block is
 * neither on the page nor computed, and a saved layout survives the registry
 * changing under it — a key that vanished is dropped, a key that arrived
 * takes its default place. The moving itself is Alpine, which a feature test
 * cannot tap; what it can hold is that every control it hangs off is on the
 * page with a name a screen reader can say.
 */
class DashboardLayoutTest extends TestCase
{
    use RefreshDatabase;

    protected function admin(string $email = 'a@b.test'): User
    {
        return User::firstOrCreate(
            ['email' => $email],
            ['name' => 'Admin', 'password' => bcrypt('secret'), 'role' => 'admin'],
        );
    }

    protected function dashboard(User $admin): TestResponse
    {
        return $this->actingAs($admin)->get('/admin?period=30d')->assertOk();
    }

    /** The data-block keys in the order the page renders them. */
    protected function sequence(TestResponse $response): array
    {
        preg_match_all('/<section[^>]*\sdata-block="([a-z_]+)"/', $response->getContent(), $m);

        return $m[1];
    }

    /** The blocks rendered with their card, not as a hidden placeholder. */
    protected function shown(TestResponse $response): array
    {
        preg_match_all('/<section[^>]*\sdata-block="([a-z_]+)"[^>]*>/', $response->getContent(), $m, PREG_SET_ORDER);

        return array_values(array_map(
            fn ($match) => $match[1],
            array_filter($match = $m, fn ($match) => ! str_contains($match[0], 'data-hidden')),
        ));
    }

    // ── The registry ─────────────────────────────────────────────────────────

    public function test_every_registered_block_has_a_partial_a_title_and_a_known_span(): void
    {
        foreach (DashboardBlocks::all() as $key => $block) {
            $this->assertFileExists(
                resource_path("views/admin/dashboard/blocks/{$key}.blade.php"),
                "block '{$key}' has no partial",
            );
            $this->assertNotSame('', trim($block['title'] ?? ''), "block '{$key}' has no title");
            $this->assertArrayHasKey($block['span'] ?? '', DashboardBlocks::SPANS, "block '{$key}' has an unknown span");
        }

        $this->assertSame(DashboardBlocks::SPANS['full'], DashboardBlocks::spanClass('full'));
        $this->assertSame(DashboardBlocks::SPANS['full'], DashboardBlocks::spanClass('nonsense'), 'an unknown span must fall back to a full row');
    }

    // ── Order ────────────────────────────────────────────────────────────────

    public function test_the_default_dashboard_renders_every_block_in_registry_order(): void
    {
        $response = $this->dashboard($this->admin());

        $this->assertSame(DashboardBlocks::keys(), $this->sequence($response));
        $this->assertSame(DashboardBlocks::keys(), $this->shown($response), 'nothing is hidden by default');

        // The first block carries the ten KPI cards, the whole row wide.
        $this->assertMatchesRegularExpression('/<section data-block="kpi_tiles" data-span="full"/', $response->getContent());
    }

    public function test_a_saved_order_is_the_order_the_page_renders(): void
    {
        $admin = $this->admin();
        $order = array_reverse(DashboardBlocks::keys());

        $this->actingAs($admin)->post(route('admin.dashboard.layout'), ['order' => $order, 'hidden' => []])
            ->assertRedirect();

        $this->assertSame($order, $this->sequence($this->dashboard($admin)));
    }

    public function test_a_block_missing_from_a_saved_order_takes_its_default_position(): void
    {
        // A block registered after the admin last saved — it must show up
        // where the registry puts it, not at the end and not after a reset.
        $admin = $this->admin();
        $order = array_values(array_diff(DashboardBlocks::keys(), ['orders_by_status', 'sources']));
        $admin->forceFill(['dashboard_layout' => ['order' => $order, 'hidden' => []]])->save();

        $sequence = $this->sequence($this->dashboard($admin));

        $this->assertSame(DashboardBlocks::keys(), $sequence);
    }

    public function test_a_missing_block_slots_in_after_the_nearest_block_still_before_it(): void
    {
        $admin = $this->admin();
        // Reversed, without the sales chart: its registry neighbour before it
        // is kpi_tiles, which now sits last — so the chart lands right after it.
        $order = array_values(array_diff(array_reverse(DashboardBlocks::keys()), ['sales_chart']));
        $admin->forceFill(['dashboard_layout' => ['order' => $order, 'hidden' => []]])->save();

        $resolved = DashboardLayout::for($admin->fresh())['order'];

        $this->assertSame('kpi_tiles', $resolved[count($resolved) - 2]);
        $this->assertSame('sales_chart', $resolved[count($resolved) - 1]);
        $this->assertCount(count(DashboardBlocks::keys()), $resolved);
    }

    public function test_unknown_keys_are_dropped_and_repeats_collapsed(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('admin.dashboard.layout'), [
            'order' => ['sales_chart', 'bogus', 'sales_chart', 'kpi_tiles'],
            'hidden' => ['nonsense', 'dead_stock', 'dead_stock'],
        ])->assertRedirect();

        $saved = $admin->fresh()->dashboard_layout;
        $this->assertSame(['sales_chart', 'kpi_tiles'], $saved['order']);
        $this->assertSame(['dead_stock'], $saved['hidden']);

        // On the page: no bogus block, the chart first as saved, and every
        // block the saved order never named slotted in after its registry
        // neighbour — which chains them all in behind the chart, leaving
        // the KPI tiles last exactly as the admin saved them.
        $sequence = $this->sequence($this->dashboard($admin));
        $this->assertNotContains('bogus', $sequence);
        $this->assertSame('sales_chart', $sequence[0]);
        $this->assertSame('orders_by_status', $sequence[1]);
        $this->assertSame('kpi_tiles', end($sequence));
        $this->assertSame(count(DashboardBlocks::keys()), count(array_unique($sequence)));
    }

    // ── Hidden blocks ────────────────────────────────────────────────────────

    public function test_a_hidden_block_is_left_off_the_page_and_its_analytics_are_not_computed(): void
    {
        $admin = $this->admin();
        $admin->forceFill(['dashboard_layout' => [
            'order' => DashboardBlocks::keys(),
            'hidden' => ['funnel', 'sources', 'traffic_over_time', 'viewed_not_sold', 'ads'],
        ]])->save();

        // Every visits-table analysis is behind a hidden block; none may run.
        $this->partialMock(DashboardAnalytics::class, function ($mock) {
            foreach (['funnel', 'funnelByDay', 'trafficSources', 'adPerformance'] as $method) {
                $mock->shouldNotReceive($method);
            }
            // The notification bell (AdminAlerts) asks for the three most
            // viewed unsold pieces on every admin page, dashboard or not;
            // only the dashboard's own call, at the default limit, is the
            // one that must not happen.
            $mock->shouldReceive('viewedNotSold')->with(\Mockery::type(DateRange::class), 3)->passthru();
            $mock->shouldNotReceive('viewedNotSold')->with(\Mockery::type(DateRange::class));
            $mock->shouldReceive('periodComparison')->once()->passthru();
            $mock->shouldReceive('retention')->once()->passthru();
            $mock->shouldReceive('operations')->once()->passthru();
        });

        $response = $this->dashboard($admin);

        // The card headings are gone; the block TITLES remain, on the
        // title-only placeholders arrange mode uses to put a block back.
        $response->assertDontSee('Conversion funnel</h2>', false);
        $response->assertDontSee('Where visitors come from</h2>', false);
        $response->assertDontSee('Ads &amp; campaigns</h2>', false);
        $response->assertSee('Revenue &amp; profit</h2>', false);
        $response->assertSee('Dead stock</h2>', false);

        $deep = $response->viewData('deep');
        foreach (['funnel', 'series', 'sources', 'viewedNotSold', 'ads'] as $key) {
            $this->assertNull($deep[$key], "\$deep['{$key}'] was computed for a hidden block");
        }
        $this->assertIsArray($deep['profit']);
        $this->assertIsArray($deep['operations']);

        $this->assertSame(array_values(array_diff(DashboardBlocks::keys(), ['funnel', 'sources', 'traffic_over_time', 'viewed_not_sold', 'ads'])), $this->shown($response));
    }

    public function test_a_hidden_block_leaves_a_placeholder_in_its_place_so_it_can_be_put_back(): void
    {
        $admin = $this->admin();
        $admin->forceFill(['dashboard_layout' => ['order' => DashboardBlocks::keys(), 'hidden' => ['most_loved']]])->save();

        $response = $this->dashboard($admin);

        // Still in the sequence, at its place, but as a hidden title-only
        // handle: no card, no "Most loved products" heading.
        $this->assertSame(DashboardBlocks::keys(), $this->sequence($response));
        $this->assertMatchesRegularExpression('/<section data-block="most_loved" data-span="full"\s+data-hidden\s+class="[^"]*\bhidden\b/', $response->getContent());
        $this->assertStringNotContainsString('Most loved products</h2>', $response->getContent());
        $this->assertStringNotContainsString('total ❤️', $response->getContent());
    }

    public function test_a_block_with_nothing_to_show_takes_no_room_until_arranged(): void
    {
        // No unread messages: the inbox card renders nothing, and an empty
        // grid cell would still cost its gap on a phone.
        $response = $this->dashboard($this->admin());

        $this->assertMatchesRegularExpression('/<section data-block="messages" data-span="full"\s+data-empty\s+class="[^"]*\bhidden\b/', $response->getContent());
        $this->assertStringContainsString('nothing to show right now', $response->getContent());
    }

    public function test_the_old_panel_choice_still_hides_those_blocks_for_an_admin_with_no_layout(): void
    {
        // The store-wide ⚙ choice saved before per-admin layouts existed.
        Setting::put('dashboard_panels', ['profit']);

        $layout = DashboardLayout::for($this->admin());

        $this->assertSame(DashboardBlocks::keys(), $layout['order']);
        $this->assertContains('funnel', $layout['hidden']);
        $this->assertContains('live_visitors', $layout['hidden']);
        $this->assertContains('retention', $layout['hidden']);
        $this->assertContains('dead_stock', $layout['hidden']);
        $this->assertNotContains('profit', $layout['hidden']);
        $this->assertNotContains('kpi_tiles', $layout['hidden'], 'a block that never belonged to a panel cannot be hidden by one');

        $response = $this->dashboard($this->admin());
        $response->assertSee('Revenue &amp; profit</h2>', false);
        $response->assertDontSee('Conversion funnel</h2>', false);
        $response->assertDontSee('Customers &amp; retention</h2>', false);
    }

    public function test_the_old_panel_choice_is_ignored_once_the_admin_has_arranged(): void
    {
        Setting::put('dashboard_panels', ['profit']);

        $admin = $this->admin();
        $admin->forceFill(['dashboard_layout' => ['order' => DashboardBlocks::keys(), 'hidden' => []]])->save();

        $this->assertSame([], DashboardLayout::for($admin->fresh())['hidden']);
    }

    public function test_the_old_panels_form_still_works_by_hiding_blocks_for_this_admin_only(): void
    {
        $admin = $this->admin();
        $other = $this->admin('other@b.test');

        $this->actingAs($admin)->post(route('admin.dashboard.panels'), ['panels' => ['profit', 'operations']])
            ->assertRedirect();

        $hidden = DashboardLayout::for($admin->fresh())['hidden'];
        $this->assertContains('funnel', $hidden);
        $this->assertContains('retention', $hidden);
        $this->assertNotContains('profit', $hidden);
        $this->assertNotContains('dead_stock', $hidden);
        $this->assertNotContains('kpi_tiles', $hidden);

        $this->assertNull($other->fresh()->dashboard_layout, 'the old form must no longer change what another admin sees');
        $this->assertNull(Setting::get('dashboard_panels'), 'the old form no longer writes the store-wide setting');
    }

    // ── Save, reset, separation ──────────────────────────────────────────────

    public function test_saving_over_xhr_answers_ok_and_stores_the_layout(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->postJson(route('admin.dashboard.layout'), ['order' => ['recent_orders', 'kpi_tiles'], 'hidden' => ['ads']])
            ->assertOk()
            ->assertExactJson(['ok' => true]);

        $this->assertSame(
            ['order' => ['recent_orders', 'kpi_tiles'], 'hidden' => ['ads']],
            $admin->fresh()->dashboard_layout,
        );
    }

    public function test_a_layout_that_is_not_a_list_of_strings_is_refused(): void
    {
        $this->actingAs($this->admin())
            ->postJson(route('admin.dashboard.layout'), ['order' => 'kpi_tiles', 'hidden' => [['nested']]])
            ->assertUnprocessable();
    }

    public function test_reset_puts_the_default_layout_back(): void
    {
        $admin = $this->admin();
        $admin->forceFill(['dashboard_layout' => ['order' => array_reverse(DashboardBlocks::keys()), 'hidden' => ['ads']]])->save();

        // The old store-wide tick-list must not creep back in after a reset:
        // the button promises every block, so it stores an explicit empty
        // layout rather than null (which would mean "never arranged").
        \App\Models\Setting::put('dashboard_panels', ['profit']);

        $this->actingAs($admin)->postJson(route('admin.dashboard.layout.reset'))->assertOk()->assertExactJson(['ok' => true]);

        $this->assertSame(['order' => [], 'hidden' => []], $admin->fresh()->dashboard_layout);
        $this->assertSame([], \App\Support\DashboardLayout::for($admin->fresh())['hidden']);
        $response = $this->dashboard($admin);
        $this->assertSame(DashboardBlocks::keys(), $this->sequence($response));
        $this->assertSame(DashboardBlocks::keys(), $this->shown($response));
    }

    public function test_two_admins_keep_separate_layouts(): void
    {
        $one = $this->admin('one@b.test');
        $two = $this->admin('two@b.test');

        $this->actingAs($one)->postJson(route('admin.dashboard.layout'), [
            'order' => array_reverse(DashboardBlocks::keys()), 'hidden' => ['dead_stock'],
        ])->assertOk();

        $this->assertSame(array_reverse(DashboardBlocks::keys()), $this->sequence($this->dashboard($one)));
        $this->assertNotContains('dead_stock', $this->shown($this->dashboard($one)));

        $this->assertSame(DashboardBlocks::keys(), $this->sequence($this->dashboard($two)));
        $this->assertContains('dead_stock', $this->shown($this->dashboard($two)));
    }

    public function test_the_layout_routes_need_an_admin(): void
    {
        $this->post(route('admin.dashboard.layout'), ['order' => []])->assertRedirect(route('admin.login'));
        $this->post(route('admin.dashboard.layout.reset'))->assertRedirect(route('admin.login'));
    }

    // ── Arrange mode ─────────────────────────────────────────────────────────

    public function test_arrange_mode_controls_are_on_the_page_with_accessible_names(): void
    {
        $html = $this->dashboard($this->admin())->getContent();

        $this->assertStringContainsString('aria-label="Arrange dashboard"', $html);
        $this->assertStringContainsString('x-data="dashboardArrange(', $html);

        foreach (DashboardBlocks::all() as $key => $block) {
            $title = e($block['title']);
            $this->assertStringContainsString("aria-label=\"Move {$title} up\"", $html);
            $this->assertStringContainsString("aria-label=\"Move {$title} down\"", $html);
            $this->assertStringContainsString("aria-label=\"Move {$title} to the top\"", $html);
            $this->assertStringContainsString("aria-label=\"Move {$title} to the bottom\"", $html);
            $this->assertStringContainsString("aria-label=\"Hide {$title}\"", $html);
        }

        $this->assertStringContainsString('>Save layout<', $html);
        $this->assertStringContainsString('>Reset to default<', $html);
        $this->assertStringContainsString('>Cancel<', $html);

        // What the Alpine component is handed: the resolved layout and where
        // to post it. @js wraps it as JSON.parse('…') with the string
        // JSON-encoded a second time, so it is unpicked the same way.
        $this->assertMatchesRegularExpression('/x-data="dashboardArrange\(JSON\.parse\(\'([^\']*)\'\)\)"/', $html);
        preg_match('/x-data="dashboardArrange\(JSON\.parse\(\'([^\']*)\'\)\)"/', $html, $m);
        $config = json_decode(json_decode('"'.$m[1].'"'), true);

        $this->assertSame(DashboardBlocks::keys(), $config['order']);
        $this->assertSame([], $config['hidden']);
        $this->assertSame(route('admin.dashboard.layout'), $config['save']);
        $this->assertSame(route('admin.dashboard.layout.reset'), $config['reset']);
        $this->assertNotEmpty($config['csrf']);
    }

    public function test_the_page_lays_the_blocks_out_in_one_grid(): void
    {
        $html = $this->dashboard($this->admin())->getContent();

        $this->assertSame(1, substr_count($html, 'grid grid-cols-1 lg:grid-cols-12'));
        $this->assertStringContainsString('data-block="sales_chart" data-span="two_thirds"', $html);
        $this->assertStringContainsString('data-block="orders_by_status" data-span="third"', $html);
        $this->assertMatchesRegularExpression('/data-block="sales_chart"[^>]*class="min-w-0 lg:col-span-8"/', $html);
    }
}

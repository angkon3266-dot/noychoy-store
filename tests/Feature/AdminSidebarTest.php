<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The admin sidebar folds away, and the phone drawer lets go.
 *
 * The owner, 2026-09-17: "The side bar in admin panel — add option to collapse
 * and show it — right now when it's open I have to press something to close
 * it." On a phone the open drawer covered the only button that closed it; on a
 * desktop the sidebar could not be hidden at all.
 *
 * The behaviour itself is Alpine and CSS, which a feature test cannot click.
 * What it can pin is the markup every part of that behaviour hangs off: the
 * controls exist, the remembered fold is applied in <head> before anything
 * paints, the nav still renders with its labels and badges, and the two
 * contracts other code relies on — one bell (a second would double-poll the
 * alerts feed) and one `header h1` (admin-ajax.js swaps the title through it) —
 * survived the bell moving into the top bar.
 */
class AdminSidebarTest extends TestCase
{
    use RefreshDatabase;

    protected function adminPage(): string
    {
        $admin = User::create([
            'name' => 'Admin', 'email' => 'sidebar@b.test',
            'password' => bcrypt('secret'), 'role' => 'admin',
        ]);

        return $this->actingAs($admin)->get('/admin')->assertOk()->getContent();
    }

    public function test_a_desktop_admin_can_fold_the_sidebar_to_an_icon_rail(): void
    {
        $html = $this->adminPage();

        $this->assertStringContainsString('data-sidebar-toggle', $html);
        $this->assertStringContainsString('@click="toggleRail()"', $html);
        $this->assertStringContainsString('aria-label="Collapse sidebar"', $html);

        // The rail is plain CSS shipped in the page, keyed off the <html>
        // class — so it works even before the next `npm run build`.
        $this->assertStringContainsString('html.sb-collapsed .sb-aside { width: 4rem; }', $html);
        $this->assertStringContainsString('html.sb-collapsed .sb-main { margin-left: 4rem; }', $html);
        $this->assertStringContainsString('sb-main flex-1 min-w-0 md:ml-60', $html);
    }

    public function test_the_folded_state_is_restored_before_the_first_paint(): void
    {
        $html = $this->adminPage();
        $head = strstr($html, '</head>', true);

        $hook = strpos($head, "localStorage.getItem('adminSidebarCollapsed')");
        $this->assertNotFalse($hook, 'the remembered fold has to be read in <head>, not by Alpine after paint');
        $this->assertStringContainsString("root.classList.add('sb-collapsed')", $head);

        // A synchronous script placed after the stylesheet would wait on it,
        // and one placed after the module would run too late to matter.
        foreach (['<link rel="stylesheet"', '<link rel="preload"', '<link rel="modulepreload"', '<script type="module"'] as $tag) {
            $at = strpos($head, $tag);

            if ($at !== false) {
                $this->assertLessThan($at, $hook, "the fold must be applied before {$tag}");
            }
        }
    }

    public function test_the_phone_drawer_closes_from_its_own_button_the_backdrop_or_escape(): void
    {
        $html = $this->adminPage();

        $this->assertStringContainsString('aria-label="Open menu"', $html);
        $this->assertStringContainsString('aria-label="Close menu"', $html);
        $this->assertMatchesRegularExpression('/<div class="sb-backdrop[^"]*"[^>]*data-sidebar-backdrop[^>]*@click="closeDrawer\(\)"/', $html);
        $this->assertStringContainsString('@keydown.escape.window="closeDrawer()"', $html);

        // Hidden from the first paint on a phone, rather than sliding away
        // once Alpine has loaded.
        $this->assertStringContainsString('html.sb-js .sb-aside {', $html);
    }

    public function test_the_nav_still_renders_every_link_with_its_label(): void
    {
        $html = $this->adminPage();

        foreach ([
            'admin.dashboard' => 'Dashboard',
            'admin.orders.index' => 'Orders',
            'admin.products.index' => 'Products',
            'admin.suppliers.index' => 'Suppliers',
            'admin.settings' => 'Settings',
        ] as $route => $label) {
            $this->assertStringContainsString('href="'.route($route).'"', $html);
            // The label is the link's accessible name, in the rail as well.
            $this->assertStringContainsString('<span class="sb-label">'.$label.'</span>', $html);
        }
    }

    public function test_a_nav_badge_still_counts_what_needs_attention(): void
    {
        Order::create([
            'order_number' => '20001',
            'customer_name' => 'Buyer',
            'customer_phone' => '01712345678',
            'shipping_address' => 'x',
            'subtotal' => 500, 'total' => 500,
            'status' => 'processing',
        ]);

        $html = $this->adminPage();

        $this->assertMatchesRegularExpression('/<span class="sb-badge[^"]*"\s+title="1 order\(s\) being processed">1<\/span>/', $html);
    }

    public function test_no_two_nav_entries_share_an_icon(): void
    {
        // In the rail an icon is all there is, so two alike are two guesses.
        $nav = strstr(strstr($this->adminPage(), '<nav class="sb-nav'), '</nav>', true);

        preg_match_all('/<path[^>]*\sd="([^"]+)"/', $nav, $m);

        $this->assertGreaterThan(20, count($m[1]), 'the nav rendered fewer icons than expected');
        $this->assertSame(
            [],
            array_values(array_unique(array_diff_assoc($m[1], array_unique($m[1])))),
            'nav entries sharing an icon',
        );
    }

    public function test_the_bell_is_rendered_once_so_the_feed_is_polled_once(): void
    {
        $html = $this->adminPage();

        $this->assertSame(1, substr_count($html, 'aria-label="Notifications"'));
        $this->assertSame(1, substr_count($html, 'x-data="adminAlerts('));
    }

    public function test_the_page_keeps_one_header_with_one_heading_for_the_ajax_swap(): void
    {
        $html = $this->adminPage();

        $this->assertSame(1, substr_count($html, '<header'));

        $header = strstr(strstr($html, '<header'), '</header>', true);
        $this->assertSame(1, substr_count($header, '<h1'));
    }
}

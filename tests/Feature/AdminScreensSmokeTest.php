<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Every admin screen that takes no route parameters must render.
 *
 * This exists because Admin → Categories 500'd in production: the
 * product-template picker was deleted from `config/theme.php`, but three
 * references to `config('theme.product_templates')` were left behind in the
 * categories view, and `@foreach(null)` is fatal. Nothing in the suite ever
 * loaded that page, so nothing caught it — the store owner did.
 *
 * A dangling config key, a renamed helper or a deleted partial is invisible
 * until a Blade view is actually rendered, so this walks the admin route
 * table instead of naming screens one by one: a screen added tomorrow is
 * covered tomorrow, with no edit here.
 */
class AdminScreensSmokeTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Routes whose correct answer to a bare GET is not a page.
     *
     * @var array<string, array<int, int>>
     */
    protected array $expected = [
        // Only reachable while Meta Debug Mode is on; 404 is the guard working.
        'admin.meta.debug' => [404],
    ];

    public function test_every_parameterless_admin_screen_renders(): void
    {
        // A little real data, so views that loop over rows actually loop.
        $parent = Category::create(['name' => 'Earrings', 'is_active' => true]);
        Category::create(['name' => 'Studs', 'parent_id' => $parent->id, 'is_active' => true]);
        Product::create([
            'name' => 'Smoke Ring', 'slug' => 'smoke-ring', 'status' => 'published',
            'price' => 1200, 'category_id' => $parent->id, 'manage_stock' => false, 'in_stock' => true,
        ]);

        $admin = User::create([
            'name' => 'Admin', 'email' => 'smoke@b.test',
            'password' => bcrypt('secret'), 'role' => 'admin',
        ]);

        $screens = collect(Route::getRoutes())
            ->filter(fn ($r) => str_starts_with((string) $r->getName(), 'admin.'))
            ->filter(fn ($r) => in_array('GET', $r->methods(), true) && ! str_contains($r->uri(), '{'))
            ->reject(fn ($r) => $r->getName() === 'admin.logout')
            ->mapWithKeys(fn ($r) => [$r->getName() => '/'.ltrim($r->uri(), '/')]);

        $this->assertGreaterThan(30, $screens->count(), 'the admin route table looks empty — did the filter break?');

        $broken = [];
        foreach ($screens as $name => $uri) {
            $status = $this->actingAs($admin)->get($uri)->baseResponse->getStatusCode();
            $allowed = $this->expected[$name] ?? [200, 302];

            if (! in_array($status, $allowed, true)) {
                $broken[] = "{$name} ({$uri}) → {$status}";
            }
        }

        $this->assertSame([], $broken, "Admin screens that did not render:\n".implode("\n", $broken));
    }
}

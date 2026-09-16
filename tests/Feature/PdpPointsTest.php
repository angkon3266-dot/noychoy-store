<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The "why buy from us" list beside the product page's buy button: one list,
 * taken from the product, else its category (or a parent), else Appearance.
 */
class PdpPointsTest extends TestCase
{
    use RefreshDatabase;

    protected function admin(): User
    {
        return User::firstOrCreate(
            ['email' => 'points@b.test'],
            ['name' => 'Admin', 'password' => bcrypt('secret'), 'role' => 'admin'],
        );
    }

    protected function product(array $attrs = []): Product
    {
        return Product::create($attrs + [
            'name' => 'Verdant Cascade Earrings',
            'slug' => 'verdant-cascade-earrings',
            'status' => 'published',
            'price' => 1650,
            'manage_stock' => false,
            'in_stock' => true,
        ]);
    }

    public function test_the_store_wide_list_shows_by_default(): void
    {
        $this->get(route('product.show', $this->product()))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('pdpPoints.heading', config('theme.defaults.pdp_points_heading'))
                ->has('pdpPoints.items', count(config('theme.defaults.pdp_points')))
                ->where('pdpPoints.items.0.title', config('theme.defaults.pdp_points.0.title'))
                ->missing('trustBadges'));
    }

    public function test_a_category_list_replaces_the_store_wide_one_and_reaches_sub_categories(): void
    {
        $parent = Category::create(['name' => 'Earrings', 'pdp_points' => [['icon' => 'diamond', 'title' => 'Claw set stones', 'text' => '']]]);
        $child = Category::create(['name' => 'Studs', 'parent_id' => $parent->id]);

        $this->get(route('product.show', $this->product(['category_id' => $child->id])))
            ->assertInertia(fn (Assert $page) => $page
                ->has('pdpPoints.items', 1)
                ->where('pdpPoints.items.0.title', 'Claw set stones')
                ->where('pdpPoints.items.0.text', null));
    }

    public function test_a_product_list_beats_its_category(): void
    {
        $category = Category::create(['name' => 'Bangles', 'pdp_points' => [['icon' => 'tag', 'title' => 'Category point']]]);
        $product = $this->product([
            'category_id' => $category->id,
            'pdp_points' => [['icon' => 'gift', 'title' => 'Product point', 'text' => 'why'], ['title' => '']],
        ]);

        $this->get(route('product.show', $product))
            ->assertInertia(fn (Assert $page) => $page
                ->has('pdpPoints.items', 1)
                ->where('pdpPoints.items.0.title', 'Product point'));
    }

    public function test_the_product_form_saves_clears_and_leaves_alone(): void
    {
        $product = $this->product();
        $base = ['name' => $product->name, 'slug' => $product->slug, 'price' => 1650, 'status' => 'published'];

        $this->actingAs($this->admin())->put(route('admin.products.update', $product), $base + [
            'pdp_points_custom' => '1',
            'pdp_points' => [['icon' => 'diamond', 'title' => ' 5A stones ', 'text' => ''], ['icon' => 'check', 'title' => '', 'text' => 'dropped']],
        ])->assertRedirect();
        $this->assertSame([['icon' => 'diamond', 'title' => '5A stones', 'text' => null]], $product->fresh()->pdp_points);

        // A save that does not carry the editor must not wipe the list.
        $this->put(route('admin.products.update', $product), $base)->assertRedirect();
        $this->assertCount(1, $product->fresh()->pdp_points);

        // Unticking "use its own list" goes back to inheriting.
        $this->put(route('admin.products.update', $product), $base + [
            'pdp_points_custom' => '0',
            'pdp_points' => [['icon' => 'diamond', 'title' => '5A stones']],
        ])->assertRedirect();
        $this->assertNull($product->fresh()->pdp_points);
    }

    public function test_the_category_form_saves_a_list(): void
    {
        $category = Category::create(['name' => 'Rings']);

        $this->actingAs($this->admin())->put(route('admin.categories.update', $category), [
            'name' => 'Rings',
            'parent_id' => '',
            'google_category' => '',
            'pdp_points_custom' => '1',
            'pdp_points' => [['icon' => 'shieldCheck', 'title' => 'Size exchange', 'text' => 'free']],
        ])->assertRedirect();

        $this->assertSame('Size exchange', $category->fresh()->pdp_points[0]['title']);
    }

    public function test_appearance_saves_the_store_wide_list_and_an_empty_list_hides_it(): void
    {
        $payload = ['homepage_template' => 'couture', 'product_template' => 'showcase'];

        $this->actingAs($this->admin())->post('/admin/appearance', $payload + [
            'pdp_points_heading' => 'Why us',
            'pdp_points' => [['icon' => 'cash', 'title' => 'COD', 'text' => '']],
        ])->assertRedirect();
        $this->assertSame('Why us', theme('pdp_points_heading'));
        $this->assertSame('COD', theme('pdp_points')[0]['title']);

        $this->post('/admin/appearance', $payload + ['pdp_points_heading' => ''])->assertRedirect();

        $this->get(route('product.show', $this->product()))
            ->assertInertia(fn (Assert $page) => $page
                ->where('pdpPoints.heading', null)
                ->has('pdpPoints.items', 0));
    }

    public function test_the_admin_forms_render_the_editor(): void
    {
        $this->actingAs($this->admin());
        $category = Category::create(['name' => 'Anklets']);

        $this->get(route('admin.products.edit', $this->product(['category_id' => $category->id])))
            ->assertOk()->assertSee('Product page points')->assertSee('pdp_points_custom', false);
        $this->get(route('admin.categories.edit', $category))
            ->assertOk()->assertSee('Product page points');
        $this->get('/admin/appearance')
            ->assertOk()->assertSee('pdp_points_heading', false);
    }
}

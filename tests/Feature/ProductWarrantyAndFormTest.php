<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Owner, 2026-11-27: a warranty badge beside the price, the product form's
 * categories grouped by their main category, and a floating Save button.
 */
class ProductWarrantyAndFormTest extends TestCase
{
    use RefreshDatabase;

    protected function admin(): User
    {
        return User::firstOrCreate(['email' => 'warranty@b.test'], ['name' => 'Admin', 'password' => bcrypt('secret'), 'role' => 'admin']);
    }

    protected function product(array $attrs = []): Product
    {
        return Product::create($attrs + [
            'name' => 'Teardrop Moissanite Pendant', 'slug' => 'teardrop-'.uniqid(), 'status' => 'published',
            'price' => 2690, 'manage_stock' => false, 'in_stock' => true,
        ]);
    }

    public function test_the_warranty_saves_from_the_form_and_shows_on_the_product_page(): void
    {
        $product = $this->product();
        $base = ['name' => $product->name, 'slug' => $product->slug, 'price' => 2690, 'status' => 'published'];

        $this->actingAs($this->admin())->put(route('admin.products.update', $product), $base + ['warranty' => ' 1-Year Warranty '])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('1-Year Warranty', $product->fresh()->warranty);

        $this->get(route('product.show', $product))
            ->assertInertia(fn (Assert $page) => $page->where('product.warranty', '1-Year Warranty'));

        // Emptied, the badge goes.
        $this->put(route('admin.products.update', $product), $base + ['warranty' => '']);
        $this->get(route('product.show', $product->fresh()))
            ->assertInertia(fn (Assert $page) => $page->where('product.warranty', null));
    }

    public function test_a_product_without_a_warranty_sends_none(): void
    {
        $this->get(route('product.show', $this->product()))
            ->assertInertia(fn (Assert $page) => $page->where('product.warranty', null));
    }

    public function test_the_form_groups_categories_under_their_parent_and_has_a_floating_save(): void
    {
        $earrings = Category::create(['name' => 'Earrings']);
        $premium = Category::create(['name' => 'Premium Statement Earrings', 'parent_id' => $earrings->id]);
        $product = $this->product();
        $product->categories()->sync([$premium->id]);

        $html = $this->actingAs($this->admin())->get(route('admin.products.edit', $product))->assertOk()->getContent();

        // The tree the picker draws: Earrings with its sub-category inside it,
        // and the ticked one pre-selected.
        // (@js escapes each double quote as a " sequence.)
        $q = fn ($s) => str_replace('"', '\u0022', $s);
        $this->assertStringContainsString($q('"name":"Earrings","children":[{"id":'.$premium->id.',"name":"Premium Statement Earrings"}]'), $html);
        $this->assertStringContainsString("selected: JSON.parse('[".$q('"'.$premium->id.'"')."]')", $html);
        $this->assertStringContainsString('data-main-save', $html);
        $this->assertStringContainsString('unsaved', $html);
        $this->assertStringContainsString('name="warranty"', $html);
    }

    public function test_saving_keeps_the_ticked_categories(): void
    {
        $earrings = Category::create(['name' => 'Earrings']);
        $premium = Category::create(['name' => 'Premium Statement Earrings', 'parent_id' => $earrings->id]);
        $product = $this->product();

        $this->actingAs($this->admin())->put(route('admin.products.update', $product), [
            'name' => $product->name, 'slug' => $product->slug, 'price' => 2690, 'status' => 'published',
            'category_ids' => [(string) $earrings->id, (string) $premium->id],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertEqualsCanonicalizing([$earrings->id, $premium->id], $product->fresh()->categories->pluck('id')->all());
    }
}

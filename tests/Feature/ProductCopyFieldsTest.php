<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Product edit form (owner, 2026-11-27): custom fields are one reorderable
 * list, and both custom fields and page points can be copied from another
 * product.
 */
class ProductCopyFieldsTest extends TestCase
{
    use RefreshDatabase;

    protected function admin(): User
    {
        return User::firstOrCreate(
            ['email' => 'copy@b.test'],
            ['name' => 'Admin', 'password' => bcrypt('secret'), 'role' => 'admin'],
        );
    }

    protected function product(array $attrs = []): Product
    {
        static $n = 0;
        $n++;

        return Product::create($attrs + [
            'name' => "Piece {$n}",
            'slug' => "piece-{$n}",
            'status' => 'published',
            'price' => 1000,
            'manage_stock' => false,
            'in_stock' => true,
        ]);
    }

    public function test_custom_fields_save_in_the_order_the_form_sends_and_the_legacy_field_folds_in(): void
    {
        $product = $this->product([
            'custom_label' => 'Metal', 'custom_value' => 'Brass', 'custom_show' => true,
            'custom_fields' => [['label' => 'Stone', 'value' => 'CZ', 'show' => true]],
        ]);

        // The form loads the legacy field as the first row of one list.
        $this->actingAs($this->admin())->get(route('admin.products.edit', $product))
            ->assertOk()
            ->assertDontSee('name="custom_label"', false)
            ->assertSee('Copy custom fields from another product')
            ->assertSee('Copy page points from another product');

        // The owner moves Metal below Stone and saves.
        $this->put(route('admin.products.update', $product), [
            'name' => $product->name, 'slug' => $product->slug, 'price' => 1000, 'status' => 'published',
            'custom_fields' => [
                ['label' => 'Stone', 'value' => 'CZ', 'show' => '1'],
                ['label' => 'Metal', 'value' => 'Brass', 'show' => '1'],
            ],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $product->refresh();
        $this->assertNull($product->custom_label);
        $this->assertNull($product->custom_value);
        $this->assertSame(['Stone', 'Metal'], array_column($product->customFieldList(), 'label'));
    }

    public function test_the_picker_finds_drafts_by_name_and_leaves_out_the_product_being_edited(): void
    {
        $editing = $this->product(['name' => 'Rose Huggie']);
        $draft = $this->product(['name' => 'Rose Drop', 'status' => 'draft']);
        $this->product(['name' => 'Pearl Stud']);

        $results = $this->actingAs($this->admin())
            ->getJson(route('admin.products.copy-search', ['q' => 'rose', 'except' => $editing->id]))
            ->assertOk()->json('results');

        $this->assertSame([$draft->id], array_column($results, 'id'));
        $this->assertSame(route('admin.products.copyable', $draft), $results[0]['copyable_url']);

        $this->getJson(route('admin.products.copy-search', ['q' => 'r']))->assertJson(['results' => []]);
    }

    public function test_copyable_returns_custom_fields_in_order_and_the_page_points_the_product_shows(): void
    {
        $category = Category::create(['name' => 'Rings', 'pdp_points' => [['icon' => 'check', 'title' => 'Size exchange', 'text' => 'free']]]);
        $source = $this->product([
            'category_id' => $category->id,
            'custom_label' => 'Metal', 'custom_value' => 'Brass', 'custom_show' => true,
            'custom_fields' => [['label' => 'Stone', 'value' => 'CZ', 'show' => false]],
        ]);

        // No list of its own: it copies what it shows, the category's list.
        $this->actingAs($this->admin())->getJson(route('admin.products.copyable', $source))
            ->assertOk()
            ->assertJsonPath('custom_fields.0', ['label' => 'Metal', 'value' => 'Brass', 'show' => true])
            ->assertJsonPath('custom_fields.1', ['label' => 'Stone', 'value' => 'CZ', 'show' => false])
            ->assertJsonPath('pdp_points.0.title', 'Size exchange')
            ->assertJsonPath('pdp_points_own', false);

        $source->update(['pdp_points' => [['icon' => 'diamond', 'title' => '5A stones', 'text' => null]]]);
        $this->getJson(route('admin.products.copyable', $source))
            ->assertJsonPath('pdp_points.0.title', '5A stones')
            ->assertJsonPath('pdp_points_own', true);
    }

    public function test_the_category_and_appearance_editors_do_not_offer_the_product_picker(): void
    {
        $category = Category::create(['name' => 'Rings']);

        $this->actingAs($this->admin())->get(route('admin.categories.edit', $category))
            ->assertOk()->assertDontSee('Copy page points from another product');
    }
}

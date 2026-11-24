<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Support\Storefront\SizeGuide;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * "Find my size" on the product page: which pieces get the ring or bangle
 * finder, and which option it is allowed to pick.
 */
class SizeGuideTest extends TestCase
{
    use RefreshDatabase;

    protected function product(array $attrs = []): Product
    {
        static $n = 0;
        $n++;

        return Product::create($attrs + [
            'name' => "Piece $n",
            'slug' => "piece-$n",
            'status' => 'published',
            'price' => 1200,
            'manage_stock' => false,
            'in_stock' => true,
        ]);
    }

    /** SizeGuide::for without the chart rows, which have tests of their own. */
    protected function guide(Product $product): ?array
    {
        $guide = SizeGuide::for($product);

        return $guide === null ? null : \Illuminate\Support\Arr::except($guide, 'chart');
    }

    public function test_a_sized_ring_gets_the_ring_finder_on_its_size_option(): void
    {
        $parent = Category::create(['name' => 'Ring']);
        $child = Category::create(['name' => 'Fashion Ring', 'parent_id' => $parent->id]);
        $product = $this->product([
            'category_id' => $child->id,
            'has_variants' => true,
            'options' => [['name' => 'Colour', 'values' => ['Gold', 'Silver']], ['name' => 'Size', 'values' => ['6', '7', '8']]],
        ]);

        $this->assertSame(['kind' => 'ring', 'attribute' => 'Size'], $this->guide($product));

        $this->get(route('product.show', $product))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('sizeGuide.kind', 'ring')
                ->where('sizeGuide.attribute', 'Size'));
    }

    public function test_earrings_are_not_rings(): void
    {
        // Were "Earring" read as a ring category, this unnamed option of small
        // numbers (a pack count, say) would be offered as ring sizes.
        $category = Category::create(['name' => 'Fashion Earring', 'slug' => 'earrings']);
        $product = $this->product([
            'category_id' => $category->id,
            'options' => [['name' => 'Option', 'values' => ['6', '7']]],
        ]);

        $this->assertNull($this->guide($product));
    }

    public function test_an_unnamed_option_of_ring_numbers_is_picked_on_a_ring(): void
    {
        $category = Category::create(['name' => 'Rings']);
        $product = $this->product([
            'category_id' => $category->id,
            'options' => [['name' => 'Option', 'values' => ['US 6', 'US 6.5', 'US 7']]],
        ]);

        $this->assertSame(['kind' => 'ring', 'attribute' => 'Option'], $this->guide($product));
    }

    public function test_a_wordy_value_beside_the_sizes_does_not_switch_it_off(): void
    {
        $category = Category::create(['name' => '925 Silver Ring']);
        $product = $this->product([
            'category_id' => $category->id,
            'options' => [['name' => 'Size', 'values' => ['Adjustable', '7', '8']]],
        ]);

        $this->assertSame('Size', SizeGuide::for($product)['attribute']);
    }

    public function test_bangles_get_the_bangle_finder(): void
    {
        $category = Category::create(['name' => 'Bangles']);
        $product = $this->product([
            'category_id' => $category->id,
            'options' => [['name' => 'Size', 'values' => ['2.4', '2.6', '2.8', '2.10']]],
        ]);

        $this->assertSame(['kind' => 'bangle', 'attribute' => 'Size'], $this->guide($product));
    }

    public function test_nothing_to_pick_means_no_finder(): void
    {
        $rings = Category::create(['name' => 'Rings']);
        $bracelets = Category::create(['name' => 'Bracelets']);

        $this->assertNull(SizeGuide::for($this->product(['category_id' => $rings->id])));
        $this->assertNull(SizeGuide::for($this->product(['category_id' => $rings->id, 'options' => [['name' => 'Size', 'values' => ['Free size']]]])));
        // Bracelets are sized by length, not by a circle on the screen.
        $this->assertNull(SizeGuide::for($this->product(['category_id' => $bracelets->id, 'options' => [['name' => 'Size', 'values' => ['16 cm', '18 cm']]]])));

        $this->get(route('product.show', $this->product(['category_id' => $rings->id])))
            ->assertInertia(fn (Assert $page) => $page->where('sizeGuide', null));
    }
}

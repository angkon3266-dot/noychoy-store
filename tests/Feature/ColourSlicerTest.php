<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Services\StorefrontFilters;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The colour facet feeds a strip of chips above the product grid, not a list of
 * sidebar checkboxes — so the ORDER is the feature. Alphabetical put Black (2
 * products) above Silver (31), which made "the first five" the wrong five.
 */
class ColourSlicerTest extends TestCase
{
    use RefreshDatabase;

    private int $n = 0;

    private function product(array $colors): Product
    {
        $cat = Category::firstOrCreate(['slug' => 'c'], ['name' => 'C', 'is_active' => true]);

        return Product::create([
            'name' => 'P'.++$this->n, 'slug' => 'p'.$this->n, 'price' => 100,
            'status' => 'published', 'category_id' => $cat->id, 'in_stock' => true,
            'colors' => $colors,
        ]);
    }

    private function colourGroup(string $url = '/shop'): ?array
    {
        $groups = null;
        $this->get($url)->assertOk()->assertInertia(function ($page) use (&$groups) {
            $groups = $page->toArray()['props']['filters'] ?? [];
        });

        return collect($groups)->firstWhere('param', 'colors[]');
    }

    public function test_colours_are_ordered_by_how_many_products_carry_them(): void
    {
        foreach (range(1, 3) as $i) {
            $this->product(['Silver']);
        }
        $this->product(['Black']);
        foreach (range(1, 2) as $i) {
            $this->product(['Gold']);
        }

        $group = $this->colourGroup();

        $this->assertSame(
            ['Silver', 'Gold', 'Black'],
            collect($group['options'])->pluck('value')->all(),
        );
    }

    public function test_each_colour_carries_its_product_count(): void
    {
        $this->product(['Gold']);
        $this->product(['Gold', 'White']);

        $group = $this->colourGroup();
        $byValue = collect($group['options'])->keyBy('value');

        $this->assertSame(2, $byValue['Gold']['count']);
        $this->assertSame(1, $byValue['White']['count']);
    }

    public function test_a_tie_falls_back_to_alphabetical_so_the_strip_is_stable(): void
    {
        $this->product(['Red']);
        $this->product(['Blue']);
        $this->product(['Green']);

        $this->assertSame(
            ['Blue', 'Green', 'Red'],
            collect($this->colourGroup()['options'])->pluck('value')->all(),
        );
    }

    public function test_a_chosen_colour_comes_back_marked_so_the_chip_reads_as_on(): void
    {
        $this->product(['Gold']);
        $this->product(['Silver']);

        $group = $this->colourGroup('/shop?colors[]=Gold');
        $byValue = collect($group['options'])->keyBy('value');

        $this->assertTrue($byValue['Gold']['checked']);
        $this->assertFalse($byValue['Silver']['checked']);
    }

    public function test_the_colours_actually_on_this_catalogue_all_have_a_swatch(): void
    {
        // A chip with no hex draws a blank circle. These three were live on the
        // store and drawing exactly that.
        foreach (['Turquoise', 'Rose', 'Magenta', 'Gold', 'Silver', 'Multi'] as $c) {
            $this->assertNotNull(color_hex($c), "$c has no swatch colour");
        }
    }
}

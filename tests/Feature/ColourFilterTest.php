<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The colour facet in the filter sidebar — a checkbox list of swatches,
 * alphabetical, alongside every other group.
 *
 * (It was briefly a strip of chips above the grid; the owner asked for the
 * sidebar back on 2026-09-12 because the page reads cleaner without it.)
 */
class ColourFilterTest extends TestCase
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

    public function test_the_colour_group_sits_in_the_sidebar_with_the_other_filters(): void
    {
        $this->product(['Gold']);

        $groups = null;
        $this->get('/shop')->assertOk()->assertInertia(function ($page) use (&$groups) {
            $groups = collect($page->toArray()['props']['filters'] ?? [])->pluck('label')->all();
        });

        $this->assertContains('Colour', $groups);
    }

    public function test_colours_are_listed_alphabetically(): void
    {
        $this->product(['Silver']);
        $this->product(['Silver']);
        $this->product(['Black']);
        $this->product(['Gold']);

        // Alphabetical, not by popularity — the sidebar is a list, not a
        // ranking, and a list that reorders itself is hard to use.
        $this->assertSame(
            ['Black', 'Gold', 'Silver'],
            collect($this->colourGroup()['options'])->pluck('value')->all(),
        );
    }

    public function test_each_colour_appears_once_however_many_products_carry_it(): void
    {
        $this->product(['Gold']);
        $this->product(['Gold', 'White']);

        $this->assertSame(
            ['Gold', 'White'],
            collect($this->colourGroup()['options'])->pluck('value')->all(),
        );
    }

    public function test_a_chosen_colour_comes_back_ticked(): void
    {
        $this->product(['Gold']);
        $this->product(['Silver']);

        $byValue = collect($this->colourGroup('/shop?colors[]=Gold')['options'])->keyBy('value');

        $this->assertTrue($byValue['Gold']['checked']);
        $this->assertFalse($byValue['Silver']['checked']);
    }

    public function test_choosing_a_colour_actually_narrows_the_grid(): void
    {
        $this->product(['Gold']);
        $this->product(['Silver']);
        $this->product(['Silver']);

        $this->get('/shop?colors[]=Silver')->assertOk()->assertInertia(
            fn ($page) => $page->where('products.total', 2),
        );
    }

    public function test_the_colours_on_this_catalogue_all_have_a_swatch(): void
    {
        // A colour with no hex draws a blank circle beside its checkbox. These
        // three are live on the store and were doing exactly that.
        foreach (['Turquoise', 'Rose', 'Magenta', 'Gold', 'Silver', 'Multi'] as $c) {
            $this->assertNotNull(color_hex($c), "$c has no swatch colour");
        }
    }
}

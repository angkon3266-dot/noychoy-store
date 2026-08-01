<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Duplicating a product used to 500 on every attempt: replicate() copied the
 * `serial` column (the admin's Product ID), which is UNIQUE, so the insert was
 * rejected before the copy ever reached the database.
 */
class ProductDuplicateTest extends TestCase
{
    use RefreshDatabase;

    protected function admin(): User
    {
        return User::firstOrCreate(
            ['email' => 'a@b.test'],
            ['name' => 'Admin', 'password' => bcrypt('secret'), 'role' => 'admin'],
        );
    }

    protected function product(array $attributes = []): Product
    {
        return Product::create($attributes + [
            'name' => 'Golden Zircon Ring',
            'slug' => 'golden-zircon-ring',
            'status' => 'published',
            'price' => 550,
        ]);
    }

    public function test_a_product_can_be_duplicated(): void
    {
        $product = $this->product();

        $this->actingAs($this->admin())
            ->post('/admin/products/'.$product->slug.'/duplicate')
            ->assertRedirect();

        $this->assertSame(2, Product::count());
    }

    public function test_the_copy_gets_its_own_product_id(): void
    {
        $product = $this->product();

        $copy = $this->duplicate($product);

        $this->assertNotNull($copy->serial);
        $this->assertNotSame($product->serial, $copy->serial);
    }

    public function test_the_copy_is_a_draft_with_a_fresh_slug_and_no_views(): void
    {
        $product = $this->product(['views' => 42, 'sku' => 'RING-1', 'is_featured' => true]);

        $copy = $this->duplicate($product);

        $this->assertSame('draft', $copy->status);
        $this->assertNotSame($product->slug, $copy->slug);
        $this->assertSame(0, (int) $copy->views);
        $this->assertSame('RING-1-COPY', $copy->sku);
        $this->assertFalse((bool) $copy->is_featured);
    }

    public function test_a_product_can_be_duplicated_twice(): void
    {
        // The second copy is the one that would collide if the ID were reused
        // from a fixed source rather than taken from the next free number.
        $product = $this->product();

        $first = $this->duplicate($product);
        $second = $this->duplicate($product);

        $this->assertNotSame($first->serial, $second->serial);
        $this->assertSame(3, Product::count());
    }

    protected function duplicate(Product $product): Product
    {
        $before = Product::pluck('id')->all();

        $this->actingAs($this->admin())
            ->post('/admin/products/'.$product->slug.'/duplicate')
            ->assertRedirect();

        return Product::whereNotIn('id', $before)->firstOrFail();
    }
}

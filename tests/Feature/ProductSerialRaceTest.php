<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Product serials are handed out as max(serial)+1, which is a read-then-write
 * race: two creates landing together both read the same maximum and the second
 * insert dies on `products_serial_unique`. Production logged two such 500s.
 *
 * Same shape as the order-number race, and fixed the same way — each retry
 * steps to the NEXT number, because retrying the same one just reproduces the
 * identical duplicate-key error.
 */
class ProductSerialRaceTest extends TestCase
{
    use RefreshDatabase;

    protected function product(array $attrs = []): Product
    {
        static $n = 0;
        $n++;

        return Product::createUnique(array_merge([
            'name' => 'Ring '.$n, 'slug' => 'ring-'.$n, 'status' => 'published',
            'price' => 100, 'manage_stock' => false, 'in_stock' => true,
        ], $attrs));
    }

    public function test_serials_are_sequential(): void
    {
        $a = $this->product();
        $b = $this->product();

        $this->assertSame($a->serial + 1, $b->serial);
    }

    public function test_a_taken_serial_is_stepped_past(): void
    {
        // Simulate the loser of the race: the number our max()+1 would pick has
        // already been claimed by another request that committed first.
        $this->product(['serial' => 500]);

        $next = Product::nextSerial();
        $this->assertSame(501, $next);
        $this->assertSame(502, Product::nextSerial(1));
    }

    public function test_creating_recovers_when_the_serial_is_already_taken(): void
    {
        $this->product(['serial' => 900]);

        // Force the collision: hand it a serial that already exists.
        $clash = new Product([
            'name' => 'Clash', 'slug' => 'clash', 'status' => 'draft',
            'price' => 100, 'manage_stock' => false, 'in_stock' => true,
        ]);
        $clash->serial = 900;

        $clash->saveWithUniqueSerial();

        $this->assertTrue($clash->exists);
        $this->assertNotSame(900, $clash->serial);
        $this->assertSame(2, Product::count());
    }

    public function test_a_soft_deleted_serial_is_not_reissued(): void
    {
        // serial is UNIQUE and a trashed row keeps its number in the index.
        $gone = $this->product(['serial' => 700]);
        $gone->delete();

        $this->assertGreaterThan(700, Product::nextSerial());
    }

    public function test_every_product_gets_a_distinct_serial(): void
    {
        // The property that actually matters: whatever order creates interleave
        // in, no two products end up sharing a number.
        $serials = collect(range(1, 12))->map(fn () => $this->product()->serial);

        $this->assertCount(12, $serials->unique(), 'two products shared a serial');
        $this->assertSame($serials->sort()->values()->all(), $serials->values()->all());
    }

    public function test_the_admin_create_screen_still_works(): void
    {
        $admin = User::create([
            'name' => 'A', 'email' => 'a@b.c', 'password' => bcrypt('x'), 'role' => 'admin',
        ]);

        $this->actingAs($admin)->post('/admin/products', [
            'name' => 'From the form',
            'price' => 1200,
            'status' => 'draft',
            'product_type' => 'simple',
        ])->assertRedirect();

        $this->assertNotNull(Product::where('name', 'From the form')->first()?->serial);
    }
}

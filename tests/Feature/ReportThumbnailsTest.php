<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductImage;
use App\Models\Setting;
use App\Models\User;
use App\Services\AdminAlerts;
use App\Support\ProductThumbs;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * "Low stock alert — show images of the product too. Every other product where
 * you are showing report — add product image for easy ref" (owner, 2026-09-19).
 */
class ReportThumbnailsTest extends TestCase
{
    use RefreshDatabase;

    protected function admin(): User
    {
        return User::firstOrCreate(['email' => 'owner@thumbs.test'], ['name' => 'Owner', 'password' => bcrypt('x'), 'role' => 'admin']);
    }

    /** A product with a photo at a recognisable path. */
    protected function product(string $photo, array $attrs = []): Product
    {
        $product = Product::create(array_merge([
            'name' => 'Pearl '.$photo, 'slug' => 'pearl-'.$photo, 'status' => 'published',
            'price' => 1500, 'manage_stock' => true, 'stock_quantity' => 1, 'in_stock' => true,
        ], $attrs));
        ProductImage::create(['product_id' => $product->id, 'path' => "products/{$photo}.webp", 'position' => 0, 'is_primary' => true]);

        return $product;
    }

    public function test_one_query_finds_the_pictures_for_many_products_deleted_ones_too(): void
    {
        $a = $this->product('alpha');
        $b = $this->product('beta');
        $b->delete();
        $bare = Product::create(['name' => 'No photo', 'slug' => 'no-photo', 'status' => 'published', 'price' => 10]);

        $thumbs = ProductThumbs::for([$a->id, $b->id, $bare->id, null, $a->id]);

        $this->assertStringEndsWith('products/alpha.webp', $thumbs[$a->id]);
        $this->assertStringEndsWith('products/beta.webp', $thumbs[$b->id]);
        $this->assertArrayNotHasKey($bare->id, $thumbs, 'no photo, no entry — the tile stays empty');
    }

    public function test_the_low_stock_card_shows_each_products_picture(): void
    {
        $this->product('lowstock', ['stock_quantity' => 1]);

        $this->actingAs($this->admin())->get('/admin?period=30d')
            ->assertOk()
            ->assertSee('products/lowstock.webp', false);
    }

    public function test_the_most_loved_card_shows_pictures_too(): void
    {
        $this->product('loved', ['stock_quantity' => 50, 'loves_count' => 12]);

        $this->actingAs($this->admin())->get('/admin?period=30d')
            ->assertOk()
            ->assertSee('products/loved.webp', false);
    }

    public function test_low_stock_on_the_dashboard_follows_the_owners_threshold(): void
    {
        Setting::put('theme', ['low_stock_threshold' => 6]);
        $this->product('six', ['stock_quantity' => 6]);

        $this->actingAs($this->admin())->get('/admin?period=30d')
            ->assertOk()
            ->assertSee('≤ 6 left', false)
            ->assertSee('products/six.webp', false);
    }

    public function test_stock_alerts_in_the_bell_carry_the_products_picture(): void
    {
        Cache::flush();
        $this->product('gone', ['stock_quantity' => 0]);
        $this->product('last', ['stock_quantity' => 1]);

        $images = app(AdminAlerts::class)->for($this->admin())->pluck('image', 'key');

        $out = $images->first(fn ($img, $key) => str_starts_with($key, 'stock.out.'));
        $low = $images->first(fn ($img, $key) => str_starts_with($key, 'stock.low.'));
        $this->assertStringEndsWith('products/gone.webp', $out);
        $this->assertStringEndsWith('products/last.webp', $low);
    }

    public function test_the_bell_feed_and_page_render_the_picture(): void
    {
        Cache::flush();
        $this->product('feed', ['stock_quantity' => 0]);

        $this->actingAs($this->admin())->getJson(route('admin.alerts.feed'))
            ->assertOk()
            ->assertJsonFragment(['image' => ProductThumbs::url(Product::where('slug', 'pearl-feed')->with('images')->first())]);

        // The bell's first-paint list, on a page that shows no product photos of its own.
        $this->actingAs($this->admin())->get('/admin/settings')
            ->assertOk()
            ->assertSee('products/feed.webp', false);
    }
}

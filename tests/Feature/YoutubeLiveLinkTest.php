<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use App\Services\Meta\MetaProductMapper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * youtube.com/live/ID is the link YouTube's share button gives for a live
 * stream or its recording. youtube_id() did not know the form, so
 * video_meta() typed it as an uploaded file with the watch page as its
 * source: the product page's "See it in motion" section tried to play a web
 * page through a <video> the CSP refuses anyway, and the Meta catalogue was
 * sent the watch page as a video file to download.
 */
class YoutubeLiveLinkTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_live_link_is_read_as_a_youtube_video(): void
    {
        $this->assertSame('dQw4w9WgXcQ', youtube_id('https://www.youtube.com/live/dQw4w9WgXcQ?si=Ab12Cd34'));

        $meta = video_meta('https://youtube.com/live/dQw4w9WgXcQ');

        $this->assertSame('youtube', $meta['type']);
        $this->assertSame('https://www.youtube.com/embed/dQw4w9WgXcQ', $meta['embed']);
        $this->assertNull($meta['src']);
    }

    public function test_the_forms_already_understood_still_are(): void
    {
        foreach ([
            'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
            'https://youtu.be/dQw4w9WgXcQ',
            'https://www.youtube.com/embed/dQw4w9WgXcQ',
            'https://www.youtube.com/shorts/dQw4w9WgXcQ',
            'dQw4w9WgXcQ',
        ] as $url) {
            $this->assertSame('dQw4w9WgXcQ', youtube_id($url), $url);
        }

        $this->assertNull(youtube_id('https://www.youtube.com/@noychoy/live'));
        $this->assertSame('file', video_meta('product-videos/clip.mp4')['type']);
    }

    public function test_a_live_link_on_a_product_reaches_the_page_as_an_embed(): void
    {
        $product = $this->product(['video_urls' => ['https://www.youtube.com/live/dQw4w9WgXcQ']]);

        $this->get(route('product.show', $product))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Product')
                ->where('product.videos.0.type', 'youtube')
                ->where('product.videos.0.embed', 'https://www.youtube.com/embed/dQw4w9WgXcQ')
                ->where('product.videos.0.src', null));
    }

    public function test_a_live_link_is_not_sent_to_the_meta_catalogue_as_a_video_file(): void
    {
        $product = $this->product(['video_urls' => ['https://www.youtube.com/live/dQw4w9WgXcQ']]);

        $this->assertArrayNotHasKey('video', app(MetaProductMapper::class)->items($product)[0]['data']);
    }

    protected function product(array $attrs = []): Product
    {
        $category = Category::firstOrCreate(['slug' => 'rings'], ['name' => 'Rings']);

        $product = Product::create(array_merge([
            'name' => 'Live Piece', 'slug' => 'live-piece', 'status' => 'published',
            'price' => 1000, 'category_id' => $category->id, 'stock_quantity' => 5, 'in_stock' => true,
        ], $attrs));

        ProductImage::create(['product_id' => $product->id, 'path' => 'products/x.jpg', 'is_primary' => true]);

        return $product->fresh();
    }
}

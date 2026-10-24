<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Review;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The homepage "Customer love" band survives its own cache.
 *
 * Production's cache store refuses to unserialize objects
 * (cache.serializable_classes = false). The band was cached as a Collection,
 * so the first visitor saw the reviews and everyone after them got
 * `{"__PHP_Incomplete_Class_Name": "Illuminate\\Support\\Collection"}` — the
 * band silently disappeared from the live homepage. It is cached as a plain
 * array now; this runs the second, cached render the way production does.
 */
class HomeReviewsBandCacheTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_reviews_band_reads_back_from_a_cache_that_refuses_objects(): void
    {
        config(['cache.stores.array' => ['driver' => 'array', 'serialize' => true, 'serializable_classes' => false]]);
        Cache::purge('array');

        Setting::put('theme', ['homepage_template' => 'couture']);
        $product = Product::create(['name' => 'Kundan Bangle Set', 'slug' => 'kundan-bangle-set', 'status' => 'published', 'price' => 2400, 'manage_stock' => false]);
        Review::create(['product_id' => $product->id, 'author_name' => 'Nusrat J.', 'rating' => 5, 'status' => 'approved', 'body' => 'Even better in person.']);

        $band = fn (Assert $page) => $page->component('Home')
            ->has('reviews', 1)
            ->where('reviews.0.author', 'Nusrat J.')
            ->where('reviews.0.quote', 'Even better in person.');

        // First render fills the cache; the second reads it back.
        $this->get('/')->assertOk()->assertInertia($band);
        $this->get('/')->assertOk()->assertInertia($band);
    }
}

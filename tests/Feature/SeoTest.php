<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * What a search engine actually receives.
 *
 * The storefront's browsing pages are React over Inertia, so for a long time
 * the document that left the server was a JSON blob and an empty <div>: no
 * heading, no copy, no links. Google renders JavaScript eventually; Bing
 * largely does not, and the AI answer engines do not at all. These tests pin
 * down the server-rendered half — the half that is true before any script runs.
 *
 * They also pin the Bangladesh signals. This shop sells only here, in taka,
 * with cash on delivery, and every one of those facts is a ranking signal that
 * has to be stated rather than inferred.
 */
class SeoTest extends TestCase
{
    use RefreshDatabase;

    protected function product(string $name = 'Turquoise Heart Earrings'): Product
    {
        $category = Category::firstOrCreate(['slug' => 'earrings'], ['name' => 'Earrings']);

        $product = Product::create([
            'name' => $name,
            'slug' => Str::slug($name),
            'sku' => Str::slug($name),
            'price' => 1450,
            'category_id' => $category->id,
            'status' => 'published',
            'stock_quantity' => 5,
            'short_description' => 'Drop earrings for special occasions.',
        ]);

        ProductImage::create([
            'product_id' => $product->id,
            'path' => 'products/turquoise.webp',
            'position' => 0,
            'is_primary' => true,
        ]);

        return $product;
    }

    // ── The crawlable shell ─────────────────────────────────────────────────

    public function test_a_product_page_is_readable_without_javascript(): void
    {
        $product = $this->product();

        $html = $this->get('/product/'.$product->slug)->assertOk()->getContent();
        $body = Str::after($html, '</head>');

        // The three things that did not exist in the served HTML before.
        $this->assertStringContainsString('<h1', $body, 'product page has no server-rendered heading');
        $this->assertStringContainsString($product->name, $body);
        $this->assertStringContainsString('1,450', $body, 'product page does not state its price without JS');
        $this->assertStringContainsString('cash on delivery all over Bangladesh', $body);
    }

    public function test_every_page_carries_a_crawlable_link_graph(): void
    {
        $this->product();

        foreach (['/', '/shop', '/product/turquoise-heart-earrings'] as $path) {
            $body = Str::after($this->get($path)->assertOk()->getContent(), '</head>');

            $this->assertStringContainsString('href="'.route('shop').'"', $body, "$path does not link to the shop without JS");
            $this->assertStringContainsString('href="'.route('category.show', 'earrings').'"', $body, "$path does not link to any category without JS");
        }
    }

    public function test_the_catalog_grid_lists_its_products_without_javascript(): void
    {
        $product = $this->product();

        $body = Str::after($this->get('/shop')->assertOk()->getContent(), '</head>');

        $this->assertStringContainsString(route('product.show', $product->slug), $body);
    }

    // ── Bangladesh targeting ────────────────────────────────────────────────

    public function test_the_document_declares_the_bangladeshi_market(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('<html lang="en-BD">', $html);
        $this->assertStringContainsString('og:locale" content="en_BD', $html);
        $this->assertStringContainsString('geo.region" content="BD', $html);
        $this->assertStringNotContainsString('en_GB', $html, 'the shop still tells Facebook it is British');
    }

    public function test_a_product_title_names_the_market_when_the_admin_has_not(): void
    {
        $product = $this->product();

        $this->get('/product/'.$product->slug)
            ->assertOk()
            ->assertSee('<title>'.$product->name.' — Price in Bangladesh | '.store_name().'</title>', false);
    }

    public function test_an_admin_written_meta_title_is_left_alone(): void
    {
        $product = $this->product();
        $product->update(['meta_title' => 'Hand-picked turquoise earrings']);

        $this->get('/product/'.$product->slug)
            ->assertOk()
            ->assertSee('<title>Hand-picked turquoise earrings | '.store_name().'</title>', false)
            ->assertDontSee('Price in Bangladesh');
    }

    public function test_a_title_no_longer_repeats_the_store_name_twice(): void
    {
        $html = $this->get('/cart')->assertOk()->getContent();

        $this->assertStringNotContainsString(
            '<title>'.store_name().' | '.store_name().'</title>',
            $html
        );
    }

    // ── Indexation control ──────────────────────────────────────────────────

    public function test_an_indexable_page_asks_for_large_image_previews(): void
    {
        $this->get('/shop')
            ->assertOk()
            ->assertSee('name="robots" content="index, follow, max-image-preview:large', false);
    }

    public function test_a_filtered_catalog_page_is_kept_out_of_the_index(): void
    {
        $this->product();

        $html = $this->get('/shop?sort=price_asc')->assertOk()->getContent();

        $this->assertStringContainsString('name="robots" content="noindex, follow"', $html);
        // noindex plus a canonical pointing elsewhere is two contradictory
        // instructions, so a filtered page declares no canonical at all.
        $this->assertStringNotContainsString('rel="canonical"', $html);
    }

    public function test_a_search_results_page_is_kept_out_of_the_index(): void
    {
        $this->product();

        $this->get('/shop?q=earrings')
            ->assertOk()
            ->assertSee('name="robots" content="noindex, follow"', false);
    }

    public function test_utility_pages_are_kept_out_of_the_index(): void
    {
        // /checkout is omitted deliberately: with an empty cart it redirects,
        // and a 302 is never indexed in the first place.
        foreach (['/cart', '/track', '/login', '/register'] as $path) {
            $this->get($path)->assertOk()->assertSee('content="noindex, follow"', false);
        }
    }

    public function test_page_two_of_a_grid_canonicalises_to_itself(): void
    {
        // Page 2 used to canonicalise to page 1, which told Google the two were
        // the same page and discarded everything only reachable from page 2+.
        $this->get('/shop?page=2')
            ->assertOk()
            ->assertSee('rel="canonical" href="'.route('shop').'?page=2"', false);
    }

    public function test_robots_txt_shuts_the_utility_paths_and_advertises_the_sitemap(): void
    {
        $body = $this->get('/robots.txt')->assertOk()->getContent();

        foreach (['/admin', '/cart', '/checkout', '/account', '/login', '/order/'] as $path) {
            $this->assertStringContainsString('Disallow: '.$path, $body);
        }

        // Facets stay crawlable on purpose: they carry noindex, and a page
        // Google may not fetch is a page whose noindex Google never reads.
        $this->assertStringNotContainsString('Disallow: /*?sort=', $body);
        $this->assertStringContainsString('Sitemap: '.route('sitemap'), $body);
    }

    // ── Structured data ─────────────────────────────────────────────────────

    public function test_every_page_identifies_the_shop_and_its_search(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('"@type":"OnlineStore"', $html);
        $this->assertStringContainsString('"addressCountry":"BD"', $html);
        $this->assertStringContainsString('"currenciesAccepted":"BDT"', $html);
        $this->assertStringContainsString('"paymentAccepted":"Cash on Delivery"', $html);
        $this->assertStringContainsString('"@type":"SearchAction"', $html);
    }

    public function test_a_product_offer_carries_what_google_shopping_asks_for(): void
    {
        $product = $this->product();

        $html = $this->get('/product/'.$product->slug)->assertOk()->getContent();

        $this->assertStringContainsString('"@type":"Product"', $html);
        $this->assertStringContainsString('"priceCurrency":"BDT"', $html);
        $this->assertStringContainsString('"itemCondition":"https://schema.org/NewCondition"', $html);
        $this->assertStringContainsString('"priceValidUntil"', $html);
        $this->assertStringContainsString('"@type":"OfferShippingDetails"', $html);
        $this->assertStringContainsString('"@type":"MerchantReturnPolicy"', $html);
        $this->assertStringContainsString('"@type":"BreadcrumbList"', $html);
        // The offer points at the Organization rather than restating it.
        $this->assertStringContainsString('"seller":{"@id":"'.rtrim(config('app.url'), '/').'/#organization"}', $html);
    }

    public function test_an_out_of_stock_product_says_so_rather_than_claiming_stock(): void
    {
        $product = $this->product();
        $product->update(['stock_quantity' => 0]);

        $this->get('/product/'.$product->slug)
            ->assertOk()
            ->assertSee('"availability":"https://schema.org/OutOfStock"', false);
    }

    public function test_a_product_with_no_reviews_claims_no_rating(): void
    {
        // A zero-count aggregateRating is a rich-results policy violation.
        $product = $this->product();

        $this->get('/product/'.$product->slug)
            ->assertOk()
            ->assertDontSee('aggregateRating', false);
    }

    public function test_a_catalog_page_lists_its_products_as_structured_data(): void
    {
        $this->product();

        $this->get('/shop')
            ->assertOk()
            ->assertSee('"@type":"ItemList"', false);
    }

    // ── Sitemap ─────────────────────────────────────────────────────────────

    public function test_the_sitemap_offers_product_photography_to_google_images(): void
    {
        $product = $this->product();

        $xml = $this->get('/sitemap.xml')->assertOk()->getContent();

        $this->assertStringContainsString('xmlns:image="http://www.google.com/schemas/sitemap-image/1.1"', $xml);
        $this->assertStringContainsString('<image:loc>', $xml);
        $this->assertStringContainsString('<image:title>'.$product->name.'</image:title>', $xml);
    }

    public function test_the_sitemap_still_lists_every_page_type(): void
    {
        $product = $this->product();

        $xml = $this->get('/sitemap.xml')->assertOk()->getContent();

        foreach ([route('home'), route('shop'), route('page.about'), route('category.show', 'earrings'), route('product.show', $product->slug)] as $url) {
            $this->assertStringContainsString('<loc>'.$url.'</loc>', $xml);
        }
    }

    // ── The homepage SEO fields the admin form was throwing away ────────────

    public function test_the_homepage_seo_title_the_owner_types_is_the_one_that_ships(): void
    {
        // 'seo_title' had a field in Admin → Appearance and no entry in
        // config/home.php, and the save loop drops unknown keys — so whatever
        // the owner typed was discarded and the hardcoded fallback shipped.
        \App\Models\Setting::put('home_content', [
            'seo_title' => 'Buy Fine Jewelry Online in Bangladesh',
            'seo_description' => 'Handpicked earrings, rings and necklaces with cash on delivery.',
        ]);

        $this->get('/')
            ->assertOk()
            ->assertSee('<title>Buy Fine Jewelry Online in Bangladesh | '.store_name().'</title>', false)
            ->assertSee('Handpicked earrings, rings and necklaces with cash on delivery.', false);
    }

    public function test_the_shipped_homepage_title_names_the_market(): void
    {
        // Seven homepage templates each hardcoded "Fine Jewelry" as their own
        // fallback — a title that competes with the entire web.
        $this->get('/')->assertOk()->assertSee('Online in Bangladesh', false);
    }

    public function test_the_appearance_form_persists_the_homepage_seo_fields(): void
    {
        $this->assertArrayHasKey('seo_title', config('home.defaults'));
        $this->assertArrayHasKey('seo_description', config('home.defaults'));
    }

    public function test_the_prehydration_shell_is_served_but_held_back_from_script_capable_visitors(): void
    {
        // The shell is the only HTML a crawler or a JS-less visitor gets, so
        // it must always be in the response. It is also plain document text
        // that React throws away on mount, so a visitor who IS getting the
        // real page must not be shown it first — that flash was reported from
        // the live homepage. Both halves are pinned here: deleting the shell
        // would silently undo the SEO work, and deleting the holdback would
        // bring the flash back.
        //
        // Asserted on the React homepage specifically, which is what the live
        // store serves; the Blade templates render their own layout and never
        // had the shell.
        \App\Models\Setting::put('theme', ['homepage_template' => \App\Support\HomePage::REACT_TEMPLATE]);

        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('id="seo-shell"', $html);
        $this->assertStringContainsString("classList.add('js')", $html);
        $this->assertMatchesRegularExpression('/\.js #seo-shell\s*\{[^}]*opacity:\s*0/', $html);

        // The holdback must be time-boxed: if the bundle never arrives the
        // shell has to appear rather than leaving a white screen forever.
        $this->assertMatchesRegularExpression('/animation:\s*seo-shell-in[^;]*forwards/', $html);
        $this->assertStringContainsString('@keyframes seo-shell-in', $html);
    }
}

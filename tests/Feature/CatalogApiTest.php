<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use App\Services\ImageOptimizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The admin API and the MCP endpoint that sits on it.
 *
 * The point of this surface is that an assistant can work the catalogue the
 * way it works a Shopify store — so the tests care about the things an agent
 * gets wrong: guessing a product by slug instead of id, uploading to a product
 * that doesn't exist, touching another product's image through this product's
 * URL, and calling without (or with a revoked) token.
 */
class CatalogApiTest extends TestCase
{
    use RefreshDatabase;

    protected string $token;

    protected function setUp(): void
    {
        parent::setUp();
        [, $this->token] = ApiToken::issue('Test');
    }

    protected function auth(array $extra = []): array
    {
        return array_merge(['Authorization' => 'Bearer '.$this->token, 'Accept' => 'application/json'], $extra);
    }

    protected function product(string $name = 'Pearl Drops'): Product
    {
        $category = Category::firstOrCreate(['slug' => 'earrings'], ['name' => 'Earrings', 'is_active' => true]);

        return Product::create([
            'name' => $name, 'slug' => Str::slug($name), 'sku' => 'SKU-'.Str::upper(Str::random(5)),
            'price' => 1450, 'category_id' => $category->id, 'status' => 'published', 'stock_quantity' => 5,
        ]);
    }

    /** A real WebP body so ImageOptimizer has something valid to decode. */
    protected function fakeRemoteImage(): void
    {
        $img = imagecreatetruecolor(900, 900);
        imagefilledrectangle($img, 0, 0, 899, 899, imagecolorallocate($img, 210, 170, 90));
        ob_start();
        imagewebp($img, null, 80);
        $binary = ob_get_clean();
        imagedestroy($img);

        Http::fake(['*' => Http::response($binary, 200, ['Content-Type' => 'image/webp'])]);
        // storeWebpFromUrl uses file_get_contents, so also make a local file the
        // test can point at rather than reaching the network.
        Storage::disk('public')->put('_fixtures/remote.webp', $binary);
    }

    // ── Auth ─────────────────────────────────────────────────────────────────

    public function test_the_api_is_closed_without_a_token(): void
    {
        $this->getJson('/api/v1/products')->assertStatus(401);
        $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])->assertStatus(401);
    }

    public function test_a_revoked_token_stops_working_immediately(): void
    {
        ApiToken::query()->delete();

        $this->getJson('/api/v1/products', $this->auth())->assertStatus(401);
    }

    public function test_an_expired_token_is_refused(): void
    {
        ApiToken::query()->delete();
        [, $plain] = ApiToken::issue('Old', null, now()->subDay());

        $this->getJson('/api/v1/products', ['Authorization' => 'Bearer '.$plain])->assertStatus(401);
    }

    public function test_the_plaintext_token_is_never_stored(): void
    {
        $this->assertDatabaseMissing('api_tokens', ['token_hash' => $this->token]);
        $this->assertDatabaseHas('api_tokens', ['token_hash' => hash('sha256', $this->token)]);
    }

    // ── Products ─────────────────────────────────────────────────────────────

    public function test_products_can_be_searched(): void
    {
        $this->product('Pearl Drops');
        $this->product('Gold Bangle');

        $this->getJson('/api/v1/products?q=pearl', $this->auth())
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Pearl Drops');
    }

    public function test_a_product_resolves_by_id_slug_or_sku(): void
    {
        $p = $this->product();

        foreach ([$p->id, $p->slug, $p->sku] as $ref) {
            $this->getJson('/api/v1/products/'.$ref, $this->auth())
                ->assertOk()->assertJsonPath('data.id', $p->id);
        }
    }

    public function test_an_unknown_product_says_so_usefully(): void
    {
        $this->getJson('/api/v1/products/nope', $this->auth())
            ->assertStatus(404)
            ->assertJsonPath('error', 'not_found');
    }

    public function test_a_product_can_be_updated_field_by_field(): void
    {
        $p = $this->product();

        // assertJsonPath is strict about int-vs-float and the driver decides
        // which one a decimal column comes back as; the value is what matters.
        $res = $this->patchJson('/api/v1/products/'.$p->id, ['price' => 1999, 'status' => 'draft'], $this->auth())->assertOk();
        $this->assertEquals(1999, $res->json('data.price'));

        $p->refresh();
        $this->assertSame('draft', $p->status);
        $this->assertSame('Pearl Drops', $p->name, 'Fields that were not sent must not change.');
    }

    // ── Images ───────────────────────────────────────────────────────────────

    public function test_an_uploaded_file_becomes_a_product_image(): void
    {
        Storage::fake('public');
        $p = $this->product();

        $res = $this->post('/api/v1/products/'.$p->id.'/images', [
            'image' => UploadedFile::fake()->image('shot.jpg', 900, 900),
        ], $this->auth());

        $res->assertStatus(201)->assertJsonPath('data.is_primary', true);
        $this->assertCount(1, $p->fresh()->images);
    }

    public function test_the_first_image_becomes_primary_even_when_not_asked(): void
    {
        Storage::fake('public');
        $p = $this->product();

        $this->post('/api/v1/products/'.$p->id.'/images', [
            'image' => UploadedFile::fake()->image('a.jpg', 900, 900),
        ], $this->auth())->assertStatus(201);

        // A product with images but no primary has no thumbnail anywhere.
        $this->assertTrue($p->fresh()->images->first()->is_primary);
    }

    public function test_uploading_generates_the_srcset_variant(): void
    {
        Storage::fake('public');
        $p = $this->product();

        $this->post('/api/v1/products/'.$p->id.'/images', [
            'image' => UploadedFile::fake()->image('a.jpg', 900, 900),
        ], $this->auth())->assertStatus(201);

        $path = $p->fresh()->images->first()->path;
        Storage::disk('public')->assertExists(app(ImageOptimizer::class)->variantPath($path, 450));
    }

    public function test_promoting_an_image_demotes_the_previous_primary(): void
    {
        Storage::fake('public');
        $p = $this->product();
        $a = ProductImage::create(['product_id' => $p->id, 'path' => 'products/a.webp', 'position' => 1, 'is_primary' => true]);
        $b = ProductImage::create(['product_id' => $p->id, 'path' => 'products/b.webp', 'position' => 2, 'is_primary' => false]);

        $this->patchJson("/api/v1/products/{$p->id}/images/{$b->id}", ['primary' => true], $this->auth())->assertOk();

        $this->assertFalse($a->fresh()->is_primary);
        $this->assertTrue($b->fresh()->is_primary);
    }

    public function test_deleting_the_primary_promotes_the_next_image(): void
    {
        Storage::fake('public');
        $p = $this->product();
        $a = ProductImage::create(['product_id' => $p->id, 'path' => 'products/a.webp', 'position' => 1, 'is_primary' => true]);
        $b = ProductImage::create(['product_id' => $p->id, 'path' => 'products/b.webp', 'position' => 2, 'is_primary' => false]);

        $this->deleteJson("/api/v1/products/{$p->id}/images/{$a->id}", [], $this->auth())->assertOk();

        $this->assertTrue($b->fresh()->is_primary, 'The product must never be left with images but no primary.');
    }

    public function test_another_products_image_cannot_be_reached_through_this_product(): void
    {
        Storage::fake('public');
        $mine = $this->product('Mine');
        $theirs = $this->product('Theirs');
        $img = ProductImage::create(['product_id' => $theirs->id, 'path' => 'products/x.webp', 'position' => 1, 'is_primary' => true]);

        $this->deleteJson("/api/v1/products/{$mine->id}/images/{$img->id}", [], $this->auth())
            ->assertStatus(404);

        $this->assertDatabaseHas('product_images', ['id' => $img->id]);
    }

    // ── MCP ──────────────────────────────────────────────────────────────────

    public function test_mcp_initialize_advertises_tools(): void
    {
        $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize'], $this->auth())
            ->assertOk()
            ->assertJsonPath('result.protocolVersion', '2025-06-18')
            ->assertJsonPath('result.capabilities.tools.listChanged', false);
    }

    public function test_mcp_lists_the_catalogue_tools(): void
    {
        $names = collect($this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'], $this->auth())
            ->assertOk()->json('result.tools'))->pluck('name');

        foreach (['search_products', 'get_product', 'upload_product_image', 'set_primary_image', 'delete_product_image', 'update_product'] as $tool) {
            $this->assertContains($tool, $names);
        }
    }

    public function test_mcp_can_search_products(): void
    {
        $this->product('Pearl Drops');

        $text = $this->postJson('/mcp', [
            'jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call',
            'params' => ['name' => 'search_products', 'arguments' => ['query' => 'pearl']],
        ], $this->auth())->assertOk()->json('result.content.0.text');

        $this->assertStringContainsString('Pearl Drops', $text);
    }

    public function test_mcp_can_update_a_product_by_slug(): void
    {
        $p = $this->product();

        $this->postJson('/mcp', [
            'jsonrpc' => '2.0', 'id' => 4, 'method' => 'tools/call',
            'params' => ['name' => 'update_product', 'arguments' => ['product' => $p->slug, 'price' => 2100]],
        ], $this->auth())->assertOk()->assertJsonPath('result.isError', false);

        $this->assertEquals(2100, $p->fresh()->price);
    }

    public function test_mcp_reports_a_missing_product_as_a_jsonrpc_error(): void
    {
        $res = $this->postJson('/mcp', [
            'jsonrpc' => '2.0', 'id' => 5, 'method' => 'tools/call',
            'params' => ['name' => 'get_product', 'arguments' => ['product' => 'does-not-exist']],
        ], $this->auth())->assertOk();

        // An agent needs a usable next step, not a stack trace.
        $this->assertStringContainsString('search_products', $res->json('error.message'));
    }

    public function test_mcp_rejects_an_unknown_method(): void
    {
        $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 6, 'method' => 'nonsense'], $this->auth())
            ->assertOk()->assertJsonPath('error.code', -32601);
    }

    public function test_mcp_notifications_get_no_body(): void
    {
        $this->postJson('/mcp', ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'], $this->auth())
            ->assertNoContent();
    }
}

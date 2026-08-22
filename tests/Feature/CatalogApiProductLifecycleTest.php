<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Full product lifecycle through the agent surfaces (REST + MCP): an assistant
 * must be able to list categories, create a product, publish it, and archive
 * it again — and an archive must be recoverable, never a hard delete.
 */
class CatalogApiProductLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected string $token;

    protected function setUp(): void
    {
        parent::setUp();
        [, $this->token] = ApiToken::issue('Lifecycle');
        Category::create(['name' => 'Earrings', 'slug' => 'earrings', 'is_active' => true]);
    }

    protected function auth(): array
    {
        return ['Authorization' => 'Bearer '.$this->token, 'Accept' => 'application/json'];
    }

    protected function mcp(string $tool, array $arguments = [])
    {
        return $this->postJson('/mcp', [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['name' => $tool, 'arguments' => $arguments],
        ], $this->auth());
    }

    // ── REST ─────────────────────────────────────────────────────────────────

    public function test_rest_lists_categories(): void
    {
        $this->getJson('/api/v1/categories', $this->auth())
            ->assertOk()
            ->assertJsonPath('data.0.slug', 'earrings');
    }

    public function test_rest_creates_a_draft_product_by_category_slug(): void
    {
        $res = $this->postJson('/api/v1/products', [
            'name' => 'Moonstone Drops',
            'price' => 1890,
            'category' => 'earrings',
            'stock_quantity' => 12,
            'tags' => ['gift', 'silver'],
        ], $this->auth())->assertCreated();

        $res->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.category.slug', 'earrings')
            ->assertJsonPath('data.stock_quantity', 12)
            ->assertJsonPath('data.tags', 'gift,silver');

        $product = Product::first();
        $this->assertSame('moonstone-drops', $product->slug);
        $this->assertTrue($product->manage_stock);
        $this->assertNotNull($product->serial, 'createUnique should assign a display serial');

        // A draft never shows on the storefront.
        $this->get('/product/moonstone-drops')->assertNotFound();
    }

    public function test_rest_rejects_an_unknown_category_with_guidance(): void
    {
        $this->postJson('/api/v1/products', ['name' => 'X', 'price' => 10, 'category' => 'hats'], $this->auth())
            ->assertStatus(422)
            ->assertJsonPath('error', 'unknown_category');
    }

    public function test_rest_archives_a_product_softly(): void
    {
        $this->postJson('/api/v1/products', ['name' => 'Temp Ring', 'price' => 500], $this->auth())->assertCreated();
        $product = Product::first();

        $this->deleteJson('/api/v1/products/'.$product->slug, [], $this->auth())
            ->assertOk()->assertJsonPath('data.restorable', true);

        $this->assertSoftDeleted('products', ['id' => $product->id]);
        $this->assertNotNull(Product::withTrashed()->find($product->id));
    }

    // ── MCP ──────────────────────────────────────────────────────────────────

    public function test_mcp_advertises_the_lifecycle_tools(): void
    {
        $names = collect($this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 9, 'method' => 'tools/list'], $this->auth())
            ->assertOk()->json('result.tools'))->pluck('name');

        foreach (['list_categories', 'create_product', 'update_product', 'delete_product', 'upload_product_image'] as $tool) {
            $this->assertTrue($names->contains($tool), "missing MCP tool {$tool}");
        }
    }

    public function test_mcp_can_create_then_publish_then_archive(): void
    {
        $created = $this->mcp('create_product', [
            'name' => 'Pearl Halo Studs', 'price' => 2400, 'category' => 'Earrings', 'short_description' => 'Freshwater pearls.',
        ])->assertOk()->assertJsonPath('result.isError', false)->json('result.content.0.text');

        $payload = json_decode($created, true);
        $this->assertSame('draft', $payload['status']);
        $slug = $payload['slug'];

        $this->mcp('update_product', ['product' => $slug, 'status' => 'published', 'is_featured' => true])
            ->assertOk()->assertJsonPath('result.isError', false);
        $this->get('/product/'.$slug)->assertOk();

        $this->mcp('delete_product', ['product' => $slug, 'confirm' => true])->assertOk();
        $this->assertSoftDeleted('products', ['slug' => $slug]);
        $this->get('/product/'.$slug)->assertNotFound();
    }

    public function test_mcp_delete_requires_explicit_confirmation(): void
    {
        $this->mcp('create_product', ['name' => 'Keep Me', 'price' => 100])->assertOk();

        $res = $this->mcp('delete_product', ['product' => 'keep-me'])->assertOk();

        $this->assertStringContainsString('confirm=true', $res->json('error.message'));
        $this->assertDatabaseHas('products', ['slug' => 'keep-me', 'deleted_at' => null]);
    }

    public function test_mcp_create_needs_name_and_price(): void
    {
        $res = $this->mcp('create_product', ['name' => 'No price'])->assertOk();
        $this->assertSame(-32602, $res->json('error.code'));
    }
}

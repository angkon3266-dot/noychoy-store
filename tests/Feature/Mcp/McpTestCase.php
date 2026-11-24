<?php

namespace Tests\Feature\Mcp;

use App\Models\ApiToken;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Talks to the connector the way Claude does: JSON-RPC over POST /mcp with a
 * bearer token. Nothing here sends a real SMS or books a courier — SMS goes
 * through Http::fake() in the tests that need it.
 */
abstract class McpTestCase extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected string $token;

    protected ApiToken $tokenModel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actAs('admin');
    }

    protected function actAs(string $role): User
    {
        $this->user = User::factory()->create(['role' => $role, 'name' => ucfirst($role).' User']);
        [$this->tokenModel, $this->token] = ApiToken::issue('Claude', null, null, $this->user);

        return $this->user;
    }

    protected function headers(?string $token = null): array
    {
        return ['Authorization' => 'Bearer '.($token ?? $this->token), 'Accept' => 'application/json, text/event-stream'];
    }

    protected function rpc(string $method, array $params = [], ?string $token = null): TestResponse
    {
        return $this->postJson('/mcp', array_filter([
            'jsonrpc' => '2.0', 'id' => random_int(1, 99999), 'method' => $method, 'params' => $params ?: null,
        ], fn ($v) => $v !== null), $this->headers($token));
    }

    /** Call a tool; returns [decoded payload or text, isError]. */
    protected function callTool(string $tool, array $arguments = []): array
    {
        $res = $this->rpc('tools/call', ['name' => $tool, 'arguments' => (object) $arguments])->assertOk();
        $text = (string) $res->json('result.content.0.text');
        $decoded = json_decode($text, true);

        return [$decoded ?? $text, (bool) $res->json('result.isError')];
    }

    /** Call a tool that must succeed; returns the decoded payload. */
    protected function ok(string $tool, array $arguments = []): mixed
    {
        [$payload, $error] = $this->callTool($tool, $arguments);
        $this->assertFalse($error, "{$tool} failed: ".json_encode($payload));

        return $payload;
    }

    /** Call a tool that must fail; returns the error text. */
    protected function fails(string $tool, array $arguments = []): string
    {
        [$payload, $error] = $this->callTool($tool, $arguments);
        $this->assertTrue($error, "{$tool} should have failed but returned: ".json_encode($payload));

        return is_string($payload) ? $payload : json_encode($payload);
    }

    protected function toolNames(?string $token = null): array
    {
        return collect($this->rpc('tools/list', [], $token)->assertOk()->json('result.tools'))->pluck('name')->all();
    }

    protected function product(string $name = 'Pearl Drops', array $extra = []): Product
    {
        $category = Category::firstOrCreate(['slug' => 'earrings'], ['name' => 'Earrings', 'is_active' => true]);

        return Product::create(array_merge([
            'name' => $name, 'slug' => Str::slug($name), 'sku' => 'SKU-'.Str::upper(Str::random(5)),
            'price' => 1450, 'category_id' => $category->id, 'status' => 'published', 'stock_quantity' => 5,
            'manage_stock' => true, 'in_stock' => true,
        ], $extra));
    }
}

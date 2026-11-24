<?php

namespace Tests\Feature\Mcp;

use App\Models\ApiToken;
use App\Models\McpToolCall;
use App\Models\User;

class McpAuthTest extends McpTestCase
{
    public function test_no_token_is_401_with_a_discovery_header(): void
    {
        $res = $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list']);

        $res->assertStatus(401);
        $this->assertStringContainsString('resource_metadata=', (string) $res->headers->get('WWW-Authenticate'));
        $this->assertStringContainsString('/.well-known/oauth-protected-resource', (string) $res->headers->get('WWW-Authenticate'));
    }

    public function test_a_wrong_or_revoked_or_expired_token_is_refused(): void
    {
        $this->rpc('tools/list', [], 'mec_nope')->assertStatus(401);

        [$expired, $plain] = ApiToken::issue('Old', null, now()->subDay(), $this->user);
        $this->rpc('tools/list', [], $plain)->assertStatus(401);

        $this->tokenModel->delete();
        $this->rpc('tools/list')->assertStatus(401);
    }

    public function test_a_token_without_an_owner_or_whose_owner_lost_admin_access_is_refused(): void
    {
        [, $orphan] = ApiToken::issue('Legacy');
        $this->rpc('tools/list', [], $orphan)->assertStatus(401);

        $this->user->update(['role' => 'customer']);
        $this->rpc('tools/list')->assertStatus(401);
    }

    public function test_initialize_names_the_store_and_explains_the_rules(): void
    {
        $res = $this->rpc('initialize', [
            'protocolVersion' => '2025-06-18',
            'capabilities' => (object) [],
            'clientInfo' => ['name' => 'test', 'version' => '1'],
        ])->assertOk();

        $res->assertJsonPath('result.capabilities.tools.listChanged', false);
        $this->assertStringContainsString(store_name(), $res->json('result.serverInfo.name'));
        $this->assertStringContainsString('confirm=true', $res->json('result.instructions'));
    }

    public function test_get_on_the_endpoint_is_405(): void
    {
        $this->get('/mcp', $this->headers())->assertStatus(405);
    }

    public function test_every_call_is_audited_with_token_and_user(): void
    {
        $this->ok('store_info');
        $this->fails('get_product', ['product' => 'nope']);

        $calls = McpToolCall::orderBy('id')->get();
        $this->assertCount(2, $calls);
        $this->assertSame('store_info', $calls[0]->tool);
        $this->assertTrue($calls[0]->ok);
        $this->assertSame($this->user->id, $calls[0]->user_id);
        $this->assertSame($this->tokenModel->id, $calls[0]->api_token_id);
        $this->assertSame('Claude', $calls[0]->token_name);
        $this->assertFalse($calls[1]->ok);
        $this->assertSame(['product' => 'nope'], $calls[1]->arguments);
        $this->assertStringContainsString('search_products', $calls[1]->error);
    }

    public function test_store_info_reports_who_the_token_acts_as(): void
    {
        $info = $this->ok('store_info');

        $this->assertSame('admin', $info['acting_as']['role']);
        $this->assertContains('products', $info['can_reach']);
        $this->assertContains('orders', $info['can_reach']);
    }

    public function test_the_rest_api_also_needs_an_owned_token_with_products_access(): void
    {
        $this->getJson('/api/v1/products', $this->headers())->assertOk();

        $this->actAs('staff');
        $this->getJson('/api/v1/products', $this->headers())->assertStatus(403);
    }

    public function test_deleting_the_user_kills_their_tokens(): void
    {
        $id = $this->tokenModel->id;
        $this->user->delete();

        $this->assertNull(ApiToken::find($id));
        $this->rpc('tools/list')->assertStatus(401);
    }
}

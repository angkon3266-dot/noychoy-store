<?php

namespace Tests\Feature\Mcp;

use App\Models\ApiToken;
use App\Models\OAuthClient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The claude.ai custom-connector path: discover → register → the owner logs
 * in to the admin and clicks Allow → code + PKCE → token → /mcp → refresh.
 */
class McpOAuthTest extends TestCase
{
    use RefreshDatabase;

    private const REDIRECT = 'https://claude.ai/api/mcp/auth_callback';

    protected function register(array $extra = []): array
    {
        return $this->postJson('/oauth/register', array_merge([
            'client_name' => 'Claude', 'redirect_uris' => [self::REDIRECT],
            'grant_types' => ['authorization_code', 'refresh_token'], 'token_endpoint_auth_method' => 'none',
        ], $extra))->assertCreated()->json();
    }

    protected function pkce(): array
    {
        $verifier = Str::random(64);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        return [$verifier, $challenge];
    }

    protected function authorizeQuery(string $clientId, string $challenge, array $extra = []): array
    {
        return array_merge([
            'response_type' => 'code', 'client_id' => $clientId, 'redirect_uri' => self::REDIRECT,
            'code_challenge' => $challenge, 'code_challenge_method' => 'S256', 'state' => 'xyz',
            'scope' => 'mcp', 'resource' => url('/mcp'),
        ], $extra);
    }

    /** Runs the consent step as $user; returns the code. */
    protected function approve(User $user, string $clientId, string $challenge): string
    {
        $res = $this->actingAs($user)->post('/oauth/authorize', $this->authorizeQuery($clientId, $challenge) + ['decision' => 'approve']);
        $res->assertRedirect();
        $location = $res->headers->get('Location');
        $this->assertStringStartsWith(self::REDIRECT.'?', $location);
        parse_str(parse_url($location, PHP_URL_QUERY), $q);
        $this->assertSame('xyz', $q['state']);

        return $q['code'];
    }

    public function test_discovery_documents(): void
    {
        $this->getJson('/.well-known/oauth-protected-resource/mcp')->assertOk()
            ->assertJsonPath('resource', url('/mcp'))
            ->assertJsonPath('authorization_servers.0', url('/'));

        $this->getJson('/.well-known/oauth-authorization-server')->assertOk()
            ->assertJsonPath('issuer', url('/'))
            ->assertJsonPath('token_endpoint', url('/oauth/token'))
            ->assertJsonPath('registration_endpoint', url('/oauth/register'))
            ->assertJsonPath('code_challenge_methods_supported', ['S256']);
    }

    public function test_registration_rejects_unsafe_redirects(): void
    {
        $this->postJson('/oauth/register', ['redirect_uris' => ['http://evil.example/cb']])->assertStatus(400);
        $this->postJson('/oauth/register', ['redirect_uris' => []])->assertStatus(400);
        $this->postJson('/oauth/register', ['redirect_uris' => ['http://localhost:6274/oauth/callback']])->assertCreated();
    }

    public function test_the_full_flow_ends_in_a_token_that_acts_as_the_approving_user(): void
    {
        $owner = User::factory()->create(['role' => 'manager', 'name' => 'Owner']);
        $client = $this->register();
        [$verifier, $challenge] = $this->pkce();

        // Not logged in: off to the admin login, which brings them back.
        $this->get('/oauth/authorize?'.http_build_query($this->authorizeQuery($client['client_id'], $challenge)))
            ->assertRedirect(route('admin.login'));

        // Logged in: the consent screen names the store, the app and the user.
        $this->actingAs($owner)->get('/oauth/authorize?'.http_build_query($this->authorizeQuery($client['client_id'], $challenge)))
            ->assertOk()->assertSee('Claude')->assertSee('Owner')->assertSee('Allow')
            ->assertHeader('X-Frame-Options', 'DENY');

        $code = $this->approve($owner, $client['client_id'], $challenge);

        $token = $this->post('/oauth/token', [
            'grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => self::REDIRECT,
            'client_id' => $client['client_id'], 'code_verifier' => $verifier,
        ])->assertOk()->assertJsonPath('token_type', 'Bearer')->json();

        $this->assertArrayHasKey('refresh_token', $token);

        $row = ApiToken::where('kind', 'oauth')->sole();
        $this->assertSame($owner->id, $row->user_id);

        // The token works on /mcp, scoped to the manager role.
        $this->app['auth']->forgetGuards();
        $res = $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'store_info', 'arguments' => (object) []]],
            ['Authorization' => 'Bearer '.$token['access_token']])->assertOk();
        $this->assertStringContainsString('"role":"manager"', $res->json('result.content.0.text'));

        // The code is single-use.
        $this->post('/oauth/token', [
            'grant_type' => 'authorization_code', 'code' => $code, 'client_id' => $client['client_id'], 'code_verifier' => $verifier,
        ])->assertStatus(400)->assertJsonPath('error', 'invalid_grant');
    }

    public function test_pkce_must_match(): void
    {
        $owner = User::factory()->create(['role' => 'admin']);
        $client = $this->register();
        [, $challenge] = $this->pkce();
        $code = $this->approve($owner, $client['client_id'], $challenge);

        $this->post('/oauth/token', [
            'grant_type' => 'authorization_code', 'code' => $code, 'client_id' => $client['client_id'], 'code_verifier' => 'wrong',
        ])->assertStatus(400)->assertJsonPath('error', 'invalid_grant');

        $this->assertSame(0, ApiToken::count());
    }

    public function test_refresh_rotates_both_tokens_and_revoking_ends_it(): void
    {
        $owner = User::factory()->create(['role' => 'admin']);
        $client = $this->register();
        [$verifier, $challenge] = $this->pkce();
        $code = $this->approve($owner, $client['client_id'], $challenge);
        $first = $this->post('/oauth/token', [
            'grant_type' => 'authorization_code', 'code' => $code, 'client_id' => $client['client_id'], 'code_verifier' => $verifier,
        ])->json();

        $second = $this->post('/oauth/token', [
            'grant_type' => 'refresh_token', 'refresh_token' => $first['refresh_token'], 'client_id' => $client['client_id'],
        ])->assertOk()->json();

        $this->assertNotSame($first['access_token'], $second['access_token']);
        $this->assertNull(ApiToken::findValid($first['access_token']));
        $this->assertNotNull(ApiToken::findValid($second['access_token']));

        // The old refresh token is dead.
        $this->post('/oauth/token', ['grant_type' => 'refresh_token', 'refresh_token' => $first['refresh_token'], 'client_id' => $client['client_id']])
            ->assertStatus(400);

        // Revoking in the admin ends access and refresh at once.
        ApiToken::sole()->delete();
        $this->post('/oauth/token', ['grant_type' => 'refresh_token', 'refresh_token' => $second['refresh_token'], 'client_id' => $client['client_id']])
            ->assertStatus(400);
    }

    public function test_deny_returns_access_denied_and_unknown_clients_are_never_redirected(): void
    {
        $owner = User::factory()->create(['role' => 'admin']);
        $client = $this->register();
        [, $challenge] = $this->pkce();

        $res = $this->actingAs($owner)->post('/oauth/authorize', $this->authorizeQuery($client['client_id'], $challenge) + ['decision' => 'deny']);
        $this->assertStringContainsString('error=access_denied', $res->headers->get('Location'));

        $this->actingAs($owner)->get('/oauth/authorize?'.http_build_query($this->authorizeQuery('nope', $challenge)))->assertStatus(400);
        $this->actingAs($owner)->get('/oauth/authorize?'.http_build_query($this->authorizeQuery($client['client_id'], $challenge, ['redirect_uri' => 'https://evil.example/cb'])))
            ->assertStatus(400);

        // No PKCE: bounced back to the (registered) client with invalid_request.
        $res = $this->actingAs($owner)->get('/oauth/authorize?'.http_build_query($this->authorizeQuery($client['client_id'], '')));
        $this->assertStringContainsString('error=invalid_request', $res->headers->get('Location'));
    }

    public function test_customers_cannot_authorize(): void
    {
        $shopper = User::factory()->create(['role' => 'customer']);
        $client = $this->register();
        [, $challenge] = $this->pkce();

        $this->actingAs($shopper)->get('/oauth/authorize?'.http_build_query($this->authorizeQuery($client['client_id'], $challenge)))
            ->assertStatus(403);
    }

    public function test_a_confidential_client_must_present_its_secret(): void
    {
        $owner = User::factory()->create(['role' => 'admin']);
        $client = $this->register(['token_endpoint_auth_method' => 'client_secret_post']);
        $this->assertNotEmpty($client['client_secret']);
        [$verifier, $challenge] = $this->pkce();
        $code = $this->approve($owner, $client['client_id'], $challenge);

        $this->post('/oauth/token', ['grant_type' => 'authorization_code', 'code' => $code, 'client_id' => $client['client_id'], 'code_verifier' => $verifier])
            ->assertStatus(401)->assertJsonPath('error', 'invalid_client');

        $this->withHeader('Authorization', 'Basic '.base64_encode($client['client_id'].':'.$client['client_secret']))
            ->post('/oauth/token', ['grant_type' => 'authorization_code', 'code' => $code, 'code_verifier' => $verifier])
            ->assertOk();

        $this->assertSame(1, OAuthClient::count());
    }
}

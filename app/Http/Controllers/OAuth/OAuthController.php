<?php

namespace App\Http\Controllers\OAuth;

use App\Http\Controllers\Controller;
use App\Models\ApiToken;
use App\Models\OAuthAuthCode;
use App\Models\OAuthClient;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * A deliberately small OAuth 2.1 authorization server, just enough for
 * claude.ai's "custom connector" to sign in to this store with nothing pasted.
 *
 * What the MCP authorization spec asks of a server, and where it lives:
 *  - Protected Resource Metadata (RFC 9728) → protectedResource(); the 401 from
 *    /mcp points at it through the WWW-Authenticate header.
 *  - Authorization Server Metadata (RFC 8414) → authorizationServer().
 *  - Dynamic Client Registration (RFC 7591) → register(). claude.ai registers
 *    itself the first time the connector is added.
 *  - Authorization code + PKCE (S256 only) → authorize() / approve() / token().
 *    The person approving is an admin user logged in to this store's own admin
 *    panel; the access token then acts as that user, scoped to their role.
 *  - Refresh tokens, rotated on every use.
 *
 * Access tokens are ordinary ApiToken rows (kind=oauth), so revoking one in
 * Admin → Claude connector ends the connection, and the same audit trail
 * covers both kinds. No Passport: no signing keys to generate on a buyer's
 * host, nothing to set up.
 */
class OAuthController extends Controller
{
    public const SCOPE = 'mcp';

    private const ACCESS_TTL_HOURS = 8;

    private const REFRESH_TTL_DAYS = 90;

    private const CODE_TTL_MINUTES = 10;

    // ── Discovery ───────────────────────────────────────────────────────────

    public function protectedResource(): JsonResponse
    {
        return $this->cors(response()->json([
            'resource' => url('/mcp'),
            'authorization_servers' => [url('/')],
            'scopes_supported' => [self::SCOPE],
            'bearer_methods_supported' => ['header'],
            'resource_name' => store_name(),
        ]));
    }

    public function authorizationServer(): JsonResponse
    {
        return $this->cors(response()->json([
            'issuer' => url('/'),
            'authorization_endpoint' => url('/oauth/authorize'),
            'token_endpoint' => url('/oauth/token'),
            'registration_endpoint' => url('/oauth/register'),
            'response_types_supported' => ['code'],
            'response_modes_supported' => ['query'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'code_challenge_methods_supported' => ['S256'],
            'token_endpoint_auth_methods_supported' => ['none', 'client_secret_post', 'client_secret_basic'],
            'scopes_supported' => [self::SCOPE],
            'service_documentation' => url('/admin/api-tokens'),
        ]));
    }

    public function preflight(): \Illuminate\Http\Response
    {
        return $this->cors(response('', 204));
    }

    // ── Dynamic client registration ─────────────────────────────────────────

    public function register(Request $request): JsonResponse
    {
        $uris = $request->input('redirect_uris');

        if (! is_array($uris) || $uris === [] || count($uris) > 10) {
            return $this->oauthError('invalid_redirect_uri', 'redirect_uris must be a list of 1–10 URLs.');
        }
        foreach ($uris as $uri) {
            if (! is_string($uri) || ! $this->acceptableRedirect($uri)) {
                return $this->oauthError('invalid_redirect_uri', 'Each redirect URI must be https, or http on localhost.');
            }
        }

        $method = (string) $request->input('token_endpoint_auth_method', 'none');
        $method = in_array($method, ['client_secret_post', 'client_secret_basic'], true) ? $method : 'none';
        $secret = $method === 'none' ? null : Str::random(48);

        $client = OAuthClient::create([
            'client_id' => (string) Str::uuid(),
            'name' => Str::limit(trim((string) $request->input('client_name', 'Claude')) ?: 'Claude', 110, ''),
            'redirect_uris' => array_values($uris),
            'secret_hash' => $secret ? hash('sha256', $secret) : null,
        ]);

        return $this->cors(response()->json(array_filter([
            'client_id' => $client->client_id,
            'client_secret' => $secret,
            'client_secret_expires_at' => $secret ? 0 : null,
            'client_id_issued_at' => $client->created_at->timestamp,
            'client_name' => $client->name,
            'redirect_uris' => $client->redirect_uris,
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code'],
            'token_endpoint_auth_method' => $method,
            'scope' => self::SCOPE,
        ], fn ($v) => $v !== null), 201));
    }

    // ── Authorization (the owner's consent screen) ──────────────────────────

    public function showAuthorize(Request $request)
    {
        [$client, $problem] = $this->checkAuthorizeRequest($request);
        if ($problem) {
            return $problem;
        }

        if (! Auth::check()) {
            // intended() in the admin login brings them straight back here.
            return redirect()->guest(route('admin.login'));
        }

        /** @var User $user */
        $user = Auth::user();
        if (! in_array($user->role, ['admin', 'manager', 'staff'], true)) {
            return $this->page('oauth.error', ['message' => 'Only staff with access to this store\'s admin panel can connect Claude.'], 403);
        }

        return $this->page('oauth.authorize', [
            'client' => $client,
            'user' => $user,
            'params' => $request->only(['client_id', 'redirect_uri', 'response_type', 'code_challenge', 'code_challenge_method', 'state', 'scope', 'resource']),
            'sections' => array_values(array_filter(
                ['products', 'categories', 'collections', 'menu', 'orders', 'customers', 'coupons', 'offers', 'reviews', 'dashboard'],
                fn ($s) => $user->canAccess($s),
            )),
        ]);
    }

    public function approve(Request $request)
    {
        [$client, $problem] = $this->checkAuthorizeRequest($request);
        if ($problem) {
            return $problem;
        }

        /** @var User|null $user */
        $user = Auth::user();
        if (! $user || ! in_array($user->role, ['admin', 'manager', 'staff'], true)) {
            return redirect()->guest(route('admin.login'));
        }

        $redirect = (string) $request->input('redirect_uri');
        $state = $request->input('state');

        if ($request->input('decision') !== 'approve') {
            return redirect()->away($this->withQuery($redirect, array_filter([
                'error' => 'access_denied', 'state' => $state,
            ], fn ($v) => $v !== null)));
        }

        $code = Str::random(64);
        OAuthAuthCode::create([
            'code_hash' => hash('sha256', $code),
            'client_id' => $client->client_id,
            'user_id' => $user->id,
            'redirect_uri' => $redirect,
            'code_challenge' => (string) $request->input('code_challenge'),
            'resource' => $request->input('resource'),
            'expires_at' => now()->addMinutes(self::CODE_TTL_MINUTES),
        ]);

        return redirect()->away($this->withQuery($redirect, array_filter([
            'code' => $code, 'state' => $state, 'iss' => url('/'),
        ], fn ($v) => $v !== null)));
    }

    // ── Token endpoint ──────────────────────────────────────────────────────

    public function token(Request $request): JsonResponse
    {
        $client = $this->authenticateClient($request);
        if ($client instanceof JsonResponse) {
            return $client;
        }

        return match ($request->input('grant_type')) {
            'authorization_code' => $this->exchangeCode($request, $client),
            'refresh_token' => $this->refresh($request, $client),
            default => $this->oauthError('unsupported_grant_type', 'Use authorization_code or refresh_token.'),
        };
    }

    private function exchangeCode(Request $request, OAuthClient $client): JsonResponse
    {
        $code = OAuthAuthCode::where('code_hash', hash('sha256', (string) $request->input('code')))->first();

        if (! $code || $code->client_id !== $client->client_id || $code->expires_at->isPast()) {
            return $this->oauthError('invalid_grant', 'The authorization code is unknown or expired.');
        }
        if ($code->used_at) {
            // A replayed code: kill whatever it minted (RFC 6749 §4.1.2).
            ApiToken::where('client_id', $client->client_id)->where('user_id', $code->user_id)
                ->where('created_at', '>=', $code->used_at->copy()->subSecond())->delete();

            return $this->oauthError('invalid_grant', 'The authorization code was already used.');
        }
        if ($request->filled('redirect_uri') && $request->input('redirect_uri') !== $code->redirect_uri) {
            return $this->oauthError('invalid_grant', 'redirect_uri does not match the authorization request.');
        }

        $verifier = (string) $request->input('code_verifier');
        $expected = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        if ($verifier === '' || ! hash_equals($code->code_challenge, $expected)) {
            return $this->oauthError('invalid_grant', 'PKCE verification failed.');
        }

        $code->update(['used_at' => now()]);

        $user = $code->user;
        if (! $user || ! in_array($user->role, ['admin', 'manager', 'staff'], true)) {
            return $this->oauthError('invalid_grant', 'That admin user no longer has access.');
        }

        [$token] = ApiToken::issue($client->name.' (connected '.now(config('store.timezone', 'Asia/Dhaka'))->format('j M Y').')', null, null, $user, [
            'kind' => ApiToken::KIND_OAUTH,
            'client_id' => $client->client_id,
        ]);

        return $this->tokenResponse($token);
    }

    private function refresh(Request $request, OAuthClient $client): JsonResponse
    {
        $token = ApiToken::findByRefresh((string) $request->input('refresh_token'));

        if (! $token || $token->client_id !== $client->client_id || ! $token->user) {
            return $this->oauthError('invalid_grant', 'The refresh token is unknown, expired or revoked.');
        }

        return $this->tokenResponse($token);
    }

    private function tokenResponse(ApiToken $token): JsonResponse
    {
        [$access, $refresh] = $token->rotate(
            now()->addHours(self::ACCESS_TTL_HOURS),
            now()->addDays(self::REFRESH_TTL_DAYS),
        );

        return $this->cors(response()->json([
            'access_token' => $access,
            'token_type' => 'Bearer',
            'expires_in' => self::ACCESS_TTL_HOURS * 3600,
            'refresh_token' => $refresh,
            'scope' => self::SCOPE,
        ])->header('Cache-Control', 'no-store')->header('Pragma', 'no-cache'));
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /** @return array{0: ?OAuthClient, 1: mixed} */
    private function checkAuthorizeRequest(Request $request): array
    {
        $client = OAuthClient::where('client_id', (string) $request->input('client_id'))->first();
        $redirect = (string) $request->input('redirect_uri');

        // Never redirect to a URI we cannot vouch for — show the error here.
        if (! $client) {
            return [null, $this->page('oauth.error', ['message' => 'This connection request comes from an app this store does not know. Remove the connector in Claude and add it again.'], 400)];
        }
        if ($redirect === '' && count($client->redirect_uris) === 1) {
            $request->merge(['redirect_uri' => $redirect = $client->redirect_uris[0]]);
        }
        if (! $client->allowsRedirect($redirect)) {
            return [null, $this->page('oauth.error', ['message' => 'The return address in this connection request is not the one the app registered.'], 400)];
        }

        $error = match (true) {
            $request->input('response_type') !== 'code' => 'response_type must be code.',
            blank($request->input('code_challenge')) => 'PKCE is required: send code_challenge.',
            $request->input('code_challenge_method', 'S256') !== 'S256' => 'Only the S256 code_challenge_method is supported.',
            default => null,
        };

        if ($error) {
            return [null, redirect()->away($this->withQuery($redirect, array_filter([
                'error' => 'invalid_request', 'error_description' => $error, 'state' => $request->input('state'),
            ], fn ($v) => $v !== null)))];
        }

        return [$client, null];
    }

    private function authenticateClient(Request $request): OAuthClient|JsonResponse
    {
        $id = (string) $request->input('client_id');
        $secret = $request->input('client_secret');

        // client_secret_basic
        if ($basic = $request->header('Authorization')) {
            if (str_starts_with($basic, 'Basic ')) {
                [$bid, $bsecret] = array_pad(explode(':', (string) base64_decode(substr($basic, 6)), 2), 2, null);
                $id = $id !== '' ? $id : urldecode((string) $bid);
                $secret = $secret ?? ($bsecret !== null ? urldecode($bsecret) : null);
            }
        }

        $client = OAuthClient::where('client_id', $id)->first();
        if (! $client) {
            return $this->oauthError('invalid_client', 'Unknown client_id.', 401);
        }
        if ($client->secret_hash && ! hash_equals($client->secret_hash, hash('sha256', (string) $secret))) {
            return $this->oauthError('invalid_client', 'Client authentication failed.', 401);
        }

        return $client;
    }

    private function acceptableRedirect(string $uri): bool
    {
        $parts = parse_url($uri);
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host']) || isset($parts['fragment'])) {
            return false;
        }

        return $parts['scheme'] === 'https'
            || ($parts['scheme'] === 'http' && in_array($parts['host'], ['localhost', '127.0.0.1', '[::1]'], true));
    }

    private function withQuery(string $uri, array $params): string
    {
        return $uri.(str_contains($uri, '?') ? '&' : '?').http_build_query($params);
    }

    private function oauthError(string $error, string $description, int $status = 400): JsonResponse
    {
        if ($status >= 500) {
            Log::error('OAuth: '.$error.' — '.$description);
        }

        return $this->cors(response()->json(['error' => $error, 'error_description' => $description], $status)
            ->header('Cache-Control', 'no-store'));
    }

    private function page(string $view, array $data, int $status = 200)
    {
        return response()->view($view, $data, $status)
            ->header('Cache-Control', 'no-store, private')
            ->header('X-Frame-Options', 'DENY');
    }

    /** @template T of \Symfony\Component\HttpFoundation\Response @param T $response @return T */
    private function cors($response)
    {
        $response->headers->set('Access-Control-Allow-Origin', '*');
        $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
        $response->headers->set('Access-Control-Allow-Headers', 'Authorization, Content-Type, MCP-Protocol-Version');

        return $response;
    }
}

<?php

namespace App\Http\Middleware;

use App\Models\ApiToken;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bearer-token auth for the admin API and the MCP endpoint.
 *
 * Deliberately stateless and cookie-free: these routes are called by scripts
 * and agents, never by a browser session, so there is no CSRF surface and no
 * reason to start a session.
 */
class AuthenticateApiToken
{
    public function handle(Request $request, Closure $next, ?string $ability = null): Response
    {
        $token = ApiToken::findValid($request->bearerToken());

        if (! $token) {
            return response()->json([
                'error' => 'unauthenticated',
                'message' => 'Provide a valid API token: Authorization: Bearer '.ApiToken::PREFIX.'…',
            ], 401);
        }

        if ($ability && ! $token->can($ability)) {
            return response()->json([
                'error' => 'forbidden',
                'message' => "This token does not have the '{$ability}' ability.",
            ], 403);
        }

        $token->touchUsage();
        $request->attributes->set('api_token', $token);

        return $next($request);
    }
}

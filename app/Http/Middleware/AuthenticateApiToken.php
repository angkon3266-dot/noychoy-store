<?php

namespace App\Http\Middleware;

use App\Models\ApiToken;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bearer-token auth for the admin API and the MCP endpoint.
 *
 * Deliberately stateless and cookie-free: these routes are called by scripts
 * and agents, never by a browser session, so there is no CSRF surface and no
 * reason to start a session.
 *
 * A token acts as the admin user who owns it. The user is set on the auth
 * guard for this request only, so everything downstream — the MCP tools'
 * role checks, `auth()->user()->name` in order history — sees who it is.
 * A token whose user has left the admin (role changed to something without
 * panel access, or deleted) stops working with them.
 *
 * Optional `section` parameter: the admin section (User::sectionsFor) the
 * token's user must be able to reach, e.g. `api.token:products`.
 */
class AuthenticateApiToken
{
    public function handle(Request $request, Closure $next, ?string $section = null): Response
    {
        $token = ApiToken::findValid($request->bearerToken());
        $user = $token?->user;

        if (! $token || ! $user || ! in_array($user->role, ['admin', 'manager', 'staff'], true)) {
            return response()->json([
                'error' => 'unauthenticated',
                'message' => 'Provide a valid token: Authorization: Bearer '.ApiToken::PREFIX.'… (Admin → Claude connector).',
            ], 401);
        }

        if ($section && ! $user->canAccess($section)) {
            return response()->json([
                'error' => 'forbidden',
                'message' => "This token acts as {$user->name} ({$user->role}), whose role cannot reach '{$section}'.",
            ], 403);
        }

        $token->touchUsage();
        $request->attributes->set('api_token', $token);
        Auth::setUser($user);
        $request->setUserResolver(fn () => $user);

        return $next($request);
    }
}

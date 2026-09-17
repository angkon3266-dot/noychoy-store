<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Session\NullSessionHandler;
use Symfony\Component\HttpFoundation\Response;

/**
 * Let a request read the session and never write it back.
 *
 * Laravel saves the whole session at the end of every request that started
 * one, changed or not — the database driver rewrites the row's payload from
 * the copy it read at the start. For an ordinary page that is harmless. For a
 * read that races a write it is not: GET /cart/ladder-quote fires the moment
 * the cart changes, so a shopper who removes a second piece while the quote
 * for the first is still being worked out has her remove saved, then
 * overwritten by the quote's older copy, and the piece is back in her cart.
 *
 * So for the routes that carry this, the session store is switched to a
 * handler that discards writes once StartSession has loaded it. Everything
 * downstream still reads the same store — CartService, session(), the auth
 * guard, the CSRF cookie — and StartSession still runs its usual save; the
 * save simply lands nowhere. Nothing the request does to the session outlives
 * it, which is what a read should promise anyway.
 *
 * The real handler goes back once the response has been sent (terminate), so
 * a long-lived application — the test suite, or Octane — does not carry the
 * switch into the next request.
 *
 * It must run after StartSession and before anything that can answer early,
 * the throttle especially: a 429 is saved like any other response. See the
 * route for how that order is held.
 */
class ReadOnlySession
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->hasSession()) {
            $session = $request->session();
            $request->attributes->set(self::class, $session->getHandler());
            $session->setHandler(new NullSessionHandler);
        }

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        $handler = $request->attributes->get(self::class);
        if ($handler && $request->hasSession()) {
            $request->session()->setHandler($handler);
        }
    }
}

<?php

namespace App\Http\Middleware;

use App\Support\Referral;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Any storefront URL can carry ?ref=CODE (shared product links, ads run by a
 * member); the invite is remembered exactly like the /invite/{code} landing.
 */
class CaptureReferral
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethod('GET') && $request->filled('ref')) {
            Referral::remember($request, (string) $request->query('ref'));
        }

        return $next($request);
    }
}

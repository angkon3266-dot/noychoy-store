<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! auth()->check()) {
            return redirect()->route('admin.login');
        }

        if (! in_array(auth()->user()->role, ['admin', 'manager'], true)) {
            abort(403, 'You do not have access to the admin area.');
        }

        return $next($request);
    }
}

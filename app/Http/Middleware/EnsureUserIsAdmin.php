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

        $user = auth()->user();

        if (! in_array($user->role, ['admin', 'manager', 'staff'], true)) {
            abort(403, 'You do not have access to the admin area.');
        }

        // Per-section access for preset roles. Section = segment after "admin." in the route name.
        $routeName = (string) optional($request->route())->getName();
        if (str_starts_with($routeName, 'admin.') && $routeName !== 'admin.logout') {
            $section = explode('.', substr($routeName, 6))[0]; // e.g. admin.products.index → products
            if ($section && ! $user->canAccess($section)) {
                abort(403, 'Your role does not have access to this section.');
            }
        }

        return $next($request);
    }
}

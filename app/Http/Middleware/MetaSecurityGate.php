<?php

namespace App\Http\Middleware;

use App\Services\Meta\MetaSettings;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Secondary authentication wall for the Meta Integration module.
 *
 *  1. Only the Super Admin (role = admin) may enter — managers/staff are denied.
 *  2. Access additionally requires unlocking with a separate security password,
 *     stored per session for a limited TTL (see config meta.security.session_ttl).
 *
 * The unlock form itself is served on routes that skip this gate.
 */
class MetaSecurityGate
{
    public function __construct(private readonly MetaSettings $settings) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // Only the Super Admin role may access this module at all.
        if (! $user || $user->role !== 'admin') {
            abort(403, 'Only the Super Admin can access Meta Integration.');
        }

        // First run: no security password set yet → force the user to create one.
        if (! $this->settings->hasSecurityPassword()) {
            return redirect()->route('admin.meta.unlock')
                ->with('meta_setup', true);
        }

        $ttl = (int) config('meta.security.session_ttl', 120);
        $unlockedAt = $request->session()->get('meta_unlocked_at');

        $unlocked = $unlockedAt && now()->diffInMinutes(\Illuminate\Support\Carbon::parse($unlockedAt)) < $ttl;

        if (! $unlocked) {
            $request->session()->put('meta_intended_url', $request->fullUrl());

            return redirect()->route('admin.meta.unlock');
        }

        return $next($request);
    }
}

<?php

namespace App\Http\Middleware;

use App\Modules\Meta\Services\MetaDebug;
use App\Services\Meta\MetaSettings;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
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

        // Diagnostics only: this fires on every Meta admin request, so it is
        // gated behind Meta debug mode rather than written to production logs.
        if (app(MetaDebug::class)->enabled()) {
            Log::info('[meta-oauth] meta.gate ENTER', [
                'path' => $request->path(),
                'user_role' => $user?->role,
            ]);
        }

        // Only the Super Admin role may access this module at all.
        if (! $user || $user->role !== 'admin') {
            Log::warning('[meta-oauth] meta.gate ABORT 403 (not super admin) — controller NOT reached', ['path' => $request->path(), 'role' => $user?->role]);
            abort(403, 'Only the Super Admin can access Meta Integration.');
        }

        // First run: no security password set yet → force the user to create one.
        if (! $this->settings->hasSecurityPassword()) {
            Log::warning('[meta-oauth] meta.gate REDIRECT to unlock (no security password set) — controller NOT reached', ['path' => $request->path()]);

            return redirect()->route('admin.meta.unlock')
                ->with('meta_setup', true);
        }

        $ttl = (int) config('meta.security.session_ttl', 120);
        $unlockedAt = $request->session()->get('meta_unlocked_at');

        // Compare instants rather than diffing them. Carbon 3's diffInMinutes()
        // is SIGNED (b − a), so `now()->diffInMinutes($unlockedAt)` returned a
        // negative number for any past unlock — always < $ttl — and the module
        // never auto-locked at all, however long the session had been idle.
        $unlocked = $unlockedAt
            && Carbon::parse($unlockedAt)->gt(now()->subMinutes($ttl));

        if (! $unlocked) {
            Log::warning('[meta-oauth] meta.gate REDIRECT to unlock (session not unlocked) — controller NOT reached', [
                'path' => $request->path(),
                'unlocked_at' => $unlockedAt,
                'ttl_minutes' => $ttl,
                'session_id' => $request->session()->getId(),
            ]);
            $request->session()->put('meta_intended_url', $request->fullUrl());

            return redirect()->route('admin.meta.unlock');
        }

        if (app(MetaDebug::class)->enabled()) {
            Log::info('[meta-oauth] meta.gate PASS → controller', ['path' => $request->path()]);
        }

        return $next($request);
    }
}

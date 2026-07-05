<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Meta\MetaSecurityPasswordRequest;
use App\Models\MetaAccessLog;
use App\Services\Meta\MetaSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Handles the secondary password wall: showing the unlock screen, verifying the
 * security password (with rate limiting + lockout), and changing it. Only the
 * Super Admin reaches these routes (enforced by the `admin` middleware + the
 * "meta.access" gate).
 */
class MetaSecurityController extends Controller
{
    public function __construct(private readonly MetaSettings $settings) {}

    /** Unlock screen (also the "set your security password" screen on first run). */
    public function show(Request $request)
    {
        return view('admin.meta.unlock', [
            'needsSetup' => ! $this->settings->hasSecurityPassword(),
            'lockedUntil' => $this->settings->lockedUntil(),
        ]);
    }

    /** Verify the security password and unlock the session. */
    public function unlock(Request $request)
    {
        $request->validate(['security_password' => ['required', 'string']]);

        // First run: no password yet → send them to set one.
        if (! $this->settings->hasSecurityPassword()) {
            return redirect()->route('admin.meta.unlock')->with('meta_setup', true);
        }

        if ($this->settings->isLockedOut()) {
            MetaAccessLog::record('locked_out');

            return back()->withErrors(['security_password' => 'Locked out. Try again after '
                .$this->settings->lockedUntil()?->diffForHumans().'.']);
        }

        // Per-IP throttle as a second layer on top of the persistent lockout.
        $key = 'meta-unlock:'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, config('meta.security.max_attempts', 5))) {
            return back()->withErrors(['security_password' => 'Too many attempts. Please wait a minute.']);
        }

        if (! $this->settings->checkSecurityPassword($request->input('security_password'))) {
            RateLimiter::hit($key, 60);
            $this->settings->registerFailedAttempt();
            MetaAccessLog::record('unlock_failed');

            $msg = $this->settings->isLockedOut()
                ? 'Too many failed attempts. Access locked for '.config('meta.security.lockout_minutes', 15).' minutes.'
                : 'Incorrect security password.';

            return back()->withErrors(['security_password' => $msg]);
        }

        // Success.
        RateLimiter::clear($key);
        $this->settings->clearFailedAttempts();
        $request->session()->put('meta_unlocked_at', now()->toIso8601String());
        MetaAccessLog::record('unlock_success');

        return redirect()->to($request->session()->pull('meta_intended_url', route('admin.meta.index')));
    }

    /** Create or change the security password. */
    public function updatePassword(MetaSecurityPasswordRequest $request)
    {
        // If a password already exists, require the current one.
        if ($this->settings->hasSecurityPassword()) {
            if (! $this->settings->checkSecurityPassword((string) $request->input('current_password'))) {
                return back()->withErrors(['current_password' => 'Current security password is incorrect.']);
            }
        }

        $this->settings->setSecurityPassword($request->input('new_password'));
        $this->settings->clearFailedAttempts();
        $request->session()->put('meta_unlocked_at', now()->toIso8601String());
        MetaAccessLog::record('password_changed');

        return redirect()->route('admin.meta.index')->with('success', 'Security password updated.');
    }

    /** Explicitly lock the module again (clears the unlock flag). */
    public function lock(Request $request)
    {
        $request->session()->forget('meta_unlocked_at');

        return redirect()->route('admin.meta.unlock')->with('success', 'Meta Integration locked.');
    }
}
